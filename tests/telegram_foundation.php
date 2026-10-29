<?php

/** Local-only Stage 2 Telegram foundation QA. No external network requests. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('session.save_path', sys_get_temp_dir());
require_once dirname(__DIR__) . '/includes/telegram/queue.php';
if (DB_HOST !== '127.0.0.1' || DB_NAME !== 'kwasulcs_lcs') throw new RuntimeException('Local database required.');
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$pdo = db(); $checks = 0; $transportCalls = 0;
function tg_check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . "\n"; $checks++;
}
function tg_throws(callable $callback, string $class): bool
{
    try { $callback(); } catch (Throwable $e) { return $e instanceof $class; }
    return false;
}
function tg_job(int $id): array
{
    $stmt = db()->prepare('SELECT * FROM telegram_delivery_jobs WHERE id=?'); $stmt->execute([$id]);
    return $stmt->fetch() ?: [];
}

$migration = file_get_contents(dirname(__DIR__) . '/telegram_notifications_migration.sql');
tg_check(is_string($migration) && substr_count($migration, 'CREATE TABLE IF NOT EXISTS telegram_') === 5,
    'migration defines all five additive Telegram tables');
tg_check(!preg_match("/status ENUM\\([^)]*'delivered'|status ENUM\\([^)]*'read'/i", $migration),
    'Telegram delivery schema has no delivered or read state');
tg_check(str_contains($migration, 'telegram_user_id BIGINT NOT NULL')
    && str_contains($migration, 'telegram_chat_id BIGINT NOT NULL'), 'Telegram identifiers use signed BIGINT columns');
tg_check(str_contains($migration, "chat_type ENUM('private')"), 'connection schema permits private Telegram chats only');
tg_check(str_contains($migration, 'token_hash CHAR(64) NOT NULL') && !str_contains($migration, 'plain_token'),
    'link-token schema stores only a one-way token hash');
tg_check(!str_contains($migration, 'raw_payload'), 'webhook-update schema has no arbitrary raw message payload');

foreach (['telegram_connections','telegram_link_tokens','telegram_notification_preferences','telegram_delivery_jobs','telegram_webhook_updates'] as $table) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name=?');
    $stmt->execute([DB_NAME, $table]);
    tg_check((int)$stmt->fetchColumn() === 1, $table . ' migration exists locally');
}
$statusColumn = $pdo->query("SHOW COLUMNS FROM telegram_delivery_jobs LIKE 'status'")->fetch();
tg_check($statusColumn && !str_contains((string)$statusColumn['Type'], 'delivered') && !str_contains((string)$statusColumn['Type'], 'read'),
    'live local delivery status enum has only Telegram-supported lifecycle states');

$defaultConfig = telegram_config(['enabled' => false, 'bot_token' => '']);
$disabledStatus = telegram_configuration_status($defaultConfig);
tg_check(!$defaultConfig['enabled'] && !$disabledStatus['send_ready'], 'Telegram configuration defaults safely disabled');
tg_check($defaultConfig['max_messages_per_second'] === 25 && $defaultConfig['max_attempts'] === 5
    && $defaultConfig['link_token_ttl_seconds'] === 600, 'Telegram pacing, retry and link-token defaults are bounded');
$secretConfig = ['bot_token' => '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ_abc', 'webhook_secret' => str_repeat('s', 32)];
$redacted = telegram_redact_secrets('https://api.telegram.org/bot123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ_abc/sendMessage secret=' . str_repeat('s', 32), $secretConfig);
tg_check(!str_contains($redacted, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ_abc') && !str_contains($redacted, str_repeat('s', 32)),
    'Bot token and webhook secret are redacted from diagnostics');
tg_check(telegram_normalize_integer_id('9223372036854775807') === '9223372036854775807'
    && telegram_normalize_integer_id('-9223372036854775808') === '-9223372036854775808'
    && telegram_normalize_integer_id('9223372036854775808') === null, 'signed BIGINT normalization preserves boundaries without float conversion');

$validConfig = telegram_config(['enabled' => true, 'bot_token' => '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ_abc']);
$transportCalledWhileDisabled = false;
$disabledClient = new TelegramBotApiClient(['enabled' => false], static function () use (&$transportCalledWhileDisabled): array {
    $transportCalledWhileDisabled = true; return [];
});
$disabledResult = $disabledClient->sendMessage('123', 'No request');
tg_check(!$disabledResult['ok'] && !$transportCalledWhileDisabled, 'disabled configuration fails before transport access');

$captured = [];
$client = new TelegramBotApiClient($validConfig, function (string $url, array $headers, string $body, array $config) use (&$captured, &$transportCalls): array {
    $transportCalls++; $captured = compact('url', 'headers', 'body', 'config');
    return ['http_status' => 200, 'body' => '{"ok":true,"result":{"message_id":9223372036854775807}}'];
});
$sent = $client->sendMessage('-9223372036854775808', 'Foundation fixture', ['allow_paid_broadcast' => true]);
$capturedPayload = json_decode((string)$captured['body'], true, 12, JSON_THROW_ON_ERROR);
tg_check($sent['ok'] && $sent['provider_message_id'] === '9223372036854775807', 'native client parses successful synthetic Bot API response');
tg_check($capturedPayload['chat_id'] === '-9223372036854775808', 'native client preserves signed BIGINT chat ID in JSON');
tg_check(!array_key_exists('allow_paid_broadcast', $capturedPayload), 'paid broadcast flag cannot pass into Telegram requests');
tg_check(str_contains($captured['url'], '/sendMessage') && (int)$captured['config']['connect_timeout'] === 5
    && (int)$captured['config']['request_timeout'] === 15, 'injected transport receives native endpoint and strict timeout configuration');

$synthetic = static fn(int $http, string $body, string $transportError = ''): callable =>
    static fn(): array => ['http_status' => $http, 'body' => $body, 'transport_error' => $transportError];
$rate = (new TelegramBotApiClient($validConfig, $synthetic(429,
    '{"ok":false,"error_code":429,"description":"Too Many Requests","parameters":{"retry_after":13}}')))
    ->sendMessage('42', 'Rate fixture');
tg_check($rate['transient'] && $rate['retry_after'] === 13, 'HTTP 429 extracts Telegram retry_after metadata');
$server = (new TelegramBotApiClient($validConfig, $synthetic(503, '{"ok":false,"error_code":503,"description":"Unavailable"}')))
    ->sendMessage('42', 'Server fixture');
tg_check($server['transient'], 'HTTP 5xx is classified retryable');
$network = (new TelegramBotApiClient($validConfig, static function (): array { throw new RuntimeException('synthetic network failure'); }))
    ->sendMessage('42', 'Network fixture');
tg_check($network['transient'] && $network['error_code'] === 'transport_error', 'network failure is classified retryable');
$blocked = (new TelegramBotApiClient($validConfig, $synthetic(403,
    '{"ok":false,"error_code":403,"description":"Forbidden: bot was blocked by the user"}')))
    ->sendMessage('42', 'Blocked fixture');
tg_check(!$blocked['transient'] && $blocked['connection_inaccessible'] && $blocked['error_code'] === 'bot_blocked',
    'bot-blocked response is permanent and marks destination inaccessible');
$unauthorized = (new TelegramBotApiClient($validConfig, $synthetic(401,
    '{"ok":false,"error_code":401,"description":"Unauthorized"}')))->sendMessage('42', 'Auth fixture');
tg_check(!$unauthorized['transient'] && $unauthorized['error_code'] === 'invalid_bot_configuration',
    'invalid Bot API authentication is a permanent configuration failure');

$clock = 100.0; $slept = 0.0;
$limiter = new TelegramRateLimiter(['max_messages_per_second' => 25], static function () use (&$clock): float { return $clock; },
    static function (float $seconds) use (&$clock, &$slept): void { $slept += $seconds; $clock += $seconds; });
$firstWait = $limiter->acquire(); $secondWait = $limiter->acquire();
tg_check($firstWait === 0.0 && abs($secondWait - 0.04) < 0.000001 && abs($slept - 0.04) < 0.000001,
    '25-per-second limiter applies a monotonic 40ms minimum interval');

$tag = 'tg' . bin2hex(random_bytes(5));
$pdo->beginTransaction();
try {
    $insertUser = $pdo->prepare("INSERT INTO users(full_name,matric_no,email,password,role) VALUES (?,?,?,'not-a-login-hash','student')");
    $userIds = [];
    foreach (['main','blocked','disconnect','duplicate'] as $suffix) {
        $insertUser->execute([$tag . ' ' . $suffix, $tag . '-' . $suffix, $tag . '-' . $suffix . '@example.invalid']);
        $userIds[$suffix] = (int)$pdo->lastInsertId();
    }
    $insertConnection = $pdo->prepare("INSERT INTO telegram_connections
        (user_id,telegram_user_id,telegram_chat_id,chat_type,display_name,username,enabled,consent_status,consented_at,connected_at)
        VALUES (?,?,?,'private',?,?,1,'consented',NOW(),NOW())");
    $insertConnection->execute([$userIds['main'], '9223372036854775807', '-9223372036854775808', 'Main Fixture', 'informational_only']);
    $mainConnectionId = (int)$pdo->lastInsertId();
    $insertConnection->execute([$userIds['blocked'], '700000000000000001', '700000000000000001', 'Blocked Fixture', null]);
    $blockedConnectionId = (int)$pdo->lastInsertId();
    $insertConnection->execute([$userIds['disconnect'], '700000000000000002', '700000000000000002', 'Disconnect Fixture', null]);
    $disconnectConnectionId = (int)$pdo->lastInsertId();

    tg_check((string)telegram_connection_by_user($userIds['main'])['telegram_user_id'] === '9223372036854775807'
        && (string)telegram_connection_by_chat('-9223372036854775808')['telegram_chat_id'] === '-9223372036854775808',
        'connection repository preserves signed BIGINT identifiers');
    tg_check(telegram_connection_by_telegram_user('9223372036854775807')['user_id'] == $userIds['main'],
        'connection lookup uses Telegram numeric identity rather than username');
    tg_check(tg_throws(fn() => $insertConnection->execute([$userIds['duplicate'], '9223372036854775807', '700000000000000003', 'Duplicate', 'different']), PDOException::class),
        'Telegram user identity is unique across LCS accounts');
    tg_check(tg_throws(fn() => $insertConnection->execute([$userIds['duplicate'], '700000000000000003', '-9223372036854775808', 'Duplicate Chat', null]), PDOException::class),
        'Telegram chat identity is unique across LCS accounts');
    tg_check(tg_throws(function () use ($pdo, $userIds): void {
        $pdo->prepare("INSERT INTO telegram_connections(user_id,telegram_user_id,telegram_chat_id,chat_type,display_name)
            VALUES (?,?,?,'group','Invalid Group')")->execute([$userIds['duplicate'], '700000000000000004', '700000000000000004']);
    }, PDOException::class), 'database rejects non-private Telegram connection types');

    foreach ([$mainConnectionId, $blockedConnectionId, $disconnectConnectionId] as $connectionId) telegram_initialize_preferences($connectionId);
    $prefCount = $pdo->query('SELECT COUNT(*) FROM telegram_notification_preferences WHERE connection_id=' . $mainConnectionId)->fetchColumn();
    tg_check((int)$prefCount === 3 && telegram_category_enabled($mainConnectionId, 'calendar_lecture'),
        'new connection can initialize the three approved enabled preference defaults');
    $pdo->prepare("UPDATE telegram_notification_preferences SET enabled=0 WHERE connection_id=? AND category='assignment_test'")
        ->execute([$mainConnectionId]);
    tg_check(!telegram_category_enabled($mainConnectionId, 'assignment_test')
        && telegram_category_enabled($mainConnectionId, 'online_quiz_test'), 'category preference lookup is independent and fail-closed');

    $base = ['user_id' => $userIds['main'], 'source_type' => 'calendar', 'source_id' => 9001,
        'notification_type' => 'calendar_reminder', 'logical_occurrence_key' => '2026-09-22T10:00:00+01:00',
        'message_payload' => ['text' => 'Synthetic calendar reminder'], 'inline_keyboard' => [[['text' => 'Open LCS', 'url' => 'https://example.invalid/go']]]];
    tg_check(tg_throws(fn() => telegram_enqueue_delivery($base, ['enabled' => false]), DomainException::class),
        'disabled Telegram configuration rejects queue creation');
    $first = telegram_enqueue_delivery($base, $validConfig);
    $duplicate = telegram_enqueue_delivery($base, $validConfig);
    tg_check($first['_created'] && !$duplicate['_created'] && (int)$first['id'] === (int)$duplicate['id'],
        'stable Telegram idempotency returns one durable job');
    tg_check((string)$first['destination_chat_id'] === '-9223372036854775808', 'queued job keeps an immutable destination snapshot');
    $claim = telegram_claim_job((int)$first['id']);
    tg_check($claim && $claim['status'] === 'processing' && (int)$claim['attempt_count'] === 1, 'due Telegram job is atomically claimed');
    tg_check(telegram_claim_job((int)$first['id']) === null, 'active Telegram lease prevents a second claim');
    tg_check(telegram_process_claimed_job($claim, new TelegramMockProvider(['success']), $validConfig) === 'sent'
        && tg_job((int)$first['id'])['status'] === 'sent', 'mock provider acceptance records sent state');

    $makeJob = static function (array $base, string $key, int $sourceId, int $userId) use ($validConfig): array {
        $input = $base; $input['user_id'] = $userId; $input['source_id'] = $sourceId;
        $input['logical_occurrence_key'] = $key; return telegram_enqueue_delivery($input, $validConfig);
    };
    $rateJob = $makeJob($base, 'rate', 9002, $userIds['main']);
    $rateClaim = telegram_claim_job((int)$rateJob['id']);
    tg_check(telegram_process_claimed_job($rateClaim, new TelegramMockProvider(['rate_limit']), $validConfig) === 'retry',
        'mock HTTP 429 schedules retry');
    $rateQueued = tg_job((int)$rateJob['id']);
    $rateDelay = strtotime($rateQueued['next_attempt_at']) - time();
    tg_check($rateQueued['status'] === 'queued' && $rateDelay >= 5 && $rateDelay <= 9,
        'queue honors provider retry_after instead of generic backoff');

    $leaseJob = $makeJob($base, 'expired-lease', 9003, $userIds['main']);
    $abandonedClaim = telegram_claim_job((int)$leaseJob['id']);
    $pdo->prepare('UPDATE telegram_delivery_jobs SET lease_expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=?')
        ->execute([$leaseJob['id']]);
    $recoveredClaim = telegram_claim_job((int)$leaseJob['id']);
    tg_check($recoveredClaim && (int)$recoveredClaim['attempt_count'] === 2
        && $recoveredClaim['claim_token'] !== $abandonedClaim['claim_token'], 'expired Telegram lease is safely reclaimed with a new token');
    telegram_process_claimed_job($recoveredClaim, new TelegramMockProvider(['success']), $validConfig);

    foreach ([['transient','5xx'], ['network','network']] as [$outcome, $label]) {
        $job = $makeJob($base, $label, random_int(9100, 9199), $userIds['main']);
        $claimed = telegram_claim_job((int)$job['id']);
        tg_check(telegram_process_claimed_job($claimed, new TelegramMockProvider([$outcome]), $validConfig) === 'retry'
            && tg_job((int)$job['id'])['status'] === 'queued', $label . ' provider failure returns job to retryable queued state');
    }
    $exhausted = $makeJob($base, 'exhausted', 9201, $userIds['main']);
    $exhaustedClaim = telegram_claim_job((int)$exhausted['id']);
    tg_check(telegram_process_claimed_job($exhaustedClaim, new TelegramMockProvider(['transient']),
        array_replace($validConfig, ['max_attempts' => 1])) === 'failed'
        && tg_job((int)$exhausted['id'])['last_error_code'] === 'retry_exhausted', 'retry exhaustion becomes terminal failed');
    $permanent = $makeJob($base, 'permanent', 9202, $userIds['main']);
    tg_check(telegram_process_claimed_job(telegram_claim_job((int)$permanent['id']), new TelegramMockProvider(['permanent']), $validConfig) === 'failed',
        'permanent provider rejection becomes terminal failed');

    $blockedBase = $base; $blockedBase['user_id'] = $userIds['blocked'];
    $blockedJob = $makeJob($blockedBase, 'blocked-current', 9301, $userIds['blocked']);
    $blockedPending = $makeJob($blockedBase, 'blocked-pending', 9302, $userIds['blocked']);
    $blockedClaim = telegram_claim_job((int)$blockedJob['id']);
    tg_check(telegram_process_claimed_job($blockedClaim, new TelegramMockProvider(['blocked']), $validConfig) === 'failed',
        'bot-blocked active job becomes terminal failed');
    tg_check(!empty(telegram_connection_by_user($userIds['blocked'])['blocked_at'])
        && tg_job((int)$blockedPending['id'])['status'] === 'cancelled', 'bot-blocked response disables connection and cancels pending jobs');

    $disconnectBase = $base; $disconnectBase['user_id'] = $userIds['disconnect'];
    $disconnectJob = $makeJob($disconnectBase, 'disconnect', 9401, $userIds['disconnect']);
    tg_check(telegram_disable_connection($disconnectConnectionId, true) === 1
        && tg_job((int)$disconnectJob['id'])['status'] === 'cancelled', 'disconnect cancels pending Telegram jobs');
    $disconnected = telegram_connection_by_user($userIds['disconnect']);
    tg_check($disconnected['consent_status'] === 'revoked' && !empty($disconnected['disconnected_at']),
        'disconnect preserves historical row while revoking eligibility');

    $cancel = $makeJob($base, 'cancel-source', 9501, $userIds['main']);
    tg_check(telegram_cancel_pending_jobs_by_source('calendar', 9501, 'source_invalid', 'Synthetic source cancelled.') === 1
        && tg_job((int)$cancel['id'])['status'] === 'cancelled', 'source cancellation terminates pending Telegram delivery');

    tg_check($transportCalls === 1, 'all Bot API tests used injected or mock transports only');
    $source = file_get_contents(dirname(__DIR__) . '/includes/telegram/bot_api.php');
    tg_check(is_string($source) && !str_contains($source, "'allow_paid_broadcast' => true"), 'foundation contains no paid-broadcast enablement');
    echo "Completed $checks Telegram foundation checks. Fixture records rolled back; no Telegram request sent.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
