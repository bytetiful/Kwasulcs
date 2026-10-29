<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/assignment_test.php';
require_login();

$user = current_user();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !assignment_test_verify_csrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Invalid request token.');
}

try {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $item = assignment_test_create_draft($_POST, $user);
        if (($_POST['intent'] ?? '') === 'publish') $item = assignment_test_publish((int) $item['id'], $user);
        assignment_test_set_flash('success', $item['status'] === 'published' ? 'Assignment/Test published to the course group.' : 'Draft saved.');
    } elseif ($action === 'update') {
        $item = assignment_test_update_item((int) $_POST['assignment_test_id'], $_POST, $user);
        if (($_POST['intent'] ?? '') === 'publish') $item = assignment_test_publish((int) $item['id'], $user);
        assignment_test_set_flash('success', $item['status'] === 'closed' ? 'Changes saved. The Assignment/Test remains closed until you reopen it.' : ($item['status'] === 'published' ? 'Assignment/Test updated and published.' : 'Draft updated.'));
    } elseif ($action === 'publish') {
        assignment_test_publish((int) $_POST['assignment_test_id'], $user);
        assignment_test_set_flash('success', 'Assignment/Test published to the course group.');
    } elseif ($action === 'close') {
        assignment_test_close((int) $_POST['assignment_test_id'], $user);
        assignment_test_set_flash('success', 'Assignment/Test closed and students notified.');
    } elseif ($action === 'reopen') {
        assignment_test_reopen((int) $_POST['assignment_test_id'], $user);
        assignment_test_set_flash('success', 'Assignment/Test reopened. Existing deadline rules still apply.');
    } else {
        throw new RuntimeException('Unknown Assignment/Test action.');
    }
} catch (Throwable $e) {
    assignment_test_set_flash('error', $e->getMessage());
}
if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
    $flash = assignment_test_take_flash();
    $ok = ($flash['type'] ?? '') === 'success';
    http_response_code($ok ? 200 : 422);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['ok'=>$ok, 'message'=>$flash['message'] ?? 'Request completed.']);
    exit();
}
header('Location: assignment_tests');
exit();
