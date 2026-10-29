<?php

/** Local-only integration QA. Uses one explicitly tagged item, removed in finally. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('session.save_path', sys_get_temp_dir());
require dirname(__DIR__) . '/includes/calendar.php';
if (DB_HOST !== '127.0.0.1' || DB_NAME !== 'kwasulcs_lcs') throw new RuntimeException('Local database required.');
$pdo = db();
$id = 0;
$checks = 0;
function calcheck(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
    echo "PASS: $label\n";
}
try {
    $course = $pdo->query("SELECT * FROM courses WHERE code='CSC 402' LIMIT 1")->fetch();
    $s = $pdo->prepare('SELECT * FROM users WHERE id=?');
    $s->execute([$course['lecturer_id']]);
    $owner = $s->fetch();
    $s = $pdo->prepare("SELECT u.* FROM users u JOIN enrollments en ON en.student_id=u.id WHERE en.course_id=? AND en.status='active' AND u.role='student' LIMIT 1");
    $s->execute([$course['id']]);
    $student = $s->fetch();
    $s = $pdo->prepare("SELECT u.* FROM users u WHERE u.role='student' AND u.department_id<>? AND NOT EXISTS(SELECT 1 FROM enrollments en WHERE en.student_id=u.id AND en.course_id=? AND en.status='active') LIMIT 1");
    $s->execute([$course['department_id'], $course['id']]);
    $outsider = $s->fetch();
    $admin = $pdo->query("SELECT * FROM users WHERE role='admin' LIMIT 1")->fetch();
    // No browser/session impersonation: an absent outsider uses a non-existent
    // principal solely to exercise the shared server-side visibility predicate.
    if (!$outsider) $outsider = ['id' => 0, 'department_id' => -1, 'role' => 'student'];
    if (!$owner || !$student || !$admin) throw new RuntimeException('Role fixture missing.');
    $input = ['course_id' => $course['id'], 'title' => 'Calendar integration QA — temporary', 'type' => 'assignment', 'instructions' => 'Local test only', 'due_at' => date('Y-m-d H:i:s', time() + 86400), 'total_marks' => 10, 'allowed_formats' => 'pdf', 'max_file_size_mb' => 1, 'allow_resubmission' => 1];
    $item = assignment_test_create_draft($input, $owner);
    $id = (int)$item['id'];
    $lookup = function () use ($pdo, $id) {
        $q = $pdo->prepare('SELECT * FROM calendar_events WHERE assignment_test_id=?');
        $q->execute([$id]);
        return $q->fetchAll();
    };
    calcheck(count($lookup()) === 0, 'draft has no calendar event');
    assignment_test_publish($id, $owner);
    $event = $lookup()[0];
    $eventId = (int)$event['id'];
    calcheck(count($lookup()) === 1, 'publish creates exactly one linked event');
    calcheck($event['start_datetime'] === $event['end_datetime'] && $event['start_datetime'] === $input['due_at'], 'due date is an instant deadline');
    assignment_test_publish($id, $owner);
    calcheck(count($lookup()) === 1 && (int)$lookup()[0]['id'] === $eventId, 'repeat publication reuses same calendar event');
    $input['title'] = str_repeat('Long calendar title ', 12);
    assignment_test_update_item($id, $input, $owner);
    calcheck((int)$lookup()[0]['id'] === $eventId, 'title edit preserves event ID');
    $input['due_at'] = date('Y-m-d H:i:s', time() + 172800);
    assignment_test_update_item($id, $input, $owner);
    calcheck($lookup()[0]['start_datetime'] === $input['due_at'] && (int)$lookup()[0]['id'] === $eventId, 'due edit moves same event');
    $feed = function ($u) use ($id) {
        return array_values(array_filter(calendar_events_for_window($u, time() - 86400, time() + 604800), fn($e) => (int)$e['assignment_test_id'] === $id));
    };
    calcheck(count($feed($student)) === 1, 'enrolled student sees linked event');
    calcheck(count($feed($owner)) === 1, 'course lecturer sees linked event');
    calcheck(count($feed($outsider)) === 0, 'unrelated department student cannot see linked event');
    calcheck($feed($owner)[0]['title'] === trim($input['title']), 'detail feed keeps full untruncated source title');
    calcheck(!$feed($owner)[0]['_can_manage'], 'calendar does not offer independent linked CRUD');
    calcheck(!cal_user_can_manage_event($event, $admin), 'ordinary admin cannot manage linked deadline via calendar');
    $expected = assignment_test_can_view(assignment_test_find($id), $admin) ? 1 : 0;
    calcheck(count($feed($admin)) === $expected, 'admin calendar visibility matches module-specific policy');
    $upcoming = upcoming_events_for($outsider, 1000);
    calcheck(!in_array($id, array_map('intval', array_column($upcoming, 'assignment_test_id')), true), 'dashboard upcoming does not leak linked deadline');
    assignment_test_close($id, $owner);
    calcheck($feed($owner)[0]['assignment_status'] === 'closed' && (int)$lookup()[0]['id'] === $eventId, 'manual close retains ID and exposes closed source status');
    assignment_test_reopen($id, $owner);
    calcheck($feed($owner)[0]['assignment_status'] === 'published' && (int)$lookup()[0]['id'] === $eventId, 'reopen retains ID and source status');
    $pdo->prepare('UPDATE assignment_tests SET due_at=? WHERE id=?')->execute([date('Y-m-d H:i:s', time() - 60), $id]);
    assignment_test_close_overdue($id);
    calcheck(assignment_test_find($id)['status'] === 'closed' && count($lookup()) === 1 && (int)$lookup()[0]['id'] === $eventId, 'automatic closure retains exactly one calendar link');
    calcheck($lookup()[0]['start_datetime'] === assignment_test_find($id)['due_at'], 'automatic closure synchronizes source due date');
    $s = $pdo->prepare('SELECT COUNT(*) FROM calendar_notifications WHERE event_id=?');
    $s->execute([$eventId]);
    calcheck((int)$s->fetchColumn() === 0, 'linked events do not duplicate Assignment/Test reminders');
} finally {
    if ($id) {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM web_notifications WHERE assignment_test_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM chat_messages WHERE assignment_test_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM assignment_tests WHERE id=? AND created_by=?')->execute([$id, $owner['id']]);
        $pdo->commit();
        $s = $pdo->prepare('SELECT COUNT(*) FROM calendar_events WHERE assignment_test_id=?');
        $s->execute([$id]);
        calcheck((int)$s->fetchColumn() === 0, 'delete cascades calendar projection; no orphan');
        echo "QA item $id and its local notifications/chat/calendar records removed.\n";
    }
}
echo "$checks integration checks passed.\n";
