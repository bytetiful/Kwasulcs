<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/assignment_test.php';
require_login();

$user = current_user();
$item = assignment_test_find((int) ($_GET['id'] ?? 0));
if (!$item || !assignment_test_can_view_audit($item, $user)) { http_response_code(403); exit('Audit history is unavailable.'); }
$entries = assignment_test_audit_entries($item, $user, 150);
assignment_test_record_audit((int) $item['id'], 'audit_viewed', (int) $user['id'], null, ['entries_visible' => count($entries)]);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php $pageTitle = 'Audit Log — ' . $item['title']; $extraCss = ['assets/assignment-test.css']; include __DIR__ . '/partials/head.php'; ?></head>
<body class="app-body">
<?php $assignmentTestPageItem = $item; include __DIR__ . '/partials/nav.php'; ?>
<?php include __DIR__ . '/partials/appheader.php'; $item = $assignmentTestPageItem; ?>
<main class="page assignment-test-page">
    <nav class="assignment-breadcrumb" aria-label="Breadcrumb"><a href="assignment_tests">Assignment/Test</a><i class="bi bi-chevron-right"></i><a href="assignment_test?id=<?php echo (int) $item['id']; ?>"><?php echo h($item['course_code']); ?></a><i class="bi bi-chevron-right"></i><span>Audit Log</span></nav>
    <header class="assignment-page-header assignment-page-header--back"><a class="assignment-back" href="assignment_test?id=<?php echo (int) $item['id']; ?>" aria-label="Back to Assignment/Test"><i class="bi bi-arrow-left"></i></a><div><h1>Audit Log</h1><p class="muted"><?php echo h($item['title']); ?> · <?php echo h($item['course_code']); ?></p></div></header>
    <section class="panel assignment-audit-panel">
        <div class="assignment-admin-note"><i class="bi bi-shield-check"></i> Lifecycle history is append-only. Sensitive paths and credentials are excluded from audit metadata.</div>
        <ol class="assignment-audit-list">
            <?php foreach ($entries as $entry): $actor = $entry['actor_role'] === 'student' ? $entry['actor_matric_no'] : ($entry['actor_name'] ?: 'System'); ?>
            <li><span class="assignment-audit-icon"><i class="bi bi-clock-history"></i></span><div><strong><?php echo h(ucwords(str_replace(['_', '.'], ' ', $entry['event_type']))); ?></strong><p><?php echo h($actor ?: 'System'); ?><?php echo $entry['actor_role'] ? ' · ' . h(ucfirst($entry['actor_role'])) : ''; ?><?php echo $entry['submission_id'] ? ' · Submission #' . (int) $entry['submission_id'] : ''; ?></p><?php if (!empty($entry['metadata_json'])): ?><details><summary>Details</summary><code><?php echo h($entry['metadata_json']); ?></code></details><?php endif; ?></div><time datetime="<?php echo h(date(DATE_ATOM, strtotime($entry['created_at']))); ?>"><?php echo h(date('M j, Y · g:i:s A', strtotime($entry['created_at']))); ?></time></li>
            <?php endforeach; ?>
            <?php if (!$entries): ?><li class="assignment-empty-cell">No audit events yet.</li><?php endif; ?>
        </ol>
    </section>
</main>
</body>
</html>
