<?php

/** Local-only Stage 4 Telegram Settings security and lifecycle QA. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('session.save_path', sys_get_temp_dir());
require_once dirname(__DIR__) . '/includes/telegram/settings.php';
if (DB_HOST !== '127.0.0.1' || DB_NAME !== 'kwasulcs_lcs') throw new RuntimeException('Local database required.');

$pdo = db();
$checks = 0;
function tg4_check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . "\n";
    $checks++;
}
function tg4_user(string $tag, string $suffix): int
{
    $stmt = db()->prepare("INSERT INTO users(full_name,matric_no,email,password,role) VALUES (?,?,?,'not-a-login-hash','student')");
    $stmt->execute(["$tag $suffix", "$tag-$suffix", "$tag-$suffix@example.invalid"]);
    return (int)db()->lastInsertId();
}
function tg4_connect(int $userId, string $identity, string $name = 'Telegram Student'): int
{
    $stmt = db()->prepare("INSERT INTO telegram_connections
        (user_id,telegram_user_id,telegram_chat_id,chat_type,display_name,username,enabled,consent_status,consented_at,connected_at)
        VALUES (?,?,?,'private',?,'safe_username',1,'consented',NOW(),NOW())");
    $stmt->execute([$userId, $identity, $identity, $name]);
    $id = (int)db()->lastInsertId();
    telegram_initialize_preferences($id);
    return $id;
}
function tg4_job(int $userId, int $connectionId, string $category, string $status = 'queued'): int
{
    $key = hash('sha256', implode('|', [$userId, $connectionId, $category, $status, random_bytes(8)]));
    $stmt = db()->prepare("INSERT INTO telegram_delivery_jobs
        (user_id,connection_id,source_type,source_id,notification_type,notification_category,message_payload_json,
         destination_chat_id,status,idempotency_key) VALUES (?,?, 'fixture',1,'calendar_reminder',?,'{}',700000000000001000,?,?)");
    $stmt->execute([$userId, $connectionId, $category, $status, $key]);
    return (int)db()->lastInsertId();
}
function tg4_status(int $jobId): string
{
    $stmt = db()->prepare('SELECT status FROM telegram_delivery_jobs WHERE id=?');
    $stmt->execute([$jobId]);
    return (string)$stmt->fetchColumn();
}

$config = telegram_config([
    'enabled' => true,
    'bot_username' => 'KwasuLcsBot',
    'bot_token' => '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ_abc',
    'webhook_secret' => str_repeat('s', 40),
]);
$tag = 'tg4' . bin2hex(random_bytes(5));
$pdo->beginTransaction();
try {
    $primary = tg4_user($tag, 'primary');
    $other = tg4_user($tag, 'other');

    $categories = telegram_settings_categories();
    tg4_check(array_keys($categories) === ['calendar_lecture', 'assignment_test', 'online_quiz_test'],
        'Settings exposes exactly the three approved Telegram categories');
    $empty = telegram_settings_state($primary, $config);
    tg4_check($empty['state'] === 'not_connected' && !$empty['enabled'], 'user without a row is not connected');
    tg4_check($empty['service_enabled'] && $empty['open_url'] === 'https://t.me/KwasuLcsBot',
        'configured bot username produces a token-free HTTPS Telegram URL');
    tg4_check(telegram_settings_status_handle_request('GET', null, $config)['status'] === 401,
        'connection status requires authentication');
    tg4_check(telegram_settings_status_handle_request('POST', ['id' => $primary], $config)['status'] === 405,
        'connection status is read-only GET');

    $primaryConnection = tg4_connect($primary, '700000000000001001', "Ada\nStudent");
    $otherConnection = tg4_connect($other, '700000000000001002', 'Other Student');
    $connected = telegram_settings_state($primary, $config);
    tg4_check($connected['state'] === 'connected' && $connected['enabled'], 'healthy enabled row is connected');
    tg4_check($connected['display_name'] === 'AdaStudent' && $connected['username'] === 'safe_username',
        'display metadata is bounded and control characters are removed');
    $public = telegram_settings_public_state($primary, $config);
    $publicJson = json_encode($public, JSON_THROW_ON_ERROR);
    tg4_check(array_keys($public) === ['state', 'enabled', 'display_name', 'username', 'preferences'],
        'public status response uses the minimal safe contract');
    tg4_check(!str_contains($publicJson, '700000000000001001') && !str_contains($publicJson, $config['bot_token'])
        && !str_contains($publicJson, $config['webhook_secret']), 'public status exposes no raw Telegram IDs or secrets');
    tg4_check(count($public['preferences']) === 3, 'public status includes only approved preferences');

    $csrf = telegram_settings_csrf_token();
    tg4_check(telegram_settings_handle_action('POST', ['id' => $primary], $csrf, 'set_global', ['enabled' => '1'])['type'] === 'success',
        'authenticated valid-CSRF mutation succeeds');
    $csrfRejected = false;
    try { telegram_settings_handle_action('POST', ['id' => $primary], 'wrong', 'set_global', ['enabled' => '0']); }
    catch (DomainException $e) { $csrfRejected = str_contains($e->getMessage(), 'Invalid request token'); }
    tg4_check($csrfRejected, 'invalid CSRF is rejected');
    $authRejected = false;
    try { telegram_settings_handle_action('POST', null, $csrf, 'set_global', ['enabled' => '0']); }
    catch (DomainException $e) { $authRejected = str_contains($e->getMessage(), 'Authentication'); }
    tg4_check($authRejected, 'Settings mutation requires authentication');
    $categoryRejected = false;
    try { telegram_settings_set_category($primary, 'course_announcements', false); }
    catch (InvalidArgumentException $e) { $categoryRejected = true; }
    tg4_check($categoryRejected, 'arbitrary category names are rejected');

    $primaryQueued = tg4_job($primary, $primaryConnection, 'calendar_lecture');
    $primarySent = tg4_job($primary, $primaryConnection, 'calendar_lecture', 'sent');
    $otherQueued = tg4_job($other, $otherConnection, 'calendar_lecture');
    telegram_settings_set_global_enabled($primary, false);
    tg4_check(telegram_settings_state($primary, $config)['state'] === 'disabled',
        'global disable preserves a healthy linked connection in paused state');
    tg4_check(tg4_status($primaryQueued) === 'cancelled' && tg4_status($primarySent) === 'sent'
        && tg4_status($otherQueued) === 'queued', 'global disable cancels only current user queued jobs and preserves history');

    telegram_settings_set_global_enabled($primary, true);
    tg4_check(telegram_settings_state($primary, $config)['state'] === 'connected', 'healthy paused connection can be enabled');
    $categoryQueued = tg4_job($primary, $primaryConnection, 'assignment_test');
    telegram_settings_set_category($primary, 'assignment_test', false);
    tg4_check(!telegram_settings_preferences($primaryConnection)['assignment_test']
        && tg4_status($categoryQueued) === 'cancelled', 'category pause persists and cancels matching queued jobs');
    telegram_settings_set_category($primary, 'assignment_test', true);
    tg4_check(telegram_settings_preferences($primaryConnection)['assignment_test'], 'category can be enabled again');

    $ignoredForeignId = tg4_job($other, $otherConnection, 'online_quiz_test');
    telegram_settings_handle_action('POST', ['id' => $primary], $csrf, 'set_category', [
        'category' => 'online_quiz_test', 'enabled' => '0', 'connection_id' => (string)$otherConnection,
    ]);
    tg4_check(tg4_status($ignoredForeignId) === 'queued'
        && telegram_settings_preferences($otherConnection)['online_quiz_test'],
        'client-supplied foreign connection ID is ignored and ownership remains server-side');

    $pdo->prepare('UPDATE telegram_connections SET enabled=0,blocked_at=NOW() WHERE id=?')->execute([$primaryConnection]);
    tg4_check(telegram_settings_state($primary, $config)['state'] === 'blocked', 'blocked row maps to unavailable state');
    $blockedRejected = false;
    try { telegram_settings_set_global_enabled($primary, true); } catch (DomainException $e) { $blockedRejected = true; }
    tg4_check($blockedRejected && !(int)telegram_connection_by_user($primary)['enabled'],
        'blocked connection cannot be falsely enabled');

    $pdo->prepare('UPDATE telegram_connections SET blocked_at=NULL,enabled=1 WHERE id=?')->execute([$primaryConnection]);
    $disconnectQueued = tg4_job($primary, $primaryConnection, 'online_quiz_test');
    $oldToken = telegram_create_link_token($primary, $config);
    telegram_settings_disconnect($primary, true);
    $disconnected = telegram_settings_state($primary, $config);
    tg4_check($disconnected['state'] === 'disconnected' && !$disconnected['enabled'],
        'disconnect revokes the active connection but preserves its historical row');
    tg4_check(tg4_status($disconnectQueued) === 'cancelled' && telegram_link_token_state($oldToken['token']) === 'invalid',
        'disconnect cancels queued jobs and invalidates outstanding link tokens');
    $enableDisconnectedRejected = false;
    try { telegram_settings_set_global_enabled($primary, true); } catch (DomainException $e) { $enableDisconnectedRejected = true; }
    tg4_check($enableDisconnectedRejected, 'disconnected historical row cannot be silently enabled');
    $freshToken = telegram_create_link_token($primary, $config);
    tg4_check($freshToken['token'] !== $oldToken['token'] && telegram_link_token_state($freshToken['token']) === 'valid',
        'reconnect initiation always creates a fresh one-time token');

    $confirmRejected = false;
    try { telegram_settings_disconnect($other, false); } catch (DomainException $e) { $confirmRejected = true; }
    tg4_check($confirmRejected && telegram_settings_state($other, $config)['state'] === 'connected',
        'disconnect requires explicit confirmation');

    $statusResponse = telegram_settings_status_handle_request('GET', ['id' => $other], $config);
    tg4_check($statusResponse['status'] === 200 && $statusResponse['body']['status'] === 'ok'
        && $statusResponse['body']['connection']['state'] === 'connected', 'authenticated status endpoint returns current state');
    $settingsSource = file_get_contents(dirname(__DIR__) . '/settings.php');
    $endpointSource = file_get_contents(dirname(__DIR__) . '/telegram_connection_status.php');
    tg4_check(is_string($settingsSource) && !str_contains($settingsSource, 'whatsapp_action')
        && !str_contains($settingsSource, 'WhatsApp notifications'), 'active Settings UI contains no WhatsApp controls or copy');
    tg4_check(is_string($endpointSource) && !str_contains($endpointSource, 'bot_token')
        && !str_contains($endpointSource, 'webhook_secret'), 'status endpoint source never serializes credentials');
    tg4_check(!str_contains((string)file_get_contents(dirname(__DIR__) . '/assets/telegram-settings.js'), 'localStorage')
        && !str_contains((string)file_get_contents(dirname(__DIR__) . '/assets/telegram-settings.js'), 'payload.url,') ,
        'browser code does not persist or log the plaintext link token');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

echo "Completed $checks Telegram Stage 4 service checks. Fixture records removed; no Telegram request sent.\n";
