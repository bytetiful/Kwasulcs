<?php

declare(strict_types=1);

require_once __DIR__ . '/notifications.php';

const TELEGRAM_LIVE_EXPECTED_BOT_USERNAME = 'TrustyGee_Bot';
const TELEGRAM_LIVE_EXPECTED_WEBHOOK_URL = 'https://kwasulcs.alwaysdata.net/telegram_webhook.php';
const TELEGRAM_LIVE_TEST_NOTIFICATION_TYPE = 'telegram_connection_test';

/** Safe Stage 6A readiness data: booleans/counts only, never credentials. */
function telegram_live_readiness(?array $config = null): array
{
    $config = telegram_config($config);
    $status = telegram_configuration_status($config);
    $pilots = telegram_pilot_user_ids($config);
    $usernameMatches = hash_equals(TELEGRAM_LIVE_EXPECTED_BOT_USERNAME, (string)$config['bot_username']);
    $webhookMatches = hash_equals(TELEGRAM_LIVE_EXPECTED_WEBHOOK_URL, (string)$config['webhook_url']);
    $base = telegram_application_base_url($config);
    $signingReady = strlen((string)($config['link_signing_key'] ?? '')) >= 32;
    $pilotSafe = empty($config['enabled']) && count($pilots) === 1;

    return [
        'telegram_enabled' => (bool)$config['enabled'],
        'pilot_mode_active' => !$config['enabled'] && $pilots !== [],
        'pilot_user_count' => count($pilots),
        'pilot_restriction_safe' => $pilotSafe,
        'bot_token_configured' => (bool)$status['bot_token_configured'],
        'bot_username_configured' => (bool)$status['bot_username_configured'],
        'bot_username_matches_expected' => $usernameMatches,
        'webhook_secret_configured' => (bool)$status['webhook_secret_configured'],
        'webhook_url_configured' => (bool)$status['webhook_url_configured'],
        'webhook_url_matches_expected' => $webhookMatches,
        'signed_link_configured' => $base !== null && $signingReady,
        'send_configuration_ready' => (bool)$status['send_ready'] && $usernameMatches,
        'webhook_configuration_ready' => (bool)$status['webhook_ready'] && $webhookMatches,
        'identity_check_ready' => (bool)$status['send_ready'] && $usernameMatches && $pilotSafe,
        'live_admin_ready' => (bool)$status['send_ready'] && (bool)$status['webhook_ready']
            && $usernameMatches && $webhookMatches && $pilotSafe,
        'test_send_ready' => (bool)$status['send_ready'] && $usernameMatches && $pilotSafe
            && $base !== null && $signingReady,
    ];
}

function telegram_live_require_identity_ready(?array $config = null): array
{
    $config = telegram_config($config);
    if (!telegram_live_readiness($config)['identity_check_ready']) {
        throw new DomainException('Telegram identity checking is not safely configured. Run --readiness.');
    }
    return $config;
}

function telegram_live_require_admin_ready(?array $config = null): array
{
    $config = telegram_config($config);
    if (!telegram_live_readiness($config)['live_admin_ready']) {
        throw new DomainException('Telegram live administration is not safely configured. Run --readiness.');
    }
    return $config;
}

function telegram_live_require_pilot_user(int $userId, ?array $config = null): array
{
    $config = telegram_config($config);
    $pilots = telegram_pilot_user_ids($config);
    if (!empty($config['enabled']) || count($pilots) !== 1 || $userId < 1 || $pilots[0] !== $userId) {
        throw new DomainException('The requested LCS user is not the sole Stage 6 pilot.');
    }
    return $config;
}

/** Controlled identity call. A mismatch is terminal and callers must stop. */
function telegram_live_verify_identity(TelegramBotApiClient $client, ?array $config = null): array
{
    $config = telegram_live_require_identity_ready($config);
    $result = $client->getMe();
    if (empty($result['ok'])) throw new RuntimeException((string)($result['error_message'] ?? 'Telegram identity check failed.'));
    if (empty($result['is_bot']) || !hash_equals(TELEGRAM_LIVE_EXPECTED_BOT_USERNAME, (string)$result['username'])
        || !hash_equals((string)$config['bot_username'], (string)$result['username'])) {
        throw new DomainException('Telegram bot identity mismatch. Stop before webhook registration or delivery.');
    }
    return ['verified' => true, 'username' => (string)$result['username']];
}

function telegram_live_register_webhook(TelegramBotApiClient $client, ?array $config = null): array
{
    telegram_live_require_admin_ready($config);
    $result = $client->setWebhook();
    if (empty($result['ok'])) throw new RuntimeException((string)($result['error_message'] ?? 'Webhook registration failed.'));
    return ['registered' => true];
}

