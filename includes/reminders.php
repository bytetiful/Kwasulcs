<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/telegram/notifications.php';
require_once __DIR__ . '/notifications/fanout.php';

/** Scheduling may run from writes; dispatch is exclusively CLI worker-owned. */
const REMINDER_MAX_LATENESS = 7200;
const REMINDER_HORIZON_DAYS = 90;
const REMINDER_LEASE_SECONDS = 900;

function reminder_worker_only(): void
{
    if (PHP_SAPI !== 'cli') throw new RuntimeException('Reminder dispatch requires CLI execution.');
}

function reminder_sql_time(int $time): string
{
    return date('Y-m-d H:i:s', $time);
}

/** Stable ordinal identity survives time/offset edits. Sent ordinals never resend. */
function reminder_occurrences(array $event, int $from, int $until): array
{
    $start = strtotime($event['start_datetime']);
    $duration = max(0, strtotime($event['end_datetime']) - $start);
    if (empty($event['is_recurring']) || empty($event['recurrence_rule'])) {
        return $start + $duration >= $from && $start <= $until ? [['key' => '0', 'start' => $start, 'end' => $start + $duration]] : [];
    }
    $end = empty($event['recurrence_end_date']) ? $until : min($until, strtotime($event['recurrence_end_date'] . ' 23:59:59'));
    $rule = $event['recurrence_rule'];
    $step = ['daily' => 86400, 'weekly' => 604800, 'biweekly' => 1209600][$rule] ?? 0;
    if ($rule !== 'monthly' && !$step) throw new RuntimeException('Unsupported recurrence rule.');
    $index = $step ? max(0, (int)floor(($from - $duration - $start) / $step)) : max(
        0,
        ((int)date('Y', $from) - (int)date('Y', $start)) * 12 + (int)date('n', $from) - (int)date('n', $start) - 1
    );
    $result = [];
    for ($guard = 0; $guard < 10000; $guard++, $index++) {
        if ($step) $cursor = $start + $index * $step;
        else {
            // Same day-of-month; skip months without that date (do not drift).
            $month = (int)date('n', $start) - 1 + $index;
            $year = (int)date('Y', $start) + intdiv($month, 12);
            $month = $month % 12 + 1;
            $day = (int)date('j', $start);
            if (mktime(0, 0, 0, $month, 1, $year) > $end) break;
            if (!checkdate($month, $day, $year)) continue;
            $cursor = mktime((int)date('H', $start), (int)date('i', $start), (int)date('s', $start), $month, $day, $year);
        }
        if ($cursor > $end) break;
        if ($cursor + $duration >= $from) $result[] = ['key' => (string)$index, 'start' => $cursor, 'end' => $cursor + $duration];
    }
    return $result;
}

/** Existing personal / department-global / course audience, active enrollments only. */
function reminder_audience(array $event): array
{
    $pdo = db();
    if ($event['scope'] === 'course' && !empty($event['course_id'])) {
        $q = $pdo->prepare("SELECT student_id AS id FROM enrollments WHERE course_id=? AND status='active'
            UNION SELECT lecturer_id FROM courses WHERE id=? UNION SELECT id FROM users WHERE id=?");
        $q->execute([$event['course_id'], $event['course_id'], $event['created_by']]);
    } elseif ($event['scope'] === 'global') {
        $q = $pdo->prepare('SELECT id FROM users WHERE department_id=(SELECT department_id FROM users WHERE id=?) OR id=?');
        $q->execute([$event['created_by'], $event['created_by']]);
    } else {
        $q = $pdo->prepare('SELECT id FROM users WHERE id=?');
        $q->execute([$event['created_by']]);
    }
    return array_values(array_unique(array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN))));
}

function reminder_channel_allowed(array $event, array $user, string $channel): bool
{
    return match ($channel) {
        'web' => !empty($event['notify_web']) && !empty($user['pref_web_notifications']),
        'email' => !empty($event['notify_email']) && !empty($user['pref_email_notifications']) && filter_var($user['email'], FILTER_VALIDATE_EMAIL) !== false,
        default => false, // A future adapter must explicitly define consent/eligibility.
    };
}

