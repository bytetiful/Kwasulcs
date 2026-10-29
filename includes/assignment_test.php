<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/telegram/notifications.php';
require_once __DIR__ . '/notifications/fanout.php';

/**
 * Central domain helpers for the combined Assignment/Test module.
 *
 * Pages and HTTP endpoints should delegate item resolution, authorization,
 * lifecycle checks, audit writes, upload validation and Chat-card rendering
 * to this module rather than duplicating business rules.
 */

/** Derived calendar projection; called inside the existing lifecycle transaction. */
function assignment_test_sync_calendar(array $item): void
{
    if (!in_array($item['status'], ['published', 'closed'], true)) return;
    db()->prepare("INSERT INTO calendar_events
        (assignment_test_id,title,event_type,scope,course_id,created_by,start_datetime,end_datetime,notify_email,notify_web)
        VALUES (?,?,?,'course',?,?,?,?,0,0)
        ON DUPLICATE KEY UPDATE title=VALUES(title),event_type=VALUES(event_type),
            course_id=VALUES(course_id),start_datetime=VALUES(start_datetime),end_datetime=VALUES(end_datetime),updated_at=NOW()")
        ->execute([(int)$item['id'],mb_substr($item['title'],0,200),$item['type']==='test'?'test':'general',
            (int)$item['course_id'],(int)$item['created_by'],$item['due_at'],$item['due_at']]);
}

/** SQL guard for every calendar surface. Ordinary calendar ACLs stay independent. */
function assignment_test_calendar_visibility_sql(array $user, string $alias = 'e'): string
{
    if (!preg_match('/^[a-z][a-z0-9_]*$/i', $alias)) throw new InvalidArgumentException('Invalid alias.');
    $uid=(int)$user['id'];
    $owner=in_array($user['role']??'', ['lecturer','admin'],true)?1:0;
    $learner=in_array($user['role']??'', ['student','admin'],true)?1:0;
    return "($alias.assignment_test_id IS NULL OR EXISTS (
        SELECT 1 FROM assignment_tests ca JOIN courses cc ON cc.id=ca.course_id
        WHERE ca.id=$alias.assignment_test_id AND ca.status IN ('published','closed')
        AND (($owner=1 AND cc.lecturer_id=$uid) OR ($learner=1 AND EXISTS (
            SELECT 1 FROM enrollments ce WHERE ce.course_id=ca.course_id AND ce.student_id=$uid AND ce.status='active')))))";
}

function assignment_test_find(int $assignmentTestId, bool $forUpdate = false): ?array
{
    if ($assignmentTestId <= 0) {
        return null;
    }
    // Locking callers are already inside a lifecycle transaction. Ordinary
    // reads reconcile expired items before rendering or checking permissions.
    if (!$forUpdate) assignment_test_close_overdue($assignmentTestId);

    $sql = '
        SELECT atx.*,
               c.code AS course_code,
               c.title AS course_title,
               c.lecturer_id AS course_lecturer_id,
               c.department_id AS course_department_id,
               lecturer.full_name AS lecturer_name,
               creator.full_name AS creator_name
        FROM assignment_tests atx
        JOIN courses c ON c.id = atx.course_id
        JOIN users lecturer ON lecturer.id = c.lecturer_id
        JOIN users creator ON creator.id = atx.created_by
        WHERE atx.id = ?
        LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');

    $stmt = db()->prepare($sql);
    $stmt->execute([$assignmentTestId]);
    return $stmt->fetch() ?: null;
}

function assignment_test_submission_find(
    int $submissionId,
    bool $forUpdate = false,
    ?array $viewer = null
): ?array
{
    if ($submissionId <= 0) {
        return null;
    }

    $params = [$submissionId];
    $scopeSql = '';
    if ($viewer !== null) {
        $scopeSql = assignment_test_submission_scope_sql('ats', $viewer, $params);
    }

    $sql = '
        SELECT ats.*,
               atx.course_id,
               atx.created_by,
               atx.type AS assignment_test_type,
               atx.title AS assignment_test_title,
               atx.due_at,
               atx.total_marks,
               atx.allow_late_submissions,
               atx.allow_resubmission,
               atx.status AS assignment_test_status,
               c.code AS course_code,
               c.title AS course_title,
               c.lecturer_id AS course_lecturer_id,
               c.department_id AS course_department_id,
               student.matric_no AS student_matric_no
        FROM assignment_test_submissions ats
        JOIN assignment_tests atx ON atx.id = ats.assignment_test_id
        JOIN courses c ON c.id = atx.course_id
        JOIN users student ON student.id = ats.student_id
        WHERE ats.id = ?' . $scopeSql . '
        LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch() ?: null;
    if ($result && $viewer && assignment_test_is_course_owner($result, $viewer)) {
        // Never return a student's unsent note to their lecturer.
        $receipt = db()->prepare('SELECT note_text_snapshot FROM assignment_test_submission_receipts WHERE submission_id=? ORDER BY submission_attempt DESC LIMIT 1');
        $receipt->execute([$submissionId]);
        $result['note_text'] = $receipt->fetchColumn() ?: null;
    }
    return $result;
}

/** SQL scope shared by submission/file/receipt resolution. c is the course alias. */
function assignment_test_submission_scope_sql(string $alias, array $user, array &$params): string
{
    $id = (int) ($user['id'] ?? 0);
    if (!in_array($user['role'] ?? '', ['student', 'admin', 'lecturer'], true) || !$id) return ' AND 1=0';
    $params[] = $id;
    $owner = "(c.lecturer_id=? AND {$alias}.submission_attempt>0 AND {$alias}.status<>'draft')";
    if (in_array($user['role'], ['student', 'admin'], true)) {
        $params[] = $id;
        $params[] = $id;
        $params[] = $id;
        return " AND ($owner OR ({$alias}.student_id=? AND c.lecturer_id<>? AND EXISTS (SELECT 1 FROM enrollments ae WHERE ae.course_id=c.id AND ae.student_id=? AND ae.status='active')))";
    }
    return ' AND ' . $owner;
}

/** A lecturer may only resolve files that occurred in a finalized receipt. */
function assignment_test_finalized_file_sql(string $alias = 'f'): string
{
    return "EXISTS (SELECT 1 FROM assignment_test_submission_receipts fr WHERE fr.submission_id={$alias}.submission_id AND JSON_CONTAINS(fr.file_manifest_json, JSON_OBJECT('id', {$alias}.id)))";
}

