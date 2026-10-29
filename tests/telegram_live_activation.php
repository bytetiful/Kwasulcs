<?php

/** Local-only Stage 6A/6B activation and rollback QA. Every Bot API call uses an injected transport/mock. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('session.save_path', sys_get_temp_dir());
require_once dirname(__DIR__) . '/includes/telegram/live.php';
require_once dirname(__DIR__) . '/includes/telegram/webhook.php';
if (DB_HOST !== '127.0.0.1' || DB_NAME !== 'kwasulcs_lcs') throw new RuntimeException('Local database required.');

$pdo = db();
$checks = 0;
function tg6_check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . "\n";
    $checks++;
}
function tg6_throws(callable $callback, string $class): bool
{
    try { $callback(); } catch (Throwable $e) { return $e instanceof $class; }
    return false;
}

$tag = 'tg6' . bin2hex(random_bytes(5));
$pdo->beginTransaction();
try {
    $insert = $pdo->prepare("INSERT INTO users(full_name,matric_no,email,password,role) VALUES (?,?,?,'not-a-login-hash','student')");
    $insert->execute([$tag . ' Owner', $tag . '-owner', $tag . '-owner@example.invalid']);
    $ownerId = (int)$pdo->lastInsertId();
    $insert->execute([$tag . ' Other', $tag . '-other', $tag . '-other@example.invalid']);
    $otherId = (int)$pdo->lastInsertId();

    $config = telegram_config([
        'enabled' => false,
        'pilot_user_ids' => [$ownerId],
        'bot_token' => '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ_abc',
        'bot_username' => TELEGRAM_LIVE_EXPECTED_BOT_USERNAME,
        'webhook_secret' => str_repeat('S', 40),
        'webhook_url' => TELEGRAM_LIVE_EXPECTED_WEBHOOK_URL,
        'base_url' => 'https://kwasulcs.alwaysdata.net',
        'link_signing_key' => str_repeat('K', 40),
    ]);
    $ready = telegram_live_readiness($config);
    tg6_check(!$ready['telegram_enabled'] && $ready['pilot_mode_active'] && $ready['pilot_user_count'] === 1,
        'global delivery stays off while exactly one LCS pilot is active');
    tg6_check($ready['send_configuration_ready'] && $ready['webhook_configuration_ready']
        && $ready['live_admin_ready'] && $ready['test_send_ready'],
        'send, webhook, administration and signed-link readiness are independently reported');
    tg6_check(telegram_delivery_allowed_for_user($ownerId, $config)
        && !telegram_delivery_allowed_for_user($otherId, $config), 'pilot allowlist is based on LCS user ID and fails closed for others');
    tg6_check(tg6_throws(fn() => telegram_create_link_token($otherId, $config), DomainException::class),
        'non-pilot authenticated user cannot initiate linking while global delivery is off');
    $link = telegram_create_link_token($ownerId, $config);
    tg6_check(telegram_link_token_state($link['token']) === 'valid', 'sole pilot can create one short-lived hashed link token');

    $connection = $pdo->prepare("INSERT INTO telegram_connections
        (user_id,telegram_user_id,telegram_chat_id,chat_type,display_name,username,enabled,consent_status,consented_at,connected_at)
        VALUES (?,?,?,'private',?,?,1,'consented',NOW(),NOW())");
    $connection->execute([$ownerId, '700000000000006001', '700000000000006001', 'Stage 6 Owner', 'owner_fixture']);
    $connectionId = (int)$pdo->lastInsertId();
    telegram_initialize_preferences($connectionId);

    $calls = [];
    $transport = static function (string $url, array $headers, string $body) use (&$calls): array {
        $calls[] = ['method' => substr($url, strrpos($url, '/') + 1), 'body' => json_decode($body, true)];
        if (str_ends_with($url, '/getMe')) {
            return ['http_status' => 200, 'body' => '{"ok":true,"result":{"id":123456,"is_bot":true,"username":"TrustyGee_Bot"}}'];
        }
        if (str_ends_with($url, '/setWebhook')) {
            return ['http_status' => 200, 'body' => '{"ok":true,"result":true,"description":"Webhook was set"}'];
        }
        if (str_ends_with($url, '/getWebhookInfo')) {
            return ['http_status' => 200, 'body' => json_encode(['ok' => true, 'result' => [
                'url' => TELEGRAM_LIVE_EXPECTED_WEBHOOK_URL, 'pending_update_count' => 2,
                'last_error_date' => 1790000000, 'last_error_message' => 'Synthetic ' . str_repeat('S', 40),
                'max_connections' => 40,
            ]], JSON_THROW_ON_ERROR)];
        }
        if (str_ends_with($url, '/deleteWebhook')) {
            return ['http_status' => 200, 'body' => '{"ok":true,"result":true,"description":"Webhook was deleted"}'];
        }
        throw new RuntimeException('Unexpected synthetic method.');
    };
    $client = new TelegramBotApiClient($config, $transport);
    $identity = telegram_live_verify_identity($client, $config);
    tg6_check($identity['verified'] && $identity['username'] === TELEGRAM_LIVE_EXPECTED_BOT_USERNAME
        && $calls[0]['method'] === 'getMe', 'controlled getMe verifies the exact configured bot identity without sending');
    tg6_check(telegram_live_register_webhook($client, $config)['registered'], 'explicit webhook registration helper succeeds with injected transport');
    $setPayload = $calls[1]['body'];
    tg6_check($setPayload['url'] === TELEGRAM_LIVE_EXPECTED_WEBHOOK_URL
        && $setPayload['secret_token'] === $config['webhook_secret']
        && !str_contains($setPayload['url'], $config['webhook_secret'])
        && !array_key_exists('allow_paid_broadcast', $setPayload),
        'setWebhook uses secret_token body field, exact HTTPS URL, and no paid broadcast option');
    $info = telegram_live_webhook_info($client, $config);
    tg6_check($info['url'] === TELEGRAM_LIVE_EXPECTED_WEBHOOK_URL && $info['pending_update_count'] === 2
        && $info['max_connections'] === 40 && !str_contains((string)$info['last_error_message'], $config['webhook_secret']),
        'getWebhookInfo returns only safe operational fields and redacts webhook secrets');
    $removed = telegram_live_remove_webhook($client, $config);
    tg6_check($removed['removed'] && !$removed['pending_updates_dropped'],
        'rollback helper removes the webhook without discarding pending updates');
    tg6_check($calls[3]['method'] === 'deleteWebhook'
        && ($calls[3]['body']['drop_pending_updates'] ?? null) === false,
        'webhook rollback is explicit, bounded and does not use a token-bearing shell command');

    $mismatch = new TelegramBotApiClient($config, static fn(): array => ['http_status' => 200,
        'body' => '{"ok":true,"result":{"id":123456,"is_bot":true,"username":"Wrong_Bot"}}']);
    tg6_check(tg6_throws(fn() => telegram_live_verify_identity($mismatch, $config), DomainException::class),
        'bot username mismatch is a terminal activation failure');
    $invalid = new TelegramBotApiClient($config, static fn(): array => ['http_status' => 401,
        'body' => '{"ok":false,"error_code":401,"description":"Unauthorized"}']);
    tg6_check(tg6_throws(fn() => telegram_live_verify_identity($invalid, $config), RuntimeException::class),
        'invalid bot token cannot pass the controlled identity check');

    $first = telegram_live_enqueue_test($ownerId, $config);
    $second = telegram_live_enqueue_test($ownerId, $config);
    tg6_check($first['_created'] && !$second['_created'] && (int)$first['id'] === (int)$second['id'],
        'isolated pilot message is durably idempotent');
    tg6_check((string)$first['destination_chat_id'] === '700000000000006001'
        && (int)$first['user_id'] === $ownerId, 'test destination comes only from the owner connection, never a CLI chat ID');
    $payload = json_decode((string)$first['message_payload_json'], true, 8, JSON_THROW_ON_ERROR);
    $keyboard = json_decode((string)$first['inline_keyboard_json'], true, 8, JSON_THROW_ON_ERROR);
    tg6_check(($payload['options']['parse_mode'] ?? null) === 'HTML'
        && str_contains((string)$payload['text'], 'KWASU LCS Telegram Test')
        && ($keyboard[0][0]['text'] ?? null) === 'Open LCS', 'pilot message has bounded HTML copy and one Open LCS action');
    parse_str((string)parse_url((string)$keyboard[0][0]['url'], PHP_URL_QUERY), $query);
    $target = lcs_signed_link_resolve_reference((string)($query['ref'] ?? ''), $config);
    tg6_check(($target['kind'] ?? null) === 'dashboard' && (int)($target['uid'] ?? 0) === $ownerId,
        'Open LCS uses a recipient-bound signed dashboard target with no open redirect');

    $mock = new TelegramMockProvider(['success']);
    $sent = telegram_live_send_test($ownerId, $mock, $config, new TelegramRateLimiter($config));
    $row = $pdo->query('SELECT * FROM telegram_delivery_jobs WHERE id=' . (int)$first['id'])->fetch();
    tg6_check($sent['status'] === 'sent' && count($mock->requests) === 1
        && $row['status'] === 'sent' && telegram_normalize_integer_id($row['provider_message_id']) !== null,
        'owner-only test traverses claim, provider abstraction, sent state and provider message ID storage');
    $againMock = new TelegramMockProvider(['success']);
    $again = telegram_live_send_test($ownerId, $againMock, $config);
    tg6_check($again['status'] === 'existing_sent' && count($againMock->requests) === 0,
        'repeat test-send invocation cannot duplicate an accepted message');
    tg6_check(tg6_throws(fn() => telegram_live_enqueue_test($otherId, $config), DomainException::class),
        'test-send path rejects every non-pilot LCS user');

    $headers = ['X-Telegram-Bot-Api-Secret-Token' => $config['webhook_secret']];
    tg6_check(telegram_webhook_handle_request('POST', ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'], '{}',
        new TelegramMockProvider(), $config)['status'] === 403, 'wrong webhook secret is rejected before processing');
    $oldNonPilotToken = telegram_create_link_token($otherId, array_replace($config, ['enabled' => true]));
    $rejectedUpdateId = (int)(microtime(true) * 1000000) - 1;
    $rejectedPayload = json_encode(['update_id' => $rejectedUpdateId, 'message' => [
        'from' => ['id' => '700000000000006002', 'first_name' => 'Other'],
        'chat' => ['id' => '700000000000006002', 'type' => 'private'],
        'text' => '/start ' . $oldNonPilotToken['token'],
    ]], JSON_THROW_ON_ERROR);
    $rejected = telegram_webhook_handle_request('POST', $headers, $rejectedPayload,
        new TelegramMockProvider(['success']), $config);
    tg6_check(($rejected['body']['result'] ?? null) === 'link_rejected'
        && telegram_connection_by_user($otherId) === null
        && telegram_link_token_state($oldNonPilotToken['token']) === 'valid',
        'webhook rechecks the pilot allowlist and cannot consume a stale non-pilot link token');
    $settingsMock = new TelegramMockProvider(['success']);
    $updateId = (int)(microtime(true) * 1000000);
    $settingsPayload = json_encode(['update_id' => $updateId, 'message' => [
        'from' => ['id' => '700000000000006001', 'first_name' => 'Owner'],
        'chat' => ['id' => '700000000000006001', 'type' => 'private'], 'text' => '/settings',
    ]], JSON_THROW_ON_ERROR);
    $settingsResult = telegram_webhook_handle_request('POST', $headers, $settingsPayload, $settingsMock, $config);
    tg6_check($settingsResult['status'] === 200 && ($settingsResult['body']['result'] ?? null) === 'settings'
        && count($settingsMock->requests) === 1
        && str_contains((string)$settingsMock->requests[0]['text'], 'https://kwasulcs.alwaysdata.net/settings'),
        '/settings safely returns the canonical HTTPS LCS Settings link in a private chat');

    $adminSource = file_get_contents(dirname(__DIR__) . '/telegram_admin.php');
    tg6_check(is_string($adminSource) && str_contains($adminSource, "PHP_SAPI !== 'cli'")
        && str_contains($adminSource, '--confirm-live') && !str_contains($adminSource, 'allow_paid_broadcast'),
        'administrator entry point is CLI-only, explicit-confirmation gated, and has no paid broadcast path');
    $readinessJson = json_encode($ready, JSON_THROW_ON_ERROR);
    tg6_check(!str_contains($readinessJson, $config['bot_token'])
        && !str_contains($readinessJson, $config['webhook_secret']), 'readiness output contains no bot token or webhook secret');

    $pdo->rollBack();
    echo "Completed $checks Telegram Stage 6A/6B activation checks. Fixture transaction rolled back; no real Telegram request sent.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