function reminder_schedule_hash(array $event, array $occ, int $scheduled): string
{
    return hash('sha256', json_encode([
        $occ,
        $scheduled,
        $event['scope'],
        $event['course_id'],
        $event['created_by'],
        $event['notify_web'],
        $event['notify_email'],
        $event['recurrence_rule'],
        $event['recurrence_end_date']
    ]));
}

/** Called in event-save transaction and by worker for rolling horizon / new members. */
function reminder_sync_event(int $eventId, ?int $now = null): void
{
    $now ??= time();
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $q = $pdo->prepare('SELECT * FROM calendar_events WHERE id=? FOR UPDATE');
        $q->execute([$eventId]);
        $event = $q->fetch();
        if (!$event || !empty($event['assignment_test_id'])) {
            if ($own) $pdo->commit();
            return;
        }
        if (!empty($event['online_quiz_id'])) {
            $quizQuery = $pdo->prepare('SELECT q.status,q.visibility,q.opens_at,q.closes_at,q.duration_minutes,q.attempts_allowed,q.settings_json,
                (SELECT COUNT(*) FROM online_quiz_questions qq WHERE qq.quiz_id=q.id) AS question_count
                FROM online_quizzes q WHERE q.id=?');
            $quizQuery->execute([$event['online_quiz_id']]);
            $quizState = $quizQuery->fetch();
            if (!$quizState || $quizState['status'] !== 'published' || $quizState['visibility'] !== 'enrolled') {
                $pdo->prepare("UPDATE reminder_delivery_jobs SET status='cancelled',last_error='Online quiz unavailable' WHERE source_type='calendar' AND source_id=? AND status IN ('pending','failed','processing')")->execute([$eventId]);
                telegram_cancel_source_jobs('calendar', $eventId, 'Online quiz reminder is no longer valid.');
                if ($own) $pdo->commit();
                return;
            }
        }
        $occurrences = reminder_occurrences($event, $now - REMINDER_MAX_LATENESS, $now + REMINDER_HORIZON_DAYS * 86400);
        $audience = reminder_audience($event);
        $q = $pdo->prepare('SELECT * FROM reminder_delivery_jobs WHERE source_type=\'calendar\' AND source_id=? FOR UPDATE');
        $q->execute([$eventId]);
        $existing = [];
        foreach ($q->fetchAll() as $j) $existing[$j['occurrence_key'] . ':' . $j['user_id'] . ':' . $j['channel']] = $j;
        $wanted = [];
        $userQ = $pdo->prepare('SELECT * FROM users WHERE id=?');
        $insert = $pdo->prepare('INSERT INTO reminder_delivery_jobs
            (source_id,occurrence_key,occurrence_at,occurrence_end_at,user_id,channel,scheduled_at,expires_at,status,schedule_hash,last_error)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($audience as $uid) {
            $userQ->execute([$uid]);
            $user = $userQ->fetch();
            if (!$user) continue;
            foreach ($occurrences as $occ) foreach (['web', 'email'] as $channel) {
                $key = $occ['key'] . ':' . $uid . ':' . $channel;
                $wanted[$key] = true;
                $old = $existing[$key] ?? null;
                // Editing a delivered occurrence must never send it again.
                if ($old && $old['status'] === 'sent') continue;
                $scheduled = $occ['start'] - max(0, (int)$event['remind_minutes_before']) * 60;
                // Clamp only events created after the nominal reminder time, not every worker run.
                $scheduled = max($scheduled, strtotime($event['created_at']));
                $expires = min($scheduled + REMINDER_MAX_LATENESS, $occ['end']);
                $allowed = reminder_channel_allowed($event, $user, $channel);
                $status = $allowed && $expires >= $now ? 'pending' : 'cancelled';
                $reason = !$allowed ? 'channel_disabled' : ($expires < $now ? 'stale' : null);
                $hash = reminder_schedule_hash($event, $occ, $scheduled);
                if (!$old) $insert->execute([
                    $eventId,
                    $occ['key'],
                    reminder_sql_time($occ['start']),
                    reminder_sql_time($occ['end']),
                    $uid,
                    $channel,
                    reminder_sql_time($scheduled),
                    reminder_sql_time($expires),
                    $status,
                    $hash,
                    $reason
                ]);
                elseif (
                    $old['schedule_hash'] !== $hash || ($status === 'cancelled' && $old['status'] !== 'cancelled')
                    || ($status === 'pending' && in_array($old['status'], ['cancelled', 'superseded'], true) && in_array($old['last_error'], ['channel_disabled', 'audience_changed', 'schedule_changed'], true))
                ) {
                    $pdo->prepare('UPDATE reminder_delivery_jobs SET occurrence_at=?,occurrence_end_at=?,scheduled_at=?,expires_at=?,
                        status=?,schedule_hash=?,last_error=?,next_retry_at=NULL,claim_token=NULL,lease_expires_at=NULL,claimed_at=NULL,attempts=0 WHERE id=?')
                        ->execute([reminder_sql_time($occ['start']), reminder_sql_time($occ['end']), reminder_sql_time($scheduled), reminder_sql_time($expires), $status, $hash, $reason, $old['id']]);
                }
            }
        }
        foreach ($existing as $key => $old) {
            if (isset($wanted[$key]) || in_array($old['status'], ['sent', 'cancelled', 'superseded'], true)) continue;
            $reason = in_array((int)$old['user_id'], $audience, true) ? 'schedule_changed' : 'audience_changed';
            $pdo->prepare("UPDATE reminder_delivery_jobs SET status='superseded',last_error=?,claim_token=NULL,lease_expires_at=NULL WHERE id=?")
                ->execute([$reason, $old['id']]);
        }
        // Telegram remains an independent optional sibling of web/email jobs.
        // One recurrence ordinal and recipient produce one stable delivery.
        $telegramKeep = [];
        if (!empty($event['notify_web']) || !empty($event['notify_email'])) {
            $courseCode = 'LCS';
            $courseTitle = '';
            $lecturerName = '';
            if (!empty($event['course_id'])) {
                $courseQ = $pdo->prepare('SELECT c.code,c.title,u.full_name AS lecturer_name FROM courses c JOIN users u ON u.id=c.lecturer_id WHERE c.id=?');
                $courseQ->execute([$event['course_id']]);
                $courseRow = $courseQ->fetch();
                $courseCode = (string)($courseRow['code'] ?? 'LCS');
                $courseTitle = (string)($courseRow['title'] ?? '');
                $lecturerName = (string)($courseRow['lecturer_name'] ?? '');
            }
            foreach ($audience as $uid) {
                foreach ($occurrences as $occ) {
                    $scheduled = $occ['start'] - max(0, (int)$event['remind_minutes_before']) * 60;
                    $scheduled = max($scheduled, strtotime($event['created_at']));
                    $expires = min($scheduled + REMINDER_MAX_LATENESS, $occ['end']);
                    if ($expires < $now) continue;
                    $logicalKey = 'occurrence:' . $occ['key'];
                    $type = !empty($event['online_quiz_id']) ? 'online_quiz_reminder' : ((string)($event['event_type'] ?? '') === 'lecture' ? 'lecture_reminder' : 'calendar_reminder');
                    $result = lcs_notification_fanout($type, (int)$uid, [
                        'course_code' => $courseCode,
                        'course_title' => $courseTitle,
                        'assessment_type' => !empty($event['online_quiz_id']) ? 'Online Quiz' : ((string)($event['event_type'] ?? '') === 'lecture' ? 'Lecture' : 'Calendar Event'),
                        'title' => (string)$event['title'],
                        'starts_at' => reminder_sql_time($occ['start']),
                        'ends_at' => reminder_sql_time($occ['end']),
                        'opens_at' => !empty($event['online_quiz_id']) ? (string)($quizState['opens_at'] ?? reminder_sql_time($occ['start'])) : '',
                        'closes_at' => !empty($event['online_quiz_id']) ? (string)($quizState['closes_at'] ?? reminder_sql_time($occ['end'])) : '',
                        'duration_minutes' => !empty($event['online_quiz_id']) ? (int)($quizState['duration_minutes'] ?? 0) : 0,
                        'question_count' => !empty($event['online_quiz_id']) ? (int)($quizState['question_count'] ?? 0) : 0,
                        'attempts_allowed' => !empty($event['online_quiz_id']) ? (int)($quizState['attempts_allowed'] ?? 0) : 0,
                        'secure' => !empty($event['online_quiz_id']) && !empty((json_decode((string)($quizState['settings_json'] ?? '{}'), true) ?: [])['secure']),
                        'location' => (string)($event['location'] ?? ''),
                        'description' => (string)($event['description'] ?? ''),
                        'lecturer' => $lecturerName,
                        'remind_minutes_before' => (int)($event['remind_minutes_before'] ?? 0),
                        'action_url' => !empty($event['online_quiz_id'])
                            ? 'online_quiz?id=' . (int)$event['online_quiz_id']
                            : 'calendar?date=' . date('Y-m-d', $occ['start']) . '&view=day',
                    ], 'calendar', $eventId, $logicalKey, reminder_sql_time($scheduled));
                    if (in_array($result['channels']['telegram']['status'] ?? '', ['queued', 'existing'], true))
                        $telegramKeep[] = telegram_delivery_idempotency_key('calendar', $eventId, (int)$uid, $type, $logicalKey);
                }
            }
        }
        telegram_cancel_source_jobs('calendar', $eventId, 'Calendar reminder was cancelled or superseded.', $telegramKeep);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function reminder_cancel_event(int $eventId): void
{
    db()->prepare("UPDATE reminder_delivery_jobs SET status='cancelled',last_error='event_deleted',claim_token=NULL,lease_expires_at=NULL
        WHERE source_type='calendar' AND source_id=? AND status<>'sent'")->execute([$eventId]);
    telegram_cancel_source_jobs('calendar', $eventId, 'Calendar event was deleted or cancelled.');
}

/** One-time conservative import. Old sent flags are retained; never replay them. */
function reminder_import_legacy(): int
{
    reminder_worker_only();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $health = $pdo->query('SELECT * FROM reminder_worker_health WHERE id=1 FOR UPDATE')->fetch();
        if ($health['legacy_imported_at']) {
            $pdo->commit();
            return 0;
        }
        $rows = $pdo->query('SELECT cn.*, ce.start_datetime,ce.end_datetime,ce.is_recurring,ce.recurrence_rule,ce.recurrence_end_date,
            ce.remind_minutes_before FROM calendar_notifications cn JOIN calendar_events ce ON ce.id=cn.event_id
            WHERE ce.assignment_test_id IS NULL ORDER BY cn.id')->fetchAll();
        $n = 0;
        foreach ($rows as $row) {
            $target = strtotime($row['scheduled_at']) + (int)$row['remind_minutes_before'] * 60;
            $occs = reminder_occurrences($row, $target - 62 * 86400, $target + 62 * 86400);
            usort($occs, fn($a, $b) => abs($a['start'] - $target) <=> abs($b['start'] - $target));
            $occ = $occs[0] ?? ['key' => '0', 'start' => strtotime($row['start_datetime']), 'end' => strtotime($row['end_datetime'])];
            foreach (['web', 'email'] as $channel) {
                $sent = (bool)$row['sent_' . $channel];
                $pdo->prepare('INSERT INTO reminder_delivery_jobs
                    (source_id,occurrence_key,occurrence_at,occurrence_end_at,user_id,channel,scheduled_at,expires_at,status,schedule_hash,legacy_notification_id,last_error)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE
                    status=IF(VALUES(status)=\'sent\',\'sent\',status),legacy_notification_id=VALUES(legacy_notification_id)')->execute([
                    $row['event_id'],
                    $occ['key'],
                    reminder_sql_time($occ['start']),
                    reminder_sql_time($occ['end']),
                    $row['user_id'],
                    $channel,
                    $row['scheduled_at'],
                    reminder_sql_time(min(strtotime($row['scheduled_at']) + REMINDER_MAX_LATENESS, $occ['end'])),
                    $sent ? 'sent' : 'pending',
                    'legacy',
                    $row['id'],
                    $sent ? 'legacy_sent_time_unknown' : null
                ]);
                $n++;
            }
        }
        $pdo->exec('UPDATE reminder_worker_health SET legacy_imported_at=NOW() WHERE id=1');
        $pdo->commit();
        return $n;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** The advisory lock serializes workers without holding table/row locks during SMTP. */
function reminder_worker_lock(): bool
{
    reminder_worker_only();
    $q = db()->prepare('SELECT GET_LOCK(?,0)');
    $q->execute(['lcs.reminders.' . DB_NAME]);
    return (int)$q->fetchColumn() === 1;
}
function reminder_worker_unlock(): void
{
    $q = db()->prepare('SELECT RELEASE_LOCK(?)');
    $q->execute(['lcs.reminders.' . DB_NAME]);
}

/** Fail fast when an edit races an in-flight send; never wait through SMTP in a page request. */
function reminder_event_lock(int $eventId): void
{
    $q = db()->prepare('SELECT GET_LOCK(?,0)');
    $q->execute(['lcs.reminder.event.' . DB_NAME . '.' . $eventId]);
    if ((int)$q->fetchColumn() !== 1) throw new RuntimeException('A reminder for this event is being processed. Please retry shortly.');
}
function reminder_event_unlock(int $eventId): void
{
    $q = db()->prepare('SELECT RELEASE_LOCK(?)');
    $q->execute(['lcs.reminder.event.' . DB_NAME . '.' . $eventId]);
}

/** Atomically lease one job. Caller must own the whole-worker advisory lock. */
function reminder_claim(int $id): ?array
{
    reminder_worker_only();
    $token = bin2hex(random_bytes(16));
    $q = db()->prepare("UPDATE reminder_delivery_jobs SET status='processing',claim_token=?,claimed_at=NOW(),
        lease_expires_at=DATE_ADD(NOW(),INTERVAL " . REMINDER_LEASE_SECONDS . " SECOND),attempts=attempts+1
        WHERE id=? AND status IN ('pending','failed') AND scheduled_at<=NOW() AND (next_retry_at IS NULL OR next_retry_at<=NOW())");
    $q->execute([$token, $id]);
    if ($q->rowCount() !== 1) return null;
    $q = db()->prepare('SELECT * FROM reminder_delivery_jobs WHERE id=? AND claim_token=?');
    $q->execute([$id, $token]);
    return $q->fetch() ?: null;
}

function reminder_delivery_context(array $job): ?array
{
    if ($job['source_type'] === 'online_quiz') {
        require_once __DIR__ . '/online_quiz.php';
        return oq_notice_delivery_context($job);
    }
    $q = db()->prepare('SELECT ce.*,ce.title AS event_title,c.code AS course_code,u.email AS user_email,u.full_name AS user_name,
        u.email,u.pref_email_notifications,u.pref_web_notifications FROM calendar_events ce
        LEFT JOIN courses c ON c.id=ce.course_id JOIN users u ON u.id=? WHERE ce.id=? AND ce.assignment_test_id IS NULL');
    $q->execute([$job['user_id'], $job['source_id']]);
    $row = $q->fetch();
    if (!$row || !in_array((int)$job['user_id'], reminder_audience($row), true) || !reminder_channel_allowed($row, $row, $job['channel'])) return null;
    if (!empty($row['online_quiz_id'])) {
        require_once __DIR__ . '/online_quiz.php';
        $quiz = oq_find((int)$row['online_quiz_id']);
        $recipient = oq_query('SELECT * FROM users WHERE id=?', [$job['user_id']])->fetch();
        if (!$quiz || $quiz['status'] !== 'published' || $quiz['visibility'] !== 'enrolled' || !$recipient || !oq_visible($quiz, $recipient)) return null;
    }
    $row['start_datetime'] = $job['occurrence_at'];
    $row['end_datetime'] = $job['occurrence_end_at'];
    return $row;
}

/** Web insert + sent state commit together; email is acknowledged only after SMTP success. */
function reminder_deliver(array $job, ?callable $emailAdapter = null): string
{
    reminder_worker_only();
    $pdo = db();
    try {
        reminder_event_lock((int)$job['source_id']);
        $current = $pdo->prepare("SELECT id FROM reminder_delivery_jobs WHERE id=? AND claim_token=? AND status='processing'");
        $current->execute([$job['id'], $job['claim_token']]);
        if (!$current->fetchColumn()) return 'cancelled';
        $context = reminder_delivery_context($job);
        if (!$context || strtotime($job['expires_at']) < time()) {
            $pdo->prepare("UPDATE reminder_delivery_jobs SET status='cancelled',last_error=?,claim_token=NULL,lease_expires_at=NULL WHERE id=? AND claim_token=?")
                ->execute([$context ? 'stale' : 'no_longer_eligible', $job['id'], $job['claim_token']]);
            return 'cancelled';
        }
        if ($job['channel'] === 'web') {
            $pdo->beginTransaction();
            $q = $pdo->prepare("SELECT id FROM reminder_delivery_jobs WHERE id=? AND claim_token=? AND status='processing' FOR UPDATE");
            $q->execute([$job['id'], $job['claim_token']]);
            if (!$q->fetchColumn()) {
                $pdo->rollBack();
                return 'cancelled';
            }
            $pdo->prepare('INSERT INTO web_notifications (user_id,event_id,message,reminder_delivery_job_id,action_url)
                VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE reminder_delivery_job_id=VALUES(reminder_delivery_job_id)')
                ->execute([$job['user_id'], $job['source_type'] === 'calendar' ? $job['source_id'] : null, $context['notice_message'] ?? reminder_short_message($context), $job['id'], $context['action_url'] ?? (!empty($context['online_quiz_id']) ? 'online_quiz?id=' . (int)$context['online_quiz_id'] : null)]);
        } elseif ($job['channel'] === 'email') {
            $send = $emailAdapter ?? 'send_mail';
            $notice = $context['notice_message'] ?? null;
            $html = $notice ? '<p>' . h($notice) . '</p><p>Sign in to KWASU LCS and open Assignments &amp; Tests to view this assessment.</p>' : reminder_email_html($context);
            if (!$send($context['user_email'], $context['user_name'], '[KWASU LCS] ' . ($notice ? 'Assessment: ' : 'Reminder: ') . $context['event_title'], $html)) {
                throw new RuntimeException('SMTP delivery failed; consult the mailer error log.');
            }
        } else throw new RuntimeException('No adapter registered for channel.');
        $pdo->prepare("UPDATE reminder_delivery_jobs SET status='sent',sent_at=NOW(),claim_token=NULL,lease_expires_at=NULL,next_retry_at=NULL,last_error=NULL
            WHERE id=? AND claim_token=? AND status='processing'")->execute([$job['id'], $job['claim_token']]);
        if ($pdo->inTransaction()) $pdo->commit();
        return 'sent';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $delay = min(3600, 60 * (2 ** min(6, max(0, (int)$job['attempts'] - 1))));
        $pdo->prepare("UPDATE reminder_delivery_jobs SET status='failed',last_error=?,next_retry_at=DATE_ADD(NOW(),INTERVAL $delay SECOND),claim_token=NULL,lease_expires_at=NULL WHERE id=? AND claim_token=?")
            ->execute([mb_substr($e->getMessage(), 0, 1000), $job['id'], $job['claim_token']]);
        return 'failed';
    } finally {
        reminder_event_unlock((int)$job['source_id']);
    }
}

function reminder_run_worker(
    int $limit = 200,
    ?int $eventId = null,
    ?callable $emailAdapter = null,
    ?int $assignmentId = null,
    $retiredChannelProvider = null,
    ?array $retiredChannelConfig = null,
    ?TelegramBotProvider $telegramProvider = null,
    ?array $telegramConfig = null,
    ?TelegramRateLimiter $telegramRateLimiter = null
): array
{
    reminder_worker_only();
    $pdo = db();
    if (!reminder_worker_lock()) return ['status' => 'busy', 'processed' => 0, 'whatsapp_queued' => 0, 'whatsapp_processed' => 0, 'whatsapp_sent' => 0, 'whatsapp_retry' => 0, 'whatsapp_failed' => 0, 'whatsapp_cancelled' => 0, 'whatsapp_skipped' => 0, 'whatsapp_webhook_reprocessed' => 0, 'whatsapp_webhook_failed' => 0,
        'telegram_queued' => 0, 'telegram_processed' => 0, 'telegram_sent' => 0, 'telegram_failed' => 0,
        'telegram_retried' => 0, 'telegram_cancelled' => 0, 'telegram_skipped' => 0, 'telegram_queued_remaining' => 0];
    $started = microtime(true);
    $stats = ['status' => 'ok', 'scope' => $eventId === null ? 'all' : 'event:' . $eventId, 'processed' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0, 'assignment_sent' => 0, 'assignment_closed' => 0];
    // Legacy health keys remain zero for existing consumers. No retired
    // provider, webhook, or database table is accessed.
    $stats += ['whatsapp_queued' => 0, 'whatsapp_processed' => 0, 'whatsapp_sent' => 0,
        'whatsapp_retry' => 0, 'whatsapp_failed' => 0, 'whatsapp_cancelled' => 0,
        'whatsapp_skipped' => 0, 'whatsapp_webhook_reprocessed' => 0,
        'whatsapp_webhook_failed' => 0, 'whatsapp_queue_states' => []];
    telegram_notification_metrics_reset();
    try {
        if (!$pdo->query('SELECT legacy_imported_at FROM reminder_worker_health WHERE id=1')->fetchColumn()) throw new RuntimeException('Run --import-legacy before dispatch.');
        $pdo->exec("UPDATE reminder_worker_health SET started_at=NOW(),finished_at=NULL,status='running',last_error=NULL WHERE id=1");
        $where = $eventId === null ? '' : " AND source_type='calendar' AND source_id=" . (int)$eventId;
        if ($eventId === null && $assignmentId === null) {
            require_once __DIR__ . '/online_quiz.php';
            $stats['quiz_attempts_expired'] = oq_worker_expire();
            foreach (oq_query("SELECT DISTINCT q.id FROM online_quizzes q JOIN online_quiz_audit tr ON tr.quiz_id=q.id AND tr.action='result_notice_pending' WHERE q.visibility='enrolled'")->fetchAll(PDO::FETCH_COLUMN) as $qid) {
                $pdo->beginTransaction();
                try {
                    oq_result_notices((int)$qid, null, true);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            }
        }
        $pdo->exec("UPDATE reminder_delivery_jobs SET status='failed',next_retry_at=NOW(),claim_token=NULL,lease_expires_at=NULL,last_error='claim_expired'
            WHERE status='processing' AND lease_expires_at<NOW()" . $where);
        $pdo->exec("UPDATE reminder_delivery_jobs j LEFT JOIN calendar_events ce ON ce.id=j.source_id
            SET j.status='cancelled',j.last_error='event_deleted',j.claim_token=NULL,j.lease_expires_at=NULL
            WHERE j.source_type='calendar' AND ce.id IS NULL AND j.status<>'sent'" . ($eventId === null ? '' : ' AND j.source_id=' . (int)$eventId));
        $events = $pdo->query('SELECT id FROM calendar_events WHERE assignment_test_id IS NULL' . ($eventId === null ? '' : ' AND id=' . (int)$eventId))->fetchAll(PDO::FETCH_COLUMN);
        foreach ($events as $id) reminder_sync_event((int)$id);
        $pdo->exec("UPDATE reminder_delivery_jobs SET status='cancelled',last_error='stale',claim_token=NULL,lease_expires_at=NULL
            WHERE status IN ('pending','failed') AND expires_at<NOW()" . $where);
        $sources = $eventId === null && $assignmentId === null ? "('calendar','online_quiz')" : "('calendar')";
        $jobs = $pdo->query("SELECT id FROM reminder_delivery_jobs WHERE source_type IN $sources AND status IN ('pending','failed')
            AND scheduled_at<=NOW() AND (next_retry_at IS NULL OR next_retry_at<=NOW())" . $where . ' ORDER BY scheduled_at,id LIMIT ' . max(1, min(1000, $limit)))->fetchAll(PDO::FETCH_COLUMN);
        foreach ($jobs as $id) {
            // Leave remaining jobs for the next minute; a slow channel must not monopolize a run.
            if (microtime(true) - $started > 50) {
                $stats['budget_exhausted'] = true;
                break;
            }
            $job = reminder_claim((int)$id);
            if (!$job) continue;
            $stats[reminder_deliver($job, $emailAdapter)]++;
            $stats['processed']++;
        }
        if ($eventId === null || $assignmentId !== null) {
            require_once __DIR__ . '/assignment_test.php';
            $stats['assignment_closed'] = assignment_test_close_overdue($assignmentId);
            $stats['assignment_sent'] = assignment_test_fire_due_reminders($limit, $assignmentId);
        }
        $telegram = telegram_run_worker($limit, $telegramProvider, $telegramConfig, $started + 50.0, $telegramRateLimiter);
        $telegramFanout = telegram_notification_metrics();
        $stats['telegram_queued'] = $telegramFanout['queued'];
        $stats['telegram_processed'] = $telegram['processed'];
        $stats['telegram_sent'] = $telegram['sent'];
        $stats['telegram_failed'] = $telegram['failed'];
        $stats['telegram_retried'] = $telegram['retried'];
        $stats['telegram_cancelled'] = $telegramFanout['cancelled'] + $telegram['cancelled'];
        $stats['telegram_skipped'] = $telegramFanout['skipped'] + $telegram['skipped'];
        $stats['telegram_queued_remaining'] = $telegram['queued_remaining'];
        $stats['telegram_budget_exhausted'] = $telegram['budget_exhausted'];
        $stats['telegram_queue_states'] = $pdo->query('SELECT status,COUNT(*) AS count FROM telegram_delivery_jobs GROUP BY status')->fetchAll();
        $stats['overdue'] = (int)$pdo->query("SELECT COUNT(*) FROM reminder_delivery_jobs WHERE status IN ('pending','processing','failed') AND scheduled_at<NOW()" . $where)->fetchColumn();
        $stats['queue_states'] = $pdo->query('SELECT status,COUNT(*) AS count FROM reminder_delivery_jobs WHERE 1=1' . $where . ' GROUP BY status')->fetchAll();
        $pdo->prepare('UPDATE reminder_worker_health SET finished_at=NOW(),status=?,stats_json=? WHERE id=1')
            ->execute([$stats['failed'] ? 'degraded' : 'ok', json_encode($stats)]);
        return $stats;
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE reminder_worker_health SET finished_at=NOW(),status='error',last_error=? WHERE id=1")->execute([mb_substr($e->getMessage(), 0, 1000)]);
        throw $e;
    } finally {
        reminder_worker_unlock();
    }
}
