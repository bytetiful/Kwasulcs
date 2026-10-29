<?php

declare(strict_types=1);

require_once __DIR__ . '/linking.php';

/** The only notification categories exposed by Telegram Settings. */
function telegram_settings_categories(): array
{
    return [
        LCS_NOTIFICATION_CATEGORY_CALENDAR_LECTURE => [
            'label' => 'Calendar & Lectures',
            'description' => 'Class schedules, calendar events and lecture reminders.',
            'icon' => 'bi-calendar3',
        ],
        LCS_NOTIFICATION_CATEGORY_ASSIGNMENT_TEST => [
            'label' => 'Assignment/Test',
            'description' => 'Publishing, deadlines and result updates for assignments and tests.',
            'icon' => 'bi-file-earmark-check',
        ],
        LCS_NOTIFICATION_CATEGORY_ONLINE_QUIZ_TEST => [
            'label' => 'Online Quiz/Test',
            'description' => 'Online quiz availability, closing reminders and released results.',
            'icon' => 'bi-ui-checks-grid',
        ],
    ];
}

function telegram_settings_csrf_token(): string
{
    return telegram_link_csrf_token();
}

function telegram_settings_verify_csrf(?string $token): bool
{
    return telegram_link_verify_csrf($token);
}

function telegram_settings_preferences(int $connectionId): array
{
    $preferences = array_fill_keys(array_keys(telegram_settings_categories()), true);
    if ($connectionId < 1) return $preferences;
    $stmt = db()->prepare('SELECT category,enabled FROM telegram_notification_preferences WHERE connection_id=?');
    $stmt->execute([$connectionId]);
    foreach ($stmt->fetchAll() as $row) {
        $category = (string)($row['category'] ?? '');
        if (array_key_exists($category, $preferences)) $preferences[$category] = (bool)$row['enabled'];
    }
    return $preferences;
}

function telegram_settings_bot_username(?array $config = null): ?string
{
    $username = ltrim(trim((string)(telegram_config($config)['bot_username'] ?? '')), '@');
    return preg_match('/^[A-Za-z0-9_]{5,32}$/', $username) === 1 ? $username : null;
}

function telegram_settings_connection_is_healthy(array $connection): bool
{
    return (string)($connection['chat_type'] ?? '') === 'private'
        && (string)($connection['consent_status'] ?? '') === 'consented'
        && !empty($connection['consented_at'])
        && !empty($connection['connected_at'])
        && empty($connection['disconnected_at'])
        && empty($connection['blocked_at'])
        && telegram_normalize_integer_id($connection['telegram_user_id'] ?? '') !== null
        && telegram_normalize_integer_id($connection['telegram_chat_id'] ?? '') !== null;
}

/**
 * Load the authenticated user's Settings state. Internal identifiers remain
 * server-side and are stripped by telegram_settings_public_state().
 */
function telegram_settings_state(int $userId, ?array $config = null): array
{
    $resolvedConfig = telegram_config($config);
    $connection = telegram_connection_by_user($userId);
    $state = 'not_connected';
    if ($connection) {
        if (!empty($connection['disconnected_at']) || (string)$connection['consent_status'] === 'revoked') {
            $state = 'disconnected';
        } elseif (!empty($connection['blocked_at'])) {
            $state = 'blocked';
        } elseif (telegram_settings_connection_is_healthy($connection)) {
            $state = (int)$connection['enabled'] === 1 ? 'connected' : 'disabled';
        } else {
            $state = 'disconnected';
        }
    }

    $displayName = $connection ? telegram_link_bounded_identity_text($connection['display_name'] ?? null, 191) : null;
    $username = $connection ? telegram_link_bounded_identity_text($connection['username'] ?? null, 191) : null;
    $botUsername = telegram_settings_bot_username($resolvedConfig);

    return [
        'state' => $state,
        'enabled' => $state === 'connected',
        'display_name' => $displayName,
        'username' => $username,
        'preferences' => telegram_settings_preferences((int)($connection['id'] ?? 0)),
        'service_enabled' => telegram_delivery_allowed_for_user($userId, $resolvedConfig) && $botUsername !== null,
        'bot_username' => $botUsername,
        'open_url' => $botUsername === null ? null : 'https://t.me/' . rawurlencode($botUsername),
        'connection' => $connection,
    ];
}

/** Safe response contract for the authenticated status endpoint. */
function telegram_settings_public_state(int $userId, ?array $config = null): array
{
    $state = telegram_settings_state($userId, $config);
    return [
        'state' => $state['state'],
        'enabled' => $state['enabled'],
        'display_name' => $state['display_name'],
        'username' => $state['username'],
        'preferences' => $state['preferences'],
    ];
}

function telegram_settings_cancel_category_jobs(int $connectionId, string $category): int
{
    return telegram_cancel_pending_jobs_by_connection_category(
        $connectionId,
        $category,
        'category_disabled',
        'Telegram notification category was disabled.'
    );
}

