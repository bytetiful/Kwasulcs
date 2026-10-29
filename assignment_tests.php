<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/assessments.php';
require_login();

$user = current_user();
$courses = assignment_test_courses_owned_by($user);
$flash = assignment_test_take_flash();
$editItem = null;
if (!empty($_GET['edit'])) {
    $candidate = assignment_test_find((int) $_GET['edit']);
    if ($candidate && assignment_test_can_manage($candidate, $user)) $editItem = $candidate;
}
$snapshot = assessment_snapshot($user);
// Lecturer/editor views contain reusable mutation controls and are deliberately
// network-only. The learner's read-oriented assessment list may be cached.
if (!$courses && !$editItem) {
    require_once __DIR__ . '/includes/pwa.php';
    pwa_mark_cacheable_page((int)$user['id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php $pageTitle = 'Assignments & Tests'; $extraCss = ['assets/assignment-test.css','assets/assessments.css']; include __DIR__ . '/partials/head.php'; ?>
</head>
<body class="app-body">
<?php include __DIR__ . '/partials/nav.php'; ?>
<?php include __DIR__ . '/partials/appheader.php'; ?>

<main class="page assignment-test-page assessment-page" data-assignment-live data-unified-assessments>
    <a class="assessment-context-label" href="chat"><i class="bi bi-chat-dots-fill" aria-hidden="true"></i><span>Assessment/Chat</span></a>
    <header class="assignment-page-header">
        <div><h1>Assignments &amp; Tests</h1><p class="muted"><?php echo $courses ? 'Create, publish and review all course assessments in one place.' : 'View course assessments and manage your submissions.'; ?></p></div>
        <?php if ($courses): ?><details class="assessment-create"><summary class="btn primary"><i class="bi bi-plus-circle" aria-hidden="true"></i> Create assessment</summary><div><a href="#assignment-editor" onclick="document.getElementById('assignment-editor').open=true;this.closest('details').open=false">Assignment/Test</a><a href="online_quiz_edit">Online Quiz/Test</a></div></details><?php endif; ?>
    </header>

    <?php if ($flash): ?><div class="assignment-alert assignment-alert--<?php echo h($flash['type']); ?>" role="status"><?php echo h($flash['message']); ?></div><?php endif; ?>

    <section class="assignment-stats" aria-label="Assessment totals">
        <?php foreach (['all'=>['Total','is-green','bi-file-earmark-text'], 'assignment'=>['Assignments','is-blue','bi-file-earmark-arrow-up'], 'quiz'=>['Online Quizzes','is-amber','bi-check2-circle'], 'active'=>['Active Now','is-purple','bi-clock']] as $filter => $style): ?>
        <div class="assignment-stat-filter"><span class="assignment-stat-icon <?php echo h($style[1]); ?>"><i class="bi <?php echo h($style[2]); ?>" aria-hidden="true"></i></span><span><strong data-count="<?php echo h($filter); ?>"><?php echo (int) $snapshot['counts'][$filter]; ?></strong><span><?php echo h($style[0]); ?></span></span></div>
        <?php endforeach; ?>
    </section>
    <p id="assignment-live-status" class="muted assessment-sr" role="status" aria-live="polite"></p>

    <?php if ($courses): ?>
    <details class="panel assignment-editor" id="assignment-editor" <?php echo $editItem ? 'open' : ''; ?>>
        <summary><span><i class="bi bi-file-earmark-plus"></i> <?php echo $editItem ? 'Edit Assignment/Test' : 'Create Assignment/Test'; ?></span><i class="bi bi-chevron-down"></i></summary>
        <form method="post" action="assignment_test_action.php" class="assignment-form" data-assignment-action>
            <input type="hidden" name="csrf" value="<?php echo h(assignment_test_csrf_token()); ?>">
            <input type="hidden" name="action" value="<?php echo $editItem ? 'update' : 'create'; ?>">
            <?php if ($editItem): ?><input type="hidden" name="assignment_test_id" value="<?php echo (int) $editItem['id']; ?>"><?php endif; ?>
            <label>Title <span aria-hidden="true">*</span><input name="title" maxlength="255" required value="<?php echo h($editItem['title'] ?? ''); ?>"></label>
            <div class="assignment-form-grid">
                <label>Type <span aria-hidden="true">*</span><select name="type" required <?php echo $editItem && $editItem['status'] !== 'draft' ? 'disabled' : ''; ?>><option value="assignment" <?php echo ($editItem['type'] ?? '') === 'assignment' ? 'selected' : ''; ?>>Assignment</option><option value="test" <?php echo ($editItem['type'] ?? '') === 'test' ? 'selected' : ''; ?>>Test</option></select></label>
                <?php if ($editItem && $editItem['status'] !== 'draft'): ?><input type="hidden" name="type" value="<?php echo h($editItem['type']); ?>"><?php endif; ?>
                <label>Course <span aria-hidden="true">*</span><select name="course_id" required <?php echo $editItem && $editItem['status'] !== 'draft' ? 'disabled' : ''; ?>><?php foreach ($courses as $course): ?><option value="<?php echo (int) $course['id']; ?>" <?php echo (int) ($editItem['course_id'] ?? 0) === (int) $course['id'] ? 'selected' : ''; ?>><?php echo h($course['code'] . ' — ' . $course['title']); ?></option><?php endforeach; ?></select></label>
                <?php if ($editItem && $editItem['status'] !== 'draft'): ?><input type="hidden" name="course_id" value="<?php echo (int) $editItem['course_id']; ?>"><?php endif; ?>
                <label>Due date and time <span aria-hidden="true">*</span><input type="datetime-local" name="due_at" required value="<?php echo $editItem ? h(date('Y-m-d\TH:i', strtotime($editItem['due_at']))) : ''; ?>"></label>
                <label>Total marks <span aria-hidden="true">*</span><input type="number" name="total_marks" min="0.01" step="0.01" required value="<?php echo h((string) ($editItem['total_marks'] ?? '')); ?>"></label>
                <label>Allowed formats<input name="allowed_formats" placeholder="pdf, docx, zip" value="<?php echo h($editItem['allowed_formats'] ?? 'pdf,doc,docx,ppt,pptx,zip'); ?>"><small>Leave blank to allow every globally supported format.</small></label>
                <label>Maximum file size (MB)<input type="number" name="max_file_size_mb" min="1" max="20" value="<?php echo $editItem ? max(1, (int) round($editItem['max_file_size'] / 1048576)) : 20; ?>"></label>
            </div>
            <label>Instructions <span aria-hidden="true">*</span><textarea name="instructions" rows="6" required><?php echo h($editItem['instructions'] ?? ''); ?></textarea></label>
            <div class="assignment-checks">
                <label><input type="checkbox" name="allow_late_submissions" value="1" <?php echo !empty($editItem['allow_late_submissions']) ? 'checked' : ''; ?>> Allow late submissions</label>
                <label><input type="checkbox" name="allow_resubmission" value="1" <?php echo !empty($editItem['allow_resubmission']) ? 'checked' : ''; ?>> Allow replacement/resubmission before deadline</label>
            </div>
            <?php if ($editItem && $editItem['status'] === 'closed'): ?><p class="muted">To reopen expired work, save a future due date here, then choose Reopen in the course work list. Saving changes alone will not reopen it.</p><?php endif; ?>
            <div class="assignment-form-actions"><?php if ($editItem && $editItem['status'] !== 'draft'): ?><button class="btn primary" name="intent" value="save">Save Changes</button><?php else: ?><button class="btn secondary" name="intent" value="save">Save Draft</button><button class="btn primary" name="intent" value="publish"><i class="bi bi-send"></i> Save &amp; Publish</button><?php endif; ?></div>
        </form>
    </details>
    <?php endif; ?>

    <section class="panel assignment-list-panel">
        <?php include __DIR__.'/partials/assessment_filters.php'; ?>
        <div class="assessment-columns" aria-hidden="true"><span>Assessment</span><span>Course</span><span>Due date</span><span>Marks</span><span>Status</span><span>Actions</span></div>
        <div class="assignment-list" id="assignment-live-list"><?php echo $snapshot['html']; ?></div>
        <p class="assignment-empty" id="assignment-filter-empty" <?php echo $snapshot['counts']['all'] ? 'hidden' : ''; ?>>No assessments match these filters.</p>
        <footer class="assessment-footer"><span id="assessment-showing"></span><nav aria-label="Assessment pages"><button type="button" data-assessment-page="previous" aria-label="Previous page"><i class="bi bi-chevron-left" aria-hidden="true"></i></button><span id="assessment-page-number" aria-current="page">1</span><button type="button" data-assessment-page="next" aria-label="Next page"><i class="bi bi-chevron-right" aria-hidden="true"></i></button></nav></footer>
    </section>
</main>
<script src="assets/assignment-test.js?v=<?php echo (int) filemtime(__DIR__ . '/assets/assignment-test.js'); ?>" defer></script>
</body>
</html>
