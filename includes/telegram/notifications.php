<?php

declare(strict_types=1);

require_once __DIR__ . '/queue.php';
require_once dirname(__DIR__) . '/notifications/signed_links.php';

function telegram_notification_metrics_reset(): void
{
    $GLOBALS['lcs_telegram_notification_metrics'] = ['queued' => 0, 'skipped' => 0, 'cancelled' => 0];
}

function telegram_notification_metric(string $key, int $amount = 1): void
{
    if (!isset($GLOBALS['lcs_telegram_notification_metrics'])) telegram_notification_metrics_reset();
    $GLOBALS['lcs_telegram_notification_metrics'][$key] =
        ($GLOBALS['lcs_telegram_notification_metrics'][$key] ?? 0) + $amount;
}

function telegram_notification_metrics(): array
{
    if (!isset($GLOBALS['lcs_telegram_notification_metrics'])) telegram_notification_metrics_reset();
    return $GLOBALS['lcs_telegram_notification_metrics'];
}

function telegram_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

function telegram_application_base_url(?array $config = null): ?string
{
    $base = rtrim(trim((string)(telegram_config($config)['base_url'] ?? '')), '/');
    if ($base === '' || !filter_var($base, FILTER_VALIDATE_URL)
        || strtolower((string)parse_url($base, PHP_URL_SCHEME)) !== 'https') return null;
    return $base;
}

function telegram_format_wat(mixed $value): ?string
{
    if (!is_scalar($value) || trim((string)$value) === '') return null;
    try {
        $date = new DateTimeImmutable((string)$value, new DateTimeZone('Africa/Lagos'));
        $date = $date->setTimezone(new DateTimeZone('Africa/Lagos'));
    } catch (Throwable) {
        return null;
    }
    return $date->format('l, j F Y') . "\n" . $date->format('g:i A') . ' WAT';
}

