<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/assignment_test.php';
require_login();
$user = current_user();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !assignment_test_verify_csrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('Invalid request token.'); }
$itemId = (int) ($_POST['assignment_test_id'] ?? 0);
try {
    $item = assignment_test_find($itemId);
    if (!$item || !assignment_test_can_view($item, $user) || !assignment_test_is_learner($item, $user)) throw new RuntimeException('Assignment/Test is unavailable.');
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'upload') {
        $names = $_FILES['files']['name'] ?? [];
        if (!$names) throw new InvalidArgumentException('Choose at least one file to upload.');
        foreach ((array) $names as $index => $name) {
            $file = ['name' => $name, 'type' => $_FILES['files']['type'][$index] ?? '', 'tmp_name' => $_FILES['files']['tmp_name'][$index] ?? '', 'error' => $_FILES['files']['error'][$index] ?? UPLOAD_ERR_NO_FILE, 'size' => $_FILES['files']['size'][$index] ?? 0];
            assignment_test_add_submission_file($item, $user, $file);
        }
        assignment_test_set_flash('success', 'Files uploaded and preserved exactly.');
    } elseif ($action === 'replace') {
        assignment_test_add_submission_file($item, $user, $_FILES['file'] ?? [], (int) $_POST['replace_file_id']);
        assignment_test_set_flash('success', 'File replaced. The earlier version remains in history.');
    } elseif ($action === 'remove') {
        assignment_test_remove_submission_file((int) $_POST['file_id'], $item, $user);
        assignment_test_set_flash('success', 'File removed from the current submission; history retained.');
    } elseif ($action === 'save_note') {
        assignment_test_save_submission_note($item, $user, (string) ($_POST['note_text'] ?? ''));
        assignment_test_set_flash('success', 'Submission draft saved.');
    } elseif ($action === 'submit') {
        $submission = assignment_test_finalize_submission($item, $user);
        assignment_test_set_flash('success', 'Submission received. Attempt ' . (int) $submission['submission_attempt'] . ' was timestamped at ' . date('g:i A', strtotime($submission['submitted_at'])) . '.');
    } else throw new RuntimeException('Unknown submission action.');
} catch (Throwable $e) { assignment_test_set_flash('error', $e->getMessage()); }
header('Location: assignment_test?id=' . $itemId);
exit();
