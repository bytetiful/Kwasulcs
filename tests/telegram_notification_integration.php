<?php

/** Stage 5 local-only Telegram fan-out and worker QA. No external requests. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$environment = [];
foreach (['TELEGRAM_ENABLED','TELEGRAM_BOT_TOKEN','LCS_BASE_URL','LCS_LINK_SIGNING_KEY'] as $name) {
    $environment[$name] = getenv($name);
}
putenv('TELEGRAM_ENABLED=1');
putenv('TELEGRAM_BOT_TOKEN=123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ_abc');
putenv('LCS_BASE_URL=https://lcs.test');
putenv('LCS_LINK_SIGNING_KEY=telegram-stage-5-signing-key-minimum-32-bytes');
ini_set('session.save_path', sys_get_temp_dir());
require_once dirname(__DIR__) . '/includes/online_quiz.php';
if (DB_HOST !== '127.0.0.1' || DB_NAME !== 'kwasulcs_lcs') throw new RuntimeException('Local database required.');
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$pdo = db(); $checks = 0; $transportCalls = 0;
$config = telegram_config([
    'enabled' => true,
    'bot_token' => '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ_abc',
    'base_url' => 'https://lcs.test',
    'link_signing_key' => 'telegram-stage-5-signing-key-minimum-32-bytes',
    'max_attempts' => 3,
    'worker_max_seconds' => 8,
    'worker_max_jobs' => 100,
]);

function tg5_check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . "\n"; $checks++;
}

function tg5_count(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql); $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function tg5_job(int $id): array
{
    $stmt = db()->prepare('SELECT * FROM telegram_delivery_jobs WHERE id=?'); $stmt->execute([$id]);
    return $stmt->fetch() ?: [];
}

function tg5_connection(int $userId, int $ordinal): int
{
    $telegramId = (string)(710000000000000000 + $ordinal);
    $stmt = db()->prepare("INSERT INTO telegram_connections
        (user_id,telegram_user_id,telegram_chat_id,chat_type,display_name,username,enabled,consent_status,consented_at,connected_at)
        VALUES (?,?,?,'private',?,?,1,'consented',NOW(),NOW())");
    $stmt->execute([$userId, $telegramId, $telegramId, 'Stage 5 Fixture', 'stage5_fixture_' . $ordinal]);
    $id = (int)db()->lastInsertId(); telegram_initialize_preferences($id);
    return $id;
}

function tg5_reset_connection(int $connectionId): void
{
    db()->prepare("UPDATE telegram_connections SET enabled=1,consent_status='consented',consented_at=NOW(),
        connected_at=NOW(),disconnected_at=NULL,blocked_at=NULL WHERE id=?")->execute([$connectionId]);
    db()->prepare('UPDATE telegram_notification_preferences SET enabled=1 WHERE connection_id=?')->execute([$connectionId]);
}

$pdo->beginTransaction();
try {
    tg5_check(tg5_count("SELECT COUNT(*) FROM telegram_delivery_jobs WHERE status='queued' AND next_attempt_at<=NOW()") === 0,
        'fixture starts with no pre-existing due Telegram job');
    $tag = 'tg5-' . bin2hex(random_bytes(4));
    $insertUser = $pdo->prepare("INSERT INTO users(full_name,matric_no,email,password,role,pref_web_notifications,pref_email_notifications)
        VALUES (?,?,?,'not-a-login-hash',?,1,1)");
    $insertUser->execute([$tag . ' Lecturer', $tag . '-L', $tag . '-l@example.invalid', 'lecturer']); $lecturerId = (int)$pdo->lastInsertId();
    $insertUser->execute([$tag . ' Student', $tag . '-S', $tag . '-s@example.invalid', 'student']); $studentId = (int)$pdo->lastInsertId();
    $insertUser->execute([$tag . ' Outsider', $tag . '-O', $tag . '-o@example.invalid', 'student']); $outsiderId = (int)$pdo->lastInsertId();
    $insertUser->execute([$tag . ' Blocked', $tag . '-B', $tag . '-b@example.invalid', 'student']); $blockedId = (int)$pdo->lastInsertId();
    $studentConnection = tg5_connection($studentId, random_int(1000, 9999));
    $outsiderConnection = tg5_connection($outsiderId, random_int(10000, 19999));
    $blockedConnection = tg5_connection($blockedId, random_int(20000, 29999));

    $pdo->prepare("INSERT INTO courses(title,code,semester,lecturer_id) VALUES (?,?,'rain',?)")
        ->execute([$tag . ' Course', 'TG 505', $lecturerId]);
    $courseId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO enrollments(student_id,course_id,status) VALUES (?,?,'active')")->execute([$studentId, $courseId]);
    $pdo->prepare("INSERT INTO enrollments(student_id,course_id,status) VALUES (?,?,'active')")->execute([$blockedId, $courseId]);

    $context = [
        'course_code' => 'TG <505>',
        'course_title' => 'Private title',
        'assessment_type' => 'Assignment',
        'title' => '<script>alert(1)</script> Safe title',
        'due_at' => '2026-09-28 23:59:00',
        'closes_at' => '2026-09-28 23:59:00',
        'starts_at' => '2026-09-28 10:00:00',
        'status' => 'Published',
        'action_url' => 'assignment_test.php?id=1',
    ];
    foreach (array_keys(lcs_notification_type_definitions()) as $type) {
        $typeContext = $context;
        if (str_starts_with($type, 'online_quiz')) $typeContext['action_url'] = $type === 'online_quiz_result_available'
            ? 'online_quiz_result.php?id=1' : 'online_quiz.php?id=1';
        if (in_array($type, ['calendar_reminder','lecture_reminder'], true)) $typeContext['action_url'] = 'calendar.php?date=2026-09-28&view=day';
        $rendered = telegram_render_notification($type, $studentId, $typeContext,
            str_starts_with($type, 'online_quiz') ? 'online_quiz' : (str_contains($type, 'calendar') || str_contains($type, 'lecture') ? 'calendar' : 'assignment_test'), 1, $config);
        tg5_check(is_array($rendered) && ($rendered['message_payload']['options']['parse_mode'] ?? '') === 'HTML',
            $type . ' has an approved Telegram HTML renderer');
    }
    $escaped = telegram_render_notification('assignment_test_reminder', $studentId, $context, 'assignment_test', 1, $config);
    tg5_check(!str_contains($escaped['message_payload']['text'], '<script>')
        && str_contains($escaped['message_payload']['text'], '&lt;script&gt;'), 'renderer escapes all content-derived Telegram HTML');
    tg5_check(str_contains($escaped['message_payload']['text'], 'WAT')
        && str_starts_with($escaped['inline_keyboard'][0][0]['url'], 'https://lcs.test/go.php?ref='),
        'renderer uses WAT and one HTTPS signed action button');
    $normalized = telegram_render_notification('assignment_test_published', $studentId, array_replace($context, [
        'course_code' => 'csc402', 'course_title' => 'Data Communication and Networking',
        'published_at' => '2026-09-22 12:00:00', 'lecturer' => 'Dr Safe <Name>',
        'instructions' => 'Read chapter 2 & answer briefly.', 'allow_late_submissions' => false,
    ]), 'assignment_test', 1, $config);
    tg5_check(str_contains($normalized['message_payload']['text'], 'CSC 402 — Data Communication and Networking')
        && str_contains($normalized['message_payload']['text'], 'Dr Safe &lt;Name&gt;'),
        'course codes normalize and optional lecturer/course context is escaped');
    $calendarRich = telegram_render_notification('lecture_reminder', $studentId, array_replace($context, [
        'course_code' => 'csc 402', 'title' => 'Network Layer Services',
        'ends_at' => '2026-09-28 12:00:00', 'location' => 'CSC Lab 2',
        'description' => 'Bring notes & calculator.', 'lecturer' => 'Prof. Example', 'remind_minutes_before' => 30,
        'action_url' => 'calendar.php?date=2026-09-28&view=day',
    ]), 'calendar', 1, $config);
    tg5_check(str_contains($calendarRich['message_payload']['text'], '<b>Time:</b> 10:00 AM – 12:00 PM WAT')
        && str_contains($calendarRich['message_payload']['text'], '<b>Venue:</b> CSC Lab 2')
        && str_contains($calendarRich['message_payload']['text'], 'starts in 30 minutes'),
        'lecture renderer includes readable time range, venue and reminder context');
    $privateProbe = telegram_render_notification('online_quiz_result_available', $studentId, array_replace($context, [
        'course_code' => 'CSC402', 'action_url' => 'online_quiz_result.php?id=1',
        'score' => '99', 'answer_key' => 'SECRET-ANSWER', 'secure_token' => 'SECRET-TOKEN',
        'attempt_number' => 2, 'released_at' => '2026-09-22 15:00:00',
    ]), 'online_quiz', 1, $config);
    tg5_check(!str_contains($privateProbe['message_payload']['text'], '99')
        && !str_contains($privateProbe['message_payload']['text'], 'SECRET-ANSWER')
        && !str_contains($privateProbe['message_payload']['text'], 'SECRET-TOKEN')
        && str_contains($privateProbe['message_payload']['text'], 'Your result is now available'),
        'result message omits scores, answers and secure tokens');

    $pdo->prepare("INSERT INTO assignment_tests(course_id,created_by,type,title,instructions,due_at,total_marks,status,allow_late_submissions)
        VALUES (?,?,'assignment',?,'Private instructions',?,10,'published',0)")
        ->execute([$courseId, $lecturerId, $tag . ' Assignment', date('Y-m-d H:i:s', time() + 86400)]);
    $assignmentId = (int)$pdo->lastInsertId();
    $item = assignment_test_find($assignmentId);
    assignment_test_notify_user($item, $studentId, 'published', 'Existing web delivery.', 'published');
    tg5_check(tg5_count("SELECT COUNT(*) FROM web_notifications WHERE user_id=? AND assignment_test_id=? AND notification_type='published'", [$studentId, $assignmentId]) === 1,
        'Assignment/Test web notification remains authoritative and unchanged');
    tg5_check(tg5_count("SELECT COUNT(*) FROM telegram_delivery_jobs WHERE user_id=? AND source_type='assignment_test' AND source_id=? AND notification_type='assignment_test_published'", [$studentId, $assignmentId]) === 1,
        'Assignment/Test publication queues Telegram for an eligible learner');
    assignment_test_notify_user($item, $studentId, 'published', 'Duplicate producer call.', 'published');
    tg5_check(tg5_count("SELECT COUNT(*) FROM telegram_delivery_jobs WHERE user_id=? AND source_id=? AND notification_type='assignment_test_published'", [$studentId, $assignmentId]) === 1,
        'duplicate producer execution preserves Telegram idempotency');

    $unauthorized = telegram_fanout_notification('assignment_test_published', $outsiderId, [
        'course_code' => 'TG 505', 'title' => $item['title'], 'due_at' => $item['due_at'],
        'action_url' => 'assignment_test.php?id=' . $assignmentId,
    ], 'assignment_test', $assignmentId, 'unauthorized', null, $config);
    tg5_check($unauthorized['status'] === 'skipped', 'unauthorized Assignment/Test recipient is rejected before enqueue');

    foreach ([
        ['returned','returned-key'], ['marked','marked-key'], ['submission_receipt','receipt-key'],
    ] as [$type, $key]) assignment_test_notify_user($item, $studentId, $type, $type . ' fixture', $key);
    tg5_check(tg5_count("SELECT COUNT(*) FROM telegram_delivery_jobs WHERE source_id=? AND notification_type IN
        ('assignment_test_returned','assignment_test_result_available','assignment_test_submission_received')", [$assignmentId]) === 3,
        'returned, result-available and submission-received events map to Telegram');

    $pdo->prepare("INSERT INTO assignment_test_notification_jobs(assignment_test_id,user_id,notification_type,scheduled_at,sent_at)
        VALUES (?,?,'due_reminder',NOW(),NOW())")->execute([$assignmentId, $studentId]);
    $dueJobId = (int)$pdo->lastInsertId();
    assignment_test_notify_user($item, $studentId, 'due_reminder', 'Due reminder fixture', 'due-job:' . $dueJobId,
        'assignment_test_reminder', $dueJobId);
    tg5_check(tg5_count("SELECT COUNT(*) FROM telegram_delivery_jobs WHERE source_type='assignment_test_reminder' AND source_id=?", [$dueJobId]) === 1,
        'Assignment/Test scheduled reminder uses its stable reminder-job source');

    $pdo->prepare("UPDATE assignment_tests SET status='closed' WHERE id=?")->execute([$assignmentId]);
    assignment_test_notify_user($item, $studentId, 'closed', 'Closed fixture', 'closed:cycle:1');
    $pdo->prepare("UPDATE assignment_tests SET status='published' WHERE id=?")->execute([$assignmentId]);
    assignment_test_notify_user($item, $studentId, 'reopened', 'Reopened fixture', 'reopened:cycle:1');
    tg5_check(tg5_count("SELECT COUNT(*) FROM telegram_delivery_jobs WHERE source_id=? AND notification_type IN
        ('assignment_test_closed','assignment_test_reopened')", [$assignmentId]) === 2,
        'Assignment/Test close and reopen lifecycle events map independently');

    $pdo->prepare("INSERT INTO assignment_tests(course_id,created_by,type,title,instructions,due_at,total_marks,status)
        VALUES (?,?,'test',?,'Draft',?,10,'draft')")
        ->execute([$courseId, $lecturerId, $tag . ' Draft', date('Y-m-d H:i:s', time() + 86400)]);
    $draftId = (int)$pdo->lastInsertId();
    $draft = telegram_fanout_notification('assignment_test_published', $studentId, [
        'course_code' => 'TG 505', 'title' => 'Draft', 'due_at' => date('Y-m-d H:i:s', time() + 86400),
        'action_url' => 'assignment_test.php?id=' . $draftId,
    ], 'assignment_test', $draftId, 'draft', null, $config);
    tg5_check($draft['status'] === 'skipped', 'draft Assignment/Test never queues Telegram');

    $start = time() + 7200;
    $pdo->prepare("INSERT INTO calendar_events(title,event_type,scope,course_id,created_by,start_datetime,end_datetime,
        remind_minutes_before,notify_web,notify_email,is_recurring,recurrence_rule,recurrence_end_date,created_at)
        VALUES (?,'lecture','course',?,?,?,?,30,1,0,1,'daily',?,NOW())")
        ->execute([$tag . ' Lecture', $courseId, $lecturerId, date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $start + 3600), date('Y-m-d', $start + 2 * 86400)]);
    $lectureEventId = (int)$pdo->lastInsertId();
    reminder_sync_event($lectureEventId);
    $lectureJobs = tg5_count("SELECT COUNT(*) FROM telegram_delivery_jobs WHERE source_type='calendar' AND source_id=? AND notification_type='lecture_reminder'", [$lectureEventId]);
    reminder_sync_event($lectureEventId);
    tg5_check($lectureJobs >= 3 && tg5_count("SELECT COUNT(*) FROM telegram_delivery_jobs WHERE source_type='calendar' AND source_id=?", [$lectureEventId]) === $lectureJobs,
        'recurring lecture reminders deduplicate per occurrence, not globally');

    $pdo->prepare("INSERT INTO calendar_events(title,event_type,scope,created_by,start_datetime,end_datetime,
        remind_minutes_before,notify_web,notify_email,is_recurring,created_at)
        VALUES (?,'general','personal',?,?,?,?,1,0,0,NOW())")
        ->execute([$tag . ' Calendar', $studentId, date('Y-m-d H:i:s', $start), date('Y-m-d H:i:s', $start + 1800), 15]);
    $calendarEventId = (int)$pdo->lastInsertId(); reminder_sync_event($calendarEventId);
    tg5_check(tg5_count("SELECT COUNT(*) FROM telegram_delivery_jobs WHERE source_id=? AND notification_type='calendar_reminder'", [$calendarEventId]) === 1,
        'ordinary calendar occurrence maps to calendar_reminder');
    $pdo->prepare("UPDATE calendar_events SET recurrence_end_date=? WHERE id=?")
        ->execute([date('Y-m-d', $start), $lectureEventId]);
    reminder_sync_event($lectureEventId);
    tg5_check(tg5_count("SELECT COUNT(*) FROM telegram_delivery_jobs WHERE source_id=? AND status='cancelled'", [$lectureEventId]) >= 1,
        'superseded recurring occurrences cancel only unsent Telegram jobs');

    $quizSettings = json_encode(['result_release' => 'manual', 'answer_release' => 'manual'], JSON_THROW_ON_ERROR);
    $pdo->prepare("INSERT INTO online_quizzes(course_id,created_by,title,instructions,status,visibility,opens_at,closes_at,
        duration_minutes,attempts_allowed,settings_json,published_at) VALUES (?,?,?,'Fixture','published','enrolled',?,?,10,1,?,NOW())")
        ->execute([$courseId, $lecturerId, $tag . ' Quiz', date('Y-m-d H:i:s', time() - 60), date('Y-m-d H:i:s', time() + 3600), $quizSettings]);
    $quizId = (int)$pdo->lastInsertId(); $quiz = oq_find($quizId);
    oq_queue_notice($quiz, $pdo->query('SELECT * FROM users WHERE id=' . $studentId)->fetch(), 'published');
    tg5_check(tg5_count("SELECT COUNT(*) FROM telegram_delivery_jobs WHERE source_type='online_quiz' AND source_id=? AND notification_type='online_quiz_published'", [$quizId]) === 1,
        'Online Quiz/Test publication queues Telegram');
    $quizJob = $pdo->query("SELECT * FROM telegram_delivery_jobs WHERE source_type='online_quiz' AND source_id=$quizId AND notification_type='online_quiz_published'")->fetch();
    $quizTarget = telegram_job_signed_target($quizJob, $config);
    tg5_check(($quizTarget['kind'] ?? '') === 'online_quiz'
        && !str_contains((string)$quizJob['inline_keyboard_json'], 'online_quiz_take.php'),
        'secure quiz action lands on the guarded quiz overview, never attempt execution');

    $pdo->prepare("INSERT INTO calendar_events(online_quiz_id,title,event_type,scope,course_id,created_by,start_datetime,
        end_datetime,remind_minutes_before,notify_web,notify_email,is_recurring,created_at)
        VALUES (?,?,'test','course',?,?,?,?,30,1,0,0,NOW())")
        ->execute([$quizId, $tag . ' Quiz Reminder', $courseId, $lecturerId,
            date('Y-m-d H:i:s', time() + 3600), date('Y-m-d H:i:s', time() + 4200)]);
    $quizEventId = (int)$pdo->lastInsertId(); reminder_sync_event($quizEventId);
    tg5_check(tg5_count("SELECT COUNT(*) FROM telegram_delivery_jobs WHERE source_type='calendar' AND source_id=?
        AND notification_type='online_quiz_reminder'", [$quizEventId]) >= 1,
        'scheduled Online Quiz/Test occurrence maps to online_quiz_reminder');

    $pdo->prepare("INSERT INTO online_quiz_attempts(quiz_id,student_id,attempt_number,status,settings_snapshot_json,
        quiz_snapshot_json,started_at,expires_at,submitted_at,score,total_marks,needs_review)
        VALUES (?,?,1,'submitted',?,'{}',DATE_SUB(NOW(),INTERVAL 5 MINUTE),NOW(),NOW(),1,1,0)")
        ->execute([$quizId, $studentId, $quizSettings]);
    $attemptId = (int)$pdo->lastInsertId();
    oq_result_notices($quizId, $attemptId);
    tg5_check(tg5_count("SELECT COUNT(*) FROM telegram_delivery_jobs WHERE source_id=? AND notification_type='online_quiz_result_available'", [$quizId]) === 0,
        'manual Online Quiz/Test result policy blocks premature Telegram result notice');
    $pdo->prepare('UPDATE online_quizzes SET results_released_at=NOW() WHERE id=?')->execute([$quizId]);
    oq_result_notices($quizId, $attemptId);
    tg5_check(tg5_count("SELECT COUNT(*) FROM telegram_delivery_jobs WHERE source_id=? AND notification_type='online_quiz_result_available'", [$quizId]) === 1,
        'released Online Quiz/Test result becomes Telegram-eligible');

    $disabledProvider = new TelegramMockProvider(['success']);
    $disabledStats = telegram_run_worker(10, $disabledProvider, array_replace($config, ['enabled' => false]));
    tg5_check($disabledStats['processed'] === 0 && $disabledStats['queued_remaining'] > 0
        && count($disabledProvider->requests) === 0, 'globally disabled Telegram makes no call and leaves queued jobs dormant');

    $oneProvider = new TelegramMockProvider(array_fill(0, 20, 'success'));
    $one = telegram_run_worker(20, $oneProvider, array_replace($config, ['worker_max_jobs' => 1]));
    tg5_check($one['processed'] === 1 && $one['queued_remaining'] > 0,
        'bounded worker leaves remaining jobs queued for the next scheduled run');

    $drainProvider = new TelegramMockProvider(array_fill(0, 100, 'success'));
    $drain = telegram_run_worker(100, $drainProvider, $config);
    tg5_check($drain['processed'] > 0 && $drain['sent'] > 0 && $drain['cancelled'] > 0,
        'worker continues across valid and source-superseded Telegram jobs');

    tg5_reset_connection($studentConnection);
    $retry = telegram_fanout_notification('assignment_test_result_available', $studentId, [
        'course_code' => 'TG 505', 'title' => $item['title'], 'status' => 'Result available',
        'action_url' => 'assignment_test.php?id=' . $assignmentId,
    ], 'assignment_test', $assignmentId, 'worker-retry', null, $config);
    tg5_check($retry['status'] === 'queued', 'valid worker retry fixture queues');
    $rateProvider = new TelegramMockProvider(['rate_limit']);
    $rateStats = telegram_run_worker(1, $rateProvider, $config);
    $retryRow = $pdo->query("SELECT * FROM telegram_delivery_jobs WHERE source_id=$assignmentId AND idempotency_key='"
        . telegram_delivery_idempotency_key('assignment_test', $assignmentId, $studentId, 'assignment_test_result_available', 'worker-retry') . "'")->fetch();
    tg5_check($rateStats['retried'] === 1 && $retryRow['status'] === 'queued'
        && strtotime($retryRow['next_attempt_at']) >= time() + 5, 'HTTP 429 retry_after is honored by integrated worker');

    $pdo->prepare('UPDATE telegram_delivery_jobs SET next_attempt_at=NOW() WHERE id=?')->execute([$retryRow['id']]);
    $networkStats = telegram_run_worker(1, new TelegramMockProvider(['network']), $config);
    tg5_check($networkStats['retried'] === 1, 'transport failure remains retryable in integrated worker');
    $pdo->prepare('UPDATE telegram_delivery_jobs SET next_attempt_at=NOW(),attempt_count=2 WHERE id=?')->execute([$retryRow['id']]);
    $exhaustedStats = telegram_run_worker(1, new TelegramMockProvider(['transient']), $config);
    tg5_check($exhaustedStats['failed'] === 1 && tg5_job((int)$retryRow['id'])['last_error_code'] === 'retry_exhausted',
        'retry exhaustion becomes terminal without stopping the worker');

    tg5_reset_connection($studentConnection);
    foreach (['mixed-failure','mixed-success'] as $key) telegram_fanout_notification('assignment_test_result_available', $studentId, [
        'course_code' => 'TG 505', 'title' => $item['title'], 'status' => 'Result available',
        'action_url' => 'assignment_test.php?id=' . $assignmentId,
    ], 'assignment_test', $assignmentId, $key, null, $config);
    $mixedStats = telegram_run_worker(2, new TelegramMockProvider(['permanent','success']), $config);
    tg5_check($mixedStats['processed'] === 2 && $mixedStats['failed'] === 1 && $mixedStats['sent'] === 1,
        'one permanent Telegram failure does not stop the next worker job');

    tg5_reset_connection($studentConnection);
    telegram_fanout_notification('assignment_test_result_available', $studentId, [
        'course_code' => 'TG 505', 'title' => $item['title'], 'status' => 'Result available',
        'action_url' => 'assignment_test.php?id=' . $assignmentId,
    ], 'assignment_test', $assignmentId, 'destination-mismatch', null, $config);
    $mismatchKey = telegram_delivery_idempotency_key('assignment_test', $assignmentId, $studentId,
        'assignment_test_result_available', 'destination-mismatch');
    $pdo->prepare("UPDATE telegram_delivery_jobs SET destination_chat_id=destination_chat_id+1 WHERE idempotency_key=?")
        ->execute([$mismatchKey]);
    $mismatchProvider = new TelegramMockProvider(['success']);
    $mismatchStats = telegram_run_worker(1, $mismatchProvider, $config);
    tg5_check($mismatchStats['cancelled'] === 1 && count($mismatchProvider->requests) === 0,
        'destination snapshot mismatch cancels before provider access');

    tg5_reset_connection($studentConnection);
    telegram_fanout_notification('assignment_test_result_available', $studentId, [
        'course_code' => 'TG 505', 'title' => $item['title'], 'status' => 'Result available',
        'action_url' => 'assignment_test.php?id=' . $assignmentId,
    ], 'assignment_test', $assignmentId, 'preference-recheck', null, $config);
    $pdo->prepare("UPDATE telegram_notification_preferences SET enabled=0 WHERE connection_id=? AND category='assignment_test'")
        ->execute([$studentConnection]);
    $prefStats = telegram_run_worker(1, new TelegramMockProvider(['success']), $config);
    tg5_check($prefStats['cancelled'] === 1, 'preference disabled after enqueue cancels before send');

    tg5_reset_connection($studentConnection);
    telegram_fanout_notification('assignment_test_result_available', $studentId, [
        'course_code' => 'TG 505', 'title' => $item['title'], 'status' => 'Result available',
        'action_url' => 'assignment_test.php?id=' . $assignmentId,
    ], 'assignment_test', $assignmentId, 'consent-recheck', null, $config);
    $pdo->prepare("UPDATE telegram_connections SET consent_status='revoked' WHERE id=?")->execute([$studentConnection]);
    $consentStats = telegram_run_worker(1, new TelegramMockProvider(['success']), $config);
    tg5_check($consentStats['cancelled'] === 1, 'consent revoked after enqueue cancels before send');

    tg5_reset_connection($studentConnection);
    telegram_fanout_notification('assignment_test_result_available', $studentId, [
        'course_code' => 'TG 505', 'title' => $item['title'], 'status' => 'Result available',
        'action_url' => 'assignment_test.php?id=' . $assignmentId,
    ], 'assignment_test', $assignmentId, 'authorization-recheck', null, $config);
    $pdo->prepare("UPDATE enrollments SET status='inactive' WHERE student_id=? AND course_id=?")->execute([$studentId, $courseId]);
    $authStats = telegram_run_worker(1, new TelegramMockProvider(['success']), $config);
    tg5_check($authStats['cancelled'] === 1, 'recipient authorization removed after enqueue cancels before send');
    $pdo->prepare("UPDATE enrollments SET status='active' WHERE student_id=? AND course_id=?")->execute([$studentId, $courseId]);

    tg5_reset_connection($blockedConnection);
    telegram_fanout_notification('assignment_test_result_available', $blockedId, [
        'course_code' => 'TG 505', 'title' => $item['title'], 'status' => 'Result available',
        'action_url' => 'assignment_test.php?id=' . $assignmentId,
    ], 'assignment_test', $assignmentId, 'blocked-active', null, $config);
    telegram_fanout_notification('assignment_test_result_available', $blockedId, [
        'course_code' => 'TG 505', 'title' => $item['title'], 'status' => 'Result available',
        'action_url' => 'assignment_test.php?id=' . $assignmentId,
    ], 'assignment_test', $assignmentId, 'blocked-pending', date('Y-m-d H:i:s', time() + 60), $config);
    $blockedStats = telegram_run_worker(1, new TelegramMockProvider(['blocked']), $config);
    tg5_check($blockedStats['failed'] === 1 && !empty(telegram_connection_by_user($blockedId)['blocked_at'])
        && tg5_count("SELECT COUNT(*) FROM telegram_delivery_jobs WHERE user_id=? AND status='cancelled'", [$blockedId]) >= 1,
        'blocked destination becomes unavailable and cancels its remaining queue');

    $calls = 0;
    $guardedClient = new TelegramBotApiClient(array_replace($config, ['enabled' => false]),
        static function () use (&$calls): array { $calls++; return []; });
    $guardedClient->sendMessage('710000000000000001', 'No network');
    tg5_check($calls === 0, 'explicit disabled-mode guard prevents real Bot API transport access');

    $isolation = lcs_notification_fanout_optional(
        lcs_notification_envelope('calendar_reminder', $studentId, [], 'calendar', $calendarEventId, 'isolation'),
        [
            'web_email_authoritative' => static fn(): array => ['status' => 'sent'],
            'telegram' => static function (): array { throw new RuntimeException('synthetic Telegram enqueue failure'); },
            'other_optional' => static fn(): array => ['status' => 'queued'],
        ]
    );
    tg5_check($isolation['channels']['web_email_authoritative']['status'] === 'sent'
        && $isolation['channels']['telegram']['status'] === 'skipped'
        && $isolation['channels']['other_optional']['status'] === 'queued',
        'Telegram enqueue exception cannot roll back web/email or prevent another optional channel');
    $reverseIsolation = lcs_notification_fanout_optional(
        lcs_notification_envelope('calendar_reminder', $studentId, [], 'calendar', $calendarEventId, 'reverse-isolation'),
        [
            'other_optional' => static function (): array { throw new RuntimeException('synthetic optional enqueue failure'); },
            'telegram' => static fn(): array => ['status' => 'queued'],
        ]
    );
    tg5_check($reverseIsolation['channels']['other_optional']['status'] === 'skipped'
        && $reverseIsolation['channels']['telegram']['status'] === 'queued',
        'another optional-channel failure cannot prevent Telegram fan-out');

    $health = reminder_run_worker(1, $calendarEventId, null, null,
        null, null,
        new TelegramMockProvider(['success']), array_replace($config, ['enabled' => false]));
    tg5_check(isset($health['processed'], $health['whatsapp_processed'], $health['telegram_processed'],
        $health['telegram_queued_remaining'], $health['telegram_queue_states']),
        'authoritative worker preserves old fields and adds safe Telegram health counters');
    $healthJson = json_encode($health, JSON_THROW_ON_ERROR);
    tg5_check(!str_contains($healthJson, '710000000000') && !str_contains($healthJson, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ_abc'),
        'ordinary worker health exposes no Telegram identifiers or credentials');

    $source = file_get_contents(dirname(__DIR__) . '/includes/telegram/notifications.php');
    tg5_check(is_string($source) && !str_contains($source, 'allow_paid_broadcast')
        && !str_contains($source, 'online_quiz_take.php?id='),
        'Stage 5 integration has no paid-broadcast or secure-attempt bypass path');
    tg5_check($transportCalls === 0, 'all Stage 5 sends used mock providers; no api.telegram.org request occurred');
    echo "Completed $checks Telegram Stage 5 integration checks. Fixture transaction rolled back; no Telegram request sent.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    try { reminder_worker_unlock(); } catch (Throwable) {}
    foreach ($environment as $name => $value) {
        if ($value === false) putenv($name); else putenv($name . '=' . $value);
    }
}
