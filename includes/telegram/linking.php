<?php

declare(strict_types=1);

require_once __DIR__ . '/connections.php';

const TELEGRAM_LINK_TOKEN_BYTES = 32;

function telegram_link_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function telegram_link_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function telegram_link_token_is_well_formed(string $token): bool
{
    return strlen($token) >= 43 && strlen($token) <= 128 && preg_match('/^[A-Za-z0-9_-]+$/', $token) === 1;
}

function telegram_link_csrf_token(): string
{
    if (empty($_SESSION['telegram_link_csrf'])) {
        $_SESSION['telegram_link_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['telegram_link_csrf'];
}

function telegram_link_verify_csrf(?string $token): bool
{
    return is_string($token) && isset($_SESSION['telegram_link_csrf'])
        && hash_equals((string)$_SESSION['telegram_link_csrf'], $token);
}

/**
 * Create a short-lived, single-use bearer token. Only its SHA-256 digest is
 * persisted; the plaintext is returned once for construction of the t.me URL.
 */
function telegram_create_link_token(int $userId, ?array $config = null): array
{
    if ($userId < 1) throw new InvalidArgumentException('An authenticated LCS user is required.');
    $config = telegram_config($config);
    if (!telegram_delivery_allowed_for_user($userId, $config)) {
        throw new DomainException('Telegram connection is not available for this account.');
    }
    $ttl = max(60, min(3600, (int)($config['link_token_ttl_seconds'] ?? 600)));
    $plain = telegram_link_base64url_encode(random_bytes(TELEGRAM_LINK_TOKEN_BYTES));
    $hash = telegram_link_token_hash($plain);
    $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE telegram_link_tokens SET invalidated_at=NOW()
            WHERE user_id=? AND consumed_at IS NULL AND invalidated_at IS NULL")
            ->execute([$userId]);
        $pdo->prepare('INSERT INTO telegram_link_tokens(user_id,token_hash,expires_at) VALUES (?,?,?)')
            ->execute([$userId, $hash, $expiresAt]);
        $id = (int)$pdo->lastInsertId();
        if ($own) $pdo->commit();
        return ['id' => $id, 'token' => $plain, 'expires_at' => $expiresAt];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function telegram_link_deep_link(string $token, ?array $config = null): ?string
{
    $config = telegram_config($config);
    $username = ltrim(trim((string)($config['bot_username'] ?? '')), '@');
    if (!telegram_link_token_is_well_formed($token) || !preg_match('/^[A-Za-z0-9_]{5,32}$/', $username)) return null;
    return 'https://t.me/' . rawurlencode($username) . '?start=' . rawurlencode($token);
}

/** Testable controller primitive used by telegram_connect.php. */
function telegram_connect_handle_request(
    string $method,
    ?array $user,
    ?string $csrf,
    ?array $config = null
): array {
    if (strtoupper($method) !== 'POST') {
        return ['status' => 405, 'body' => ['status' => 'error', 'message' => 'Method not allowed.']];
    }
    if (!$user || (int)($user['id'] ?? 0) < 1) {
        return ['status' => 401, 'body' => ['status' => 'error', 'message' => 'Authentication required.']];
    }
    if (!telegram_link_verify_csrf($csrf)) {
        return ['status' => 403, 'body' => ['status' => 'error', 'message' => 'Invalid request token.']];
    }
    $config = telegram_config($config);
    if (!telegram_delivery_allowed_for_user((int)$user['id'], $config)) {
        return ['status' => 503, 'body' => ['status' => 'error', 'message' => 'Telegram connection is not available.']];
    }
    $username = ltrim(trim((string)($config['bot_username'] ?? '')), '@');
    if (!preg_match('/^[A-Za-z0-9_]{5,32}$/', $username)) {
        return ['status' => 503, 'body' => ['status' => 'error', 'message' => 'Telegram connection is not configured.']];
    }
    $link = telegram_create_link_token((int)$user['id'], $config);
    $url = telegram_link_deep_link((string)$link['token'], $config);
    if ($url === null) {
        return ['status' => 503, 'body' => ['status' => 'error', 'message' => 'Telegram connection is not configured.']];
    }
    return ['status' => 200, 'body' => ['status' => 'ok', 'url' => $url]];
}

/** Return only neutral states so callers cannot enumerate link-token details. */
function telegram_link_token_state(string $token, ?int $now = null): string
{
    if (!telegram_link_token_is_well_formed($token)) return 'invalid';
    $stmt = db()->prepare('SELECT expires_at,consumed_at,invalidated_at FROM telegram_link_tokens WHERE token_hash=?');
    $stmt->execute([telegram_link_token_hash($token)]);
    $row = $stmt->fetch();
    if (!$row || !empty($row['consumed_at']) || !empty($row['invalidated_at'])
        || strtotime((string)$row['expires_at']) < ($now ?? time())) return 'invalid';
    return 'valid';
}

/** Internal-only lookup used to enforce the current pilot gate before token consumption. */
function telegram_link_token_user_id(string $token, ?int $now = null): ?int
{
    if (!telegram_link_token_is_well_formed($token)) return null;
    $stmt = db()->prepare('SELECT user_id,expires_at,consumed_at,invalidated_at FROM telegram_link_tokens WHERE token_hash=?');
    $stmt->execute([telegram_link_token_hash($token)]);
    $row = $stmt->fetch();
    if (!$row || !empty($row['consumed_at']) || !empty($row['invalidated_at'])
        || strtotime((string)$row['expires_at']) < ($now ?? time())) return null;
    $userId = (int)$row['user_id'];
    return $userId > 0 ? $userId : null;
}

function telegram_link_bounded_identity_text(mixed $value, int $max): ?string
{
    if (!is_string($value)) return null;
    $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
    return $value === '' ? null : mb_substr($value, 0, $max);
}

/**
 * Atomically consume a token and bind a private Telegram identity.
 *
 * $beforeCommit is a test seam used to prove rollback safety; production
 * callers leave it null.
 */
function telegram_consume_link_token(
    string $token,
    int|string $telegramUserId,
    int|string $telegramChatId,
    string $chatType,
    ?string $displayName = null,
    ?string $username = null,
    ?callable $beforeCommit = null
): array {
    $tgUser = telegram_normalize_integer_id($telegramUserId);
    $tgChat = telegram_normalize_integer_id($telegramChatId);
    if (!telegram_link_token_is_well_formed($token) || $tgUser === null || $tgChat === null || $chatType !== 'private') {
        return ['ok' => false, 'state' => 'invalid'];
    }
    $hash = telegram_link_token_hash($token);
    $displayName = telegram_link_bounded_identity_text($displayName, 191) ?? 'Telegram user';
    $username = telegram_link_bounded_identity_text($username, 191);

    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    else $pdo->exec('SAVEPOINT telegram_link_consume');
    try {
        $stmt = $pdo->prepare('SELECT * FROM telegram_link_tokens WHERE token_hash=? FOR UPDATE');
        $stmt->execute([$hash]);
        $link = $stmt->fetch();
        if (!$link || !empty($link['consumed_at']) || !empty($link['invalidated_at'])
            || strtotime((string)$link['expires_at']) < time()) {
            if ($own) $pdo->rollBack();
            else $pdo->exec('RELEASE SAVEPOINT telegram_link_consume');
            return ['ok' => false, 'state' => 'invalid'];
        }
        $userId = (int)$link['user_id'];
        $existing = telegram_connection_by_user($userId, true);
        $byTelegramUser = telegram_connection_by_telegram_user($tgUser, true);
        $byChat = telegram_connection_by_chat($tgChat, true);
        if (($byTelegramUser && (int)$byTelegramUser['user_id'] !== $userId)
            || ($byChat && (int)$byChat['user_id'] !== $userId)) {
            if ($own) $pdo->rollBack();
            else $pdo->exec('RELEASE SAVEPOINT telegram_link_consume');
            return ['ok' => false, 'state' => 'identity_conflict'];
        }

        if ($existing) {
            $identityChanged = (string)$existing['telegram_user_id'] !== $tgUser
                || (string)$existing['telegram_chat_id'] !== $tgChat;
            $explicitlyDisconnected = !empty($existing['disconnected_at'])
                || (string)$existing['consent_status'] === 'revoked';
            if ($identityChanged && !$explicitlyDisconnected) {
                if ($own) $pdo->rollBack();
                else $pdo->exec('RELEASE SAVEPOINT telegram_link_consume');
                return ['ok' => false, 'state' => 'identity_conflict'];
            }
            $pdo->prepare("UPDATE telegram_connections SET telegram_user_id=?,telegram_chat_id=?,chat_type='private',
                display_name=?,username=?,enabled=1,consent_status='consented',consented_at=NOW(),connected_at=NOW(),
                disconnected_at=NULL,blocked_at=NULL WHERE id=?")
                ->execute([$tgUser, $tgChat, $displayName, $username, $existing['id']]);
            $connectionId = (int)$existing['id'];
            $state = 'reconnected';
        } else {
            $pdo->prepare("INSERT INTO telegram_connections
                (user_id,telegram_user_id,telegram_chat_id,chat_type,display_name,username,enabled,consent_status,
                 consented_at,connected_at) VALUES (?,?,?,'private',?,?,1,'consented',NOW(),NOW())")
                ->execute([$userId, $tgUser, $tgChat, $displayName, $username]);
            $connectionId = (int)$pdo->lastInsertId();
            $state = 'connected';
        }
        telegram_initialize_preferences($connectionId);
        $consume = $pdo->prepare("UPDATE telegram_link_tokens SET consumed_at=NOW()
            WHERE id=? AND consumed_at IS NULL AND invalidated_at IS NULL AND expires_at>=NOW()");
        $consume->execute([$link['id']]);
        if ($consume->rowCount() !== 1) throw new RuntimeException('Telegram link token could not be consumed.');
        $pdo->prepare("UPDATE telegram_link_tokens SET invalidated_at=NOW()
            WHERE user_id=? AND id<>? AND consumed_at IS NULL AND invalidated_at IS NULL")
            ->execute([$userId, $link['id']]);
        if ($beforeCommit) $beforeCommit();
        if ($own) $pdo->commit();
        else $pdo->exec('RELEASE SAVEPOINT telegram_link_consume');
        return ['ok' => true, 'state' => $state, 'user_id' => $userId, 'connection_id' => $connectionId];
    } catch (PDOException $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        elseif (!$own && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT telegram_link_consume');
            $pdo->exec('RELEASE SAVEPOINT telegram_link_consume');
        }
        if ((string)$e->getCode() === '23000') return ['ok' => false, 'state' => 'identity_conflict'];
        throw $e;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        elseif (!$own && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT telegram_link_consume');
            $pdo->exec('RELEASE SAVEPOINT telegram_link_consume');
        }
        throw $e;
    }
}

function telegram_disconnect_user(int $userId, string $reason = 'Telegram connection was disconnected.'): int
{
    if ($userId < 1) return 0;
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    else $pdo->exec('SAVEPOINT telegram_link_disconnect');
    try {
        $connection = telegram_connection_by_user($userId, true);
        $cancelled = $connection ? telegram_disable_connection((int)$connection['id'], true, $reason) : 0;
        $pdo->prepare("UPDATE telegram_link_tokens SET invalidated_at=NOW()
            WHERE user_id=? AND consumed_at IS NULL AND invalidated_at IS NULL")
            ->execute([$userId]);
        if ($own) $pdo->commit();
        else $pdo->exec('RELEASE SAVEPOINT telegram_link_disconnect');
        return $cancelled;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        elseif (!$own && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT telegram_link_disconnect');
            $pdo->exec('RELEASE SAVEPOINT telegram_link_disconnect');
        }
        throw $e;
    }
}
