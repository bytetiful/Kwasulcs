<?php

declare(strict_types=1);

require_once __DIR__ . '/bot_api.php';
require_once __DIR__ . '/connections.php';
require_once __DIR__ . '/rate_limit.php';

const TELEGRAM_JOB_LEASE_SECONDS = 300;
const TELEGRAM_MAX_PAYLOAD_BYTES = 65536;

function telegram_worker_only(): void
{
    if (PHP_SAPI !== 'cli') throw new RuntimeException('Telegram delivery processing requires CLI execution.');
}

function telegram_payload_json(array $payload): string
{
    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, 12);
    if (strlen($json) > TELEGRAM_MAX_PAYLOAD_BYTES) throw new InvalidArgumentException('Telegram delivery payload is too large.');
    return $json;
}

function telegram_delivery_idempotency_key(
    string $sourceType,
    int $sourceId,
    int $userId,
    string $notificationType,
    string $logicalOccurrenceKey
): string {
    return hash('sha256', 'lcs-telegram-v1:' . trim($sourceType) . ':' . $sourceId . ':' . $userId . ':'
        . trim($notificationType) . ':' . trim($logicalOccurrenceKey));
}

function telegram_enqueue_delivery(array $input, ?array $config = null): array
{
    $config = telegram_config($config);
    $userId = (int)($input['user_id'] ?? 0);
    if (!telegram_delivery_allowed_for_user($userId, $config)) {
        throw new DomainException('Telegram notifications are disabled for this user.');
    }
    $sourceType = trim((string)($input['source_type'] ?? ''));
    $sourceId = (int)($input['source_id'] ?? 0);
    $notificationType = trim((string)($input['notification_type'] ?? ''));
    $logicalKey = trim((string)($input['logical_occurrence_key'] ?? ''));
    $messagePayload = $input['message_payload'] ?? null;
    $keyboard = $input['inline_keyboard'] ?? null;
    $category = lcs_notification_category($notificationType);
    if ($userId < 1 || $sourceType === '' || mb_strlen($sourceType) > 40 || $sourceId < 1
        || !lcs_notification_type_is_supported($notificationType) || $logicalKey === '' || mb_strlen($logicalKey) > 500
        || !is_array($messagePayload) || ($keyboard !== null && !is_array($keyboard))) {
        throw new InvalidArgumentException('Invalid Telegram delivery request.');
    }
    $messageJson = telegram_payload_json($messagePayload);
    $keyboardJson = $keyboard === null ? null : telegram_payload_json($keyboard);
    $idempotencyKey = telegram_delivery_idempotency_key($sourceType, $sourceId, $userId, $notificationType, $logicalKey);
    $nextInput = trim((string)($input['next_attempt_at'] ?? date('Y-m-d H:i:s')));
    $nextTimestamp = strtotime($nextInput);
    if ($nextTimestamp === false) throw new InvalidArgumentException('Invalid Telegram next-attempt time.');
    $nextAttempt = date('Y-m-d H:i:s', $nextTimestamp);

    $pdo = db(); $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $connection = telegram_eligible_connection($userId, (string)$category, true);
        if (!$connection) throw new DomainException('Telegram connection is not eligible for this notification.');
        $insert = $pdo->prepare("INSERT INTO telegram_delivery_jobs
            (user_id,connection_id,source_type,source_id,notification_type,notification_category,message_payload_json,
             inline_keyboard_json,destination_chat_id,status,next_attempt_at,idempotency_key)
            VALUES (?,?,?,?,?,?,?,?,?,'queued',?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
        $insert->execute([$userId, $connection['id'], $sourceType, $sourceId, $notificationType, $category,
            $messageJson, $keyboardJson, (string)$connection['telegram_chat_id'], $nextAttempt, $idempotencyKey]);
        $created = $insert->rowCount() === 1;
        $jobId = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM telegram_delivery_jobs WHERE id=?');
        $stmt->execute([$jobId]); $job = $stmt->fetch();
        if (!$job || (int)$job['user_id'] !== $userId || (string)$job['source_type'] !== $sourceType
            || (int)$job['source_id'] !== $sourceId || (string)$job['notification_type'] !== $notificationType) {
            throw new DomainException('Telegram idempotency key conflicts with another logical delivery.');
        }
        $job['_created'] = $created;
        if ($own) $pdo->commit();
        return $job;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Atomically lease one due job; MariaDB UPDATE ORDER BY LIMIT prevents double claims. */
function telegram_claim_job(?int $jobId = null): ?array
{
    telegram_worker_only();
    $token = bin2hex(random_bytes(16));
    $sql = "UPDATE telegram_delivery_jobs SET status='processing',claim_token=?,claimed_at=NOW(),
        lease_expires_at=DATE_ADD(NOW(),INTERVAL " . TELEGRAM_JOB_LEASE_SECONDS . " SECOND),attempt_count=attempt_count+1
        WHERE ((status='queued' AND next_attempt_at<=NOW()) OR (status='processing' AND lease_expires_at<NOW()))";
    $params = [$token];
    if ($jobId !== null) { $sql .= ' AND id=?'; $params[] = $jobId; }
    $sql .= ' ORDER BY next_attempt_at,id LIMIT 1';
    $stmt = db()->prepare($sql); $stmt->execute($params);
    if ($stmt->rowCount() !== 1) return null;
    $stmt = db()->prepare("SELECT * FROM telegram_delivery_jobs WHERE claim_token=? AND status='processing'");
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

function telegram_mark_sent(int $jobId, string $claimToken, int|string $providerMessageId): bool
{
    $messageId = telegram_normalize_integer_id($providerMessageId);
    if ($messageId === null) throw new InvalidArgumentException('Invalid Telegram message ID.');
    $stmt = db()->prepare("UPDATE telegram_delivery_jobs SET status='sent',provider_message_id=?,sent_at=NOW(),
        claim_token=NULL,claimed_at=NULL,lease_expires_at=NULL,last_error_code=NULL,last_error_message=NULL
        WHERE id=? AND claim_token=? AND status='processing'");
    $stmt->execute([$messageId, $jobId, $claimToken]);
    return $stmt->rowCount() === 1;
}

function telegram_retry_delay_seconds(int $attemptCount): int
{
    return min(21600, 60 * (2 ** min(8, max(0, $attemptCount - 1))));
}

function telegram_schedule_retry(
    int $jobId,
    string $claimToken,
    int $attemptCount,
    ?string $code,
    string $message,
    ?int $retryAfter = null
): bool {
    $delay = $retryAfter !== null ? max(1, min(86400, $retryAfter)) : telegram_retry_delay_seconds($attemptCount);
    $nextAttempt = date('Y-m-d H:i:s', time() + $delay);
    $stmt = db()->prepare("UPDATE telegram_delivery_jobs SET status='queued',next_attempt_at=?,failed_at=NULL,
        last_error_code=?,last_error_message=?,claim_token=NULL,claimed_at=NULL,lease_expires_at=NULL
        WHERE id=? AND claim_token=? AND status='processing'");
    $stmt->execute([$nextAttempt, $code === null ? null : mb_substr($code, 0, 100),
        telegram_redact_secrets($message), $jobId, $claimToken]);
    return $stmt->rowCount() === 1;
}

function telegram_mark_failed(int $jobId, string $claimToken, ?string $code, string $message): bool
{
    $stmt = db()->prepare("UPDATE telegram_delivery_jobs SET status='failed',failed_at=NOW(),last_error_code=?,
        last_error_message=?,claim_token=NULL,claimed_at=NULL,lease_expires_at=NULL
        WHERE id=? AND claim_token=? AND status='processing'");
    $stmt->execute([$code === null ? null : mb_substr($code, 0, 100), telegram_redact_secrets($message), $jobId, $claimToken]);
    return $stmt->rowCount() === 1;
}

function telegram_cancel_claimed(int $jobId, string $claimToken, string $code, string $message): bool
{
    $stmt = db()->prepare("UPDATE telegram_delivery_jobs SET status='cancelled',cancelled_at=NOW(),last_error_code=?,
        last_error_message=?,claim_token=NULL,claimed_at=NULL,lease_expires_at=NULL
        WHERE id=? AND claim_token=? AND status='processing'");
    $stmt->execute([mb_substr($code, 0, 100), telegram_redact_secrets($message), $jobId, $claimToken]);
    return $stmt->rowCount() === 1;
}

function telegram_cancel_job(int $jobId, string $code = 'cancelled', string $message = 'Telegram delivery was cancelled.'): bool
{
    $stmt = db()->prepare("UPDATE telegram_delivery_jobs SET status='cancelled',cancelled_at=NOW(),last_error_code=?,
        last_error_message=?,claim_token=NULL,claimed_at=NULL,lease_expires_at=NULL
        WHERE id=? AND status='queued'");
    $stmt->execute([mb_substr($code, 0, 100), telegram_redact_secrets($message), $jobId]);
    return $stmt->rowCount() === 1;
}

function telegram_cancel_pending_jobs_by_user(int $userId, string $code, string $message): int
{
    $stmt = db()->prepare("UPDATE telegram_delivery_jobs SET status='cancelled',cancelled_at=NOW(),last_error_code=?,
        last_error_message=?,claim_token=NULL,claimed_at=NULL,lease_expires_at=NULL
        WHERE user_id=? AND status='queued'");
    $stmt->execute([mb_substr($code, 0, 100), telegram_redact_secrets($message), $userId]);
    return $stmt->rowCount();
}

function telegram_cancel_pending_jobs_by_source(string $sourceType, int $sourceId, string $code, string $message): int
{
    $stmt = db()->prepare("UPDATE telegram_delivery_jobs SET status='cancelled',cancelled_at=NOW(),last_error_code=?,
        last_error_message=?,claim_token=NULL,claimed_at=NULL,lease_expires_at=NULL
        WHERE source_type=? AND source_id=? AND status='queued'");
    $stmt->execute([mb_substr($code, 0, 100), telegram_redact_secrets($message), $sourceType, $sourceId]);
    return $stmt->rowCount();
}

/** Process one explicitly claimed job. Stage 2 intentionally has no scheduler integration. */
function telegram_process_claimed_job(
    array $job,
    TelegramBotProvider $provider,
    ?array $config = null,
    ?TelegramRateLimiter $rateLimiter = null,
    ?callable $sourceValidator = null
): string {
    telegram_worker_only();
    $config = telegram_config($config);
    $jobId = (int)($job['id'] ?? 0); $claimToken = (string)($job['claim_token'] ?? '');
    if ($jobId < 1 || $claimToken === '' || ($job['status'] ?? '') !== 'processing') {
        throw new InvalidArgumentException('A claimed Telegram job is required.');
    }
    $lease = db()->prepare("SELECT id FROM telegram_delivery_jobs
        WHERE id=? AND claim_token=? AND status='processing' AND lease_expires_at>NOW()");
    $lease->execute([$jobId, $claimToken]);
    if (!$lease->fetchColumn()) return 'cancelled';
    if (!telegram_delivery_allowed_for_user((int)$job['user_id'], $config)) {
        telegram_cancel_claimed($jobId, $claimToken, 'channel_disabled', 'Telegram notifications are disabled.');
        return 'cancelled';
    }
    $connection = telegram_connection_by_user((int)$job['user_id']);
    if (!$connection || (int)$connection['id'] !== (int)$job['connection_id']
        || (string)$connection['telegram_chat_id'] !== (string)$job['destination_chat_id']
        || !telegram_connection_is_eligible($connection, (string)$job['notification_category'])) {
        telegram_cancel_claimed($jobId, $claimToken, 'connection_not_eligible', 'Telegram connection is no longer eligible.');
        return 'cancelled';
    }
    if ($sourceValidator !== null) {
        try {
            if (!$sourceValidator($job)) {
                telegram_cancel_claimed($jobId, $claimToken, 'source_invalid', 'Telegram notification is no longer eligible.');
                return 'cancelled';
            }
        } catch (Throwable $e) {
            telegram_cancel_claimed($jobId, $claimToken, 'source_validation_failed', 'Telegram notification could not be validated.');
            return 'cancelled';
        }
    }
    try {
        $payload = json_decode((string)$job['message_payload_json'], true, 12, JSON_THROW_ON_ERROR);
        $keyboard = $job['inline_keyboard_json'] === null ? null
            : json_decode((string)$job['inline_keyboard_json'], true, 12, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || !is_string($payload['text'] ?? null)) throw new JsonException('Invalid payload.');
        $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];
        if (is_array($keyboard)) $options['reply_markup'] = ['inline_keyboard' => $keyboard];
        if ($rateLimiter !== null) $rateLimiter->acquire();
        $result = $provider->sendMessage((string)$job['destination_chat_id'], $payload['text'], $options);
    } catch (InvalidArgumentException | JsonException $e) {
        telegram_mark_failed($jobId, $claimToken, 'invalid_job', 'Stored Telegram delivery data is invalid.');
        return 'failed';
    } catch (Throwable $e) {
        error_log('[LCS Telegram] provider exception: ' . telegram_redact_secrets($e->getMessage(), $config));
        if ((int)$job['attempt_count'] < (int)$config['max_attempts']) {
            telegram_schedule_retry($jobId, $claimToken, (int)$job['attempt_count'], 'provider_exception',
                'Telegram delivery is temporarily unavailable.');
            return 'retry';
        }
        telegram_mark_failed($jobId, $claimToken, 'retry_exhausted', 'Telegram delivery retry limit was reached.');
        return 'failed';
    }
    if (!empty($result['ok'])) {
        if (!telegram_mark_sent($jobId, $claimToken, (string)$result['provider_message_id'])) {
            throw new RuntimeException('Telegram job claim was lost before acknowledgement.');
        }
        return 'sent';
    }
    $code = isset($result['error_code']) ? (string)$result['error_code'] : null;
    $message = (string)($result['error_message'] ?? 'Telegram delivery failed.');
    if (!empty($result['transient'])) {
        if ((int)$job['attempt_count'] < (int)$config['max_attempts']) {
            telegram_schedule_retry($jobId, $claimToken, (int)$job['attempt_count'], $code, $message,
                isset($result['retry_after']) ? (int)$result['retry_after'] : null);
            return 'retry';
        }
        telegram_mark_failed($jobId, $claimToken, 'retry_exhausted', 'Telegram delivery retry limit was reached.');
        return 'failed';
    }
    telegram_mark_failed($jobId, $claimToken, $code, $message);
    if (!empty($result['connection_inaccessible'])) {
        telegram_mark_connection_inaccessible((int)$job['connection_id']);
    }
    return 'failed';
}
