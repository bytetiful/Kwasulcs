<?php

/** Local-only Stage 3 Telegram linking/webhook QA. No external requests. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('session.save_path', sys_get_temp_dir());
require_once dirname(__DIR__) . '/includes/telegram/webhook.php';
if (DB_HOST !== '127.0.0.1' || DB_NAME !== 'kwasulcs_lcs') throw new RuntimeException('Local database required.');

$pdo = db();
$checks = 0;
function tg3_check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . "\n";
    $checks++;
}
function tg3_user(string $tag, string $suffix): int
{
    $stmt = db()->prepare("INSERT INTO users(full_name,matric_no,email,password,role) VALUES (?,?,?,'not-a-login-hash','student')");
    $stmt->execute(["$tag $suffix", "$tag-$suffix", "$tag-$suffix@example.invalid"]);
    return (int)db()->lastInsertId();
}
function tg3_webhook_payload(int|string $updateId, int|string $userId, int|string $chatId, string $text, string $type = 'private'): string
{
    return json_encode(['update_id' => $updateId, 'message' => [
        'message_id' => 1,
        'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'Ada', 'last_name' => 'Student',
            'username' => 'display_only'],
        'chat' => ['id' => $chatId, 'type' => $type],
        'text' => $text,
    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}
function tg3_row(string $table, string $where, array $params): ?array
{
    $stmt = db()->prepare("SELECT * FROM $table WHERE $where");
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

$config = telegram_config([
    'enabled' => true,
    'bot_token' => '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ_abc',
    'bot_username' => 'KwasuLcsBot',
    'webhook_secret' => str_repeat('w', 40),
    'link_token_ttl_seconds' => 600,
]);
$headers = ['X-Telegram-Bot-Api-Secret-Token' => $config['webhook_secret']];
$tag = 'tg3' . bin2hex(random_bytes(5));
$pdo->beginTransaction();
try {
    $users = [];
    foreach (['primary','other','rollback','webhook','rebind'] as $suffix) $users[$suffix] = tg3_user($tag, $suffix);

    $first = telegram_create_link_token($users['primary'], $config);
    $decoded = base64_decode(strtr($first['token'], '-_', '+/') . '=', true);
    tg3_check(is_string($decoded) && strlen($decoded) === TELEGRAM_LINK_TOKEN_BYTES,
        'link token contains at least 32 bytes of cryptographic randomness');
    $stored = tg3_row('telegram_link_tokens', 'id=?', [$first['id']]);
    tg3_check($stored && $stored['token_hash'] === telegram_link_token_hash($first['token'])
        && $stored['token_hash'] !== $first['token'], 'only the SHA-256 token representation is persisted');
    tg3_check(telegram_link_token_state($first['token']) === 'valid', 'fresh link token validates');

    $second = telegram_create_link_token($users['primary'], $config);
    tg3_check(telegram_link_token_state($first['token']) === 'invalid'
        && telegram_link_token_state($second['token']) === 'valid', 'creating a token invalidates prior unused tokens for that user');
    $pdo->prepare('UPDATE telegram_link_tokens SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=?')->execute([$second['id']]);
    tg3_check(telegram_link_token_state($second['token']) === 'invalid', 'expired token is rejected');
    tg3_check(telegram_link_token_state('not-a-valid-token') === 'invalid', 'malformed token is rejected neutrally');

    $link = telegram_create_link_token($users['primary'], $config);
    $linked = telegram_consume_link_token($link['token'], '700000000000000101', '700000000000000101',
        'private', 'Primary Student', 'identity_is_not_authority');
    tg3_check($linked['ok'] && $linked['state'] === 'connected', '/start token binding connects the intended LCS user');
    $connection = telegram_connection_by_user($users['primary']);
    tg3_check($connection && (string)$connection['telegram_user_id'] === '700000000000000101'
        && $connection['consent_status'] === 'consented' && (int)$connection['enabled'] === 1,
        'successful binding records consented private numeric identity');
    tg3_check((int)$pdo->query('SELECT COUNT(*) FROM telegram_notification_preferences WHERE connection_id=' . (int)$connection['id'])->fetchColumn() === 3,
        'successful binding initializes approved notification preferences');
    tg3_check(telegram_link_token_state($link['token']) === 'invalid'
        && !telegram_consume_link_token($link['token'], '700000000000000101', '700000000000000101', 'private')['ok'],
        'consumed token cannot be replayed');

    $groupToken = telegram_create_link_token($users['other'], $config);
    tg3_check(!telegram_consume_link_token($groupToken['token'], '700000000000000102', '-100700000000000102', 'group')['ok']
        && telegram_link_token_state($groupToken['token']) === 'valid', 'non-private chat is rejected without consuming token');
    $hijack = telegram_consume_link_token($groupToken['token'], '700000000000000101', '700000000000000101', 'private');
    tg3_check(!$hijack['ok'] && $hijack['state'] === 'identity_conflict'
        && telegram_link_token_state($groupToken['token']) === 'valid', 'Telegram identity already bound to one user cannot bind another account');

    $activeRebind = telegram_create_link_token($users['primary'], $config);
    $wrongIdentity = telegram_consume_link_token($activeRebind['token'], '700000000000000103', '700000000000000103', 'private');
    tg3_check(!$wrongIdentity['ok'] && $wrongIdentity['state'] === 'identity_conflict',
        'different Telegram identity cannot silently take over an active connection');

    telegram_mark_connection_inaccessible((int)$connection['id'], 'Synthetic block state.');
    $sameReconnect = telegram_create_link_token($users['primary'], $config);
    $reconnected = telegram_consume_link_token($sameReconnect['token'], '700000000000000101', '700000000000000101',
        'private', 'Primary Reconnected');
    $connection = telegram_connection_by_user($users['primary']);
    tg3_check($reconnected['ok'] && $reconnected['state'] === 'reconnected' && empty($connection['blocked_at'])
        && empty($connection['disconnected_at']) && (int)$connection['enabled'] === 1,
        'fresh same-identity reconnect clears blocked and disconnected state safely');

    telegram_disconnect_user($users['primary'], 'Synthetic explicit disconnect.');
    $disconnected = telegram_connection_by_user($users['primary']);
    tg3_check($disconnected['consent_status'] === 'revoked' && !empty($disconnected['disconnected_at'])
        && !(int)$disconnected['enabled'], 'disconnect service revokes consent while preserving connection history');
    $newIdentityToken = telegram_create_link_token($users['primary'], $config);
    $newIdentity = telegram_consume_link_token($newIdentityToken['token'], '700000000000000104', '700000000000000104',
        'private', 'Explicitly Rebound');
    tg3_check($newIdentity['ok'] && (string)telegram_connection_by_user($users['primary'])['telegram_user_id'] === '700000000000000104',
        'explicitly disconnected user may safely bind a new unclaimed Telegram identity');

    $rollbackToken = telegram_create_link_token($users['rollback'], $config);
    $rolledBack = false;
    try {
        telegram_consume_link_token($rollbackToken['token'], '700000000000000105', '700000000000000105', 'private',
            'Rollback Fixture', null, static function (): void { throw new RuntimeException('synthetic rollback'); });
    } catch (RuntimeException $e) { $rolledBack = $e->getMessage() === 'synthetic rollback'; }
    tg3_check($rolledBack && telegram_link_token_state($rollbackToken['token']) === 'valid'
        && telegram_connection_by_user($users['rollback']) === null,
        'transaction rollback leaves token unconsumed and connection absent');

    $csrf = telegram_link_csrf_token();
    tg3_check(telegram_connect_handle_request('GET', ['id' => $users['primary']], $csrf, $config)['status'] === 405,
        'connect initiation is POST only');
    tg3_check(telegram_connect_handle_request('POST', null, $csrf, $config)['status'] === 401,
        'connect initiation requires authentication');
    tg3_check(telegram_connect_handle_request('POST', ['id' => $users['primary']], 'wrong', $config)['status'] === 403,
        'connect initiation rejects invalid CSRF token');
    $connectResponse = telegram_connect_handle_request('POST', ['id' => $users['primary']], $csrf, $config);
    tg3_check($connectResponse['status'] === 200
        && preg_match('#^https://t\.me/KwasuLcsBot\?start=[A-Za-z0-9_-]{43}$#', $connectResponse['body']['url']) === 1,
        'authenticated CSRF-protected connect initiation returns only a bot-username deep link');
    tg3_check(!str_contains($connectResponse['body']['url'], (string)$config['bot_token']), 'connect deep link never exposes Bot API token');

    $before = (int)$pdo->query('SELECT COUNT(*) FROM telegram_webhook_updates')->fetchColumn();
    $mock = new TelegramMockProvider();
    tg3_check(telegram_webhook_handle_request('GET', $headers, '', $mock, $config)['status'] === 405, 'webhook rejects GET');
    tg3_check(telegram_webhook_handle_request('POST', [], '{}', $mock, $config)['status'] === 403, 'webhook rejects missing secret');
    tg3_check(telegram_webhook_handle_request('POST', ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'], '{}', $mock, $config)['status'] === 403,
        'webhook rejects wrong secret');
    tg3_check((int)$pdo->query('SELECT COUNT(*) FROM telegram_webhook_updates')->fetchColumn() === $before,
        'secret rejection happens before database side effects');
    tg3_check(telegram_webhook_handle_request('POST', $headers, '{broken', $mock, $config)['status'] === 400,
        'webhook handles malformed JSON safely');
    tg3_check(telegram_webhook_handle_request('POST', $headers, str_repeat('x', TELEGRAM_WEBHOOK_MAX_BYTES + 1), $mock, $config)['status'] === 413,
        'webhook rejects oversized request body');
    tg3_check(telegram_webhook_handle_request('POST', $headers, '{}', $mock, $config)['status'] === 400,
        'webhook requires numeric update_id');

    $baseUpdate = random_int(1000000000, 1500000000);
    $startMock = new TelegramMockProvider(['success']);
    $ordinary = telegram_webhook_handle_request('POST', $headers,
        tg3_webhook_payload($baseUpdate, '700000000000000201', '700000000000000201', '/start'), $startMock, $config);
    tg3_check($ordinary['status'] === 200 && $ordinary['body']['result'] === 'start_without_token'
        && str_contains($startMock->requests[0]['text'], 'KWASU LCS Settings'), 'ordinary /start gives safe LCS-origin instructions without identity guessing');

    $helpMock = new TelegramMockProvider(['success']);
    $help = telegram_webhook_handle_request('POST', $headers,
        tg3_webhook_payload($baseUpdate + 1, '700000000000000202', '700000000000000202', '/help'), $helpMock, $config);
    tg3_check($help['status'] === 200 && $help['body']['result'] === 'help'
        && str_contains($helpMock->requests[0]['text'], 'does not provide AI')
        && str_contains($helpMock->requests[0]['text'], 'disconnect'), '/help is concise and explicitly has no AI/chat capability');

    $webhookToken = telegram_create_link_token($users['webhook'], $config);
    $linkMock = new TelegramMockProvider(['success']);
    $linkPayload = tg3_webhook_payload($baseUpdate + 2, '700000000000000203', '700000000000000203', '/start ' . $webhookToken['token']);
    $webhookLinked = telegram_webhook_handle_request('POST', $headers, $linkPayload, $linkMock, $config);
    tg3_check($webhookLinked['status'] === 200 && $webhookLinked['body']['result'] === 'connected'
        && telegram_connection_by_user($users['webhook']) !== null, 'verified private /start token webhook links account');
    $duplicate = telegram_webhook_handle_request('POST', $headers, $linkPayload, $linkMock, $config);
    tg3_check($duplicate['status'] === 200 && $duplicate['body']['duplicate'] === true
        && count($linkMock->requests) === 1, 'duplicate update_id returns neutral success without repeated side effects or response');
    $webhookRow = tg3_row('telegram_webhook_updates', 'update_id=?', [$baseUpdate + 2]);
    tg3_check($webhookRow && $webhookRow['command'] === 'start'
        && $webhookRow['token_hash'] === telegram_link_token_hash($webhookToken['token'])
        && (int)$pdo->query('SELECT COUNT(*) FROM telegram_webhook_updates WHERE update_id=' . ($baseUpdate + 2))->fetchColumn() === 1,
        'webhook stores one durable sanitized update row with token hash only');

    $privateToken = telegram_create_link_token($users['rebind'], $config);
    $groupMock = new TelegramMockProvider(['success']);
    $group = telegram_webhook_handle_request('POST', $headers,
        tg3_webhook_payload($baseUpdate + 3, '700000000000000204', '-100700000000000204', '/start ' . $privateToken['token'], 'group'),
        $groupMock, $config);
    tg3_check($group['status'] === 200 && $group['body']['result'] === 'ignored'
        && telegram_link_token_state($privateToken['token']) === 'valid' && telegram_connection_by_user($users['rebind']) === null,
        'group /start is ignored and cannot consume a link token');

    $arbitrary = 'private body that must never be stored';
    $unsupportedMock = new TelegramMockProvider();
    telegram_webhook_handle_request('POST', $headers,
        tg3_webhook_payload($baseUpdate + 4, '700000000000000205', '700000000000000205', $arbitrary), $unsupportedMock, $config);
    $schema = file_get_contents(dirname(__DIR__) . '/telegram_notifications_migration.sql');
    $webhookSource = file_get_contents(dirname(__DIR__) . '/includes/telegram/webhook.php');
    tg3_check(is_string($schema) && !str_contains($schema, 'raw_payload')
        && is_string($webhookSource) && !str_contains($webhookSource, 'INSERT INTO telegram_webhook_updates\n        (raw'),
        'durable webhook storage has no arbitrary message-body column');
    tg3_check(count($mock->requests) === 0 && count($unsupportedMock->requests) === 0,
        'invalid and unsupported webhook requests cause no Bot API calls');
    tg3_check($startMock instanceof TelegramMockProvider && $helpMock instanceof TelegramMockProvider
        && $linkMock instanceof TelegramMockProvider && $groupMock instanceof TelegramMockProvider,
        'all outbound Stage 3 responses used the mock provider; no real Telegram request occurred');

    $endpointSource = file_get_contents(dirname(__DIR__) . '/telegram_connect.php');
    tg3_check(is_string($endpointSource) && !str_contains($endpointSource, "\$_POST['user_id']")
        && str_contains($endpointSource, 'current_user()'), 'connect endpoint derives identity only from authenticated session');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

// Use two independent PHP processes/connections against one committed token
// to exercise the real SELECT ... FOR UPDATE replay race.
$concurrentUserId = 0;
try {
    $concurrentUserId = tg3_user($tag, 'concurrent');
    $concurrentToken = telegram_create_link_token($concurrentUserId, $config);
    $payload = json_encode(['token' => $concurrentToken['token'],
        'telegram_user_id' => '700000000000000301', 'telegram_chat_id' => '700000000000000301'], JSON_THROW_ON_ERROR);
    $worker = __DIR__ . '/fixtures/telegram_link_concurrency_worker.php';
    $processes = [];
    foreach ([1, 2] as $_) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, $worker], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            dirname(__DIR__));
        if (!is_resource($process)) throw new RuntimeException('Unable to start concurrency worker.');
        $processes[] = [$process, $pipes];
    }
    foreach ($processes as [, $pipes]) { fwrite($pipes[0], $payload); fclose($pipes[0]); }
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) throw new RuntimeException('Concurrency worker failed: ' . trim($stderr));
        $results[] = json_decode((string)$stdout, true, 8, JSON_THROW_ON_ERROR);
    }
    $successes = count(array_filter($results, static fn(array $result): bool => !empty($result['ok'])));
    $connectionCount = (int)$pdo->query('SELECT COUNT(*) FROM telegram_connections WHERE user_id=' . $concurrentUserId)->fetchColumn();
    tg3_check($successes === 1 && $connectionCount === 1 && telegram_link_token_state($concurrentToken['token']) === 'invalid',
        'concurrent /start consumers produce one connection and one successful token consumption');
} finally {
    if ($concurrentUserId > 0) $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$concurrentUserId]);
}

echo "Completed $checks Telegram Stage 3 checks. Fixture records removed; no Telegram request sent.\n";