function telegram_normalize_course_code(string $value): string
{
    $value = strtoupper(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    if (preg_match('/^([A-Z]{2,8})\s*[- ]?\s*(\d{2,4}[A-Z]?)$/', $value, $match)) {
        return $match[1] . ' ' . $match[2];
    }
    return $value !== '' ? mb_strimwidth($value, 0, 40, '…') : 'LCS';
}

function telegram_format_date_wat(mixed $value): ?string
{
    if (!is_scalar($value) || trim((string)$value) === '') return null;
    try {
        return (new DateTimeImmutable((string)$value, new DateTimeZone('Africa/Lagos')))
            ->setTimezone(new DateTimeZone('Africa/Lagos'))->format('l, j F Y');
    } catch (Throwable) { return null; }
}

function telegram_format_time_wat(mixed $value): ?string
{
    if (!is_scalar($value) || trim((string)$value) === '') return null;
    try {
        return (new DateTimeImmutable((string)$value, new DateTimeZone('Africa/Lagos')))
            ->setTimezone(new DateTimeZone('Africa/Lagos'))->format('g:i A');
    } catch (Throwable) { return null; }
}

function telegram_time_range_wat(mixed $start, mixed $end): ?string
{
    $startTime = telegram_format_time_wat($start);
    if ($startTime === null) return null;
    $endTime = telegram_format_time_wat($end);
    return $startTime . ($endTime !== null ? ' – ' . $endTime : '') . ' WAT';
}

function telegram_time_remaining(mixed $value): ?string
{
    if (!is_scalar($value) || trim((string)$value) === '') return null;
    try { $seconds = (new DateTimeImmutable((string)$value))->getTimestamp() - time(); }
    catch (Throwable) { return null; }
    if ($seconds <= 0) return null;
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $minutes = max(1, intdiv($seconds % 3600, 60));
    if ($days > 0) return $days . ' day' . ($days === 1 ? '' : 's') . ($hours ? ', ' . $hours . ' hr' : '');
    if ($hours > 0) return $hours . ' hr' . ($minutes ? ' ' . $minutes . ' min' : '');
    return $minutes . ' min';
}

function telegram_context_text(array $context, string $key, int $limit = 220): ?string
{
    $value = trim((string)($context[$key] ?? ''));
    return $value === '' ? null : mb_strimwidth(preg_replace('/\s+/u', ' ', $value) ?? $value, 0, $limit, '…');
}

function telegram_add_field(array &$lines, string $label, mixed $value): void
{
    if (!is_scalar($value) || trim((string)$value) === '') return;
    $lines[] = '<b>' . telegram_html($label) . ':</b> ' . telegram_html(trim((string)$value));
}

function telegram_notification_definition(string $notificationType): ?array
{
    $definitions = [
        'calendar_reminder' => ['emoji' => '📅', 'label' => 'Calendar Reminder', 'button' => 'View Calendar', 'time_key' => 'starts_at'],
        'lecture_reminder' => ['emoji' => '📚', 'label' => 'Lecture Reminder', 'button' => 'View Lecture', 'time_key' => 'starts_at'],
        'assignment_test_published' => ['emoji' => '📝', 'label' => 'Published', 'button' => 'View Assignment/Test', 'time_key' => 'due_at'],
        'assignment_test_reminder' => ['emoji' => '⏰', 'label' => 'Assignment/Test Reminder', 'button' => 'View Assignment/Test', 'time_key' => 'due_at'],
        'assignment_test_closed' => ['emoji' => '🔒', 'label' => 'Assignment/Test Closed', 'button' => 'View Assignment/Test', 'time_key' => 'due_at'],
        'assignment_test_reopened' => ['emoji' => '🔓', 'label' => 'Assignment/Test Reopened', 'button' => 'View Assignment/Test', 'time_key' => 'due_at'],
        'assignment_test_returned' => ['emoji' => '↩️', 'label' => 'Returned for Revision', 'button' => 'View Feedback', 'time_key' => null],
        'assignment_test_result_available' => ['emoji' => '✅', 'label' => 'Result Available', 'button' => 'View Result', 'time_key' => null],
        'assignment_test_submission_received' => ['emoji' => '📥', 'label' => 'Submission Received', 'button' => 'View Submission', 'time_key' => null],
        'online_quiz_published' => ['emoji' => '📋', 'label' => 'Online Quiz/Test Published', 'button' => 'View Quiz/Test', 'time_key' => 'closes_at'],
        'online_quiz_reminder' => ['emoji' => '⏰', 'label' => 'Online Quiz/Test Reminder', 'button' => 'View Quiz/Test', 'time_key' => 'starts_at'],
        'online_quiz_result_available' => ['emoji' => '✅', 'label' => 'Quiz/Test Result Available', 'button' => 'View Result', 'time_key' => null],
    ];
    return $definitions[$notificationType] ?? null;
}

/** Render a concise HTML message and recipient-bound signed action button. */
function telegram_render_notification(
    string $notificationType,
    int $userId,
    array $context,
    string $sourceType,
    int $sourceId,
    ?array $config = null
): ?array {
    $definition = telegram_notification_definition($notificationType);
    $config = telegram_config($config);
    if ($definition === null || $userId < 1 || $sourceId < 1) return null;

    $course = telegram_normalize_course_code((string)($context['course_code'] ?? 'LCS'));
    $courseTitle = telegram_context_text($context, 'course_title', 120);
    $title = trim((string)($context['title'] ?? ''));
    $actionUrl = trim((string)($context['action_url'] ?? ''));
    if ($course === '' || $title === '' || $actionUrl === '') return null;
    $internal = lcs_signed_link_internal_path($actionUrl, $userId, $sourceType, $sourceId, $config);
    $base = telegram_application_base_url($config);
    if ($internal === null || $base === null) return null;

    $courseIdentity = $course . ($courseTitle ? ' — ' . $courseTitle : '');
    $lines = ['📚 <b>' . telegram_html($courseIdentity) . '</b>', '', $definition['emoji'] . ' <b>' . telegram_html((string)$definition['label']) . '</b>', ''];
    $assessmentType = telegram_context_text($context, 'assessment_type', 60);
    $lecturer = telegram_context_text($context, 'lecturer', 100);
    $status = telegram_context_text($context, 'status', 100);

    if (in_array($notificationType, ['calendar_reminder', 'lecture_reminder'], true)) {
        telegram_add_field($lines, $notificationType === 'lecture_reminder' ? 'Topic' : 'Event', mb_strimwidth($title, 0, 220, '…'));
        telegram_add_field($lines, 'Type', $assessmentType);
        telegram_add_field($lines, 'Date', telegram_format_date_wat($context['starts_at'] ?? null));
        telegram_add_field($lines, 'Time', telegram_time_range_wat($context['starts_at'] ?? null, $context['ends_at'] ?? null));
        telegram_add_field($lines, 'Venue', telegram_context_text($context, 'location', 120));
        telegram_add_field($lines, 'Lecturer', $lecturer);
        telegram_add_field($lines, 'Note', telegram_context_text($context, 'description', 180));
        $minutes = filter_var($context['remind_minutes_before'] ?? null, FILTER_VALIDATE_INT);
        $lines[] = '';
        $lines[] = $minutes !== false && $minutes > 0
            ? 'This ' . strtolower($assessmentType ?: 'event') . ' starts in ' . (int)$minutes . ' minute' . ((int)$minutes === 1 ? '' : 's') . '.'
            : 'This event is coming up soon.';
    } elseif (str_starts_with($notificationType, 'assignment_test_')) {
        telegram_add_field($lines, 'Assessment', mb_strimwidth($title, 0, 220, '…'));
        telegram_add_field($lines, 'Type', $assessmentType);
        if ($notificationType === 'assignment_test_published') telegram_add_field($lines, 'Published', telegram_format_wat($context['published_at'] ?? null));
        if (!in_array($notificationType, ['assignment_test_returned', 'assignment_test_result_available', 'assignment_test_submission_received'], true)) {
            telegram_add_field($lines, 'Deadline', telegram_format_wat($context['due_at'] ?? null));
        }
        if ($notificationType === 'assignment_test_reminder') telegram_add_field($lines, 'Time remaining', telegram_time_remaining($context['due_at'] ?? null));
        if ($notificationType === 'assignment_test_submission_received') {
            telegram_add_field($lines, 'Submitted', telegram_format_wat($context['submitted_at'] ?? null));
            telegram_add_field($lines, 'Attempt', filter_var($context['attempt_number'] ?? null, FILTER_VALIDATE_INT) ?: null);
        }
        telegram_add_field($lines, 'Lecturer', $lecturer);
        if (array_key_exists('allow_late_submissions', $context) && !in_array($notificationType, ['assignment_test_result_available', 'assignment_test_submission_received'], true)) {
            telegram_add_field($lines, 'Late submissions', !empty($context['allow_late_submissions']) ? 'Allowed' : 'Not allowed');
        }
        if ($notificationType === 'assignment_test_published') telegram_add_field($lines, 'Instructions', telegram_context_text($context, 'instructions', 180));
        telegram_add_field($lines, 'Status', $status);
        $lines[] = '';
        $lines[] = match ($notificationType) {
            'assignment_test_published' => 'A new assessment is available in LCS.',
            'assignment_test_reminder' => 'Please submit before the deadline shown above.',
            'assignment_test_closed' => 'This assessment is no longer accepting ordinary submissions.',
            'assignment_test_reopened' => 'This assessment is available again. Existing deadline rules still apply.',
            'assignment_test_returned' => 'Your work was returned for revision. Open LCS to review the authenticated feedback.',
            'assignment_test_result_available' => 'Your result is now available in LCS.',
            default => 'Your submission has been received successfully.',
        };
    } else {
        telegram_add_field($lines, 'Assessment', mb_strimwidth($title, 0, 220, '…'));
        telegram_add_field($lines, 'Type', $assessmentType ?: 'Online Quiz/Test');
        telegram_add_field($lines, 'Lecturer', $lecturer);
        if ($notificationType !== 'online_quiz_result_available') {
            if ($notificationType === 'online_quiz_published') telegram_add_field($lines, 'Published', telegram_format_wat($context['published_at'] ?? null));
            telegram_add_field($lines, 'Opens', telegram_format_wat($context['opens_at'] ?? $context['starts_at'] ?? null));
            telegram_add_field($lines, 'Closes', telegram_format_wat($context['closes_at'] ?? null));
            telegram_add_field($lines, 'Duration', filter_var($context['duration_minutes'] ?? null, FILTER_VALIDATE_INT) ? (int)$context['duration_minutes'] . ' minutes' : null);
            telegram_add_field($lines, 'Questions', filter_var($context['question_count'] ?? null, FILTER_VALIDATE_INT) ?: null);
            telegram_add_field($lines, 'Attempts allowed', filter_var($context['attempts_allowed'] ?? null, FILTER_VALIDATE_INT) ?: null);
            if (!empty($context['secure'])) $lines[] = '<b>Secure assessment:</b> Start only when you are ready and remain in the secure view.';
        } else {
            telegram_add_field($lines, 'Attempt', filter_var($context['attempt_number'] ?? null, FILTER_VALIDATE_INT) ?: null);
            telegram_add_field($lines, 'Released', telegram_format_wat($context['released_at'] ?? null));
            telegram_add_field($lines, 'Status', $status ?: 'Result available');
        }
        $lines[] = '';
        $lines[] = $notificationType === 'online_quiz_result_available'
            ? 'Your result is now available. Open LCS to view it securely.'
            : ($notificationType === 'online_quiz_reminder' ? 'This quiz/test starts soon. Open the information page before beginning.' : 'A new online quiz/test is available. Review the details before starting.');
    }

    return [
        'message_payload' => [
            'text' => implode("\n", $lines),
            'options' => ['parse_mode' => 'HTML', 'disable_web_page_preview' => true],
        ],
        'inline_keyboard' => [[[
            'text' => (string)$definition['button'],
            'url' => $base . '/' . ltrim($internal, '/'),
        ]]],
    ];
}

function telegram_job_signed_target(array $job, ?array $config = null): ?array
{
    try {
        $keyboard = json_decode((string)($job['inline_keyboard_json'] ?? ''), true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    $url = $keyboard[0][0]['url'] ?? null;
    if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) return null;
    $base = telegram_application_base_url($config);
    if ($base === null || !str_starts_with($url, $base . '/go.php?')) return null;
    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
    $reference = $query['ref'] ?? null;
    if (!is_string($reference)) return null;
    $payload = lcs_signed_link_resolve_reference($reference, $config);
    return $payload && (int)($payload['uid'] ?? 0) === (int)($job['user_id'] ?? 0) ? $payload : null;
}

/** Recheck source lifecycle, recipient authorization, release policy and signed target before enqueue/send. */
function telegram_source_is_valid(array $job, ?array $config = null): bool
{
    $notificationType = (string)($job['notification_type'] ?? '');
    $target = telegram_job_signed_target($job, $config);
    if ($target === null) return false;
    $sourceType = (string)($job['source_type'] ?? '');
    $sourceId = (int)($job['source_id'] ?? 0);
    $userId = (int)($job['user_id'] ?? 0);

    // Deliberately isolated Stage 6 connection test. It is owner-bound,
    // allowlisted, idempotent, and cannot masquerade as an academic event.
    if ($notificationType === 'telegram_connection_test') {
        return $sourceType === 'telegram_smoke' && $sourceId === $userId
            && telegram_delivery_allowed_for_user($userId, $config)
            && ($target['kind'] ?? '') === 'dashboard';
    }
    if (telegram_notification_definition($notificationType) === null) return false;

    if ($sourceType === 'calendar') {
        require_once dirname(__DIR__) . '/reminders.php';
        $stmt = db()->prepare('SELECT * FROM calendar_events WHERE id=? AND assignment_test_id IS NULL');
        $stmt->execute([$sourceId]);
        $event = $stmt->fetch();
        if (!$event || (empty($event['notify_web']) && empty($event['notify_email']))
            || !in_array($userId, reminder_audience($event), true)) return false;
        if (!empty($event['online_quiz_id'])) {
            require_once dirname(__DIR__) . '/online_quiz.php';
            $quiz = oq_find((int)$event['online_quiz_id']);
            $user = oq_query('SELECT * FROM users WHERE id=?', [$userId])->fetch();
            return (bool)($quiz && $user && $quiz['status'] === 'published' && $quiz['visibility'] === 'enrolled'
                && oq_visible($quiz, $user) && ($target['kind'] ?? '') === 'online_quiz'
                && (int)($target['id'] ?? 0) === (int)$quiz['id']);
        }
        return ($target['kind'] ?? '') === 'calendar_event' && (int)($target['id'] ?? 0) === $sourceId;
    }

    if ($sourceType === 'assignment_test') {
        require_once dirname(__DIR__) . '/assignment_test.php';
        $item = assignment_test_find($sourceId);
        if (!$item || !assignment_test_has_active_enrollment($item, ['id' => $userId, 'role' => 'student'])
            || ($target['kind'] ?? '') !== 'assignment_test' || (int)($target['id'] ?? 0) !== $sourceId) return false;
        return match ($notificationType) {
            'assignment_test_published', 'assignment_test_reopened' => $item['status'] === 'published',
            'assignment_test_closed' => $item['status'] === 'closed',
            default => in_array($item['status'], ['published', 'closed'], true),
        };
    }

    if ($sourceType === 'assignment_test_reminder') {
        $stmt = db()->prepare("SELECT j.assignment_test_id,atx.status,e.status AS enrollment_status
            FROM assignment_test_notification_jobs j
            JOIN assignment_tests atx ON atx.id=j.assignment_test_id
            JOIN enrollments e ON e.course_id=atx.course_id AND e.student_id=j.user_id
            WHERE j.id=? AND j.user_id=? AND j.sent_at IS NOT NULL AND j.cancelled_at IS NULL");
        $stmt->execute([$sourceId, $userId]);
        $row = $stmt->fetch();
        return (bool)($row && $row['status'] === 'published' && $row['enrollment_status'] === 'active'
            && ($target['kind'] ?? '') === 'assignment_test'
            && (int)($target['id'] ?? 0) === (int)$row['assignment_test_id']);
    }

    if ($sourceType === 'online_quiz') {
        require_once dirname(__DIR__) . '/online_quiz.php';
        $quiz = oq_find($sourceId);
        $user = oq_query('SELECT * FROM users WHERE id=?', [$userId])->fetch();
        if (!$quiz || !$user || $quiz['status'] === 'draft' || $quiz['visibility'] !== 'enrolled'
            || !oq_learner($quiz, $user) || !oq_visible($quiz, $user)) return false;
        if ($notificationType === 'online_quiz_published') {
            return oq_display_status($quiz) !== 'Closed' && ($target['kind'] ?? '') === 'online_quiz'
                && (int)($target['id'] ?? 0) === $sourceId;
        }
        if ($notificationType !== 'online_quiz_result_available' || ($target['kind'] ?? '') !== 'online_quiz_result') return false;
        $attempt = oq_query('SELECT * FROM online_quiz_attempts WHERE id=? AND quiz_id=? AND student_id=?',
            [(int)($target['id'] ?? 0), $sourceId, $userId])->fetch();
        if (!$attempt || $attempt['needs_review']) return false;
        $settings = json_decode((string)$attempt['settings_snapshot_json'], true);
        return is_array($settings) && oq_released((string)$settings['result_release'], $quiz, $attempt, 'results');
    }
    return false;
}

/** Optional adapter: every exception is contained and represented as a safe skip. */
function telegram_fanout_notification(
    string $notificationType,
    int $userId,
    array $context,
    string $sourceType,
    int $sourceId,
    string $logicalKey,
    ?string $nextAttemptAt = null,
    ?array $config = null
): array {
    $pdo = db();
    $savepoint = $pdo->inTransaction();
    if ($savepoint) $pdo->exec('SAVEPOINT telegram_optional_fanout');
    try {
        $config = telegram_config($config);
        if (!telegram_delivery_allowed_for_user($userId, $config)) throw new DomainException('channel_disabled');
        $category = lcs_notification_category($notificationType);
        if ($category === null || !telegram_eligible_connection($userId, $category, false)) {
            throw new DomainException('connection_not_eligible');
        }
        $rendered = telegram_render_notification($notificationType, $userId, $context, $sourceType, $sourceId, $config);
        if ($rendered === null) throw new DomainException('invalid_notification_context');
        $candidate = [
            'user_id' => $userId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'notification_type' => $notificationType,
            'message_payload_json' => telegram_payload_json($rendered['message_payload']),
            'inline_keyboard_json' => telegram_payload_json($rendered['inline_keyboard']),
        ];
        if (!telegram_source_is_valid($candidate, $config)) throw new DomainException('source_not_eligible');
        $job = telegram_enqueue_delivery([
            'user_id' => $userId,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'notification_type' => $notificationType,
            'logical_occurrence_key' => $logicalKey,
            'message_payload' => $rendered['message_payload'],
            'inline_keyboard' => $rendered['inline_keyboard'],
            'next_attempt_at' => $nextAttemptAt ?? date('Y-m-d H:i:s'),
        ], $config);
        if ($savepoint && $pdo->inTransaction()) $pdo->exec('RELEASE SAVEPOINT telegram_optional_fanout');
        if (!empty($job['_created'])) telegram_notification_metric('queued');
        return ['status' => !empty($job['_created']) ? 'queued' : 'existing'];
    } catch (Throwable $e) {
        if ($savepoint && $pdo->inTransaction()) {
            try {
                $pdo->exec('ROLLBACK TO SAVEPOINT telegram_optional_fanout');
                $pdo->exec('RELEASE SAVEPOINT telegram_optional_fanout');
            } catch (Throwable) {
                // The authoritative web/email transaction remains untouched.
            }
        }
        telegram_notification_metric('skipped');
        $safeReasons = ['channel_disabled', 'connection_not_eligible', 'invalid_notification_context', 'source_not_eligible'];
        $reason = $e instanceof DomainException && in_array($e->getMessage(), $safeReasons, true)
            ? $e->getMessage() : 'optional_channel_failed';
        return ['status' => 'skipped', 'reason' => $reason];
    }
}

function telegram_cancel_source_jobs(
    string $sourceType,
    int $sourceId,
    string $reason,
    ?array $keepIdempotencyKeys = null,
    ?string $notificationType = null
): int {
    $sql = "UPDATE telegram_delivery_jobs SET status='cancelled',cancelled_at=NOW(),last_error_code='source_invalid',
        last_error_message=?,claim_token=NULL,claimed_at=NULL,lease_expires_at=NULL
        WHERE source_type=? AND source_id=? AND status='queued'";
    $params = [mb_strimwidth($reason, 0, 300, '…'), $sourceType, $sourceId];
    if ($notificationType !== null) { $sql .= ' AND notification_type=?'; $params[] = $notificationType; }
    if ($keepIdempotencyKeys !== null && $keepIdempotencyKeys !== []) {
        $sql .= ' AND idempotency_key NOT IN (' . implode(',', array_fill(0, count($keepIdempotencyKeys), '?')) . ')';
        array_push($params, ...$keepIdempotencyKeys);
    }
    $stmt = db()->prepare($sql); $stmt->execute($params);
    $count = $stmt->rowCount();
    if ($count) telegram_notification_metric('cancelled', $count);
    return $count;
}

function telegram_cancel_assignment_reminders(int $assignmentTestId, string $reason): int
{
    $stmt = db()->prepare("UPDATE telegram_delivery_jobs t
        JOIN assignment_test_notification_jobs j ON t.source_type='assignment_test_reminder' AND t.source_id=j.id
        SET t.status='cancelled',t.cancelled_at=NOW(),t.last_error_code='source_invalid',t.last_error_message=?,
            t.claim_token=NULL,t.claimed_at=NULL,t.lease_expires_at=NULL
        WHERE j.assignment_test_id=? AND t.status='queued'");
    $stmt->execute([mb_strimwidth($reason, 0, 300, '…'), $assignmentTestId]);
    $count = $stmt->rowCount();
    if ($count) telegram_notification_metric('cancelled', $count);
    return $count;
}

/** Bounded Telegram pass owned by the existing reminder worker lock. */
function telegram_run_worker(
    int $limit = 100,
    ?TelegramBotProvider $provider = null,
    ?array $config = null,
    ?float $deadline = null,
    ?TelegramRateLimiter $rateLimiter = null
): array {
    telegram_worker_only();
    $config = telegram_config($config);
    $stats = ['processed' => 0, 'sent' => 0, 'retried' => 0, 'failed' => 0, 'cancelled' => 0,
        'skipped' => 0, 'budget_exhausted' => false, 'queued_remaining' => 0];
    if (!telegram_delivery_runtime_active($config)) {
        $stats['skipped'] = 1;
        $stats['queued_remaining'] = (int)db()->query("SELECT COUNT(*) FROM telegram_delivery_jobs WHERE status='queued'")->fetchColumn();
        return $stats;
    }
    if (!telegram_configuration_status($config)['send_ready']) {
        $stats['skipped'] = 1;
        $stats['queued_remaining'] = (int)db()->query("SELECT COUNT(*) FROM telegram_delivery_jobs WHERE status='queued'")->fetchColumn();
        return $stats;
    }
    $provider ??= new TelegramBotApiClient($config);
    $rateLimiter ??= new TelegramRateLimiter($config);
    $internalDeadline = microtime(true) + (float)$config['worker_max_seconds'];
    if ($deadline !== null) $internalDeadline = min($internalDeadline, $deadline);
    $boundedLimit = max(0, min((int)$config['worker_max_jobs'], min(200, $limit)));
    for ($i = 0; $i < $boundedLimit; $i++) {
        if (microtime(true) >= $internalDeadline) { $stats['budget_exhausted'] = true; break; }
        $job = telegram_claim_job();
        if (!$job) break;
        try {
            $result = telegram_process_claimed_job(
                $job,
                $provider,
                $config,
                $rateLimiter,
                static fn(array $candidate): bool => telegram_source_is_valid($candidate, $config)
            );
        } catch (Throwable $e) {
            if ((int)$job['attempt_count'] < (int)$config['max_attempts']) {
                telegram_schedule_retry((int)$job['id'], (string)$job['claim_token'], (int)$job['attempt_count'],
                    'worker_exception', 'Telegram delivery is temporarily unavailable.');
                $result = 'retry';
            } else {
                telegram_mark_failed((int)$job['id'], (string)$job['claim_token'], 'retry_exhausted',
                    'Telegram delivery retry limit was reached.');
                $result = 'failed';
            }
        }
        $stats['processed']++;
        $key = $result === 'retry' ? 'retried' : $result;
        $stats[$key] = ($stats[$key] ?? 0) + 1;
    }
    $stats['queued_remaining'] = (int)db()->query("SELECT COUNT(*) FROM telegram_delivery_jobs WHERE status='queued'")->fetchColumn();
    return $stats;
}
