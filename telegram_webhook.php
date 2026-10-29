<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/telegram/webhook.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$headers = [];
if (isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'])) {
    $headers['X-Telegram-Bot-Api-Secret-Token'] = (string)$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'];
}
if (function_exists('getallheaders')) {
    foreach ((array)getallheaders() as $name => $value) {
        if (is_string($name) && is_string($value)) $headers[$name] = $value;
    }
}
$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
if ($method === 'POST' && $contentLength > TELEGRAM_WEBHOOK_MAX_BYTES) {
    $response = ['status' => 413, 'body' => ['ok' => false, 'error' => 'payload_too_large']];
} else {
    $rawBody = $method === 'POST'
        ? (string)file_get_contents('php://input', false, null, 0, TELEGRAM_WEBHOOK_MAX_BYTES + 1)
        : '';
    $response = telegram_webhook_handle_request($method, $headers, $rawBody);
}

http_response_code((int)$response['status']);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if ((int)$response['status'] === 405) header('Allow: POST');
echo json_encode($response['body'], JSON_UNESCAPED_SLASHES);