function telegram_live_webhook_info(TelegramBotApiClient $client, ?array $config = null): array
{
    telegram_live_require_admin_ready($config);
    $result = $client->getWebhookInfo();
    if (empty($result['ok'])) throw new RuntimeException((string)($result['error_message'] ?? 'Webhook inspection failed.'));
    return [
        'url' => (string)$result['url'],
        'pending_update_count' => (int)$result['pending_update_count'],
        'last_error_date' => $result['last_error_date'],
        'last_error_message' => $result['last_error_message'],
        'max_connections' => $result['max_connections'],
    ];
}

/** Controlled emergency rollback: remove the webhook without discarding pending updates. */
function telegram_live_remove_webhook(TelegramBotApiClient $client, ?array $config = null): array
{
    telegram_live_require_admin_ready($config);
    $result = $client->deleteWebhook();
    if (empty($result['ok'])) throw new RuntimeException((string)($result['error_message'] ?? 'Webhook removal failed.'));
    return ['removed' => true, 'pending_updates_dropped' => false];
}

/**
 * Queue exactly one recipient-bound Stage 6 test through the durable ledger.
 * The stable key prevents repeat CLI invocations from producing duplicates.
 */
function telegram_live_enqueue_test(int $userId, ?array $config = null): array
{
    $config = telegram_live_require_pilot_user($userId, $config);
    if (!telegram_live_readiness($config)['test_send_ready']) {
        throw new DomainException('Telegram pilot test delivery is not safely configured.');
    }
    $connection = telegram_eligible_connection($userId, LCS_NOTIFICATION_CATEGORY_CALENDAR_LECTURE, true);
    if (!$connection) throw new DomainException('The pilot Telegram connection is not eligible.');

    $internal = lcs_signed_link_internal_path('dashboard', $userId, 'telegram_smoke', $userId, $config,
        time() + 3600);
    $base = telegram_application_base_url($config);
    if ($internal === null || $base === null) throw new DomainException('A safe LCS action link could not be created.');
    $payload = [
        'text' => "<b>KWASU LCS Telegram Test</b>\n\nYour Telegram notification channel is connected successfully.\n\nFuture academic notifications can be delivered here.",
        'options' => ['parse_mode' => 'HTML', 'disable_web_page_preview' => true],
    ];
    $keyboard = [[['text' => 'Open LCS', 'url' => $base . '/' . ltrim($internal, '/')]]];
    $logicalKey = 'stage6a-owner-connection-test-v1';
    $idempotencyKey = telegram_delivery_idempotency_key('telegram_smoke', $userId, $userId,
        TELEGRAM_LIVE_TEST_NOTIFICATION_TYPE, $logicalKey);

    $insert = db()->prepare("INSERT INTO telegram_delivery_jobs
        (user_id,connection_id,source_type,source_id,notification_type,notification_category,message_payload_json,
         inline_keyboard_json,destination_chat_id,status,next_attempt_at,idempotency_key)
        VALUES (?,?,?,?,?,?,?,?,?,'queued',NOW(),?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    $insert->execute([$userId, (int)$connection['id'], 'telegram_smoke', $userId,
        TELEGRAM_LIVE_TEST_NOTIFICATION_TYPE, LCS_NOTIFICATION_CATEGORY_CALENDAR_LECTURE,
        telegram_payload_json($payload), telegram_payload_json($keyboard),
        (string)$connection['telegram_chat_id'], $idempotencyKey]);
    $created = $insert->rowCount() === 1;
    $jobId = (int)db()->lastInsertId();
    $stmt = db()->prepare('SELECT * FROM telegram_delivery_jobs WHERE id=?');
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();
    if (!$job || (int)$job['user_id'] !== $userId || (string)$job['source_type'] !== 'telegram_smoke'
        || (string)$job['notification_type'] !== TELEGRAM_LIVE_TEST_NOTIFICATION_TYPE) {
        throw new DomainException('Telegram test-delivery idempotency conflict.');
    }
    $job['_created'] = $created;
    return $job;
}

/** Send only the isolated pilot job, never the general queue. */
function telegram_live_send_test(
    int $userId,
    TelegramBotProvider $provider,
    ?array $config = null,
    ?TelegramRateLimiter $rateLimiter = null
): array {
    telegram_worker_only();
    $config = telegram_live_require_pilot_user($userId, $config);
    $job = telegram_live_enqueue_test($userId, $config);
    if ((string)$job['status'] === 'sent') {
        return ['status' => 'existing_sent', 'created' => false, 'job_id' => (int)$job['id']];
    }
    if ((string)$job['status'] !== 'queued') {
        throw new DomainException('The one-time Telegram pilot test is not sendable in its current state.');
    }
    $claimed = telegram_claim_job((int)$job['id']);
    if (!$claimed) throw new RuntimeException('The Telegram pilot test job could not be claimed.');
    $result = telegram_process_claimed_job($claimed, $provider, $config, $rateLimiter,
        static fn(array $candidate): bool => telegram_source_is_valid($candidate, $config));
    return ['status' => $result, 'created' => (bool)$job['_created'], 'job_id' => (int)$job['id']];
}
