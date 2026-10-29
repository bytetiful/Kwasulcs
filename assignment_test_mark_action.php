<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/assignment_test.php';
require_login();

$user = current_user();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !assignment_test_verify_csrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Invalid request token.');
}
$itemId = (int) ($_POST['assignment_test_id'] ?? 0);
$submissionId = (int) ($_POST['submission_id'] ?? 0);
try {
    $item = assignment_test_find($itemId);
    if (!$item || !assignment_test_can_mark($item, $user)) throw new RuntimeException('Only the course lecturer may mark this submission.');
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_draft') {
        assignment_test_save_marking_draft($item, $submissionId, $user, $_POST['score'] ?? null, (string) ($_POST['feedback'] ?? ''));
        assignment_test_set_flash('success', 'Marking draft saved.');
    } elseif ($action === 'mark_complete') {
        assignment_test_complete_marking($item, $submissionId, $user, $_POST['score'] ?? null, (string) ($_POST['feedback'] ?? ''));
        assignment_test_set_flash('success', 'Submission marked and the student notified.');
    } elseif ($action === 'return') {
        assignment_test_return_submission($item, $submissionId, $user, (string) ($_POST['feedback'] ?? ''));
        assignment_test_set_flash('success', 'Submission returned for revision and the student notified.');
    } else {
        throw new RuntimeException('Unknown marking action.');
    }
} catch (Throwable $e) {
    assignment_test_set_flash('error', $e->getMessage());
}
header('Location: assignment_test_mark.php?id=' . $itemId . '&submission_id=' . $submissionId);
exit();
