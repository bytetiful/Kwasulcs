<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/telegram/settings.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    $response = telegram_settings_status_handle_request(
        (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        current_user()
    );
    http_response_code((int)$response['status']);
    if ((int)$response['status'] === 405) header('Allow: GET');
    echo json_encode($response['body'], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[LCS Telegram Settings] status request failed.');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to check Telegram connection.']);
}
