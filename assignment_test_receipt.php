<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/assignment_test.php';
require_login();

$user = current_user();
$receipt = assignment_test_submission_receipt_find((int) ($_GET['id'] ?? 0), $user);
if (!$receipt) { http_response_code(404); exit('Submission receipt not found.'); }
$manifest = json_decode((string) $receipt['file_manifest_json'], true);
if (!is_array($manifest)) $manifest = [];
assignment_test_record_audit((int) $receipt['assignment_test_id'], 'receipt_viewed', (int) $user['id'], (int) $receipt['submission_id'], [
    'receipt_id' => (int) $receipt['id'], 'attempt' => (int) $receipt['submission_attempt'],
]);
$embedded = ($_GET['embedded'] ?? '') === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php $pageTitle = 'Submission Receipt'; $extraCss = ['assets/assignment-test.css']; include __DIR__ . '/partials/head.php'; ?></head>
<body class="<?php echo $embedded ? 'assignment-embedded-receipt' : 'app-body'; ?>">
<?php if (!$embedded) { include __DIR__ . '/partials/nav.php'; include __DIR__ . '/partials/appheader.php'; } ?>
<main class="page assignment-test-page assignment-receipt-page">
    <nav <?php echo $embedded ? 'hidden' : ''; ?> class="assignment-breadcrumb no-print" aria-label="Breadcrumb"><a href="assignment_tests">Assignment/Test</a><i class="bi bi-chevron-right"></i><a href="assignment_test?id=<?php echo (int) $receipt['assignment_test_id']; ?>"><?php echo h($receipt['course_code']); ?></a><i class="bi bi-chevron-right"></i><span>Receipt</span></nav>
    <section class="panel assignment-receipt">
        <header><div class="assignment-receipt-check"><i class="bi bi-check2"></i></div><div><span>Submission received</span><h1>Assignment/Test receipt</h1></div><button class="btn secondary no-print" type="button" onclick="window.print()"><i class="bi bi-printer"></i> Print</button></header>
        <dl class="assignment-receipt-facts"><div><dt>Course</dt><dd><?php echo h($receipt['course_code'] . ' — ' . $receipt['course_title']); ?></dd></div><div><dt>Assignment/Test</dt><dd><?php echo h($receipt['assignment_test_title']); ?></dd></div><div><dt>Matric number</dt><dd><?php echo h($receipt['student_matric_no']); ?></dd></div><div><dt>Attempt</dt><dd><?php echo (int) $receipt['submission_attempt']; ?></dd></div><div><dt>Submitted at</dt><dd><?php echo h(date('M j, Y · g:i:s A', strtotime($receipt['submitted_at']))); ?></dd></div><div><dt>Submission timing</dt><dd><?php echo $receipt['is_late'] ? 'Late submission' : 'Before deadline'; ?></dd></div></dl>
        <div class="assignment-receipt-files"><h2>File manifest</h2><?php if (!$manifest): ?><p class="muted">No files — this receipt covers a text response.</p><?php endif; ?><?php foreach ($manifest as $file): ?><article><span class="assignment-file-icon"><i class="bi bi-file-earmark-check"></i></span><div><strong><?php echo h($file['original_filename'] ?? 'File'); ?></strong><span><?php echo h(format_filesize((int) ($file['file_size'] ?? 0))); ?> · Version <?php echo (int) ($file['version_number'] ?? 1); ?></span><code>SHA-256 <?php echo h($file['checksum_sha256'] ?? ''); ?></code></div><?php if (!empty($file['id'])): ?><a class="btn tiny secondary no-print" data-document-preview data-document-title="<?php echo h($file['original_filename']); ?>" href="assignment_test_file.php?id=<?php echo (int) $file['id']; ?>&amp;view=1">Open</a><a class="btn tiny secondary no-print" href="assignment_test_file.php?id=<?php echo (int) $file['id']; ?>">Download</a><?php endif; ?></article><?php endforeach; ?></div>
        <?php if (!empty($receipt['note_text_snapshot'])): ?><div class="assignment-receipt-note"><h2>Text response / note</h2><p><?php echo nl2br(h($receipt['note_text_snapshot'])); ?></p></div><?php endif; ?>
        <footer><i class="bi bi-shield-check"></i><span>This receipt is a permanent snapshot of attempt <?php echo (int) $receipt['submission_attempt']; ?>. Later replacements or resubmissions do not alter it.</span></footer>
    </section>
</main>
<?php include __DIR__ . '/partials/assignment_document_viewer.php'; ?>
<script src="assets/assignment-test.js?v=<?php echo (int) filemtime(__DIR__ . '/assets/assignment-test.js'); ?>" defer></script>
</body>
</html>
