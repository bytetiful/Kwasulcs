<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/assignment_test.php';
require_login();

$user = current_user();
$item = assignment_test_find((int) ($_GET['id'] ?? 0));
if (!$item || !assignment_test_can_view_all_submissions($item, $user)) {
    http_response_code(403);
    exit('Assignment/Test submissions are unavailable.');
}

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
];
$allRows = assignment_test_submission_roster($item, $user);
$rows = $filters['search'] === '' && $filters['status'] === '' ? $allRows : assignment_test_submission_roster($item, $user, $filters);
$studentCount = count(assignment_test_enrolled_student_ids((int) $item['course_id']));
$totals = ['students' => $studentCount, 'submitted' => 0, 'pending' => max(0, $studentCount - count($allRows)), 'marked' => 0];
foreach ($allRows as $row) {
    if (empty($row['submission_id']) || ($row['status'] ?? '') === 'draft') $totals['pending']++;
    else $totals['submitted']++;
    if (($row['status'] ?? '') === 'marked') $totals['marked']++;
}
$flash = assignment_test_take_flash();
assignment_test_record_audit((int) $item['id'], 'viewed', (int) $user['id'], null, ['surface' => 'submission_roster']);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php $pageTitle = 'Submissions — ' . $item['title']; $extraCss = ['assets/assignment-test.css']; include __DIR__ . '/partials/head.php'; ?></head>
<body class="app-body">
<?php $assignmentTestPageItem = $item; include __DIR__ . '/partials/nav.php'; ?>
<?php include __DIR__ . '/partials/appheader.php'; $item = $assignmentTestPageItem; ?>
<main class="page assignment-test-page">
    <nav class="assignment-breadcrumb" aria-label="Breadcrumb"><a href="chat">Assessment/Chat</a><i class="bi bi-chevron-right"></i><a href="assignment_tests">Assignment/Test</a><i class="bi bi-chevron-right"></i><a href="assignment_test?id=<?php echo (int) $item['id']; ?>"><?php echo h($item['course_code']); ?></a><i class="bi bi-chevron-right"></i><span>Submissions</span></nav>
    <header class="assignment-page-header assignment-page-header--back">
        <a class="assignment-back" href="assignment_test?id=<?php echo (int) $item['id']; ?>" aria-label="Back to Assignment/Test"><i class="bi bi-arrow-left"></i></a>
        <div><h1>Submissions</h1><p class="muted"><?php echo h($item['title']); ?> · <?php echo h($item['course_code']); ?></p></div>
    </header>
    <?php if ($flash): ?><div class="assignment-alert assignment-alert--<?php echo h($flash['type']); ?>" role="status"><?php echo h($flash['message']); ?></div><?php endif; ?>

    <section class="assignment-stats" aria-label="Submission summary">
        <article><span class="assignment-stat-icon is-purple"><i class="bi bi-people"></i></span><div><strong><?php echo $totals['students']; ?></strong><span>Total students</span></div></article>
        <article><span class="assignment-stat-icon is-green"><i class="bi bi-file-earmark-check"></i></span><div><strong><?php echo $totals['submitted']; ?></strong><span>Submitted</span></div></article>
        <article><span class="assignment-stat-icon is-amber"><i class="bi bi-hourglass-split"></i></span><div><strong><?php echo $totals['pending']; ?></strong><span>Pending</span></div></article>
        <article><span class="assignment-stat-icon is-blue"><i class="bi bi-check2-circle"></i></span><div><strong><?php echo $totals['marked']; ?></strong><span>Marked</span></div></article>
    </section>

    <section class="panel assignment-roster-panel">
        <form class="assignment-filter-bar" method="get">
            <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
            <label class="assignment-search"><i class="bi bi-search"></i><span class="sr-only">Search by matric number</span><input type="search" name="search" value="<?php echo h($filters['search']); ?>" placeholder="Search by matric number…"></label>
            <label><span class="sr-only">Filter by status</span><select name="status"><option value="">All statuses</option><option value="pending" <?php echo $filters['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option><?php foreach (array_diff_key(assignment_test_submission_statuses(), ['draft'=>true]) as $value => $label): ?><option value="<?php echo h($value); ?>" <?php echo $filters['status'] === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option><?php endforeach; ?></select></label>
            <button class="btn secondary">Filter</button>
        </form>
        <div class="assignment-table-wrap">
            <table class="assignment-table">
                <thead><tr><th>Matric number</th><th>Submitted</th><th>Files</th><th>Status</th><th>Attempt</th><th>Score</th><th><span class="sr-only">Actions</span></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): $rowStatus = $row['status'] ?? 'pending'; ?>
                    <tr>
                        <td data-label="Matric number"><strong><?php echo h($row['matric_no']); ?></strong></td>
                        <td data-label="Submitted"><?php echo !empty($row['submitted_at']) ? h(date('M j, Y · g:i A', strtotime($row['submitted_at']))) : '—'; ?></td>
                        <td data-label="Files"><?php echo (int) ($row['current_file_count'] ?? 0); ?></td>
                        <td data-label="Status"><span class="assignment-submission-state is-<?php echo h($rowStatus); ?>"><?php echo h($rowStatus === 'pending' ? 'Pending' : assignment_test_submission_statuses()[$rowStatus]); ?></span></td>
                        <td data-label="Attempt"><?php echo (int) ($row['submission_attempt'] ?? 0); ?></td>
                        <td data-label="Score"><?php echo $row['score'] !== null ? h(assignment_test_format_marks($row['score'])) . ' / ' . h(assignment_test_format_marks($item['total_marks'])) : '—'; ?></td>
                        <td class="assignment-table-action"><?php if (!empty($row['submission_id'])): ?><a class="btn tiny secondary" href="assignment_test_mark.php?id=<?php echo (int) $item['id']; ?>&submission_id=<?php echo (int) $row['submission_id']; ?>"><?php echo assignment_test_can_mark($item, $user) ? 'Review' : 'Inspect'; ?></a><?php else: ?><span class="muted">No submission</span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="7" class="assignment-empty-cell">No finalized submissions match these filters.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
