<?php

declare(strict_types=1);
require_once __DIR__ . '/telegram/notifications.php';
require_once __DIR__ . '/notifications/fanout.php';

/** Caller holds the quiz row lock. The stable message is a reference, not a snapshot. */
function oq_publish_card(array $q): void
{
    if ($q['visibility'] !== 'enrolled') return;
    $group = get_or_create_chat_group((int)$q['course_id']);
    if (!oq_query('SELECT id FROM chat_messages WHERE online_quiz_id=? LIMIT 1', [$q['id']])->fetchColumn()) {
        oq_query('INSERT INTO chat_messages(group_id,sender_id,content,online_quiz_id) VALUES (?,?,NULL,?)', [$group, $q['created_by'], $q['id']]);
        oq_audit((int)$q['id'], (int)$q['created_by'], 'chat_card_created', ['message_id' => (int)db()->lastInsertId()]);
    }
}

/** Dedicated, sanitized renderer shared by initial group HTML and polling. */
function oq_chat_bundle(int $id, array $user): array
{
    require_once __DIR__ . '/assessments.php';
    $q = oq_find($id);
    if (!$q || !oq_visible($q, $user)) return ['online_quiz' => ['title' => '', 'type_label' => 'Online Quiz'], 'online_quiz_card_html' => '<article class="assignment-test-chat-card online-quiz-chat-card"><p class="assignment-test-chat-card__instructions">Online quiz unavailable.</p></article>'];
    $context = assessment_quiz_context($q, $user);
    $count = (int)oq_query('SELECT COUNT(*) FROM online_quiz_questions WHERE quiz_id=?', [$id])->fetchColumn();
    $status = oq_display_status($q);
    if ($status === 'Active') $status = 'Open';
    ob_start(); ?>
    <article class="assignment-test-chat-card online-quiz-chat-card" data-online-quiz-id="<?= $id ?>">
        <header class="assignment-test-chat-card__header"><span class="assignment-test-chat-card__icon" aria-hidden="true"><i class="bi bi-check2-circle"></i></span>
            <div><span class="assignment-test-chat-card__label">Online Quiz/Test</span>
                <h3><?= h($q['title']) ?></h3>
                <p><?= h($q['course_code'] . ' — ' . $q['course_title']) ?></p>
            </div>
        </header>
        <dl class="assignment-test-chat-card__facts"><?php foreach (['Questions' => $count, 'Duration' => $q['duration_minutes'] . ' minutes', 'Opens' => date('M j, Y · g:i A', strtotime($q['opens_at'])), 'Closes' => date('M j, Y · g:i A', strtotime($q['closes_at'])), 'Status' => $status] as $label => $value): ?><div>
                    <dt><?= h($label) ?></dt>
                    <dd><?= h((string)$value) ?></dd>
                </div><?php endforeach; ?></dl>
        <?php if ($context['note']): ?><p class="assignment-test-chat-card__instructions"><?= h($context['note']) ?></p><?php endif; ?>
        <a class="btn primary assignment-test-chat-card__cta" href="<?= h($context['url']) ?>"><?= h($context['label']) ?></a>
        <footer>Online timed assessment · times shown in WAT</footer>
    </article>
<?php return ['online_quiz' => ['title' => $q['title'], 'type_label' => 'Online Quiz'], 'online_quiz_card_html' => trim((string)ob_get_clean())];
}

