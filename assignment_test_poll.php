<?php
require_once __DIR__ . '/includes/assignment_test.php';
require_login();
$user = current_user();
header('Content-Type: application/json');
header('Cache-Control: private, no-store');
try {
    echo json_encode(assignment_test_list_snapshot($user), JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('Assignment/Test polling failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error'=>'Updates are temporarily unavailable.']);
}