function telegram_settings_set_global_enabled(int $userId, bool $enabled): int
{
    if ($userId < 1) throw new DomainException('Authentication required.');
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    else $pdo->exec('SAVEPOINT telegram_settings_global');
    try {
        $connection = telegram_connection_by_user($userId, true);
        if (!$connection) throw new DomainException('Connect Telegram before changing notification delivery.');
        if ($enabled) {
            if (!telegram_settings_connection_is_healthy($connection)) {
                throw new DomainException('Reconnect Telegram before enabling notifications.');
            }
            $pdo->prepare('UPDATE telegram_connections SET enabled=1 WHERE id=? AND user_id=?')
                ->execute([(int)$connection['id'], $userId]);
            $cancelled = 0;
        } else {
            $cancelled = telegram_disable_connection((int)$connection['id'], false,
                'Telegram notifications were paused in Settings.');
        }
        if ($own) $pdo->commit();
        else $pdo->exec('RELEASE SAVEPOINT telegram_settings_global');
        return $cancelled;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        elseif (!$own && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT telegram_settings_global');
            $pdo->exec('RELEASE SAVEPOINT telegram_settings_global');
        }
        throw $e;
    }
}

function telegram_settings_set_category(int $userId, string $category, bool $enabled): int
{
    if ($userId < 1) throw new DomainException('Authentication required.');
    if (!array_key_exists($category, telegram_settings_categories())) {
        throw new InvalidArgumentException('Unsupported Telegram notification category.');
    }
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    else $pdo->exec('SAVEPOINT telegram_settings_category');
    try {
        $connection = telegram_connection_by_user($userId, true);
        if (!$connection || !telegram_settings_connection_is_healthy($connection)) {
            throw new DomainException('Reconnect Telegram before changing notification categories.');
        }
        $pdo->prepare('INSERT INTO telegram_notification_preferences(connection_id,category,enabled) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE enabled=VALUES(enabled)')
            ->execute([(int)$connection['id'], $category, $enabled ? 1 : 0]);
        $cancelled = $enabled ? 0 : telegram_settings_cancel_category_jobs((int)$connection['id'], $category);
        if ($own) $pdo->commit();
        else $pdo->exec('RELEASE SAVEPOINT telegram_settings_category');
        return $cancelled;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        elseif (!$own && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT telegram_settings_category');
            $pdo->exec('RELEASE SAVEPOINT telegram_settings_category');
        }
        throw $e;
    }
}

function telegram_settings_disconnect(int $userId, bool $confirmed): int
{
    if ($userId < 1) throw new DomainException('Authentication required.');
    if (!$confirmed) throw new DomainException('Confirm that you want to disconnect Telegram.');
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    else $pdo->exec('SAVEPOINT telegram_settings_disconnect');
    try {
        $cancelled = telegram_disconnect_user($userId, 'Telegram was disconnected in Settings.');
        if ($own) $pdo->commit();
        else $pdo->exec('RELEASE SAVEPOINT telegram_settings_disconnect');
        return $cancelled;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        elseif (!$own && $pdo->inTransaction()) {
            $pdo->exec('ROLLBACK TO SAVEPOINT telegram_settings_disconnect');
            $pdo->exec('RELEASE SAVEPOINT telegram_settings_disconnect');
        }
        throw $e;
    }
}

/** Testable authenticated mutation controller used by settings.php. */
function telegram_settings_handle_action(
    string $method,
    ?array $user,
    ?string $csrf,
    string $action,
    array $input
): array {
    if (strtoupper($method) !== 'POST') throw new DomainException('Method not allowed.');
    $userId = (int)($user['id'] ?? 0);
    if ($userId < 1) throw new DomainException('Authentication required.');
    if (!telegram_settings_verify_csrf($csrf)) throw new DomainException('Invalid request token.');

    if ($action === 'set_global') {
        $enabled = ($input['enabled'] ?? '') === '1';
        telegram_settings_set_global_enabled($userId, $enabled);
        return ['type' => 'success', 'message' => $enabled
            ? 'Telegram notifications enabled.'
            : 'Telegram notifications paused. Your connection and category choices were preserved.'];
    }
    if ($action === 'set_category') {
        $category = (string)($input['category'] ?? '');
        $enabled = ($input['enabled'] ?? '') === '1';
        telegram_settings_set_category($userId, $category, $enabled);
        $label = telegram_settings_categories()[$category]['label'] ?? 'Telegram category';
        return ['type' => 'success', 'message' => $label . ($enabled
            ? ' enabled.'
            : ' paused. Pending Telegram messages in this category were cancelled.')];
    }
    if ($action === 'disconnect') {
        telegram_settings_disconnect($userId, ($input['disconnect_confirm'] ?? '') === '1');
        return ['type' => 'success', 'message' => 'Telegram disconnected. Delivery history was preserved.'];
    }
    throw new InvalidArgumentException('Unsupported Telegram settings action.');
}

function telegram_settings_status_handle_request(string $method, ?array $user, ?array $config = null): array
{
    if (strtoupper($method) !== 'GET') {
        return ['status' => 405, 'body' => ['status' => 'error', 'message' => 'Method not allowed.']];
    }
    $userId = (int)($user['id'] ?? 0);
    if ($userId < 1) {
        return ['status' => 401, 'body' => ['status' => 'error', 'message' => 'Authentication required.']];
    }
    return ['status' => 200, 'body' => ['status' => 'ok', 'connection' => telegram_settings_public_state($userId, $config)]];
}