/** Reuse the existing delivery ledger for deduplication and worker-owned email. */
function oq_queue_notice(array $q, array $recipient, string $key): void
{
    if ((int)$recipient['id'] === (int)$q['course_lecturer_id'] || !oq_learner($q, $recipient) || $q['visibility'] !== 'enrolled') return;
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $now = date('Y-m-d H:i:s');
        $end = date('Y-m-d H:i:s', time() + 7 * 86400);
        foreach (['web', 'email'] as $channel) {
            if (empty($recipient[$channel === 'web' ? 'pref_web_notifications' : 'pref_email_notifications'])) continue;
            $insert = oq_query("INSERT IGNORE INTO reminder_delivery_jobs(source_type,source_id,occurrence_key,occurrence_at,occurrence_end_at,user_id,channel,scheduled_at,expires_at,schedule_hash)
            VALUES ('online_quiz',?,?,?,?,?,?,?,?,?)", [$q['id'], $key, $now, $end, $recipient['id'], $channel, $now, $end, hash('sha256', $q['id'] . ':' . $key)]);
            if (!$insert->rowCount()) continue;
            $jobId = (int)db()->lastInsertId();
            if ($channel === 'web') {
                $message = oq_notice_message($q, $key);
                oq_query('INSERT INTO web_notifications(user_id,message,action_url,reminder_delivery_job_id) VALUES (?,?,?,?)', [$recipient['id'], $message, oq_notice_url($q, $key), $jobId]);
                oq_query("UPDATE reminder_delivery_jobs SET status='sent',sent_at=NOW() WHERE id=?", [$jobId]);
            }
            oq_audit((int)$q['id'], null, 'notification_queued', ['user_id' => (int)$recipient['id'], 'channel' => $channel, 'notice' => $key]);
        }
        $resultNotice = str_starts_with($key, 'result:');
        $questionCount = (int)oq_query('SELECT COUNT(*) FROM online_quiz_questions WHERE quiz_id=?', [(int)$q['id']])->fetchColumn();
        $lecturerName = (string)(oq_query('SELECT full_name FROM users WHERE id=?', [(int)$q['course_lecturer_id']])->fetchColumn() ?: '');
        $attemptNumber = null;
        if ($resultNotice) {
            $attemptNumber = oq_query('SELECT attempt_number FROM online_quiz_attempts WHERE id=? AND quiz_id=? AND student_id=?',
                [(int)substr($key, 7), (int)$q['id'], (int)$recipient['id']])->fetchColumn();
        }
        lcs_notification_fanout($resultNotice ? 'online_quiz_result_available' : 'online_quiz_published', (int)$recipient['id'], [
            'course_code' => (string)$q['course_code'],
            'course_title' => (string)($q['course_title'] ?? ''),
            'assessment_type' => 'Online Quiz',
            'title' => (string)$q['title'],
            'opens_at' => (string)$q['opens_at'],
            'closes_at' => (string)$q['closes_at'],
            'published_at' => (string)($q['published_at'] ?? ''),
            'duration_minutes' => (int)$q['duration_minutes'],
            'question_count' => $questionCount,
            'attempts_allowed' => (int)$q['attempts_allowed'],
            'attempt_number' => $attemptNumber === false ? null : (int)$attemptNumber,
            'secure' => !empty($q['settings']['secure']),
            'lecturer' => $lecturerName,
            'released_at' => $resultNotice ? (string)($q['results_released_at'] ?? date('Y-m-d H:i:s')) : '',
            'status' => $resultNotice ? 'Result available' : 'Published',
            'action_url' => oq_notice_url($q, $key),
        ], 'online_quiz', (int)$q['id'], $key);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
function oq_notice_message(array $q, string $key): string
{
    return mb_substr($q['course_code'] . ': ' . (str_starts_with($key, 'result:') ? 'Quiz result available — ' : 'Online quiz published — ') . $q['title'], 0, 300);
}
function oq_notice_url(array $q, string $key): string
{
    return str_starts_with($key, 'result:') ? 'online_quiz_result?id=' . (int)substr($key, 7) : 'online_quiz?id=' . (int)$q['id'];
}
function oq_publication_notices(array $q): void
{
    if ($q['visibility'] !== 'enrolled') return;
    foreach (oq_query("SELECT DISTINCT u.* FROM users u JOIN enrollments e ON e.student_id=u.id WHERE e.course_id=? AND e.status='active'", [$q['course_id']])->fetchAll() as $u) oq_queue_notice($q, $u, 'published');
}
/** Caller holds the attempt lock; opt in only newly submitted or reviewed work. */
function oq_track_result_notice(int $quizId, int $attemptId): void
{
    if (!oq_query("SELECT 1 FROM online_quiz_audit WHERE quiz_id=? AND action='result_notice_pending' AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.attempt_id'))=? LIMIT 1", [$quizId, $attemptId])->fetchColumn())
        oq_audit($quizId, null, 'result_notice_pending', ['attempt_id' => $attemptId]);
}
/** Called by submission/marking/release and the worker for clock-based release. */
function oq_result_notices(int $quizId, ?int $attemptId = null, bool $pendingOnly = false): void
{
    $q = oq_find($quizId);
    if (!$q || $q['visibility'] !== 'enrolled') return;
    $scope = $attemptId === null ? '' : ' AND a.id=' . (int)$attemptId;
    // Existing audit storage is also the opt-in delivery boundary for clock-based
    // release. Deployment alone must not replay every historical quiz result.
    if ($pendingOnly) $scope .= " AND EXISTS (SELECT 1 FROM online_quiz_audit tr WHERE tr.quiz_id=a.quiz_id AND tr.action='result_notice_pending' AND JSON_UNQUOTE(JSON_EXTRACT(tr.metadata_json,'$.attempt_id'))=a.id)";
    foreach (oq_query("SELECT a.* FROM online_quiz_attempts a WHERE a.quiz_id=? AND a.status<>'writing' AND a.needs_review=0" . $scope, [$quizId])->fetchAll() as $a) {
        $settings = json_decode($a['settings_snapshot_json'], true);
        if (!oq_released($settings['result_release'], $q, $a, 'results')) continue;
        $u = oq_query('SELECT * FROM users WHERE id=?', [$a['student_id']])->fetch();
        if ($u) oq_queue_notice($q, $u, 'result:' . $a['id']);
    }
}
function oq_notice_delivery_context(array $job): ?array
{
    $q = oq_find((int)$job['source_id']);
    $u = oq_query('SELECT * FROM users WHERE id=?', [$job['user_id']])->fetch();
    if (!$q || !$u || !oq_visible($q, $u) || !oq_learner($q, $u) || $q['visibility'] !== 'enrolled' || $q['status'] === 'draft' || !reminder_channel_allowed(['notify_web' => 1, 'notify_email' => 1], $u, $job['channel'])) return null;
    $key = $job['occurrence_key'];
    if ($key === 'published' && oq_display_status($q) === 'Closed') return null;
    if (str_starts_with($key, 'result:')) {
        $a = oq_query('SELECT * FROM online_quiz_attempts WHERE id=? AND quiz_id=? AND student_id=?', [(int)substr($key, 7), $q['id'], $u['id']])->fetch();
        if (!$a || $a['needs_review'] || !oq_released(json_decode($a['settings_snapshot_json'], true)['result_release'], $q, $a, 'results')) return null;
    } elseif ($key !== 'published') return null;
    return ['user_email' => $u['email'], 'user_name' => $u['full_name'], 'event_title' => $q['title'], 'notice_message' => oq_notice_message($q, $key), 'action_url' => oq_notice_url($q, $key), 'online_quiz_id' => $q['id']];
}
