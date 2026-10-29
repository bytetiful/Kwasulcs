<?php
require_once __DIR__ . '/includes/reminders.php';
require_once __DIR__ . '/includes/routes.php';
require_login();

$user    = current_user();
$role    = $user['role'];
$isStaff = in_array($role, ['admin', 'lecturer'], true);

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: calendar');
    exit();
}

// Where to redirect when done — decode any HTML entities or URL encoding first
$rawRedirect = $_POST['redirect'] ?? '';
$redirect    = html_entity_decode($rawRedirect, ENT_QUOTES, 'UTF-8');
$redirect    = urldecode($redirect);
// Only allow redirecting back to calendar pages for security
$redirect = lcs_calendar_return_url($redirect);

function flash_and_redirect(string $key, string $msg, string $url): void
{
    $_SESSION[$key] = $msg;
    header('Location: ' . $url);
    exit();
}

try {
    // ── Collect & validate fields ─────────────────────────────────────────────
    $eventId    = (int) ($_POST['event_id'] ?? 0);
    $title      = trim((string) ($_POST['title'] ?? ''));
    $eventType  = (string) ($_POST['event_type'] ?? '');
    $scope      = (string) ($_POST['scope'] ?? 'personal');
    $startDT    = trim((string) ($_POST['start_datetime'] ?? ''));
    $endDT      = trim((string) ($_POST['end_datetime'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $location   = trim((string) ($_POST['location'] ?? ''));
    $courseId   = (int) ($_POST['course_id'] ?? 0);
    $isRecurring = isset($_POST['is_recurring']) ? 1 : 0;
    $recurrRule  = (string) ($_POST['recurrence_rule'] ?? 'weekly');
    $recurrEnd   = trim((string) ($_POST['recurrence_end_date'] ?? ''));
    $notifyEmail = isset($_POST['notify_email']) ? 1 : 0;
    $notifyWeb   = isset($_POST['notify_web'])   ? 1 : 0;
    $remindMins  = (int) ($_POST['remind_minutes_before'] ?? 30);

    // ── Basic validation ──────────────────────────────────────────────────────
    if ($title === '') {
        throw new RuntimeException('Event title is required.');
    }

    $allowedTypes = ['lecture', 'tutorial', 'exam', 'test', 'personal', 'general'];
    if (!in_array($eventType, $allowedTypes, true)) {
        throw new RuntimeException('Invalid event type.');
    }

    $allowedScopes = ['personal', 'course', 'global'];
    if (!in_array($scope, $allowedScopes, true)) {
        throw new RuntimeException('Invalid scope.');
    }

    // Non-staff can only create personal events
    if (!$isStaff && $scope !== 'personal') {
        $scope = 'personal';
    }

    // Only admin can create global events
    if ($scope === 'global' && $role !== 'admin') {
        throw new RuntimeException('Only admins can create global events.');
    }

    // Course scope requires a course
    if ($scope === 'course') {
        if ($courseId <= 0) {
            throw new RuntimeException('Please select a course for this event.');
        }
        // Verify the user can manage this course
        if (!cal_can_create_course_event($user, $courseId)) {
            throw new RuntimeException('You do not have permission to create events for this course.');
        }
    } else {
        $courseId = null; // ensure NULL in DB for non-course events
    }

    // Validate datetime format
    $startTS = strtotime($startDT);
    $endTS   = strtotime($endDT);
    if (!$startTS || !$endTS) {
        throw new RuntimeException('Invalid start or end date/time.');
    }
    if ($endTS <= $startTS) {
        throw new RuntimeException('End time must be after start time.');
    }

    // Normalise to MySQL DATETIME format
    $startDT = date('Y-m-d H:i:s', $startTS);
    $endDT   = date('Y-m-d H:i:s', $endTS);

    // Recurrence
    $allowedRules = ['daily', 'weekly', 'biweekly', 'monthly'];
    if (!in_array($recurrRule, $allowedRules, true)) {
        $recurrRule = 'weekly';
    }
    $recurrEndSQL = ($isRecurring && $recurrEnd !== '') ? $recurrEnd : null;

    if ($remindMins < 0 || $remindMins > 10080) throw new RuntimeException('Reminder offset must be between 0 and 10080 minutes.');
    if ($eventId > 0) reminder_event_lock($eventId);
    db()->beginTransaction();

    // ── Insert or update ──────────────────────────────────────────────────────
    if ($eventId > 0) {
        // Edit: check ownership / permission
        $chk = db()->prepare('SELECT * FROM calendar_events WHERE id = ? LIMIT 1');
        $chk->execute([$eventId]);
        $existing = $chk->fetch();

        if (!$existing) {
            throw new RuntimeException('Event not found.');
        }
        if (!empty($existing['assignment_test_id']) || !empty($existing['online_quiz_id'])) {
            throw new RuntimeException('Manage this deadline from its Assignment/Test page.');
        }

        // The event's own creator can always edit it; beyond that, staff can
        // only edit course/global events within their OWN department — not
        // blanket "any staff can edit any event" any more (Task 19 Part B:
        // no cross-department admin authority).
        $canEdit = (int) $existing['created_by'] === (int) $user['id']
            || ($isStaff && cal_user_can_manage_event($existing, $user));
        if (!$canEdit) {
            throw new RuntimeException('You do not have permission to edit this event.');
        }

        db()->prepare('
            UPDATE calendar_events SET
                title = ?, event_type = ?, scope = ?, course_id = ?,
                start_datetime = ?, end_datetime = ?,
                description = ?, location = ?,
                is_recurring = ?, recurrence_rule = ?, recurrence_end_date = ?,
                notify_email = ?, notify_web = ?, remind_minutes_before = ?,
                updated_at = NOW()
            WHERE id = ?
        ')->execute([
            $title,
            $eventType,
            $scope,
            $courseId,
            $startDT,
            $endDT,
            $description,
            $location,
            $isRecurring,
            $isRecurring ? $recurrRule : null,
            $recurrEndSQL,
            $notifyEmail,
            $notifyWeb,
            $remindMins,
            $eventId,
        ]);

        reminder_sync_event($eventId);
        db()->commit();
        reminder_event_unlock($eventId);

        // Task 6 — a fresh, immediate "Event Updated" notice, separate from
        // the scheduled reminder rescheduled just above. Built from the
        // just-saved values rather than re-querying, since they're already
        // validated/normalised in scope here.
        send_event_update_notification([
            'id'             => $eventId,
            'title'          => $title,
            'event_type'     => $eventType,
            'scope'          => $scope,
            'course_id'      => $courseId,
            'start_datetime' => $startDT,
            'location'       => $location,
            'description'    => $description,
            'notify_email'   => $notifyEmail,
            'notify_web'     => $notifyWeb,
            'created_by'     => (int) $existing['created_by'],
        ], $user);

        flash_and_redirect('cal_flash', 'Event updated.', $redirect);
    } else {
        // Create
        $stmt = db()->prepare('
            INSERT INTO calendar_events
                (title, event_type, scope, course_id,
                 start_datetime, end_datetime,
                 description, location, created_by,
                 is_recurring, recurrence_rule, recurrence_end_date,
                 notify_email, notify_web, remind_minutes_before)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ');
        $stmt->execute([
            $title,
            $eventType,
            $scope,
            $courseId,
            $startDT,
            $endDT,
            $description,
            $location,
            $user['id'],
            $isRecurring,
            $isRecurring ? $recurrRule : null,
            $recurrEndSQL,
            $notifyEmail,
            $notifyWeb,
            $remindMins,
        ]);
        $newId = (int) db()->lastInsertId();

        reminder_sync_event($newId);
        db()->commit();

        flash_and_redirect('cal_flash', 'Event created successfully.', $redirect);
    }
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    if (!empty($eventId)) reminder_event_unlock($eventId);
    flash_and_redirect('cal_error', $e->getMessage(), $redirect);
}

// ── Notification helpers ──────────────────────────────────────────────────────

/**
 * Collect audience user IDs for an event scope.
 */
function collect_audience(int $creatorId, string $scope, ?int $courseId): array
{
    return reminder_audience(['created_by'=>$creatorId,'scope'=>$scope,'course_id'=>$courseId]);
}

/**
 * Task 6 — immediate "Event Updated" notice, sent once at edit time.
 *
 * Deliberately separate from scheduled reminder delivery jobs:
 * this is a single, synchronous send triggered directly by one edit POST
 * request, not a polled/claimed row — there's no concurrent-firing race to
 * guard against here the way there is for the cron/poll-driven reminder
 * system, so no atomic claim is needed (or possible, since there's no
 * pre-existing row to claim against).
 *
 * Reuses collect_audience() — the same recipients who'd get a reminder for
 * this event — minus the editor themselves (they already know what they
 * just changed).
 */
function send_event_update_notification(array $ev, array $editedBy): void
{
    if (!$ev['notify_email'] && !$ev['notify_web']) {
        return; // notifications disabled for this event — respect that here too
    }

    $courseCode = null;
    if (!empty($ev['course_id'])) {
        $c = db()->prepare('SELECT code FROM courses WHERE id = ?');
        $c->execute([$ev['course_id']]);
        $courseCode = $c->fetchColumn() ?: null;
    }

    $row = [
        'event_title'    => $ev['title'],
        'event_type'     => $ev['event_type'],
        'start_datetime' => $ev['start_datetime'],
        'location'       => $ev['location'],
        'description'    => $ev['description'],
        'course_code'    => $courseCode,
    ];

    $userIds = collect_audience($ev['created_by'], $ev['scope'], $ev['course_id']);
    $userIds = array_diff($userIds, [(int) $editedBy['id']]);
    if (!$userIds) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $stmt = db()->prepare("
        SELECT id, email, full_name, pref_email_notifications, pref_web_notifications
        FROM users WHERE id IN ($placeholders)
    ");
    $stmt->execute(array_values($userIds));
    $recipients = $stmt->fetchAll();

    $subject = "[KWASU LCS] Event Updated: {$ev['title']}";
    $html    = event_update_email_html($row);

    foreach ($recipients as $u) {
        if ($ev['notify_web'] && $u['pref_web_notifications']) {
            db()->prepare('INSERT INTO web_notifications (user_id, event_id, message) VALUES (?, ?, ?)')
                ->execute([$u['id'], $ev['id'], event_update_short_message($row)]);
        }
        if ($ev['notify_email'] && $u['email'] && $u['pref_email_notifications']) {
            send_mail($u['email'], $u['full_name'], $subject, $html);
        }
    }
}
