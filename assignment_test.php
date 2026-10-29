<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/assignment_test.php';
require_login();

$user = current_user();
$item = assignment_test_find((int) ($_GET['id'] ?? 0));
if (!$item || !assignment_test_can_view($item, $user)) { http_response_code(403); exit('Assignment/Test not found or unavailable.'); }
$isLearner = assignment_test_is_learner($item, $user);
$submission = $isLearner ? assignment_test_submission_for_student((int) $item['id'], (int) $user['id']) : null;
$files = $submission ? assignment_test_submission_files_for_user($submission, $item, $user) : [];
$receipts = $submission ? assignment_test_submission_receipts($submission, $item, $user) : [];
// Marking drafts are private until the lecturer completes or returns work.
if ($submission && !in_array($submission['status'], ['marked', 'returned'], true)) {
    $submission['feedback'] = null;
}
$currentFiles = array_values(array_filter($files, fn($f) => (bool) $f['is_current']));
$historyFiles = array_values(array_filter($files, fn($f) => !(bool) $f['is_current']));
$permissions = $isLearner ? assignment_test_submission_permissions($item, $submission, $user) : null;
$status = assignment_test_status_info($item);
$flash = assignment_test_take_flash();
assignment_test_record_audit((int) $item['id'], 'viewed', (int) $user['id'], $submission ? (int) $submission['id'] : null, ['surface' => 'detail']);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php $pageTitle = $item['title']; $extraCss = ['assets/assignment-test.css']; include __DIR__ . '/partials/head.php'; ?></head>
<body class="app-body">
<?php $assignmentTestPageItem = $item; include __DIR__ . '/partials/nav.php'; ?>
<?php include __DIR__ . '/partials/appheader.php'; $item = $assignmentTestPageItem; ?>
<main class="page assignment-test-page">
    <nav class="assignment-breadcrumb" aria-label="Breadcrumb"><a href="chat">Assessment/Chat</a><i class="bi bi-chevron-right"></i><a href="assignment_tests">Assignment/Test</a><i class="bi bi-chevron-right"></i><span><?php echo h($item['course_code']); ?></span></nav>
    <?php if ($flash): ?><div class="assignment-alert assignment-alert--<?php echo h($flash['type']); ?>" role="status"><?php echo h($flash['message']); ?></div><?php endif; ?>

    <section class="panel assignment-detail-hero">
        <div class="assignment-detail-title"><span class="assignment-list-icon is-<?php echo h($item['type']); ?>"><i class="bi <?php echo $item['type'] === 'test' ? 'bi-clipboard-check' : 'bi-file-earmark-text'; ?>"></i></span><div><span class="assignment-type-badge is-<?php echo h($item['type']); ?>"><?php echo h(assignment_test_type_label($item['type'])); ?></span><h1><?php echo h($item['title']); ?></h1></div></div>
        <dl class="assignment-detail-facts"><div><dt>Course</dt><dd><?php echo h($item['course_code']); ?></dd></div><div><dt>Due date</dt><dd><?php echo h(date('M j, Y · g:i A', strtotime($item['due_at']))); ?></dd></div><div><dt>Total marks</dt><dd><?php echo h(assignment_test_format_marks($item['total_marks'])); ?></dd></div><div><dt>Lecturer</dt><dd><?php echo h($item['lecturer_name']); ?></dd></div><div><dt>Status</dt><dd><span class="assignment-test-status assignment-test-status--<?php echo h($status['tone']); ?>"><?php echo h($status['label']); ?></span></dd></div></dl>
        <?php if (assignment_test_can_view_all_submissions($item, $user)): ?><div class="assignment-detail-actions"><a class="btn secondary" href="assignment_test_audit?id=<?php echo (int) $item['id']; ?>"><i class="bi bi-clock-history"></i> Audit Log</a><a class="btn primary" href="assignment_test_submissions?id=<?php echo (int) $item['id']; ?>"><i class="bi bi-people"></i> View Submissions</a></div><?php endif; ?>
    </section>

    <div class="assignment-detail-layout">
        <div class="assignment-detail-main">
            <section class="panel assignment-section"><h2><i class="bi bi-info-circle"></i> Instructions</h2><div class="assignment-instructions"><?php echo nl2br(h($item['instructions'])); ?></div></section>

            <?php if ($isLearner): ?>
            <p class="assignment-admin-note">Uploads, replacements and saved notes are draft changes until you click Submit or Resubmit. Your receipts show exactly what was finalized for each attempt.</p>
            <section class="panel assignment-section">
                <h2><i class="bi bi-cloud-arrow-up"></i> Upload your files</h2>
                <?php if ($permissions['can_upload']): ?>
                <form method="post" action="assignment_test_submission.php" enctype="multipart/form-data" class="assignment-upload-zone">
                    <input type="hidden" name="csrf" value="<?php echo h(assignment_test_csrf_token()); ?>"><input type="hidden" name="action" value="upload"><input type="hidden" name="assignment_test_id" value="<?php echo (int) $item['id']; ?>">
                    <i class="bi bi-cloud-arrow-up" aria-hidden="true"></i><label for="assignment-upload">Choose one or more files</label><span><?php echo h(strtoupper($item['allowed_formats'] ?: implode(', ', allowed_upload_extensions()))); ?> · up to <?php echo (int) round($item['max_file_size'] / 1048576); ?> MB each</span><input id="assignment-upload" type="file" name="files[]" multiple required><button class="btn secondary">Upload Files</button>
                </form>
                <?php else: ?><p class="assignment-locked"><i class="bi bi-lock"></i> <?php echo h($permissions['reason']); ?></p><?php endif; ?>
            </section>

            <section class="panel assignment-section"><h2 id="assignment-note-label">Text response or note</h2><form method="post" action="assignment_test_submission.php"><input type="hidden" name="csrf" value="<?php echo h(assignment_test_csrf_token()); ?>"><input type="hidden" name="action" value="save_note"><input type="hidden" name="assignment_test_id" value="<?php echo (int) $item['id']; ?>"><textarea aria-labelledby="assignment-note-label" name="note_text" rows="5" <?php echo !$permissions['can_upload'] ? 'disabled' : ''; ?> placeholder="Add an optional response or note for your lecturer."><?php echo h($submission['note_text'] ?? ''); ?></textarea><?php if ($permissions['can_upload']): ?><button class="btn secondary">Save Draft</button><?php endif; ?></form></section>

            <section class="panel assignment-section"><h2>Current files</h2><?php if (!$currentFiles): ?><p class="muted">No current files uploaded.</p><?php endif; ?><div class="assignment-file-list"><?php foreach ($currentFiles as $file): ?><article><span class="assignment-file-icon"><i class="bi bi-file-earmark"></i></span><div><strong><?php echo h($file['original_filename']); ?></strong><span><?php echo h(format_filesize((int) $file['file_size'])); ?> · Version <?php echo (int) $file['version_number']; ?> · <?php echo h(date('M j, Y · g:i A', strtotime($file['uploaded_at']))); ?></span></div><a class="btn tiny secondary" href="assignment_test_file.php?id=<?php echo (int) $file['id']; ?>&view=1" data-document-preview data-document-title="Submission file">Open</a><?php if ($permissions['can_upload']): ?><form method="post" action="assignment_test_submission.php" enctype="multipart/form-data" class="assignment-replace-form"><input type="hidden" name="csrf" value="<?php echo h(assignment_test_csrf_token()); ?>"><input type="hidden" name="action" value="replace"><input type="hidden" name="assignment_test_id" value="<?php echo (int) $item['id']; ?>"><input type="hidden" name="replace_file_id" value="<?php echo (int) $file['id']; ?>"><label class="btn tiny secondary">Replace<input type="file" name="file" required onchange="this.form.submit()"></label></form><form method="post" action="assignment_test_submission.php" onsubmit="return confirm('Remove this current file? Its version history will be retained.');"><input type="hidden" name="csrf" value="<?php echo h(assignment_test_csrf_token()); ?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="assignment_test_id" value="<?php echo (int) $item['id']; ?>"><input type="hidden" name="file_id" value="<?php echo (int) $file['id']; ?>"><button class="assignment-icon-button" aria-label="Remove <?php echo h($file['original_filename']); ?>"><i class="bi bi-trash"></i></button></form><?php endif; ?></article><?php endforeach; ?></div></section>

            <?php if ($historyFiles): ?><details class="panel assignment-section assignment-history"><summary>Submission version history (<?php echo count($historyFiles); ?>)</summary><div class="assignment-file-list"><?php foreach ($historyFiles as $file): ?><article><span class="assignment-file-icon"><i class="bi bi-clock-history"></i></span><div><strong><?php echo h($file['original_filename']); ?></strong><span>Version <?php echo (int) $file['version_number']; ?> · <?php echo h(date('M j, Y · g:i A', strtotime($file['uploaded_at']))); ?></span></div><a class="btn tiny secondary" href="assignment_test_file.php?id=<?php echo (int) $file['id']; ?>">Download</a></article><?php endforeach; ?></div></details><?php endif; ?>
            <?php endif; ?>
        </div>

        <aside class="assignment-detail-side">
            <?php if ($isLearner): ?>
            <section class="panel assignment-side-card"><h2>Submission status</h2><span class="assignment-submission-state is-<?php echo h($submission['status'] ?? 'draft'); ?>"><?php echo h(assignment_test_submission_statuses()[$submission['status'] ?? 'draft']); ?></span><dl><div><dt>Attempt</dt><dd><?php echo (int) ($submission['submission_attempt'] ?? 0); ?></dd></div><div><dt>Submitted</dt><dd><?php echo !empty($submission['submitted_at']) ? h(date('M j, g:i A', strtotime($submission['submitted_at']))) : 'Not yet'; ?></dd></div><?php if (($submission['status'] ?? '') === 'marked'): ?><div><dt>Score</dt><dd><?php echo h(assignment_test_format_marks($submission['score'])); ?> / <?php echo h(assignment_test_format_marks($item['total_marks'])); ?></dd></div><?php endif; ?></dl><?php if (!empty($submission['feedback'])): ?><div class="assignment-feedback"><strong>Lecturer feedback</strong><p><?php echo nl2br(h($submission['feedback'])); ?></p></div><?php endif; ?><?php if ($receipts): ?><div class="assignment-receipt-links"><strong>Submission receipts</strong><?php foreach ($receipts as $receipt): ?><a href="assignment_test_receipt?id=<?php echo (int) $receipt['id']; ?>"><i class="bi bi-receipt"></i> Attempt <?php echo (int) $receipt['submission_attempt']; ?> · <?php echo h($receipt['receipt_code']); ?></a><?php endforeach; ?></div><?php endif; ?></section>
            <section class="panel assignment-side-card"><h2>Deadline</h2><strong class="assignment-deadline"><?php echo h(date('M j, Y · g:i A', strtotime($item['due_at']))); ?></strong><p class="muted"><?php echo $item['allow_late_submissions'] ? 'Late submissions are allowed.' : 'Late submissions are not allowed.'; ?></p></section>
            <section class="panel assignment-side-card"><h2>Accepted formats</h2><div class="assignment-format-pills"><?php foreach (assignment_test_normalize_formats($item['allowed_formats']) ?: allowed_upload_extensions() as $format): ?><span><?php echo h(strtoupper($format)); ?></span><?php endforeach; ?></div><p class="muted">Maximum <?php echo (int) round($item['max_file_size'] / 1048576); ?> MB per file.</p></section>
            <section class="panel assignment-side-card"><h2>Submission checklist</h2><ul class="assignment-checklist"><li class="<?php echo $currentFiles || !empty($submission['note_text']) ? 'done' : ''; ?>"><i class="bi <?php echo $currentFiles || !empty($submission['note_text']) ? 'bi-check-circle-fill' : 'bi-circle'; ?>"></i> Work added</li><li class="<?php echo !empty($submission['submitted_at']) ? 'done' : ''; ?>"><i class="bi <?php echo !empty($submission['submitted_at']) ? 'bi-check-circle-fill' : 'bi-circle'; ?>"></i> Final attempt submitted</li><li class="<?php echo !empty($receipts) ? 'done' : ''; ?>"><i class="bi <?php echo !empty($receipts) ? 'bi-check-circle-fill' : 'bi-circle'; ?>"></i> Receipt available</li></ul></section>
            <section class="panel assignment-side-card assignment-submit-card"><h2>Ready to submit?</h2><p class="muted">Final submission creates a timestamped receipt. Your files remain private.</p><?php if ($permissions['can_submit'] || $permissions['can_resubmit']): ?><form method="post" action="assignment_test_submission.php" onsubmit="return confirm('Submit this attempt now?');"><input type="hidden" name="csrf" value="<?php echo h(assignment_test_csrf_token()); ?>"><input type="hidden" name="action" value="submit"><input type="hidden" name="assignment_test_id" value="<?php echo (int) $item['id']; ?>"><button class="btn primary"><i class="bi bi-send"></i> <?php echo $permissions['can_resubmit'] ? 'Resubmit' : 'Submit Assignment/Test'; ?></button></form><?php else: ?><p class="assignment-locked"><i class="bi bi-lock"></i> <?php echo h($permissions['reason']); ?></p><?php endif; ?></section>
            <?php endif; ?>
        </aside>
    </div>
</main>
<?php include __DIR__ . '/partials/assignment_document_viewer.php'; ?>
<script src="assets/assignment-test.js?v=<?php echo (int) filemtime(__DIR__ . '/assets/assignment-test.js'); ?>" defer></script>
</body>
</html>