function assignment_test_submission_for_student(int $assignmentTestId, int $studentId): ?array
{
    $stmt = db()->prepare('
        SELECT *
        FROM assignment_test_submissions
        WHERE assignment_test_id = ? AND student_id = ?
        LIMIT 1
    ');
    $stmt->execute([$assignmentTestId, $studentId]);
    return $stmt->fetch() ?: null;
}

function assignment_test_submission_file_for_user(int $fileId, array $user): ?array
{
    if ($fileId <= 0) {
        return null;
    }

    $params = [$fileId];
    $scopeSql = assignment_test_submission_scope_sql('s', $user, $params);
    $params[] = (int) $user['id'];
    $scopeSql .= ' AND (c.lecturer_id<>? OR ' . assignment_test_finalized_file_sql() . ')';

    $stmt = db()->prepare('
        SELECT f.*,
               s.assignment_test_id,
               s.student_id,
               s.status AS submission_status,
               s.submission_attempt,
               atx.course_id,
               atx.status AS assignment_test_status,
               c.lecturer_id AS course_lecturer_id,
               c.department_id AS course_department_id
        FROM assignment_test_submission_files f
        JOIN assignment_test_submissions s ON s.id = f.submission_id
        JOIN assignment_tests atx ON atx.id = s.assignment_test_id
        JOIN courses c ON c.id = atx.course_id
        WHERE f.id = ?' . $scopeSql . '
        LIMIT 1
    ');
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

function assignment_test_is_admin(array $user): bool
{
    return ($user['role'] ?? null) === 'admin';
}

function assignment_test_is_course_owner(array $item, array $user): bool
{
    return in_array($user['role'] ?? null, ['lecturer', 'admin'], true)
        && (int) ($user['id'] ?? 0) > 0
        && (int) ($item['course_lecturer_id'] ?? $item['lecturer_id'] ?? 0) === (int) ($user['id'] ?? 0);
}

function assignment_test_is_learner(array $item, array $user): bool
{
    return in_array($user['role'] ?? null, ['student', 'admin'], true)
        && !assignment_test_is_course_owner($item, $user);
}

function assignment_test_has_active_enrollment(array $item, array $user): bool
{
    if (!assignment_test_is_learner($item, $user)) {
        return false;
    }

    $stmt = db()->prepare("
        SELECT 1
        FROM enrollments
        WHERE student_id = ? AND course_id = ? AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([(int) ($user['id'] ?? 0), (int) ($item['course_id'] ?? 0)]);
    return (bool) $stmt->fetchColumn();
}

/** Module-only policy: assigned course owners manage; other admins are learners. */
function assignment_test_can_manage(array $item, array $user): bool
{
    return assignment_test_is_course_owner($item, $user);
}

function assignment_test_can_view(array $item, array $user): bool
{
    if (assignment_test_is_course_owner($item, $user)) {
        return true;
    }

    return in_array((string) ($item['status'] ?? ''), ['published', 'closed'], true)
        && assignment_test_has_active_enrollment($item, $user);
}

function assignment_test_can_view_all_submissions(array $item, array $user): bool
{
    return assignment_test_is_course_owner($item, $user);
}

function assignment_test_can_mark(array $item, array $user): bool
{
    return assignment_test_is_course_owner($item, $user);
}

function assignment_test_can_view_audit(array $item, array $user): bool
{
    return assignment_test_is_course_owner($item, $user);
}

function assignment_test_can_download_submission_file(array $file, array $user): bool
{
    if (assignment_test_is_course_owner($file, $user)) {
        // The resolver additionally enforces finalized receipt membership.
        $resolved = assignment_test_submission_file_for_user((int) ($file['id'] ?? 0), $user);
        return $resolved !== null;
    }

    return assignment_test_is_learner($file, $user)
        && (int) ($file['student_id'] ?? 0) === (int) ($user['id'] ?? 0)
        && assignment_test_has_active_enrollment($file, $user);
}

function assignment_test_private_file_path(array $file): ?string
{
    $relative = ltrim(str_replace('\\', '/', (string) ($file['file_path'] ?? '')), '/');
    if (!str_starts_with($relative, 'assignment_tests/')) {
        return null;
    }

    $root = realpath(PRIVATE_UPLOAD_ROOT);
    $absolute = realpath(rtrim(PRIVATE_UPLOAD_ROOT, '/') . '/' . $relative);
    if ($root === false || $absolute === false || !is_file($absolute)) {
        return null;
    }

    $rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $normalizedAbsolute = str_replace('\\', '/', $absolute);
    return str_starts_with($normalizedAbsolute, $rootPrefix) ? $absolute : null;
}

function assignment_test_submission_statuses(): array
{
    return [
        'draft'        => 'Draft',
        'submitted'    => 'Submitted',
        'under_review' => 'Under Review',
        'marked'       => 'Marked',
        'returned'     => 'Returned',
        'resubmitted'  => 'Resubmitted',
    ];
}

function assignment_test_submission_transition_map(): array
{
    return [
        'draft'        => ['submitted'],
        'submitted'    => ['under_review', 'resubmitted'],
        'under_review' => ['marked', 'returned'],
        'marked'       => ['returned'],
        'returned'     => ['resubmitted'],
        'resubmitted'  => ['under_review', 'resubmitted'],
    ];
}

function assignment_test_submission_transition_allowed(string $from, string $to): bool
{
    return in_array($to, assignment_test_submission_transition_map()[$from] ?? [], true);
}

function assignment_test_submission_transition_decision(
    array $item,
    array $submission,
    array $user,
    string $targetStatus,
    ?DateTimeImmutable $now = null
): array {
    $currentStatus = (string) ($submission['status'] ?? 'draft');
    if (!array_key_exists($targetStatus, assignment_test_submission_statuses())) {
        return ['allowed' => false, 'reason' => 'Unknown submission status.'];
    }
    if (!assignment_test_submission_transition_allowed($currentStatus, $targetStatus)) {
        return ['allowed' => false, 'reason' => 'This submission status transition is not allowed.'];
    }

    if (in_array($targetStatus, ['submitted', 'resubmitted'], true)) {
        if (!assignment_test_is_learner($item, $user)
            || (int) ($submission['student_id'] ?? 0) !== (int) ($user['id'] ?? 0)) {
            return ['allowed' => false, 'reason' => 'Only the submitting student may finalize this attempt.'];
        }

        $permissions = assignment_test_submission_permissions($item, $submission, $user, $now);
        $allowed = $targetStatus === 'submitted'
            ? $permissions['can_submit']
            : $permissions['can_resubmit'];
        return [
            'allowed' => (bool) $allowed,
            'reason' => $allowed ? null : $permissions['reason'],
            'will_be_late' => (bool) $permissions['will_be_late'],
        ];
    }

    if (in_array($targetStatus, ['under_review', 'marked', 'returned'], true)) {
        $allowed = assignment_test_can_mark($item, $user);
        return [
            'allowed' => $allowed,
            'reason' => $allowed ? null : 'Only the course lecturer may review or mark this submission.',
        ];
    }

    return ['allowed' => false, 'reason' => 'This transition has no authorized actor.'];
}

/**
 * Applies an authorized lifecycle transition and writes its audit event in
 * the same database transaction. Later endpoints should call this service
 * instead of updating assignment_test_submissions.status directly.
 */
function assignment_test_transition_submission(
    int $submissionId,
    string $targetStatus,
    array $actor,
    array $changes = [],
    ?DateTimeImmutable $now = null
): array {
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $submission = assignment_test_submission_find($submissionId, true, $actor);
        if (!$submission) {
            throw new RuntimeException('Submission not found.');
        }
        $item = assignment_test_find((int) $submission['assignment_test_id'], true);
        if (!$item) {
            throw new RuntimeException('Assignment/Test not found.');
        }

        $decision = assignment_test_submission_transition_decision(
            $item,
            $submission,
            $actor,
            $targetStatus,
            $now
        );
        if (!$decision['allowed']) {
            throw new RuntimeException((string) ($decision['reason'] ?? 'Status transition is not allowed.'));
        }

        $currentStatus = (string) $submission['status'];
        $eventType = $targetStatus;
        $metadata = ['from' => $currentStatus, 'to' => $targetStatus];
        $sets = ['status = ?'];
        $params = [$targetStatus];

        if (in_array($targetStatus, ['submitted', 'resubmitted'], true)) {
            $attempt = (int) $submission['submission_attempt'] + 1;
            $isLate = !empty($decision['will_be_late']) ? 1 : 0;
            $sets[] = 'submission_attempt = ?';
            $params[] = $attempt;
            $sets[] = 'submitted_at = NOW()';
            $sets[] = 'is_late = ?';
            $params[] = $isLate;
            $sets[] = 'review_started_at = NULL';
            $sets[] = 'marked_at = NULL';
            $sets[] = 'returned_at = NULL';
            $metadata['submission_attempt'] = $attempt;
            $metadata['is_late'] = (bool) $isLate;
            if ($targetStatus === 'resubmitted') {
                $metadata['previous_score'] = $submission['score'];
                $sets[] = 'score = NULL';
            }
        } elseif ($targetStatus === 'under_review') {
            $sets[] = 'review_started_at = NOW()';
            $eventType = 'review_started';
        } elseif ($targetStatus === 'marked') {
            if (!array_key_exists('score', $changes) || !is_numeric($changes['score'])) {
                throw new InvalidArgumentException('A numeric score is required to mark this submission.');
            }
            $score = (float) $changes['score'];
            if ($score < 0 || $score > (float) $item['total_marks']) {
                throw new InvalidArgumentException('Score must be between zero and the total marks.');
            }
            $feedback = array_key_exists('feedback', $changes)
                ? trim((string) $changes['feedback'])
                : $submission['feedback'];
            $sets[] = 'score = ?';
            $params[] = $score;
            $sets[] = 'feedback = ?';
            $params[] = $feedback !== '' ? $feedback : null;
            $sets[] = 'marked_at = NOW()';
            $metadata['score'] = $score;
            $metadata['feedback_updated'] = array_key_exists('feedback', $changes);
        } elseif ($targetStatus === 'returned') {
            if (array_key_exists('feedback', $changes)) {
                $feedback = trim((string) $changes['feedback']);
                $sets[] = 'feedback = ?';
                $params[] = $feedback !== '' ? $feedback : null;
                $metadata['feedback_updated'] = true;
            }
            $sets[] = 'returned_at = NOW()';
        }

        $params[] = $submissionId;
        $stmt = $pdo->prepare(
            'UPDATE assignment_test_submissions SET ' . implode(', ', $sets) . ' WHERE id = ?'
        );
        $stmt->execute($params);

        assignment_test_record_audit(
            (int) $item['id'],
            $eventType,
            (int) ($actor['id'] ?? 0),
            $submissionId,
            $metadata
        );

        $updated = assignment_test_submission_find($submissionId, false, $actor);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $updated ?: [];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function assignment_test_deadline_state(array $item, ?DateTimeImmutable $now = null): array
{
    $timezone = new DateTimeZone(date_default_timezone_get() ?: 'Africa/Lagos');
    $now = $now ?: new DateTimeImmutable('now', $timezone);
    $dueAt = new DateTimeImmutable((string) $item['due_at'], $timezone);
    $secondsRemaining = $dueAt->getTimestamp() - $now->getTimestamp();
    $pastDue = $secondsRemaining < 0;

    return [
        'now'                       => $now,
        'due_at'                    => $dueAt,
        'past_due'                  => $pastDue,
        'seconds_remaining'         => $secondsRemaining,
        'late_submissions_allowed'  => !empty($item['allow_late_submissions']),
        'initial_submission_open'   => !$pastDue || !empty($item['allow_late_submissions']),
        // Product rule: resubmission/replacement is a before-deadline option.
        'resubmission_window_open'  => !$pastDue && !empty($item['allow_resubmission']),
    ];
}

/**
 * Returns the complete server-side submission decision used by upload and
 * final-submit endpoints. JavaScript may display it but must never replace it.
 */
function assignment_test_submission_permissions(
    array $item,
    ?array $submission,
    array $user,
    ?DateTimeImmutable $now = null
): array {
    $denied = [
        'can_upload'       => false,
        'can_remove'       => false,
        'can_submit'       => false,
        'can_resubmit'     => false,
        'will_be_late'     => false,
        'reason'           => 'Submission is not available.',
    ];

    if (!assignment_test_is_learner($item, $user)
        || !assignment_test_has_active_enrollment($item, $user)) {
        $denied['reason'] = 'An active student enrollment is required.';
        return $denied;
    }

    if (($item['status'] ?? null) !== 'published') {
        $denied['reason'] = ($item['status'] ?? null) === 'closed'
            ? 'This Assignment/Test is closed.'
            : 'This Assignment/Test is not published.';
        return $denied;
    }

    $deadline = assignment_test_deadline_state($item, $now);
    $status = (string) ($submission['status'] ?? 'draft');

    if ($submission === null || $status === 'draft') {
        if (!$deadline['initial_submission_open']) {
            $denied['reason'] = 'The submission deadline has passed.';
            return $denied;
        }

        return [
            'can_upload'   => true,
            'can_remove'   => true,
            'can_submit'   => true,
            'can_resubmit' => false,
            'will_be_late' => $deadline['past_due'],
            'reason'       => null,
        ];
    }

    if (in_array($status, ['submitted', 'returned', 'resubmitted'], true)
        && $deadline['resubmission_window_open']) {
        return [
            'can_upload'   => true,
            'can_remove'   => true,
            'can_submit'   => false,
            'can_resubmit' => true,
            'will_be_late' => false,
            'reason'       => null,
        ];
    }

    if (in_array($status, ['under_review', 'marked'], true)) {
        $denied['reason'] = $status === 'marked'
            ? 'This submission has been marked.'
            : 'This submission is under review.';
        return $denied;
    }

    $denied['reason'] = $deadline['past_due']
        ? 'The resubmission deadline has passed.'
        : 'Resubmission is not enabled.';
    return $denied;
}

function assignment_test_can_submit(
    array $item,
    ?array $submission,
    array $user,
    ?DateTimeImmutable $now = null
): bool {
    $permissions = assignment_test_submission_permissions($item, $submission, $user, $now);
    return $permissions['can_submit'] || $permissions['can_resubmit'];
}

function assignment_test_can_replace_or_resubmit(
    array $item,
    ?array $submission,
    array $user,
    ?DateTimeImmutable $now = null
): bool {
    $permissions = assignment_test_submission_permissions($item, $submission, $user, $now);
    return $permissions['can_resubmit'];
}

function assignment_test_type_label(string $type): string
{
    return $type === 'test' ? 'Test' : 'Assignment';
}

function assignment_test_status_info(array $item): array
{
    $status = (string) ($item['status'] ?? 'draft');
    $labels = [
        'draft'     => ['label' => 'Draft', 'tone' => 'muted'],
        'published' => ['label' => 'Open', 'tone' => 'success'],
        'closed'    => ['label' => 'Closed', 'tone' => 'danger'],
    ];
    $info = $labels[$status] ?? ['label' => ucfirst($status), 'tone' => 'muted'];
    if ($status === 'published' && !empty($item['allow_late_submissions'])
        && assignment_test_deadline_state($item)['past_due']) {
        $info['label'] = 'Open — late submissions allowed';
    }
    $info['value'] = $status;
    return $info;
}

function assignment_test_normalize_formats($formats): array
{
    $parts = is_array($formats) ? $formats : preg_split('/[\s,]+/', (string) $formats);
    $normalized = [];
    foreach ($parts ?: [] as $format) {
        $format = strtolower(ltrim(trim((string) $format), '.'));
        if ($format !== '' && preg_match('/^[a-z0-9]{1,20}$/', $format)) {
            $normalized[$format] = true;
        }
    }
    return array_keys($normalized);
}

function assignment_test_format_marks($marks): string
{
    $number = (float) $marks;
    return fmod($number, 1.0) === 0.0
        ? (string) (int) $number
        : rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
}

function assignment_test_card_payload($itemOrId): array
{
    $item = is_array($itemOrId) ? $itemOrId : assignment_test_find((int) $itemOrId);
    if (!$item) {
        throw new RuntimeException('Assignment/Test not found.');
    }

    $timezone = new DateTimeZone(date_default_timezone_get() ?: 'Africa/Lagos');
    $dueAt = new DateTimeImmutable((string) $item['due_at'], $timezone);
    $formats = assignment_test_normalize_formats($item['allowed_formats'] ?? '');
    $status = assignment_test_status_info($item);

    return [
        'id'                  => (int) $item['id'],
        'type'                => (string) $item['type'],
        'type_label'          => assignment_test_type_label((string) $item['type']),
        'title'               => (string) $item['title'],
        'course_id'           => (int) $item['course_id'],
        'course_code'         => (string) ($item['course_code'] ?? ''),
        'course_title'        => (string) ($item['course_title'] ?? ''),
        'due_at'              => $dueAt->format(DateTimeInterface::ATOM),
        'due_label'           => $dueAt->format('M j, Y · g:i A'),
        'total_marks'         => (float) $item['total_marks'],
        'total_marks_label'   => assignment_test_format_marks($item['total_marks']) . ' Marks',
        'allowed_formats'     => $formats,
        'allowed_formats_label' => $formats ? strtoupper(implode(', ', $formats)) : 'Any supported format',
        'max_file_size'       => (int) $item['max_file_size'],
        'instructions_preview' => mb_strimwidth(trim((string) $item['instructions']), 0, 180, '…'),
        'status'              => $status['value'],
        'status_label'        => $status['label'],
        'status_tone'         => $status['tone'],
        'open_url'            => 'assignment_test?id=' . (int) $item['id'],
        'system_note'         => 'Automatically shared with this course group',
    ];
}

/**
 * The sole Chat-card HTML renderer. Both initial group rendering and polling
 * responses must call this function rather than maintaining separate markup.
 */
function assignment_test_render_chat_card(array $payload): string
{
    $formats = (array) ($payload['allowed_formats'] ?? []);
    $typeClass = ($payload['type'] ?? '') === 'test' ? 'is-test' : 'is-assignment';

    ob_start();
    ?>
    <article class="assignment-test-chat-card <?php echo h($typeClass); ?>" data-assignment-test-id="<?php echo (int) $payload['id']; ?>">
        <header class="assignment-test-chat-card__header">
            <span class="assignment-test-chat-card__icon" aria-hidden="true"><i class="bi bi-file-earmark-text"></i></span>
            <div>
                <span class="assignment-test-chat-card__label">Assignment/Test</span>
                <span class="assignment-test-chat-card__type"><?php echo h((string) $payload['type_label']); ?></span>
                <h3><?php echo h((string) $payload['title']); ?></h3>
                <p><?php echo h(trim((string) $payload['course_code'] . ' — ' . (string) $payload['course_title'], ' —')); ?></p>
            </div>
        </header>
        <dl class="assignment-test-chat-card__facts">
            <div>
                <dt><i class="bi bi-calendar3" aria-hidden="true"></i> Due Date</dt>
                <dd><?php echo h((string) $payload['due_label']); ?></dd>
            </div>
            <div>
                <dt><i class="bi bi-star" aria-hidden="true"></i> Total Marks</dt>
                <dd><?php echo h((string) $payload['total_marks_label']); ?></dd>
            </div>
            <div>
                <dt><i class="bi bi-file-earmark" aria-hidden="true"></i> Allowed Formats</dt>
                <dd><?php echo h($formats ? strtoupper(implode(', ', $formats)) : 'Any supported format'); ?></dd>
            </div>
            <div>
                <dt>Status</dt>
                <dd><span class="assignment-test-status assignment-test-status--<?php echo h((string) $payload['status_tone']); ?>"><?php echo h((string) $payload['status_label']); ?></span></dd>
            </div>
        </dl>
        <?php if (!empty($payload['instructions_preview'])): ?>
            <div class="assignment-test-chat-card__instructions">
                <strong>Instructions</strong>
                <p><?php echo h((string) $payload['instructions_preview']); ?></p>
            </div>
        <?php endif; ?>
        <?php if (!empty($payload['progress'])) echo assignment_test_render_progress($payload['progress']); ?>
        <a class="btn primary assignment-test-chat-card__cta" href="<?php echo h((string) $payload['open_url']); ?>">
            <?php echo !empty($payload['progress']['feedback_available']) ? 'View Feedback' : 'Open Assignment/Test'; ?>
        </a>
        <footer><i class="bi bi-info-circle" aria-hidden="true"></i> <?php echo h((string) $payload['system_note']); ?></footer>
    </article>
    <?php
    return trim((string) ob_get_clean());
}

function assignment_test_chat_card_bundle($itemOrId, ?array $viewer = null): array
{
    $item = is_array($itemOrId) ? $itemOrId : assignment_test_find((int) $itemOrId);
    if (!$item || ($viewer && !assignment_test_can_view($item, $viewer))) {
        return ['assignment_test' => ['id' => (int) ($item['id'] ?? $itemOrId)],
            'assignment_test_card_html' => '<article class="assignment-test-chat-card">Assignment/Test unavailable.</article>'];
    }
    $payload = assignment_test_card_payload($item);
    if ($viewer && assignment_test_is_learner($item, $viewer)) {
        $submission = assignment_test_submission_for_student((int) $item['id'], (int) $viewer['id']);
        $payload['progress'] = assignment_test_learner_progress($item, $submission);
    }
    return [
        'assignment_test' => $payload,
        'assignment_test_card_html' => assignment_test_render_chat_card($payload),
    ];
}

function assignment_test_learner_progress(array $item, ?array $submission): array
{
    $status = $submission && array_key_exists('submission_status', $submission)
        ? ($submission['submission_status'] ?? 'draft') : ($submission['status'] ?? 'draft');
    $label = ['draft'=>'Pending Submission', 'submitted'=>'Submitted ✓', 'resubmitted'=>'Submitted ✓',
        'under_review'=>'Under Review', 'marked'=>'Marked ✓', 'returned'=>'Returned'][$status] ?? 'Pending Submission';
    $score = $status === 'marked' && isset($submission['score'])
        ? assignment_test_format_marks($submission['score']) . ' / ' . assignment_test_format_marks($item['total_marks']) : null;
    return ['status'=>$status, 'label'=>$label, 'late'=>!empty($submission['is_late']), 'score'=>$score,
        'feedback_available'=>in_array($status, ['marked','returned'], true)];
}

function assignment_test_render_progress(array $progress): string
{
    ob_start(); ?>
    <div class="assignment-own-progress"><span class="assignment-submission-state is-<?php echo h($progress['status']); ?>"><?php echo h($progress['label']); ?></span>
        <?php if ($progress['late']): ?><span class="assignment-submission-state is-returned">Late</span><?php endif; ?>
        <?php if ($progress['score'] !== null): ?><strong>Score: <?php echo h($progress['score']); ?></strong><?php endif; ?>
    </div>
    <?php return trim((string) ob_get_clean());
}

function assignment_test_audit_event_types(): array
{
    return [
        'created', 'draft_saved', 'updated', 'published', 'viewed', 'closed', 'reopened',
        'submission_created', 'file_uploaded', 'file_replaced', 'file_removed',
        'submission_draft_saved', 'submitted', 'resubmitted', 'review_started', 'marking_draft_saved',
        'marked', 'returned', 'feedback_updated', 'file_viewed',
        'file_downloaded', 'bulk_downloaded', 'audit_viewed', 'due_reminder_sent',
        'receipt_created', 'receipt_viewed',
        // Reserved namespaces for future optional AI modules.
        'ai.rubric_assistance', 'ai.similarity_checked',
        'ai.marking_recommendation', 'ai.summary_created',
        'ai.feedback_suggestion',
    ];
}

function assignment_test_sanitize_audit_metadata(array $metadata): array
{
    $blockedKeyPattern = '/(?:password|secret|token|private_path|absolute_path)/i';
    $sanitize = function ($value, $key = null) use (&$sanitize, $blockedKeyPattern) {
        if ($key !== null && preg_match($blockedKeyPattern, (string) $key)) {
            return '[redacted]';
        }
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $childKey => $childValue) {
                $clean[$childKey] = $sanitize($childValue, $childKey);
            }
            return $clean;
        }
        if (is_object($value)) {
            return '[object omitted]';
        }
        if (is_string($value) && strlen($value) > 4000) {
            return substr($value, 0, 4000) . '…';
        }
        return $value;
    };

    return $sanitize($metadata);
}

function assignment_test_record_audit(
    int $assignmentTestId,
    string $eventType,
    ?int $actorId = null,
    ?int $submissionId = null,
    array $metadata = []
): int {
    if (!preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $eventType)) {
        throw new InvalidArgumentException('Invalid Assignment/Test audit event type.');
    }

    $metadata = assignment_test_sanitize_audit_metadata($metadata);
    $metadataJson = $metadata
        ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        : null;
    $ipAddress = isset($_SERVER['REMOTE_ADDR'])
        ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45)
        : null;

    $stmt = db()->prepare('
        INSERT INTO assignment_test_audit_logs
            (assignment_test_id, submission_id, actor_id, event_type, metadata_json, ip_address)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $assignmentTestId,
        $submissionId ?: null,
        $actorId ?: null,
        $eventType,
        $metadataJson,
        $ipAddress,
    ]);
    return (int) db()->lastInsertId();
}

