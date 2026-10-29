<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/assignment_test.php';
require_login();

$user = current_user();
$item = assignment_test_find((int) ($_GET['id'] ?? 0));
$submission = assignment_test_submission_find((int) ($_GET['submission_id'] ?? 0), false, $user);
if (!$item || !$submission || (int) $submission['assignment_test_id'] !== (int) $item['id'] || !assignment_test_can_view_all_submissions($item, $user)) {
    http_response_code(403);
    exit('Submission is unavailable.');
}
$files = assignment_test_submission_files_for_user($submission, $item, $user);
$receipts = assignment_test_submission_receipts($submission, $item, $user);
$reviewSnapshot = assignment_test_review_snapshot($submission, $item, $user, $receipts, $files);
$currentFiles = $reviewSnapshot['files'];
$submission['note_text'] = $reviewSnapshot['note_text'];
$reviewFileIds = array_column($currentFiles, 'id');
$historyFiles = array_values(array_filter($files, fn($file) => !in_array($file['id'], $reviewFileIds, true)));
$preview = null;
foreach ($currentFiles as $file) {
    if (in_array($file['mime_type'], ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'text/plain'], true)) { $preview = $file; break; }
}
$flash = assignment_test_take_flash();
assignment_test_record_audit((int) $item['id'], 'viewed', (int) $user['id'], (int) $submission['id'], ['surface' => 'marking']);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php $pageTitle = 'Marking — ' . $item['title']; $extraCss = ['assets/assignment-test.css']; include __DIR__ . '/partials/head.php'; ?></head>
<body class="app-body">
<?php $assignmentTestPageItem = $item; include __DIR__ . '/partials/nav.php'; ?>
<?php include __DIR__ . '/partials/appheader.php'; $item = $assignmentTestPageItem; ?>
<main class="page assignment-test-page assignment-mark-page">
    <nav class="assignment-breadcrumb" aria-label="Breadcrumb"><a href="chat">Assessment/Chat</a><i class="bi bi-chevron-right"></i><a href="assignment_tests">Assignment/Test</a><i class="bi bi-chevron-right"></i><a href="assignment_test_submissions?id=<?php echo (int) $item['id']; ?>">Submissions</a><i class="bi bi-chevron-right"></i><span>Marking</span></nav>
    <header class="assignment-page-header assignment-page-header--back"><a class="assignment-back" href="assignment_test_submissions?id=<?php echo (int) $item['id']; ?>" aria-label="Back to submissions"><i class="bi bi-arrow-left"></i></a><div><h1>Marking</h1><p class="muted"><?php echo h($item['title']); ?> · <?php echo h($item['course_code']); ?></p></div></header>
    <?php if ($flash): ?><div class="assignment-alert assignment-alert--<?php echo h($flash['type']); ?>" role="status"><?php echo h($flash['message']); ?></div><?php endif; ?>

    <div class="assignment-mark-grid">
        <section class="panel assignment-mark-submission">
            <?php if (count($receipts) > 1): ?>
            <label class="assignment-attempt-picker">Previous Attempts
                <select data-previous-attempt><option value="">Current finalized attempt <?php echo (int) $submission['submission_attempt']; ?></option>
                <?php foreach (array_slice($receipts, 1) as $older): ?><option value="assignment_test_receipt?id=<?php echo (int) $older['id']; ?>&amp;embedded=1">Attempt <?php echo (int) $older['submission_attempt']; ?> · <?php echo h(date('M j, g:i A', strtotime($older['submitted_at']))); ?></option><?php endforeach; ?>
                </select>
            </label>
            <div data-previous-receipt hidden><p class="assignment-admin-note">Read-only previous attempt. Select the current attempt to resume marking.</p><iframe title="Previous attempt receipt"></iframe></div>
            <?php endif; ?>
            <div data-current-attempt>
            <?php if ($reviewSnapshot['receipt']): ?><p class="assignment-admin-note">Reviewing finalized attempt <?php echo (int) $reviewSnapshot['receipt']['submission_attempt']; ?>. Unsubmitted replacements do not change this review.</p><?php endif; ?>
            <header><div><span class="muted">Matric number</span><h2><?php echo h($submission['student_matric_no']); ?></h2><p class="muted">Attempt <?php echo (int) $submission['submission_attempt']; ?> · <?php echo !empty($submission['submitted_at']) ? h(date('M j, Y · g:i A', strtotime($submission['submitted_at']))) : 'Draft'; ?></p></div><span class="assignment-submission-state is-<?php echo h($submission['status']); ?>"><?php echo h(assignment_test_submission_statuses()[$submission['status']]); ?></span></header>
            <?php if ($preview): ?><div class="assignment-preview"><iframe title="Submission preview" src="assignment_test_file.php?id=<?php echo (int) $preview['id']; ?>&view=1"></iframe></div><?php elseif (!empty($submission['note_text'])): ?><div class="assignment-text-response"><h3>Text response</h3><p><?php echo nl2br(h($submission['note_text'])); ?></p></div><?php else: ?><div class="assignment-preview-empty"><i class="bi bi-file-earmark"></i><p>No browser preview is available. Download a current file below.</p></div><?php endif; ?>
            <?php if (!empty($submission['note_text']) && $preview): ?><div class="assignment-text-response"><h3>Student note</h3><p><?php echo nl2br(h($submission['note_text'])); ?></p></div><?php endif; ?>
            <div class="assignment-file-list assignment-mark-files"><?php foreach ($currentFiles as $file): ?><article><span class="assignment-file-icon"><i class="bi bi-file-earmark"></i></span><div><strong><?php echo h($file['original_filename']); ?></strong><span><?php echo h(format_filesize((int) $file['file_size'])); ?> · Version <?php echo (int) $file['version_number']; ?></span></div><a class="btn tiny secondary" data-document-preview data-document-title="<?php echo h($file['original_filename']); ?>" href="assignment_test_file.php?id=<?php echo (int) $file['id']; ?>&amp;view=1"><i class="bi bi-arrows-fullscreen" aria-hidden="true"></i> Expand</a><a class="btn tiny secondary" href="assignment_test_file.php?id=<?php echo (int) $file['id']; ?>"><i class="bi bi-download" aria-hidden="true"></i> Download</a></article><?php endforeach; ?><?php if (!$currentFiles): ?><p class="muted">This submission has no current files.</p><?php endif; ?></div>
            <?php if ($receipts): ?><div class="assignment-receipt-strip"><strong>Receipts</strong><?php foreach ($receipts as $receipt): ?><a class="btn tiny secondary" href="assignment_test_receipt?id=<?php echo (int) $receipt['id']; ?>">Attempt <?php echo (int) $receipt['submission_attempt']; ?></a><?php endforeach; ?></div><?php endif; ?>
            <?php if ($historyFiles): ?><details class="assignment-history"><summary>Previous finalized files (<?php echo count($historyFiles); ?>)</summary><div class="assignment-file-list"><?php foreach ($historyFiles as $file): ?><article><span class="assignment-file-icon"><i class="bi bi-clock-history"></i></span><div><strong><?php echo h($file['original_filename']); ?></strong><span>Version <?php echo (int) $file['version_number']; ?> · <?php echo h(date('M j, Y · g:i A', strtotime($file['uploaded_at']))); ?></span></div><a class="btn tiny secondary" href="assignment_test_file.php?id=<?php echo (int) $file['id']; ?>">Download</a></article><?php endforeach; ?></div></details><?php endif; ?>
            </div>
        </section>

        <aside class="panel assignment-marking-panel">
            <h2>Score &amp; feedback</h2><p data-previous-marking-note class="muted" hidden>Previous attempts are read-only.</p>
            <?php if (in_array($submission['status'], ['submitted', 'resubmitted', 'under_review', 'marked'], true)): ?>
                <form method="post" action="assignment_test_mark_action.php" class="assignment-mark-form" data-current-marking>
                    <input type="hidden" name="csrf" value="<?php echo h(assignment_test_csrf_token()); ?>"><input type="hidden" name="assignment_test_id" value="<?php echo (int) $item['id']; ?>"><input type="hidden" name="submission_id" value="<?php echo (int) $submission['id']; ?>">
                    <label>Score<div class="assignment-score-input"><input type="number" name="score" min="0" max="<?php echo h((string) $item['total_marks']); ?>" step="0.01" value="<?php echo h((string) ($submission['score'] ?? '')); ?>"><span>/ <?php echo h(assignment_test_format_marks($item['total_marks'])); ?></span></div></label>
                    <label>Feedback<textarea name="feedback" rows="10" placeholder="Give clear, actionable feedback…"><?php echo h($submission['feedback'] ?? ''); ?></textarea></label>
                    <div class="assignment-mark-actions"><button class="btn secondary" name="action" value="save_draft"><i class="bi bi-floppy"></i> Save Draft</button><button class="btn primary" name="action" value="mark_complete"><i class="bi bi-check2"></i> Mark Complete</button></div>
                    <?php if (in_array($submission['status'], ['submitted', 'resubmitted', 'under_review', 'marked'], true)): ?><button class="btn assignment-return-button" name="action" value="return" formnovalidate onclick="return confirm('Return this submission to the student for revision?');"><i class="bi bi-arrow-counterclockwise"></i> Return for revision</button><?php endif; ?>
                </form>
            <?php else: ?>
                <p class="assignment-locked"><i class="bi bi-clock"></i> This submission is <?php echo h(strtolower(assignment_test_submission_statuses()[$submission['status']])); ?>. Marking can continue after the student submits a new attempt.</p>
            <?php endif; ?>
        </aside>
    </div>
</main>
<?php include __DIR__ . '/partials/assignment_document_viewer.php'; ?>
<script src="assets/assignment-test.js?v=<?php echo (int) filemtime(__DIR__ . '/assets/assignment-test.js'); ?>" defer></script>
</body>
</html>
