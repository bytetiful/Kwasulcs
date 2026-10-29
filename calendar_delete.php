<?php
require_once __DIR__ . '/includes/reminders.php';
require_once __DIR__ . '/includes/routes.php';
require_login();

$user    = current_user();
$isStaff = in_array($user['role'], ['admin', 'lecturer'], true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: calendar');
    exit();
}

$eventId     = (int) ($_POST['event_id'] ?? 0);
$rawRedirect = $_POST['redirect'] ?? '';
$redirect    = html_entity_decode($rawRedirect, ENT_QUOTES, 'UTF-8');
$redirect    = urldecode($redirect);
$redirect = lcs_calendar_return_url($redirect);

try {
    if ($eventId <= 0) {
        throw new RuntimeException('Invalid event.');
    }

    $stmt = db()->prepare('SELECT * FROM calendar_events WHERE id = ? LIMIT 1');
    $stmt->execute([$eventId]);
    $event = $stmt->fetch();

    if (!$event) {
        throw new RuntimeException('Event not found.');
    }
    if (!empty($event['assignment_test_id']) || !empty($event['online_quiz_id'])) {
        throw new RuntimeException('Linked deadlines are managed from Assignment/Test, not deleted independently.');
    }

    // The event's own creator can always delete it; beyond that, staff can
    // only delete course/global events within their OWN department — see
    // cal_user_can_manage_event() in config.php (Task 19 Part B: no
    // cross-department admin authority).
    $canDelete = (int) $event['created_by'] === (int) $user['id']
        || ($isStaff && cal_user_can_manage_event($event, $user));
    if (!$canDelete) {
        throw new RuntimeException('You do not have permission to delete this event.');
    }

    reminder_event_lock($eventId);
    db()->beginTransaction();
    reminder_cancel_event($eventId);
    // Legacy notification rows still cascade; new delivery history is retained.
    db()->prepare('DELETE FROM calendar_events WHERE id = ?')->execute([$eventId]);
    db()->commit();
    reminder_event_unlock($eventId);

    $_SESSION['cal_flash'] = 'Event deleted.';
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    if ($eventId > 0) reminder_event_unlock($eventId);
    $_SESSION['cal_error'] = $e->getMessage();
}

header('Location: ' . $redirect);
exit();
