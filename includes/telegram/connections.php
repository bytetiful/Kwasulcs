<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/notifications/types.php';

function telegram_connection_by_user(int $userId, bool $forUpdate = false): ?array
{
    if ($userId < 1) return null;
    $sql = 'SELECT * FROM telegram_connections WHERE user_id=?' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = db()->prepare($sql); $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function telegram_connection_by_telegram_user(int|string $telegramUserId, bool $forUpdate = false): ?array
{
    $id = telegram_normalize_integer_id($telegramUserId);
    if ($id === null) return null;
    $sql = 'SELECT * FROM telegram_connections WHERE telegram_user_id=?' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = db()->prepare($sql); $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function telegram_connection_by_chat(int|string $telegramChatId, bool $forUpdate = false): ?array
{
    $id = telegram_normalize_integer_id($telegramChatId);
    if ($id === null) return null;
    $sql = 'SELECT * FROM telegram_connections WHERE telegram_chat_id=?' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = db()->prepare($sql); $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function telegram_initialize_preferences(int $connectionId): void
{
    if ($connectionId < 1) throw new InvalidArgumentException('Invalid Telegram connection.');
    $insert = db()->prepare('INSERT IGNORE INTO telegram_notification_preferences(connection_id,category,enabled) VALUES (?,?,1)');
    foreach (array_keys(lcs_notification_category_map()) as $category) $insert->execute([$connectionId, $category]);
}

function telegram_category_enabled(int $connectionId, string $category): bool
{
    if ($connectionId < 1 || !array_key_exists($category, lcs_notification_category_map())) return false;
    $stmt = db()->prepare('SELECT enabled FROM telegram_notification_preferences WHERE connection_id=? AND category=?');
    $stmt->execute([$connectionId, $category]);
    $value = $stmt->fetchColumn();
    return $value !== false && (bool)$value;
}

function telegram_connection_is_eligible(array $connection, ?string $category = null): bool
{
    if ((string)($connection['chat_type'] ?? '') !== 'private' || !(int)($connection['enabled'] ?? 0)
        || (string)($connection['consent_status'] ?? '') !== 'consented'
        || empty($connection['consented_at']) || empty($connection['connected_at'])
        || !empty($connection['disconnected_at']) || !empty($connection['blocked_at'])
        || telegram_normalize_integer_id($connection['telegram_user_id'] ?? '') === null
        || telegram_normalize_integer_id($connection['telegram_chat_id'] ?? '') === null) return false;
    return $category === null || telegram_category_enabled((int)$connection['id'], $category);
}

function telegram_eligible_connection(int $userId, string $category, bool $forUpdate = false): ?array
{
    $connection = telegram_connection_by_user($userId, $forUpdate);
    return $connection && telegram_connection_is_eligible($connection, $category) ? $connection : null;
}

function telegram_cancel_pending_jobs_by_connection(int $connectionId, string $code, string $message): int
{
    $stmt = db()->prepare("UPDATE telegram_delivery_jobs SET status='cancelled',cancelled_at=NOW(),
        last_error_code=?,last_error_message=?,claim_token=NULL,claimed_at=NULL,lease_expires_at=NULL
        WHERE connection_id=? AND status='queued'");
    $stmt->execute([mb_substr($code, 0, 100), telegram_redact_secrets($message), $connectionId]);
    return $stmt->rowCount();
}

function telegram_cancel_pending_jobs_by_connection_category(
    int $connectionId,
    string $category,
    string $code,
    string $message
): int {
    if ($connectionId < 1 || !array_key_exists($category, lcs_notification_category_map())) return 0;
    $stmt = db()->prepare("UPDATE telegram_delivery_jobs SET status='cancelled',cancelled_at=NOW(),
        last_error_code=?,last_error_message=?,claim_token=NULL,claimed_at=NULL,lease_expires_at=NULL
        WHERE connection_id=? AND notification_category=? AND status='queued'");
    $stmt->execute([
        mb_substr($code, 0, 100),
        telegram_redact_secrets($message),
        $connectionId,
        $category,
    ]);
    return $stmt->rowCount();
}

function telegram_disable_connection(int $connectionId, bool $disconnect = false, string $reason = 'Telegram connection was disabled.'): int
{
    if ($connectionId < 1) return 0;
    $pdo = db(); $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if ($disconnect) {
            $stmt = $pdo->prepare("UPDATE telegram_connections SET enabled=0,consent_status='revoked',
                disconnected_at=COALESCE(disconnected_at,NOW()) WHERE id=?");
        } else {
            $stmt = $pdo->prepare('UPDATE telegram_connections SET enabled=0 WHERE id=?');
        }
        $stmt->execute([$connectionId]);
        $cancelled = telegram_cancel_pending_jobs_by_connection($connectionId,
            $disconnect ? 'connection_disconnected' : 'connection_disabled', $reason);
        if ($own) $pdo->commit();
        return $cancelled;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function telegram_mark_connection_inaccessible(int $connectionId, string $reason = 'Telegram destination is inaccessible.'): int
{
    if ($connectionId < 1) return 0;
    $pdo = db(); $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE telegram_connections SET enabled=0,blocked_at=COALESCE(blocked_at,NOW()) WHERE id=?')
            ->execute([$connectionId]);
        $cancelled = telegram_cancel_pending_jobs_by_connection($connectionId, 'connection_inaccessible', $reason);
        if ($own) $pdo->commit();
        return $cancelled;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