function assignment_test_audit_entries(
    array $item,
    array $user,
    int $limit = 100,
    ?int $beforeId = null
): array {
    if (!assignment_test_can_view_audit($item, $user)) {
        throw new RuntimeException('You are not authorized to view this audit history.');
    }

    $limit = max(1, min(250, $limit));
    $params = [(int) $item['id']];
    $beforeSql = '';
    if ($beforeId !== null && $beforeId > 0) {
        $beforeSql = ' AND log.id < ?';
        $params[] = $beforeId;
    }

    $stmt = db()->prepare('
        SELECT log.*, actor.matric_no AS actor_matric_no,
               actor.full_name AS actor_name, actor.role AS actor_role
        FROM assignment_test_audit_logs log
        LEFT JOIN users actor ON actor.id = log.actor_id
        WHERE log.assignment_test_id = ?
          AND (log.event_type IN (\'created\',\'draft_saved\',\'updated\',\'published\',\'closed\',\'reopened\',\'due_reminder_sent\',\'submitted\',\'resubmitted\',\'receipt_created\',\'review_started\',\'marking_draft_saved\',\'marked\',\'returned\',\'feedback_updated\')
               OR (log.actor_id = ' . (int) $user['id'] . ' AND log.event_type IN (\'viewed\',\'file_viewed\',\'file_downloaded\',\'receipt_viewed\',\'audit_viewed\')))
          AND (log.submission_id IS NULL OR EXISTS (SELECT 1 FROM assignment_test_submissions als WHERE als.id=log.submission_id AND als.submission_attempt>0 AND als.status<>\'draft\'))' . $beforeSql . '
        ORDER BY log.id DESC
        LIMIT ' . $limit
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Saves the exact uploaded bytes under PRIVATE_UPLOAD_ROOT.
 * This deliberately does not call save_upload(), because that helper may
 * recompress PDFs/images. The before/after checksum and byte count must match.
 */
function assignment_test_store_upload_exact(
    array $file,
    array $item,
    array $user,
    ?array $submission = null
): array {
    $permissions = assignment_test_submission_permissions($item, $submission, $user);
    if (!$permissions['can_upload']) {
        throw new RuntimeException((string) ($permissions['reason'] ?? 'Upload is not allowed.'));
    }

    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('No file selected.');
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed with error code ' . (int) $file['error'] . '.');
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('The uploaded temporary file is invalid.');
    }

    $originalName = basename(str_replace('\\', '/', (string) $file['name']));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $configuredFormats = assignment_test_normalize_formats($item['allowed_formats'] ?? '');
    $allowedFormats = $configuredFormats ?: allowed_upload_extensions();
    if ($extension === '' || !in_array($extension, $allowedFormats, true)) {
        throw new RuntimeException('This file type is not allowed for this Assignment/Test.');
    }

    $actualSize = (int) filesize($tmpPath);
    $itemLimit = (int) ($item['max_file_size'] ?? UPLOAD_MAX_BYTES);
    $effectiveLimit = min(UPLOAD_MAX_BYTES, $itemLimit > 0 ? $itemLimit : UPLOAD_MAX_BYTES);
    if ($actualSize <= 0 || $actualSize > $effectiveLimit) {
        throw new RuntimeException('The file exceeds the allowed size limit.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath) ?: '';
    $allowedMimes = allowed_mime_types()[$extension] ?? [];
    if (!$allowedMimes || !in_array($mime, $allowedMimes, true)) {
        throw new RuntimeException('File content does not match an allowed file type.');
    }

    $checksumBefore = hash_file('sha256', $tmpPath);
    if ($checksumBefore === false) {
        throw new RuntimeException('Unable to verify the uploaded file.');
    }

    $subdir = 'assignment_tests/item_' . (int) $item['id'] . '/student_' . (int) $user['id'];
    $directory = rtrim(PRIVATE_UPLOAD_ROOT, '/') . '/' . $subdir;
    ensure_dir($directory);
    $storedFilename = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $target = $directory . '/' . $storedFilename;

    if (!move_uploaded_file($tmpPath, $target)) {
        throw new RuntimeException('Unable to store the uploaded file.');
    }

    $storedSize = (int) filesize($target);
    $checksumAfter = hash_file('sha256', $target);
    if ($storedSize !== $actualSize || $checksumAfter !== $checksumBefore) {
        @unlink($target);
        throw new RuntimeException('Stored-file verification failed.');
    }

    return [
        'file_path'         => $subdir . '/' . $storedFilename,
        'original_filename' => $originalName,
        'stored_filename'   => $storedFilename,
        'file_extension'    => $extension,
        'mime_type'         => $mime,
        'file_size'         => $storedSize,
        'checksum_sha256'   => $checksumAfter,
    ];
}

/** Remove only a newly stored file when its surrounding DB transaction fails. */
function assignment_test_discard_uncommitted_upload(string $relativePath): void
{
    $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
    if (!str_starts_with($normalized, 'assignment_tests/')) {
        return;
    }

    $absolute = rtrim(PRIVATE_UPLOAD_ROOT, '/') . '/' . $normalized;
    if (is_file($absolute)) {
        @unlink($absolute);
    }
}

function assignment_test_csrf_token(): string
{
    if (empty($_SESSION['assignment_test_csrf'])) {
        $_SESSION['assignment_test_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['assignment_test_csrf'];
}

function assignment_test_verify_csrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['assignment_test_csrf'])
        && hash_equals((string) $_SESSION['assignment_test_csrf'], $token);
}

function assignment_test_set_flash(string $type, string $message): void
{
    $_SESSION['assignment_test_flash'] = ['type' => $type, 'message' => $message];
}

function assignment_test_take_flash(): ?array
{
    $flash = $_SESSION['assignment_test_flash'] ?? null;
    unset($_SESSION['assignment_test_flash']);
    return is_array($flash) ? $flash : null;
}

function assignment_test_courses_owned_by(array $user): array
{
    if (!in_array($user['role'] ?? null, ['lecturer', 'admin'], true)) {
        return [];
    }
    $stmt = db()->prepare('SELECT * FROM courses WHERE lecturer_id = ? ORDER BY code');
    $stmt->execute([(int) $user['id']]);
    return $stmt->fetchAll();
}

function assignment_test_list_for_user(array $user): array
{
    if (!in_array($user['role'] ?? '', ['student', 'admin', 'lecturer'], true)) return [];
    assignment_test_close_overdue();
    $owner = in_array($user['role'], ['admin', 'lecturer'], true) ? 1 : 0;
    $learner = in_array($user['role'], ['student', 'admin'], true) ? 1 : 0;
    $stmt = db()->prepare("SELECT atx.*, c.code AS course_code, c.title AS course_title,
        c.lecturer_id AS course_lecturer_id, u.full_name AS lecturer_name,
        ats.id AS submission_id, ats.status AS submission_status, ats.submission_attempt,
        ats.submitted_at, ats.score, ats.is_late
        FROM assignment_tests atx JOIN courses c ON c.id=atx.course_id
        JOIN users u ON u.id=c.lecturer_id
        LEFT JOIN assignment_test_submissions ats ON ats.assignment_test_id=atx.id AND ats.student_id=?
        WHERE (?=1 AND c.lecturer_id=?) OR (?=1 AND atx.status IN ('published','closed')
        AND EXISTS (SELECT 1 FROM enrollments e WHERE e.course_id=c.id AND e.student_id=? AND e.status='active'))
        ORDER BY atx.created_at DESC, atx.id DESC");
    $stmt->execute([(int) $user['id'], $owner, (int) $user['id'], $learner, (int) $user['id']]);
    return $stmt->fetchAll();
}

function assignment_test_validate_item_input(array $input, array $user): array
{
    $errors = [];
    $courseId = (int) ($input['course_id'] ?? 0);
    $courseStmt = db()->prepare('SELECT * FROM courses WHERE id = ? AND lecturer_id = ?');
    $courseStmt->execute([$courseId, (int) ($user['id'] ?? 0)]);
    $course = $courseStmt->fetch();
    if (!$course || !assignment_test_is_course_owner($course, $user)) {
        $errors['course_id'] = 'Select a course that you teach.';
    }

    $type = strtolower(trim((string) ($input['type'] ?? '')));
    if (!in_array($type, ['assignment', 'test'], true)) {
        $errors['type'] = 'Select Assignment or Test.';
    }
    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 255) {
        $errors['title'] = 'Enter a title of 255 characters or fewer.';
    }
    $instructions = trim((string) ($input['instructions'] ?? ''));
    if ($instructions === '') {
        $errors['instructions'] = 'Instructions are required.';
    }

    $timezone = new DateTimeZone(date_default_timezone_get() ?: 'Africa/Lagos');
    try {
        $dueAt = new DateTimeImmutable((string) ($input['due_at'] ?? ''), $timezone);
    } catch (Throwable $e) {
        $dueAt = null;
        $errors['due_at'] = 'Enter a valid due date and time.';
    }
    if ($dueAt && $dueAt <= new DateTimeImmutable('now', $timezone)) {
        $errors['due_at'] = 'Due date must be in the future.';
    }

    $totalMarks = filter_var($input['total_marks'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($totalMarks === false || $totalMarks <= 0 || $totalMarks > 999999.99) {
        $errors['total_marks'] = 'Total marks must be greater than zero.';
    }

    $formats = assignment_test_normalize_formats($input['allowed_formats'] ?? '');
    $unsupported = array_diff($formats, allowed_upload_extensions());
    if ($unsupported) {
        $errors['allowed_formats'] = 'Unsupported format: ' . implode(', ', $unsupported) . '.';
    }

    $maxFileSizeMb = filter_var($input['max_file_size_mb'] ?? 20, FILTER_VALIDATE_INT);
    if ($maxFileSizeMb === false || $maxFileSizeMb < 1 || $maxFileSizeMb > (int) floor(UPLOAD_MAX_BYTES / 1048576)) {
        $errors['max_file_size_mb'] = 'Maximum file size must be between 1 and ' . (int) floor(UPLOAD_MAX_BYTES / 1048576) . ' MB.';
    }

    return [[
        'course_id' => $courseId,
        'type' => $type,
        'title' => $title,
        'instructions' => $instructions,
        'due_at' => $dueAt ? $dueAt->format('Y-m-d H:i:s') : null,
        'total_marks' => (float) $totalMarks,
        'allowed_formats' => implode(',', $formats),
        'max_file_size' => (int) $maxFileSizeMb * 1048576,
        'allow_late_submissions' => !empty($input['allow_late_submissions']) ? 1 : 0,
        'allow_resubmission' => !empty($input['allow_resubmission']) ? 1 : 0,
    ], $errors];
}

function assignment_test_create_draft(array $input, array $user): array
{
    [$data, $errors] = assignment_test_validate_item_input($input, $user);
    if ($errors) {
        throw new InvalidArgumentException(implode(' ', array_values($errors)));
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('
            INSERT INTO assignment_tests
                (course_id, created_by, type, title, instructions, due_at,
                 total_marks, allowed_formats, max_file_size,
                 allow_late_submissions, allow_resubmission, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'draft\')
        ');
        $stmt->execute([
            $data['course_id'], (int) $user['id'], $data['type'], $data['title'],
            $data['instructions'], $data['due_at'], $data['total_marks'],
            $data['allowed_formats'], $data['max_file_size'],
            $data['allow_late_submissions'], $data['allow_resubmission'],
        ]);
        $id = (int) $pdo->lastInsertId();
        assignment_test_record_audit($id, 'created', (int) $user['id'], null, [
            'type' => $data['type'], 'course_id' => $data['course_id'],
        ]);
        $pdo->commit();
        return assignment_test_find($id) ?: [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function assignment_test_update_item(int $assignmentTestId, array $input, array $user): array
{
    [$data, $errors] = assignment_test_validate_item_input($input, $user);
    if ($errors) {
        throw new InvalidArgumentException(implode(' ', array_values($errors)));
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $item = assignment_test_find($assignmentTestId, true);
        if (!$item || !assignment_test_can_manage($item, $user)) {
            throw new RuntimeException('You cannot edit this Assignment/Test.');
        }
        // Closed work can receive a new deadline, but stays closed until the
        // owner explicitly reopens it. Its course/type must remain immutable.
        if ($item['status'] !== 'draft'
            && ((int) $data['course_id'] !== (int) $item['course_id'] || $data['type'] !== $item['type'])) {
            throw new RuntimeException('Course and type cannot change after publication.');
        }

        $stmt = $pdo->prepare('
            UPDATE assignment_tests
            SET course_id=?, type=?, title=?, instructions=?, due_at=?, total_marks=?,
                allowed_formats=?, max_file_size=?, allow_late_submissions=?, allow_resubmission=?
            WHERE id=?
        ');
        $stmt->execute([
            $data['course_id'], $data['type'], $data['title'], $data['instructions'],
            $data['due_at'], $data['total_marks'], $data['allowed_formats'],
            $data['max_file_size'], $data['allow_late_submissions'],
            $data['allow_resubmission'], $assignmentTestId,
        ]);
        if ($item['status'] === 'published' && $data['due_at'] !== $item['due_at']) {
            $pdo->prepare('UPDATE assignment_test_notification_jobs SET cancelled_at=NOW() WHERE assignment_test_id=? AND sent_at IS NULL')
                ->execute([$assignmentTestId]);
            telegram_cancel_assignment_reminders($assignmentTestId, 'Assignment/Test deadline changed.');
            $reminderItem = array_merge($item, $data);
            assignment_test_schedule_due_reminders($reminderItem);
        }
        assignment_test_record_audit($assignmentTestId, $item['status'] === 'draft' ? 'draft_saved' : 'updated', (int) $user['id'], null, [
            'status' => $item['status'], 'due_at' => $data['due_at'],
        ]);
        assignment_test_sync_calendar(array_merge($item, $data));
        $pdo->commit();
        return assignment_test_find($assignmentTestId) ?: [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function assignment_test_notify_user(array $item, int $userId, string $type, string $message, ?string $logicalKey = null, ?string $sourceType = null, ?int $sourceId = null, array $contextExtras = []): void
{
    $pref = db()->prepare('SELECT pref_web_notifications FROM users WHERE id = ?');
    $pref->execute([$userId]);
    if ((bool) $pref->fetchColumn()) {
        db()->prepare('
            INSERT INTO web_notifications
                (user_id, assignment_test_id, notification_type, message, action_url)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([
            $userId,
            (int) $item['id'],
            $type,
            mb_strimwidth($message, 0, 300, '…'),
            'assignment_test?id=' . (int) $item['id'],
        ]);
    }
    $notificationType = match ($type) {
        'published' => 'assignment_test_published',
        'due_reminder' => 'assignment_test_reminder',
        'closed' => 'assignment_test_closed',
        'reopened' => 'assignment_test_reopened',
        'returned' => 'assignment_test_returned',
        'marked' => 'assignment_test_result_available',
        'submission_receipt' => 'assignment_test_submission_received',
        default => null,
    };
    if ($notificationType !== null) {
        if ($logicalKey === null && in_array($type, ['closed','reopened'], true)) {
            $cycleQ=db()->prepare('SELECT COUNT(*) FROM assignment_test_audit_logs WHERE assignment_test_id=? AND event_type=?');
            $cycleQ->execute([(int)$item['id'],$type]);
            $logicalKey=$type.':cycle:'.((int)$cycleQ->fetchColumn()+1);
        }
        $context = array_merge([
            'course_code'=>(string)($item['course_code']??'LCS'),
            'course_title'=>(string)($item['course_title']??''),
            'assessment_type'=>assignment_test_type_label((string)($item['type']??'assignment')),
            'title'=>(string)$item['title'],
            'due_at'=>(string)($item['due_at']??date('Y-m-d H:i:s')),
            'published_at'=>(string)($item['published_at']??''),
            'lecturer'=>(string)($item['lecturer_name']??''),
            'instructions'=>(string)($item['instructions']??''),
            'allow_late_submissions'=>!empty($item['allow_late_submissions']),
            'status'=>match($type){'returned'=>'Returned for revision','marked'=>'Result available','submission_receipt'=>'Submission received',default=>ucfirst(str_replace('_',' ',$type))},
            'action_url'=>'assignment_test?id='.(int)$item['id'],
        ], $contextExtras);
        lcs_notification_fanout($notificationType,$userId,$context,$sourceType??'assignment_test',$sourceId??(int)$item['id'],$logicalKey??($type.':'.(string)($item['due_at']??'')));
    }
}

function assignment_test_enrolled_student_ids(int $courseId): array
{
    $stmt = db()->prepare("
        SELECT e.student_id
        FROM enrollments e
        JOIN users u ON u.id = e.student_id AND u.role IN ('student','admin')
        JOIN courses c ON c.id=e.course_id AND c.lecturer_id<>u.id
        WHERE e.course_id = ? AND e.status = 'active'
    ");
    $stmt->execute([$courseId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function assignment_test_schedule_due_reminders(array $item): void
{
    $due = new DateTimeImmutable((string) $item['due_at']);
    $scheduled = $due->modify('-24 hours');
    $now = new DateTimeImmutable('now');
    if ($scheduled < $now) $scheduled = $now;
    $stmt = db()->prepare('
        INSERT INTO assignment_test_notification_jobs
            (assignment_test_id, user_id, notification_type, scheduled_at)
        VALUES (?, ?, \'due_reminder\', ?)
        ON DUPLICATE KEY UPDATE cancelled_at = IF(sent_at IS NULL, NULL, cancelled_at)
    ');
    foreach (assignment_test_enrolled_student_ids((int) $item['course_id']) as $studentId) {
        $stmt->execute([(int) $item['id'], $studentId, $scheduled->format('Y-m-d H:i:s')]);
    }
}

function assignment_test_publish(int $assignmentTestId, array $user): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $item = assignment_test_find($assignmentTestId, true);
        if (!$item || !assignment_test_can_manage($item, $user)) {
            throw new RuntimeException('You cannot publish this Assignment/Test.');
        }
        if ($item['status'] === 'closed') {
            throw new RuntimeException('A closed Assignment/Test cannot be published.');
        }
        if ($item['status'] === 'published') {
            assignment_test_sync_calendar($item);
            $pdo->commit();
            return $item;
        }
        if (strtotime($item['due_at']) <= time()) {
            throw new RuntimeException('Update the due date before publishing this draft.');
        }

        $pdo->prepare("UPDATE assignment_tests SET status='published', published_at=NOW() WHERE id=?")
            ->execute([$assignmentTestId]);
        $groupId = get_or_create_chat_group((int) $item['course_id']);
        $existingCard = $pdo->prepare('
            SELECT id FROM chat_messages
            WHERE group_id = ? AND assignment_test_id = ?
            ORDER BY id LIMIT 1
        ');
        $existingCard->execute([$groupId, $assignmentTestId]);
        if (!$existingCard->fetchColumn()) {
            $pdo->prepare('
                INSERT INTO chat_messages (group_id, sender_id, content, assignment_test_id)
                VALUES (?, ?, NULL, ?)
            ')->execute([$groupId, (int) $user['id'], $assignmentTestId]);
        }

        $item['status'] = 'published';
        $item['published_at'] = date('Y-m-d H:i:s');
        assignment_test_sync_calendar($item);
        foreach (assignment_test_enrolled_student_ids((int) $item['course_id']) as $studentId) {
            assignment_test_notify_user(
                $item,
                $studentId,
                'published',
                $item['course_code'] . ': ' . assignment_test_type_label($item['type']) . ' published — ' . $item['title']
            );
        }
        assignment_test_schedule_due_reminders($item);
        assignment_test_record_audit($assignmentTestId, 'published', (int) $user['id'], null, [
            'group_id' => $groupId,
        ]);
        $pdo->commit();
        return assignment_test_find($assignmentTestId) ?: [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function assignment_test_close(int $assignmentTestId, array $user): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $item = assignment_test_find($assignmentTestId, true);
        if (!$item || !assignment_test_can_manage($item, $user)) {
            throw new RuntimeException('You cannot close this Assignment/Test.');
        }
        if ($item['status'] !== 'published') {
            throw new RuntimeException('Only a published Assignment/Test can be closed.');
        }
        assignment_test_close_locked($item, (int) $user['id'], 'manual');
        $pdo->commit();
        return assignment_test_find($assignmentTestId) ?: [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Shared closure side effects; caller must hold the item lock in a transaction. */
function assignment_test_close_locked(array $item, ?int $actorId, string $reason): void
{
    $pdo = db();
    $id = (int) $item['id'];
    $pdo->prepare("UPDATE assignment_tests SET status='closed', updated_at=NOW() WHERE id=?")
        ->execute([$id]);
    $pdo->prepare('UPDATE assignment_test_notification_jobs SET cancelled_at=NOW() WHERE assignment_test_id=? AND sent_at IS NULL AND cancelled_at IS NULL')
        ->execute([$id]);
    telegram_cancel_assignment_reminders($id, 'Assignment/Test reminder is no longer valid.');
    foreach (assignment_test_enrolled_student_ids((int) $item['course_id']) as $studentId) {
        assignment_test_notify_user($item, $studentId, 'closed', $item['course_code'] . ': ' . $item['title'] . ' has closed.');
    }
    assignment_test_record_audit($id, 'closed', $actorId, null, ['reason'=>$reason, 'due_at'=>$item['due_at']]);
    assignment_test_sync_calendar(array_merge($item, ['status'=>'closed']));
}

/**
 * Reconcile deadlines from page reads, live polling and the notification worker.
 * Row locks plus a second deadline/status check prevent duplicate events when
 * requests overlap. Explicit late-submission permission remains an exception.
 * Savepoints keep this safe inside a caller's existing transaction as well.
 */
function assignment_test_close_overdue(?int $assignmentTestId = null): int
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM assignment_tests
        WHERE status='published' AND allow_late_submissions=0 AND due_at < ?"
        . ($assignmentTestId !== null ? ' AND id=?' : '') . ' ORDER BY id');
    $params = [date('Y-m-d H:i:s')];
    if ($assignmentTestId !== null) $params[] = $assignmentTestId;
    $stmt->execute($params);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $closed = 0;
    foreach ($ids as $id) {
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        else $pdo->exec('SAVEPOINT assignment_test_auto_close');
        try {
            $item = assignment_test_find((int) $id, true);
            if ($item && $item['status'] === 'published'
                && empty($item['allow_late_submissions'])
                && assignment_test_deadline_state($item)['past_due']) {
                assignment_test_close_locked($item, null, 'deadline_elapsed');
                $closed++;
            }
            if ($ownsTransaction) $pdo->commit();
            else $pdo->exec('RELEASE SAVEPOINT assignment_test_auto_close');
        } catch (Throwable $e) {
            if ($ownsTransaction) { if ($pdo->inTransaction()) $pdo->rollBack(); }
            else {
                $pdo->exec('ROLLBACK TO SAVEPOINT assignment_test_auto_close');
                $pdo->exec('RELEASE SAVEPOINT assignment_test_auto_close');
            }
            throw $e;
        }
    }
    return $closed;
}

function assignment_test_reopen(int $assignmentTestId, array $user): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $item = assignment_test_find($assignmentTestId, true);
        if (!$item || !assignment_test_can_manage($item, $user)) throw new RuntimeException('You cannot reopen this Assignment/Test.');
        if ($item['status'] !== 'closed') throw new RuntimeException('Only a closed Assignment/Test can be reopened.');
        if (assignment_test_deadline_state($item)['past_due'] && empty($item['allow_late_submissions'])) {
            throw new RuntimeException('Edit the due date to a future time before reopening this Assignment/Test.');
        }
        $pdo->prepare("UPDATE assignment_tests SET status='published' WHERE id=?")->execute([$assignmentTestId]);
        $item['status'] = 'published';
        assignment_test_sync_calendar($item);
        if (strtotime($item['due_at']) > time()) assignment_test_schedule_due_reminders($item);
        foreach (assignment_test_enrolled_student_ids((int) $item['course_id']) as $studentId) {
            assignment_test_notify_user($item, $studentId, 'reopened', $item['title'] . ' has reopened. Existing deadline rules still apply.');
        }
        assignment_test_record_audit($assignmentTestId, 'reopened', (int) $user['id']);
        $pdo->commit();
        return assignment_test_find($assignmentTestId) ?: [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function assignment_test_fire_due_reminders(int $limit = 50, ?int $assignmentTestId = null): int
{
    if (PHP_SAPI !== 'cli') throw new RuntimeException('Scheduled reminders require the server worker.');
    assignment_test_close_overdue($assignmentTestId);
    $limit = max(1, min(200, $limit));
    $jobs = db()->query('
        SELECT j.*, atx.title, atx.type, atx.course_id, atx.due_at,
               atx.status, c.code AS course_code, c.title AS course_title
        FROM assignment_test_notification_jobs j
        JOIN assignment_tests atx ON atx.id = j.assignment_test_id
        JOIN courses c ON c.id = atx.course_id
        WHERE j.sent_at IS NULL AND j.cancelled_at IS NULL
          AND j.scheduled_at <= NOW() AND atx.status = \'published\'
        ' . ($assignmentTestId === null ? '' : ' AND atx.id = ' . (int) $assignmentTestId) . '
        ORDER BY j.scheduled_at, j.id
        LIMIT ' . $limit
    )->fetchAll();
    $sent = 0;
    foreach ($jobs as $job) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $reminderItem = assignment_test_find((int) $job['assignment_test_id'], true);
            if (!$reminderItem || $reminderItem['status'] !== 'published'
                || !assignment_test_has_active_enrollment($reminderItem, ['id' => (int) $job['user_id'], 'role' => 'student'])) {
                $pdo->prepare('UPDATE assignment_test_notification_jobs SET cancelled_at=NOW() WHERE id=? AND sent_at IS NULL')->execute([(int) $job['id']]);
                $pdo->commit();
                continue;
            }
            $claim = $pdo->prepare('UPDATE assignment_test_notification_jobs SET sent_at=NOW() WHERE id=? AND sent_at IS NULL AND cancelled_at IS NULL');
            $claim->execute([(int) $job['id']]);
            if ($claim->rowCount() === 1) {
                assignment_test_notify_user(
                    $reminderItem,
                    (int) $job['user_id'],
                    'due_reminder',
                    $job['course_code'] . ': ' . $job['title'] . ' is due ' . date('M j, Y \a\t g:i A', strtotime($job['due_at'])) . '.',
                    'due-job:'.(int)$job['id'],
                    'assignment_test_reminder',
                    (int)$job['id']
                );
                assignment_test_record_audit((int) $job['assignment_test_id'], 'due_reminder_sent', null, null, [
                    'user_id' => (int) $job['user_id'],
                ]);
                $sent++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Assignment/Test reminder failed for job ' . (int) $job['id'] . ': ' . $e->getMessage());
        }
    }
    return $sent;
}

function assignment_test_get_or_create_submission(array $item, array $user): array
{
    $existing = assignment_test_submission_for_student((int) $item['id'], (int) $user['id']);
    if ($existing) return $existing;
    $permissions = assignment_test_submission_permissions($item, null, $user);
    if (!$permissions['can_upload']) {
        throw new RuntimeException((string) $permissions['reason']);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('
            INSERT IGNORE INTO assignment_test_submissions (assignment_test_id, student_id)
            VALUES (?, ?)
        ')->execute([(int) $item['id'], (int) $user['id']]);
        $submission = assignment_test_submission_for_student((int) $item['id'], (int) $user['id']);
        if (!$submission) throw new RuntimeException('Unable to create submission draft.');
        assignment_test_record_audit((int) $item['id'], 'submission_created', (int) $user['id'], (int) $submission['id']);
        $pdo->commit();
        return $submission;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function assignment_test_submission_files_for_user(array $submission, array $item, array $user): array
{
    if (!assignment_test_submission_find((int) $submission['id'], false, $user)) return [];
    $finalizedOnly = assignment_test_is_course_owner($item, $user)
        ? ' AND ' . assignment_test_finalized_file_sql('f') : '';
    $stmt = db()->prepare('
        SELECT id, submission_id, replaces_file_id, original_filename, stored_filename,
               file_extension, mime_type, file_size, checksum_sha256,
               version_number, is_current, uploaded_at
        FROM assignment_test_submission_files f
        WHERE submission_id = ?' . $finalizedOnly . '
        ORDER BY is_current DESC, uploaded_at DESC, id DESC
    ');
    $stmt->execute([(int) $submission['id']]);
    return $stmt->fetchAll();
}

/** Lecturer review always uses the last finalized attempt, not unsent replacements. */
function assignment_test_review_snapshot(array $submission, array $item, array $user, ?array $receipts = null, ?array $files = null): array
{
    if (!assignment_test_can_view_all_submissions($item, $user)) throw new RuntimeException('Review is unavailable.');
    $receipts = $receipts ?? assignment_test_submission_receipts($submission, $item, $user);
    $receipt = $receipts[0] ?? null;
    $files = $files ?? assignment_test_submission_files_for_user($submission, $item, $user);
    if (!$receipt) {
        return ['files' => [], 'note_text' => null, 'receipt' => null];
    }
    $manifest = json_decode($receipt['file_manifest_json'], true, 512, JSON_THROW_ON_ERROR);
    $ids = array_map('intval', array_column($manifest, 'id'));
    return [
        'files' => array_values(array_filter($files, fn($f) => in_array((int) $f['id'], $ids, true))),
        'note_text' => $receipt['note_text_snapshot'],
        'receipt' => $receipt,
    ];
}

function assignment_test_add_submission_file(
    array $item,
    array $user,
    array $upload,
    ?int $replaceFileId = null
): array {
    $submission = assignment_test_get_or_create_submission($item, $user);
    $replace = null;
    if ($replaceFileId) {
        $replace = assignment_test_submission_file_for_user($replaceFileId, $user);
        if (!$replace || (int) $replace['submission_id'] !== (int) $submission['id'] || !$replace['is_current']) {
            throw new RuntimeException('The file selected for replacement is unavailable.');
        }
    }
    $stored = assignment_test_store_upload_exact($upload, $item, $user, $submission);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $submission = assignment_test_submission_find((int) $submission['id'], true, $user);
        $item = assignment_test_find((int) $item['id'], true);
        if (!$submission || !$item) throw new RuntimeException('Submission is unavailable.');
        $permissions = assignment_test_submission_permissions($item, $submission, $user);
        if (!$permissions['can_upload']) throw new RuntimeException((string) $permissions['reason']);
        if ($replace) {
            $replace = assignment_test_submission_file_for_user((int) $replace['id'], $user);
            if (!$replace || !$replace['is_current']) throw new RuntimeException('This file has already been replaced. Reload and try again.');
            $pdo->prepare('UPDATE assignment_test_submission_files SET is_current=0 WHERE id=? AND submission_id=?')
                ->execute([(int) $replace['id'], (int) $submission['id']]);
        }
        $version = $replace ? (int) $replace['version_number'] + 1 : 1;
        $stmt = $pdo->prepare('
            INSERT INTO assignment_test_submission_files
                (submission_id, replaces_file_id, file_path, original_filename,
                 stored_filename, file_extension, mime_type, file_size,
                 checksum_sha256, version_number, is_current)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ');
        $stmt->execute([
            (int) $submission['id'], $replace ? (int) $replace['id'] : null,
            $stored['file_path'], $stored['original_filename'], $stored['stored_filename'],
            $stored['file_extension'], $stored['mime_type'], $stored['file_size'],
            $stored['checksum_sha256'], $version,
        ]);
        $fileId = (int) $pdo->lastInsertId();
        assignment_test_record_audit(
            (int) $item['id'],
            $replace ? 'file_replaced' : 'file_uploaded',
            (int) $user['id'],
            (int) $submission['id'],
            ['file_id' => $fileId, 'replaces_file_id' => $replaceFileId, 'version' => $version,
             'filename' => $stored['original_filename'], 'size' => $stored['file_size'],
             'checksum_sha256' => $stored['checksum_sha256']]
        );
        $pdo->commit();
        return assignment_test_submission_file_for_user($fileId, $user) ?: [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        assignment_test_discard_uncommitted_upload($stored['file_path']);
        throw $e;
    }
}

function assignment_test_remove_submission_file(int $fileId, array $item, array $user): void
{
    $submission = assignment_test_submission_for_student((int) $item['id'], (int) $user['id']);
    if (!$submission) throw new RuntimeException('Submission not found.');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $submission = assignment_test_submission_find((int) $submission['id'], true, $user);
        $item = assignment_test_find((int) $item['id'], true);
        $file = assignment_test_submission_file_for_user($fileId, $user);
        if (!$file || !$submission || !$item || (int) $file['submission_id'] !== (int) $submission['id'] || !$file['is_current']) throw new RuntimeException('Current file not found.');
        $permissions = assignment_test_submission_permissions($item, $submission, $user);
        if (!$permissions['can_remove']) throw new RuntimeException((string) $permissions['reason']);
        $pdo->prepare('UPDATE assignment_test_submission_files SET is_current=0 WHERE id=? AND submission_id=?')->execute([$fileId, (int) $submission['id']]);
        assignment_test_record_audit((int) $item['id'], 'file_removed', (int) $user['id'], (int) $submission['id'], [
            'file_id' => $fileId, 'filename' => $file['original_filename'], 'version' => (int) $file['version_number'],
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function assignment_test_save_submission_note(array $item, array $user, string $note): array
{
    $submission = assignment_test_get_or_create_submission($item, $user);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $submission = assignment_test_submission_find((int) $submission['id'], true, $user);
        $item = assignment_test_find((int) $item['id'], true);
        if (!$submission || !$item) throw new RuntimeException('Submission not found.');
        $permissions = assignment_test_submission_permissions($item, $submission, $user);
        if (!$permissions['can_upload']) throw new RuntimeException((string) $permissions['reason']);
        $note = trim($note);
        $pdo->prepare('UPDATE assignment_test_submissions SET note_text=? WHERE id=? AND student_id=?')->execute([$note !== '' ? $note : null, (int) $submission['id'], (int) $user['id']]);
        assignment_test_record_audit((int) $item['id'], 'submission_draft_saved', (int) $user['id'], (int) $submission['id']);
        $pdo->commit();
        return assignment_test_submission_for_student((int) $item['id'], (int) $user['id']) ?: [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function assignment_test_finalize_submission(array $item, array $user): array
{
    $submission = assignment_test_submission_for_student((int) $item['id'], (int) $user['id']);
    if (!$submission) throw new RuntimeException('Create a draft and upload a file or add a note first.');
    $fileStmt = db()->prepare('SELECT COUNT(*) FROM assignment_test_submission_files WHERE submission_id=? AND is_current=1');
    $fileStmt->execute([(int) $submission['id']]);
    if ((int) $fileStmt->fetchColumn() === 0 && trim((string) ($submission['note_text'] ?? '')) === '') {
        throw new RuntimeException('Upload at least one file or add a text response before submitting.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $submission = assignment_test_submission_find((int) $submission['id'], true, $user);
        if (!$submission) throw new RuntimeException('Submission not found.');
        $fileStmt->execute([(int) $submission['id']]);
        if ((int) $fileStmt->fetchColumn() === 0 && trim((string) ($submission['note_text'] ?? '')) === '') throw new RuntimeException('Add work before submitting.');
        $target = (int) $submission['submission_attempt'] > 0 ? 'resubmitted' : 'submitted';
        $updated = assignment_test_transition_submission((int) $submission['id'], $target, $user);
        $manifestStmt = $pdo->prepare('SELECT id, original_filename, file_extension, mime_type, file_size, checksum_sha256, version_number, uploaded_at FROM assignment_test_submission_files WHERE submission_id=? AND is_current=1 ORDER BY id');
        $manifestStmt->execute([(int) $submission['id']]);
        $manifest = $manifestStmt->fetchAll();
        $attempt = (int) $updated['submission_attempt'];
        $receiptCode = sprintf('AT-%d-%d-%d-%s', (int) $item['id'], (int) $submission['id'], $attempt, strtoupper(bin2hex(random_bytes(4))));
        $pdo->prepare('INSERT INTO assignment_test_submission_receipts (submission_id, submission_attempt, receipt_code, submitted_at, is_late, note_text_snapshot, file_manifest_json) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                (int) $submission['id'], $attempt, $receiptCode, $updated['submitted_at'],
                (int) $updated['is_late'], $updated['note_text'],
                json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        assignment_test_record_audit((int) $item['id'], 'receipt_created', (int) $user['id'], (int) $submission['id'], [
            'attempt' => $attempt, 'receipt_code' => $receiptCode, 'file_count' => count($manifest),
        ]);
        assignment_test_notify_user(
            $item,
            (int) $user['id'],
            'submission_receipt',
            'Submission receipt: ' . $item['title'] . ' — attempt ' . $attempt . ' received.',
            'submission:'.(int)$submission['id'].':attempt:'.$attempt.':receipt',
            null,
            null,
            ['submitted_at' => (string)$updated['submitted_at'], 'attempt_number' => $attempt]
        );
        $pdo->commit();
        return $updated;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function assignment_test_submission_receipts(array $submission, array $item, array $user): array
{
    if (!assignment_test_submission_find((int) $submission['id'], false, $user)) return [];
    $stmt = db()->prepare('SELECT * FROM assignment_test_submission_receipts WHERE submission_id=? ORDER BY submission_attempt DESC');
    $stmt->execute([(int) $submission['id']]);
    return $stmt->fetchAll();
}

function assignment_test_submission_receipt_find(int $receiptId, array $user): ?array
{
    $params = [$receiptId];
    $scope = assignment_test_submission_scope_sql('ats', $user, $params);
    $stmt = db()->prepare('SELECT r.*, ats.assignment_test_id, ats.student_id, u.matric_no AS student_matric_no, atx.title AS assignment_test_title, atx.type, atx.total_marks, c.code AS course_code, c.title AS course_title, c.lecturer_id AS course_lecturer_id FROM assignment_test_submission_receipts r JOIN assignment_test_submissions ats ON ats.id=r.submission_id JOIN users u ON u.id=ats.student_id JOIN assignment_tests atx ON atx.id=ats.assignment_test_id JOIN courses c ON c.id=atx.course_id WHERE r.id=?' . $scope . ' LIMIT 1');
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

function assignment_test_submission_roster(array $item, array $user, array $filters = []): array
{
    if (!assignment_test_can_view_all_submissions($item, $user)) {
        throw new RuntimeException('You cannot view these submissions.');
    }
    $params = [(int) $item['course_id']];
    $where = '';
    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $where .= ' AND u.matric_no LIKE ?';
        $params[] = '%' . $search . '%';
    }
    $status = (string) ($filters['status'] ?? '');
    if (in_array($status, ['pending','draft'], true)) {
        $where .= ' AND 1=0';
    } elseif (array_key_exists($status, assignment_test_submission_statuses())) {
        $where .= ' AND ats.status = ?';
        $params[] = $status;
    }
    $sql = '
        SELECT u.id AS student_id, u.matric_no,
               ats.id AS submission_id, ats.status, ats.submission_attempt,
               ats.submitted_at, ats.review_started_at, ats.score, ats.marked_at,
               (SELECT JSON_LENGTH(r.file_manifest_json) FROM assignment_test_submission_receipts r
                WHERE r.submission_id=ats.id ORDER BY r.submission_attempt DESC LIMIT 1) AS current_file_count
        FROM enrollments e
        JOIN users u ON u.id=e.student_id AND u.role IN (\'student\',\'admin\')
        JOIN assignment_test_submissions ats
            ON ats.assignment_test_id=? AND ats.student_id=u.id AND ats.submission_attempt>0 AND ats.status<>\'draft\'
        WHERE e.course_id=? AND e.status=\'active\'' . $where . '
        ORDER BY u.matric_no
    ';
    array_unshift($params, (int) $item['id']);
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function assignment_test_save_marking_draft(
    array $item,
    int $submissionId,
    array $user,
    $score,
    string $feedback
): array {
    return assignment_test_apply_marking($item, $submissionId, $user, 'draft', $score, $feedback);
}

/** Lock the attempt through validation, lifecycle, audit and notification writes. */
function assignment_test_apply_marking(
    array $item,
    int $submissionId,
    array $user,
    string $action,
    $score,
    string $feedback
): array {
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $submission = assignment_test_submission_find($submissionId, true, $user);
        $item = assignment_test_find((int) $item['id'], true);
        if (!$item) throw new RuntimeException('Assignment/Test not found.');
        if (!assignment_test_can_mark($item, $user)) throw new RuntimeException('Only the course lecturer may mark.');
        if (!$submission || (int) $submission['assignment_test_id'] !== (int) $item['id']) throw new RuntimeException('Submission not found.');
        if (!in_array($action, ['draft', 'complete', 'return'], true)) throw new InvalidArgumentException('Unknown marking action.');
        $scoreValue = null;
        if ($action !== 'return') {
            if ($score !== '' && $score !== null && !is_numeric($score)) throw new InvalidArgumentException('Enter a numeric score.');
            if (($action === 'complete' || $submission['status'] === 'marked') && ($score === '' || $score === null)) throw new InvalidArgumentException('A marked submission requires a score.');
            $scoreValue = $score === '' || $score === null ? null : (float) $score;
            if ($scoreValue !== null && (!is_finite($scoreValue) || $scoreValue < 0 || $scoreValue > (float) $item['total_marks'])) throw new InvalidArgumentException('Score is outside the allowed range.');
        }
        if (in_array($submission['status'], ['submitted', 'resubmitted'], true)) {
            $submission = assignment_test_transition_submission($submissionId, 'under_review', $user);
        }
        if (!in_array($submission['status'], ['under_review', 'marked'], true)) throw new RuntimeException('This submission cannot be edited.');
        if ($action === 'return') {
            assignment_test_transition_submission($submissionId, 'returned', $user, ['feedback' => $feedback]);
        } elseif ($action === 'complete' && $submission['status'] !== 'marked') {
            assignment_test_transition_submission($submissionId, 'marked', $user, ['score' => $scoreValue, 'feedback' => $feedback]);
        } else {
            $pdo->prepare('UPDATE assignment_test_submissions SET score=?, feedback=? WHERE id=?')
            ->execute([$scoreValue, trim($feedback) !== '' ? trim($feedback) : null, $submissionId]);
            assignment_test_record_audit((int) $item['id'], $submission['status'] === 'marked' ? 'feedback_updated' : 'marking_draft_saved', (int) $user['id'], $submissionId, [
            'score' => $scoreValue, 'feedback_updated' => true,
            ]);
        }
        if ($action !== 'draft') {
            assignment_test_notify_user($item, (int) $submission['student_id'], $action === 'return' ? 'returned' : 'marked',
                $item['title'] . ($action === 'return' ? ' was returned for revision.' : ' score or feedback is available.'),
                'submission:'.(int)$submission['id'].':attempt:'.(int)$submission['submission_attempt'].':'.($action === 'return'?'returned':'marked'),
                null,
                null,
                ['attempt_number' => (int)$submission['submission_attempt']]);
        }
        $updated = assignment_test_submission_find($submissionId, false, $user) ?: [];
        if ($ownsTransaction) $pdo->commit();
        return $updated;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function assignment_test_complete_marking(
    array $item,
    int $submissionId,
    array $user,
    $score,
    string $feedback
): array {
    return assignment_test_apply_marking($item, $submissionId, $user, 'complete', $score, $feedback);
}

function assignment_test_return_submission(
    array $item,
    int $submissionId,
    array $user,
    string $feedback
): array {
    return assignment_test_apply_marking($item, $submissionId, $user, 'return', null, $feedback);
}

/** Shared list rendering for the page and its authenticated live polling response. */
function assignment_test_render_list_rows(array $items, array $user): string
{
    ob_start();
    foreach ($items as $item):
        $status = assignment_test_status_info($item);
        $owner = assignment_test_can_manage($item, $user);
        $progress = $owner ? null : assignment_test_learner_progress($item, $item);
        ?>
        <article class="assignment-list-row" data-assignment-id="<?php echo (int) $item['id']; ?>" data-status="<?php echo h($item['status']); ?>">
            <span class="assignment-list-icon is-<?php echo h($item['type']); ?>" aria-hidden="true"><i class="bi <?php echo $item['type'] === 'test' ? 'bi-clipboard-check' : 'bi-file-earmark-text'; ?>"></i></span>
            <div class="assignment-list-main"><div><span class="assignment-type-badge is-<?php echo h($item['type']); ?>"><?php echo h(assignment_test_type_label($item['type'])); ?></span> <span class="assignment-test-status assignment-test-status--<?php echo h($status['tone']); ?>"><?php echo h($status['label']); ?></span></div>
                <h3><a href="assignment_test?id=<?php echo (int) $item['id']; ?>"><?php echo h($item['title']); ?></a></h3><p class="muted"><?php echo h($item['course_code'] . ' — ' . $item['course_title']); ?></p>
                <?php if ($progress) echo assignment_test_render_progress($progress); ?>
            </div>
            <div class="assignment-list-meta"><span><i class="bi bi-calendar3" aria-hidden="true"></i> <?php echo h(date('M j, Y · g:i A', strtotime($item['due_at']))); ?></span><span><?php echo h(assignment_test_format_marks($item['total_marks'])); ?> marks</span></div>
            <div class="assignment-list-actions">
                <a class="btn tiny secondary" href="assignment_test?id=<?php echo (int) $item['id']; ?>"><?php echo !empty($progress['feedback_available']) ? 'View Feedback' : 'Open'; ?></a>
                <?php if ($owner): ?>
                    <a class="btn tiny secondary" href="assignment_tests?edit=<?php echo (int) $item['id']; ?>#assignment-editor">Edit</a>
                    <?php $action = ['draft'=>'publish','published'=>'close','closed'=>'reopen'][$item['status']]; ?>
                    <form method="post" action="assignment_test_action.php" data-assignment-action>
                        <input type="hidden" name="csrf" value="<?php echo h(assignment_test_csrf_token()); ?>"><input type="hidden" name="action" value="<?php echo h($action); ?>"><input type="hidden" name="assignment_test_id" value="<?php echo (int) $item['id']; ?>">
                        <button class="btn tiny secondary"><?php echo ucfirst($action); ?></button>
                    </form>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach;
    return trim((string) ob_get_clean());
}

function assignment_test_list_snapshot(array $user, ?array $items = null): array
{
    $items = $items ?? assignment_test_list_for_user($user);
    $counts = ['all'=>count($items), 'published'=>0, 'draft'=>0, 'closed'=>0];
    foreach ($items as $item) $counts[$item['status']]++;
    return ['html'=>assignment_test_render_list_rows($items, $user), 'counts'=>$counts];
}
