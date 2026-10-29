<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/pwa.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function push_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') push_json(405, ['error' => 'method_not_allowed']);
$user = current_user();
if (!$user) push_json(401, ['error' => 'authentication_required']);
$csrf = is_string($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : null;
if (!pwa_verify_csrf($csrf)) push_json(403, ['error' => 'invalid_request_token']);

try {
    $body = json_decode((string)file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    push_json(400, ['error' => 'invalid_json']);
}
if (!is_array($body)) push_json(400, ['error' => 'invalid_request']);
$action = (string)($body['action'] ?? 'subscribe');

try {
    if ($action === 'unsubscribe') {
        $endpoint = trim((string)($body['endpoint'] ?? ''));
        if ($endpoint !== '') {
            db()->prepare('UPDATE web_push_subscriptions SET enabled=0,revoked_at=NOW() WHERE user_id=? AND endpoint_hash=?')
                ->execute([(int)$user['id'], hash('sha256', $endpoint)]);
        } else {
            db()->prepare('UPDATE web_push_subscriptions SET enabled=0,revoked_at=NOW() WHERE user_id=?')
                ->execute([(int)$user['id']]);
        }
        push_json(200, ['status' => 'ok']);
    }
    if ($action !== 'subscribe') push_json(400, ['error' => 'unsupported_action']);

    $subscription = is_array($body['subscription'] ?? null) ? $body['subscription'] : [];
    $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
    $endpoint = trim((string)($subscription['endpoint'] ?? ''));
    $p256dh = trim((string)($keys['p256dh'] ?? ''));
    $auth = trim((string)($keys['auth'] ?? ''));
    $parts = parse_url($endpoint);
    if ($endpoint === '' || strlen($endpoint) > 4096 || !is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])
        || !preg_match('/^[A-Za-z0-9_-]{40,255}$/', $p256dh)
        || !preg_match('/^[A-Za-z0-9_-]{16,255}$/', $auth)) {
        push_json(422, ['error' => 'invalid_subscription']);
    }
    $encoding = trim((string)($subscription['contentEncoding'] ?? 'aes128gcm'));
    if (!in_array($encoding, ['aes128gcm', 'aesgcm'], true)) $encoding = 'aes128gcm';
    db()->prepare('INSERT INTO web_push_subscriptions
        (user_id,endpoint_hash,endpoint,p256dh,auth_key,content_encoding,enabled,revoked_at)
        VALUES (?,?,?,?,?,?,1,NULL)
        ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),endpoint=VALUES(endpoint),p256dh=VALUES(p256dh),
            auth_key=VALUES(auth_key),content_encoding=VALUES(content_encoding),enabled=1,revoked_at=NULL,updated_at=NOW()')
        ->execute([(int)$user['id'], hash('sha256', $endpoint), $endpoint, $p256dh, $auth, $encoding]);
    push_json(200, ['status' => 'ok']);
} catch (Throwable $e) {
    error_log('[LCS Web Push] subscription update failed for user ' . (int)$user['id'] . ' (' . get_class($e) . ')');
    push_json(503, ['error' => 'service_unavailable']);
}
