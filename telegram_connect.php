<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/telegram/linking.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$user = current_user();
$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? null);
try {
    $response = telegram_connect_handle_request((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), $user,
        is_string($csrf) ? $csrf : null);
    http_response_code((int)$response['status']);
    if ((int)$response['status'] === 405) header('Allow: POST');
    echo json_encode($response['body'], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[LCS Telegram link] initiation failed.');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Unable to start Telegram connection. Please try again.']);
}
