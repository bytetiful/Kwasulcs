<?php

declare(strict_types=1);
require_once __DIR__ . '/assignment_test.php';
require_once __DIR__ . '/reminders.php';
require_once __DIR__ . '/online_quiz_integration.php';

function oq_json($value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
function oq_display_status(array $quiz, ?int $now = null): string
{
    $now ??= time();
    if ($quiz['status'] !== 'published') return ucfirst($quiz['status']);
    if (strtotime($quiz['closes_at']) <= $now) return 'Closed';
    return strtotime($quiz['opens_at']) > $now ? 'Scheduled' : 'Active';
}
function oq_defaults(): array
{
    return [
        'shuffle_questions' => true,
        'shuffle_options' => true,
        'one_per_page' => true,
        'allow_back' => true,
        'auto_submit' => true,
        'result_release' => 'after_submission',
        'answer_release' => 'after_close',
        'secure' => false,
        'fullscreen' => true,
        'disable_selection' => true,
        'disable_copy' => true,
        'disable_cut' => true,
        'disable_paste' => true,
        'disable_drag' => true,
        'disable_context' => true,
        'monitor_focus' => true,
        'monitor_fullscreen' => true,
        'monitor_viewport' => true
    ];
}
function oq_query(string $sql, array $params = []): PDOStatement
{
    $q = db()->prepare($sql);
    $q->execute($params);
    return $q;
}
function oq_audit(int $id, ?int $actor, string $action, array $meta = []): void
{
    oq_query('INSERT INTO online_quiz_audit(quiz_id,actor_id,action,metadata_json) VALUES (?,?,?,?)', [$id, $actor, $action, oq_json($meta)]);
}
function oq_find(int $id, bool $lock = false): ?array
{
    $q = oq_query('SELECT q.*,c.code AS course_code,c.title AS course_title,c.lecturer_id AS course_lecturer_id
        FROM online_quizzes q JOIN courses c ON c.id=q.course_id WHERE q.id=?' . ($lock ? ' FOR UPDATE' : ''), [$id])->fetch();
    if ($q) $q['settings'] = array_merge(oq_defaults(), json_decode($q['settings_json'], true) ?? []);
    return $q ?: null;
}
function oq_owner(array $q, array $user): bool
{
    return assignment_test_is_course_owner($q, $user);
}
function oq_learner(array $q, array $user): bool
{
    return assignment_test_has_active_enrollment($q, $user);
}
function oq_visible(array $q, array $user): bool
{
    return oq_owner($q, $user) || ($q['status'] !== 'draft' && $q['visibility'] === 'enrolled' && oq_learner($q, $user));
}
function oq_require(int $id, array $user, bool $owner = false, bool $lock = false): array
{
    $q = oq_find($id, $lock);
    if (!$q || ($owner ? !oq_owner($q, $user) : !oq_visible($q, $user))) throw new RuntimeException('Quiz not available.');
    return $q;
}
function oq_questions(int $id): array
{
    $rows = oq_query('SELECT * FROM online_quiz_questions WHERE quiz_id=? ORDER BY position', [$id])->fetchAll();
    foreach ($rows as &$r) {
        $r['options'] = json_decode($r['options_json'], true);
        $r['key'] = json_decode($r['answer_key_json'], true);
    }
    return $rows;
}
function oq_list(array $user): array
{
    $rows = oq_query(
        "SELECT q.*,c.code AS course_code,c.lecturer_id AS course_lecturer_id,
        (SELECT COUNT(*) FROM online_quiz_questions qq WHERE qq.quiz_id=q.id) AS question_count
        FROM online_quizzes q JOIN courses c ON c.id=q.course_id
        WHERE c.lecturer_id=? OR (q.status<>'draft' AND q.visibility='enrolled' AND EXISTS
        (SELECT 1 FROM enrollments e WHERE e.course_id=q.course_id AND e.student_id=? AND e.status='active')) ORDER BY q.id DESC",
        [$user['id'], $user['id']]
    )->fetchAll();
    return array_values(array_filter($rows, fn($q) => oq_visible($q, $user)));
}
/** Drafts may be incomplete; publish is the strict validation boundary. */
function oq_save(int $id, array $input, array $user): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = $id ? oq_require($id, $user, true, true) : null;
        if ($q && oq_query('SELECT id FROM online_quiz_attempts WHERE quiz_id=? LIMIT 1', [$id])->fetchColumn()) throw new RuntimeException('Attempts have started. This quiz is frozen; create a new quiz to change its questions or rules.');
        $courseId = (int)($input['course_id'] ?? $q['course_id'] ?? 0);
        $course = oq_query('SELECT *,lecturer_id AS course_lecturer_id FROM courses WHERE id=?', [$courseId])->fetch();
        if (!$course || !oq_owner($course, $user)) throw new RuntimeException('Select a course you manage.');
        $title = trim((string)($input['title'] ?? $q['title'] ?? 'Untitled quiz'));
        if (mb_strlen($title) > 255) throw new RuntimeException('Title must be at most 255 characters.');
        $settings = array_merge(oq_defaults(), $q['settings'] ?? []);
        foreach (($input['settings'] ?? []) as $key => $value) {
            if (array_key_exists($key, $settings)) $settings[$key] = is_bool(oq_defaults()[$key]) ? (bool)$value : (string)$value;
        }
        foreach (['result_release', 'answer_release'] as $key) if (!in_array($settings[$key], ['immediate', 'after_submission', 'after_close', 'manual'], true)) throw new RuntimeException('Invalid release policy.');
        $date = function ($name) use ($input, $q) {
            $v = $input[$name] ?? $q[$name] ?? null;
            if (!$v) return null;
            $raw = substr(str_replace(' ', 'T', (string)$v), 0, 16);
            $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw);
            if (!$d || $d->format('Y-m-d\TH:i') !== $raw) throw new RuntimeException('Enter a valid date and time.');
            return $d->format('Y-m-d H:i:s');
        };
        $duration = (int)($input['duration_minutes'] ?? $q['duration_minutes'] ?? 60);
        $attempts = (int)($input['attempts_allowed'] ?? $q['attempts_allowed'] ?? 1);
        if ($duration < 1 || $duration > 1440 || $attempts < 1 || $attempts > 20) throw new RuntimeException('Duration: 1–1440 minutes; attempts: 1–20.');
        $visibility = $input['visibility'] ?? $q['visibility'] ?? 'enrolled';
        $grading = $input['grading_type'] ?? $q['grading_type'] ?? 'percentage';
        if (!in_array($visibility, ['enrolled', 'hidden'], true) || !in_array($grading, ['percentage', 'points'], true)) throw new RuntimeException('Invalid visibility or grading type.');
        $pass = $input['pass_percent'] ?? $q['pass_percent'] ?? 50;
        if (!is_numeric($pass) || $pass < 0 || $pass > 100) throw new RuntimeException('Pass percentage must be 0–100.');
        $values = [
            $courseId,
            $title ?: 'Untitled quiz',
            mb_substr((string)($input['instructions'] ?? $q['instructions'] ?? ''), 0, 20000),
            $date('opens_at'),
            $date('closes_at'),
            $duration,
            $attempts,
            $grading,
            $visibility,
            $pass,
            (int)(bool)($input['expose_pass_mark'] ?? $q['expose_pass_mark'] ?? false),
            oq_json($settings),
            max(1, min(4, (int)($input['wizard_step'] ?? 1)))
        ];
        if ($q) {
            if (isset($input['revision']) && (int)$input['revision'] !== (int)$q['revision']) throw new RuntimeException('This draft changed in another tab. Reload before saving.');
            oq_query('UPDATE online_quizzes SET course_id=?,title=?,instructions=?,opens_at=?,closes_at=?,duration_minutes=?,attempts_allowed=?,grading_type=?,visibility=?,pass_percent=?,expose_pass_mark=?,settings_json=?,wizard_step=?,revision=revision+1 WHERE id=?', [...$values, $id]);
        } else {
            oq_query('INSERT INTO online_quizzes(course_id,title,instructions,opens_at,closes_at,duration_minutes,attempts_allowed,grading_type,visibility,pass_percent,expose_pass_mark,settings_json,wizard_step,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [...$values, $user['id']]);
            $id = (int)$pdo->lastInsertId();
        }
        if (array_key_exists('questions', $input)) {
            if (!is_array($input['questions']) || count($input['questions']) > 200) throw new RuntimeException('Maximum 200 questions.');
            oq_query('DELETE FROM online_quiz_questions WHERE quiz_id=?', [$id]);
            foreach ($input['questions'] as $i => $r) {
                $type = $r['question_type'] ?? 'mcq';
                if (!in_array($type, ['mcq', 'truefalse', 'short', 'essay'], true)) throw new RuntimeException('Unsupported question type.');
                $marks = $r['marks'] ?? 1;
                if (!is_numeric($marks) || $marks <= 0 || $marks > 10000) throw new RuntimeException('Every question needs positive marks.');
                $options = $r['options'] ?? [];
                if (count($options) > 12) throw new RuntimeException('Maximum 12 options per question.');
                $clean = [];
                foreach ($options as $k => $o) $clean[] = ['id' => (string)$k, 'text' => mb_substr((string)($o['text'] ?? $o), 0, 4000)];
                $key = $r['key'] ?? [];
                if (!is_array($key)) $key = [$key];
                $policy = in_array($r['grading_policy'] ?? '', ['exact_normalized', 'manual'], true) ? $r['grading_policy'] : 'exact_normalized';
                oq_query(
                    'INSERT INTO online_quiz_questions(quiz_id,position,question_type,question_text,marks,options_json,answer_key_json,grading_policy,explanation) VALUES (?,?,?,?,?,?,?,?,?)',
                    [$id, $i, $type, mb_substr((string)($r['question_text'] ?? ''), 0, 20000), $marks, oq_json($clean), oq_json(array_map('strval', $key)), $policy, mb_substr((string)($r['explanation'] ?? ''), 0, 10000)]
                );
            }
        }
        oq_audit($id, (int)$user['id'], $q ? 'draft_updated' : 'created');
        if ($q && $q['status'] === 'published') {
            oq_sync_calendar(oq_find($id));
            oq_publish_card(oq_find($id));
        }
        $pdo->commit();
        return oq_find($id);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
function oq_publish(int $id, array $user): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = oq_require($id, $user, true, true);
        $questions = oq_questions($id);
        if (!$q['opens_at'] || !$q['closes_at'] || strtotime($q['closes_at']) <= max(time(), strtotime($q['opens_at']))) throw new RuntimeException('Set a valid opening and future closing time.');
        if (!trim($q['instructions']) || !$questions) throw new RuntimeException('Add instructions and at least one question.');
        foreach ($questions as $r) {
            if (!trim($r['question_text'])) throw new RuntimeException('Complete every question.');
            if ($r['question_type'] === 'mcq' && (count($r['options']) < 2 || count(array_filter($r['options'], fn($o) => trim($o['text']) !== '')) !== count($r['options']) || count($r['key']) !== 1 || !in_array($r['key'][0], array_column($r['options'], 'id'), true))) throw new RuntimeException('Each MCQ needs at least two options and one selected answer.');
            if ($r['question_type'] === 'truefalse' && (!isset($r['key'][0]) || !in_array($r['key'][0], ['true', 'false'], true))) throw new RuntimeException('Select True or False for each answer key.');
            if ($r['question_type'] === 'short' && $r['grading_policy'] !== 'manual' && !array_filter($r['key'], fn($a) => trim($a) !== '')) throw new RuntimeException('Short answers need accepted answers or manual grading.');
        }
        if ($q['status'] !== 'published') {
            oq_query("UPDATE online_quizzes SET status='published',published_at=COALESCE(published_at,NOW()) WHERE id=?", [$id]);
            oq_audit($id, (int)$user['id'], 'published');
        }
        $q = oq_find($id);
        oq_sync_calendar($q);
        oq_publish_card($q);
        oq_publication_notices($q);
        $pdo->commit();
        return $q;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
function oq_sync_calendar(array $q): void
{
    $old = oq_query('SELECT id FROM calendar_events WHERE online_quiz_id=?', [$q['id']])->fetchColumn();
    if ($q['status'] !== 'published' || $q['visibility'] === 'hidden') {
        if ($old) {
            reminder_cancel_event((int)$old);
            oq_query('DELETE FROM calendar_events WHERE id=?', [$old]);
        }
        return;
    }
    oq_query(
        "INSERT INTO calendar_events(online_quiz_id,title,description,event_type,scope,course_id,created_by,start_datetime,end_datetime,notify_web,notify_email,remind_minutes_before)
        VALUES (?,?,?,'test','course',?,?,?,?,1,1,30) ON DUPLICATE KEY UPDATE title=VALUES(title),description=VALUES(description),course_id=VALUES(course_id),start_datetime=VALUES(start_datetime),end_datetime=VALUES(end_datetime)",
        [$q['id'], 'Quiz: ' . mb_substr($q['title'], 0, 190), 'Online Quiz/Test — open from Assessment/Chat.', $q['course_id'], $q['created_by'], $q['opens_at'], $q['closes_at']]
    );
    $id = (int)oq_query('SELECT id FROM calendar_events WHERE online_quiz_id=?', [$q['id']])->fetchColumn();
    reminder_sync_event($id);
}
function oq_attempt(int $id, array $user, bool $lock = false, bool $owner = false): array
{
    $a = oq_query('SELECT * FROM online_quiz_attempts WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), [$id])->fetch();
    if (!$a) throw new RuntimeException('Attempt not available.');
    $q = oq_require((int)$a['quiz_id'], $user, $owner);
    if (!$owner && ((int)$a['student_id'] !== (int)$user['id'] || !oq_learner($q, $user))) throw new RuntimeException('Attempt not available.');
    $a['settings'] = json_decode($a['settings_snapshot_json'], true);
    $a['quiz_snapshot'] = json_decode($a['quiz_snapshot_json'], true);
    return $a;
}
function oq_start(int $id, array $user, bool $ack): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = oq_require($id, $user, false, true);
        if (!oq_learner($q, $user)) throw new RuntimeException('Only enrolled learners may start.');
        $existing = oq_query("SELECT id FROM online_quiz_attempts WHERE quiz_id=? AND student_id=? AND status='writing' ORDER BY id DESC LIMIT 1", [$id, $user['id']])->fetchColumn();
        if ($existing) {
            $pdo->commit();
            return oq_state((int)$existing, $user);
        }
        if ($q['status'] !== 'published' || time() < strtotime($q['opens_at']) || time() >= strtotime($q['closes_at'])) throw new RuntimeException('Quiz is not open.');
        if ($q['settings']['secure'] && !$ack) throw new RuntimeException('Acknowledge the secure-test rules before starting.');
        $number = (int)oq_query('SELECT COUNT(*) FROM online_quiz_attempts WHERE quiz_id=? AND student_id=?', [$id, $user['id']])->fetchColumn() + 1;
        if ($number > (int)$q['attempts_allowed']) throw new RuntimeException('Attempt limit reached.');
        $questions = oq_questions($id);
        if (!$questions) throw new RuntimeException('No questions available.');
        if ($q['settings']['shuffle_questions']) shuffle($questions);
        $total = array_sum(array_column($questions, 'marks'));
        $snapshot = ['title' => $q['title'], 'course_code' => $q['course_code'], 'grading_type' => $q['grading_type'], 'pass_percent' => $q['pass_percent'], 'expose_pass_mark' => $q['expose_pass_mark']];
        oq_query('INSERT INTO online_quiz_attempts(quiz_id,student_id,attempt_number,settings_snapshot_json,quiz_snapshot_json,started_at,expires_at,total_marks,last_seen_at)
            VALUES (?,?,?,?,?,NOW(),?,?,NOW())', [$id, $user['id'], $number, oq_json($q['settings']), oq_json($snapshot), date('Y-m-d H:i:s', min(time() + $q['duration_minutes'] * 60, strtotime($q['closes_at']))), $total]);
        $aid = (int)$pdo->lastInsertId();
        foreach ($questions as $i => $r) {
            if ($r['question_type'] === 'truefalse') $r['options'] = [['id' => 'true', 'text' => 'True'], ['id' => 'false', 'text' => 'False']];
            if ($q['settings']['shuffle_options']) shuffle($r['options']);
            oq_query('INSERT INTO online_quiz_attempt_questions(attempt_id,source_question_id,position,snapshot_json) VALUES (?,?,?,?)', [$aid, $r['id'], $i, oq_json($r)]);
        }
        oq_audit($id, (int)$user['id'], 'attempt_started', ['attempt_id' => $aid]);
        $pdo->commit();
        return oq_state($aid, $user);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
function oq_frozen(int $id): array
{
    $rows = oq_query('SELECT aq.*,a.answer_json,a.revision AS answer_revision,a.awarded_marks,a.is_correct,a.feedback FROM online_quiz_attempt_questions aq LEFT JOIN online_quiz_answers a ON a.attempt_question_id=aq.id WHERE aq.attempt_id=? ORDER BY aq.position', [$id])->fetchAll();
    foreach ($rows as &$r) {
        $r['question'] = json_decode($r['snapshot_json'], true);
        $r['answer'] = $r['answer_json'] === null ? '' : json_decode($r['answer_json'], true);
    }
    return $rows;
}
function oq_normalize(string $text): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
}
/** Caller holds attempt lock. Objective grades do not use any AI or client marks. */
function oq_finalize(array $a, bool $expired = false): void
{
    if ($a['status'] !== 'writing') return;
    $score = 0;
    $manual = false;
    foreach (oq_frozen((int)$a['id']) as $r) {
        $q = $r['question'];
        $answer = (string)$r['answer'];
        $marks = 0;
        $correct = false;
        if (trim($answer) === '') {
            $marks = 0;
        } elseif ($q['question_type'] === 'essay' || ($q['question_type'] === 'short' && $q['grading_policy'] === 'manual')) {
            $marks = null;
            $correct = null;
            $manual = true;
        } else {
            $correct = in_array($q['question_type'], ['mcq', 'truefalse'], true) ? in_array($answer, $q['key'], true) : in_array(oq_normalize($answer), array_map('oq_normalize', $q['key']), true);
            $marks = $correct ? (float)$q['marks'] : 0;
        }
        oq_query('INSERT INTO online_quiz_answers(attempt_question_id,answer_json,awarded_marks,is_correct) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE awarded_marks=VALUES(awarded_marks),is_correct=VALUES(is_correct)', [$r['id'], oq_json($answer), $marks, $correct === null ? null : (int)$correct]);
        $score += $marks ?? 0;
    }
    $settings = json_decode($a['settings_snapshot_json'], true);
    oq_query(
        'UPDATE online_quiz_attempts SET status=?,submitted_at=?,score=?,needs_review=?,pending_integrity_type=NULL,pending_integrity_at=NULL WHERE id=? AND status=\'writing\'',
        [$expired && !$settings['auto_submit'] ? 'expired' : 'submitted', $expired ? $a['expires_at'] : date('Y-m-d H:i:s'), $manual ? null : $score, (int)$manual, $a['id']]
    );
    oq_audit((int)$a['quiz_id'], (int)$a['student_id'], $expired ? 'attempt_expired' : 'attempt_submitted', ['attempt_id' => $a['id']]);
    oq_track_result_notice((int)$a['quiz_id'], (int)$a['id']);
    oq_result_notices((int)$a['quiz_id'], (int)$a['id']);
}
function oq_expire(array $a): bool
{
    if ($a['status'] === 'writing' && time() >= strtotime($a['expires_at'])) {
        oq_finalize($a, true);
        return true;
    }
    return false;
}
function oq_state(int $id, array $user): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $a = oq_attempt($id, $user, true);
        oq_expire($a);
        $a = oq_attempt($id, $user);
        $locked = $a['locked_until'] && strtotime($a['locked_until']) > time();
        $questions = [];
        $progress = [];
        foreach (oq_frozen($id) as $r) {
            $progress[] = ['id' => (int)$r['id'], 'position' => (int)$r['position'], 'answered' => trim((string)$r['answer']) !== '', 'revision' => (int)($r['answer_revision'] ?? 0)];
            if ($a['status'] !== 'writing' || $locked || (!$a['settings']['allow_back'] && (int)$r['position'] !== (int)$a['current_position'])) continue;
            $q = $r['question'];
            $questions[] = ['id' => (int)$r['id'], 'position' => (int)$r['position'], 'question_type' => $q['question_type'], 'text' => $q['question_text'], 'marks' => $q['marks'], 'options' => $q['options'], 'answer' => $r['answer'], 'revision' => (int)($r['answer_revision'] ?? 0)];
        }
        $result = [
            'id' => $id,
            'quiz_id' => (int)$a['quiz_id'],
            'status' => $a['status'],
            'server_now' => time(),
            'expires_at' => strtotime($a['expires_at']),
            'locked_until' => $locked ? strtotime($a['locked_until']) : null,
            'warning_count' => (int)$a['warning_count'],
            'flagged' => (bool)$a['flagged'],
            'current_position' => (int)$a['current_position'],
            'settings' => $a['settings'],
            'quiz' => $a['quiz_snapshot'],
            'questions' => $questions,
            'progress' => $progress,
            'watermark' => ($user['matric_no'] ?? 'Learner') . ' • KWASU LCS • ' . strtoupper(substr(hash('sha256', 'attempt:' . $id), 0, 5))
        ];
        if (empty($result['quiz']['expose_pass_mark'])) unset($result['quiz']['pass_percent']);
        oq_query('UPDATE online_quiz_attempts SET last_seen_at=NOW() WHERE id=?', [$id]);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
function oq_save_answers(int $id, array $user, array $answers, ?int $next = null): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $a = oq_attempt($id, $user, true);
        if (oq_expire($a)) {
            $pdo->commit();
            return oq_state($id, $user);
        }
        if ($a['status'] !== 'writing') throw new RuntimeException('Attempt is already finalized.');
        if ($a['locked_until'] && strtotime($a['locked_until']) > time()) throw new RuntimeException('Attempt is temporarily locked.');
        $questions = oq_frozen($id);
        $byId = array_column($questions, null, 'id');
        $seen = [];
        foreach ($answers as $answer) {
            $qid = (int)($answer['id'] ?? 0);
            $r = $byId[$qid] ?? null;
            if (isset($seen[$qid])) throw new RuntimeException('Duplicate answer in save request.');
            $seen[$qid] = true;
            // An interrupted response may be retried after the server saved and
            // advanced a no-backtracking attempt. A byte-identical retry is a
            // no-op, never permission to change an earlier question.
            if ($r && is_string($answer['value'] ?? null) && $answer['value'] === $r['answer'] && (int)($answer['revision'] ?? -1) === (int)($r['answer_revision'] ?? 0) - 1) continue;
            if (!$r || (!$a['settings']['allow_back'] && (int)$r['position'] !== (int)$a['current_position'])) throw new RuntimeException('Question not available.');
            if ((int)($answer['revision'] ?? -1) !== (int)($r['answer_revision'] ?? 0)) throw new RuntimeException('Answer changed in another tab. Reload the latest saved answer before retrying.');
            $value = $answer['value'] ?? '';
            if (!is_string($value) || mb_strlen($value) > 30000) throw new RuntimeException('Invalid answer.');
            if (in_array($r['question']['question_type'], ['mcq', 'truefalse'], true) && $value !== '' && !in_array($value, array_column($r['question']['options'], 'id'), true)) throw new RuntimeException('Invalid answer option.');
            oq_query('INSERT INTO online_quiz_answers(attempt_question_id,answer_json) VALUES (?,?) ON DUPLICATE KEY UPDATE answer_json=VALUES(answer_json),revision=revision+1', [$qid, oq_json($value)]);
        }
        if ($next !== null) {
            if ($next < 0 || $next >= count($questions) || (!$a['settings']['allow_back'] && $next !== (int)$a['current_position'] && $next !== (int)$a['current_position'] + 1)) throw new RuntimeException('Backtracking is disabled.');
            oq_query('UPDATE online_quiz_attempts SET current_position=? WHERE id=?', [$next, $id]);
        }
        oq_query('UPDATE online_quiz_attempts SET last_seen_at=NOW() WHERE id=?', [$id]);
        $pdo->commit();
        return oq_state($id, $user);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
function oq_submit(int $id, array $user): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $a = oq_attempt($id, $user, true);
        if (!oq_expire($a)) {
            if ($a['locked_until'] && strtotime($a['locked_until']) > time()) throw new RuntimeException('Return after the lockout ends.');
            oq_finalize($a);
        }
        $pdo->commit();
        return oq_state($id, $user);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Client reports observations; only server receipt times control confirmations and penalties. */
function oq_integrity(int $id, array $user, string $type, string $requestKey): array
{
    if (!preg_match('/^[a-zA-Z0-9_-]{8,80}$/', $requestKey)) throw new RuntimeException('Invalid event identifier.');
    $neutral = ['network_lost', 'network_restored', 'reload', 'autosave_failed'];
    $exits = ['focus_exit', 'fullscreen_exit', 'reduced_viewport'];
    $returns = ['focus_return', 'fullscreen_return', 'viewport_restored'];
    if (!in_array($type, [...$neutral, ...$exits, ...$returns, 'confirm'], true)) throw new RuntimeException('Invalid integrity event.');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $a = oq_attempt($id, $user, true);
        oq_expire($a);
        if ($a['status'] !== 'writing' || time() >= strtotime($a['expires_at']) || oq_query('SELECT id FROM online_quiz_integrity_events WHERE attempt_id=? AND request_key=?', [$id, $requestKey])->fetchColumn()) {
            $pdo->commit();
            return oq_state($id, $user);
        }
        $violation = false;
        $settings = $a['settings'];
        $locked = $a['locked_until'] && strtotime($a['locked_until']) > time();
        if (in_array($type, $neutral, true)) {
            if (str_starts_with($type, 'network_')) oq_query('UPDATE online_quiz_attempts SET network_state=? WHERE id=?', [$type === 'network_lost' ? 'offline' : 'online', $id]);
            // Reload is neutral and clears an unconfirmed transition, never a confirmed lockout.
            if ($type === 'reload') oq_query('UPDATE online_quiz_attempts SET pending_integrity_type=NULL,pending_integrity_at=NULL WHERE id=?', [$id]);
        } elseif ($settings['secure'] && !$locked) {
            if (in_array($type, $exits, true)) {
                $enabled = match ($type) {
                    'focus_exit' => $settings['monitor_focus'],
                    'fullscreen_exit' => $settings['fullscreen'] && $settings['monitor_fullscreen'],
                    default => $settings['monitor_viewport']
                };
                if ($enabled && !$a['pending_integrity_type']) oq_query('UPDATE online_quiz_attempts SET pending_integrity_type=?,pending_integrity_at=NOW() WHERE id=?', [$type, $id]);
            } elseif ($a['pending_integrity_type'] && ($type === 'confirm' || $type === match ($a['pending_integrity_type']) {
                'focus_exit' => 'focus_return',
                'fullscreen_exit' => 'fullscreen_return',
                default => 'viewport_restored'
            })) {
                $grace = $a['pending_integrity_type'] === 'reduced_viewport' ? 8 : 3;
                $violation = time() - strtotime($a['pending_integrity_at']) >= $grace;
                if ($violation) {
                    $warnings = (int)$a['warning_count'] + 1;
                    oq_query('UPDATE online_quiz_attempts SET warning_count=?,flagged=IF(? >= 3,1,flagged),locked_until=DATE_ADD(NOW(),INTERVAL 120 SECOND),pending_integrity_type=NULL,pending_integrity_at=NULL WHERE id=?', [$warnings, $warnings, $id]);
                } elseif (in_array($type, $returns, true)) oq_query('UPDATE online_quiz_attempts SET pending_integrity_type=NULL,pending_integrity_at=NULL WHERE id=?', [$id]);
            }
        }
        oq_query(
            'INSERT INTO online_quiz_integrity_events(attempt_id,request_key,event_type,is_violation,warning_number,server_at,metadata_json) VALUES (?,?,?,?,?,NOW(),?)',
            [$id, $requestKey, $violation ? $a['pending_integrity_type'] : $type, (int)$violation, $violation ? $warnings : null, oq_json(['observed_action' => $type, 'classification' => $violation ? 'violation' : 'context'])]
        );
        oq_query('UPDATE online_quiz_attempts SET last_seen_at=NOW() WHERE id=?', [$id]);
        $pdo->commit();
        return oq_state($id, $user);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
function oq_released(string $policy, array $q, array $a, string $channel): bool
{
    if ($a['status'] === 'writing') return false;
    return in_array($policy, ['immediate', 'after_submission'], true) || ($policy === 'after_close' && ($q['status'] === 'closed' || time() >= strtotime($q['closes_at']))) || ($policy === 'manual' && !empty($q[$channel . '_released_at']));
}
function oq_result(int $id, array $user, bool $owner = false): array
{
    $a = oq_attempt($id, $user, false, $owner);
    $q = oq_require((int)$a['quiz_id'], $user, $owner);
    $score = $owner || oq_released($a['settings']['result_release'], $q, $a, 'results');
    $keys = $owner || oq_released($a['settings']['answer_release'], $q, $a, 'answers');
    $rows = [];
    $counts = ['correct' => 0, 'incorrect' => 0, 'unanswered' => 0, 'manual' => 0];
    foreach (oq_frozen($id) as $r) {
        $sq = $r['question'];
        $kind = trim((string)$r['answer']) === '' ? 'unanswered' : ($r['is_correct'] === null ? 'manual' : ($r['is_correct'] ? 'correct' : 'incorrect'));
        $counts[$kind]++;
        $rows[] = [
            'id' => $r['id'],
            'position' => $r['position'],
            'text' => $sq['question_text'],
            'question_type' => $sq['question_type'],
            'marks' => $sq['marks'],
            'options' => $sq['options'],
            'answer' => $r['answer'],
            'key' => $keys ? $sq['key'] : null,
            'explanation' => $keys ? $sq['explanation'] : null,
            'classification' => $keys ? $kind : null,
            'awarded_marks' => $score && $keys ? $r['awarded_marks'] : null,
            'feedback' => $keys ? $r['feedback'] : null
        ];
    }
    return [
        'attempt_id' => $id,
        'quiz_id' => $a['quiz_id'],
        'title' => $q['title'],
        'course_code' => $q['course_code'],
        'grading_type' => $a['quiz_snapshot']['grading_type'],
        'status' => $a['status'],
        'score_released' => $score,
        'answers_released' => $keys,
        'score' => $score ? $a['score'] : null,
        'total_marks' => $a['total_marks'],
        'counts' => $keys ? $counts : null,
        'needs_review' => (bool)$a['needs_review'],
        'flagged' => (bool)$a['flagged'],
        'warnings' => (int)$a['warning_count'],
        'submitted_at' => $a['submitted_at'],
        'time_used' => $a['submitted_at'] ? max(0, strtotime($a['submitted_at']) - strtotime($a['started_at'])) : null,
        'pass_percent' => $score && $a['quiz_snapshot']['expose_pass_mark'] ? $a['quiz_snapshot']['pass_percent'] : null,
        'questions' => $a['status'] !== 'writing' ? $rows : []
    ];
}
function oq_review(int $id, array $user, array $marks, string $note): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $a = oq_attempt($id, $user, true, true);
        if ($a['status'] === 'writing') throw new RuntimeException('Wait for submission before marking.');
        $rows = oq_frozen($id);
        foreach ($marks as $qid => $m) {
            $matches = array_values(array_filter($rows, fn($r) => (int)$r['id'] === (int)$qid));
            $r = $matches[0] ?? null;
            if (!$r || !is_numeric($m['score'] ?? null) || $m['score'] < 0 || $m['score'] > $r['question']['marks']) throw new RuntimeException('Invalid review mark.');
            oq_query('UPDATE online_quiz_answers SET awarded_marks=?,feedback=?,is_correct=? WHERE attempt_question_id=?', [$m['score'], mb_substr((string)($m['feedback'] ?? ''), 0, 10000), (int)($m['score'] == $r['question']['marks']), $qid]);
        }
        $total = oq_query('SELECT SUM(a.awarded_marks) AS score,SUM(a.awarded_marks IS NULL) AS pending FROM online_quiz_attempt_questions q JOIN online_quiz_answers a ON a.attempt_question_id=q.id WHERE q.attempt_id=?', [$id])->fetch();
        oq_query('UPDATE online_quiz_attempts SET score=?,needs_review=?,review_note=? WHERE id=?', [$total['pending'] ? null : $total['score'], (int)(bool)$total['pending'], mb_substr($note, 0, 10000), $id]);
        oq_audit((int)$a['quiz_id'], (int)$user['id'], 'review_saved', ['attempt_id' => $id]);
        oq_track_result_notice((int)$a['quiz_id'], $id);
        oq_result_notices((int)$a['quiz_id'], $id);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
/** Run from the existing server worker, never browser polling. */
function oq_worker_expire(): int
{
    if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only.');
    $n = 0;
    foreach (oq_query("SELECT id FROM online_quiz_attempts WHERE status='writing' AND expires_at<=NOW() ORDER BY expires_at LIMIT 200")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $a = oq_query('SELECT * FROM online_quiz_attempts WHERE id=? FOR UPDATE', [$id])->fetch();
            if (oq_expire($a)) $n++;
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    return $n;
}

function oq_manage(int $id, array $user, string $action): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $q = oq_require($id, $user, true, true);
        if ($action === 'close') {
            oq_query("UPDATE online_quizzes SET status='closed' WHERE id=?", [$id]);
            oq_query("UPDATE online_quiz_attempts SET expires_at=LEAST(expires_at,NOW()) WHERE quiz_id=? AND status='writing'", [$id]);
            // Keep the historical calendar projection, but cancel unsent reminders.
            $event = oq_query('SELECT id FROM calendar_events WHERE online_quiz_id=?', [$id])->fetchColumn();
            if ($event) reminder_cancel_event((int)$event);
            telegram_cancel_source_jobs('online_quiz', $id, 'Online quiz/test is closed.', null, 'online_quiz_published');
        } elseif (in_array($action, ['release_results', 'release_answers'], true)) {
            $column = $action === 'release_results' ? 'results_released_at' : 'answers_released_at';
            oq_query("UPDATE online_quizzes SET $column=COALESCE($column,NOW()) WHERE id=?", [$id]);
        } else throw new RuntimeException('Unknown action.');
        oq_audit($id, (int)$user['id'], $action);
        oq_result_notices($id, null, $action !== 'release_results');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
function oq_monitor(int $id, array $user): array
{
    $q = oq_require($id, $user, true);
    $rows = oq_query("SELECT a.id,a.student_id,a.attempt_number,a.status,a.started_at,a.expires_at,a.locked_until,a.submitted_at,a.warning_count,a.flagged,a.current_position,a.last_seen_at,a.network_state,a.score,a.total_marks,a.needs_review,u.matric_no,u.full_name,
        (SELECT COUNT(*) FROM online_quiz_attempt_questions aq JOIN online_quiz_answers an ON an.attempt_question_id=aq.id WHERE aq.attempt_id=a.id AND an.answer_json<>'\"\"') AS answered,
        (SELECT COUNT(*) FROM online_quiz_attempt_questions aq WHERE aq.attempt_id=a.id) AS question_count
        FROM online_quiz_attempts a JOIN users u ON u.id=a.student_id WHERE a.quiz_id=?
        ORDER BY LOWER(COALESCE(NULLIF(TRIM(u.matric_no),''),u.full_name)) ASC,a.student_id ASC,a.attempt_number ASC,a.id ASC", [$id])->fetchAll();
    $eligible = (int)oq_query("SELECT COUNT(DISTINCT e.student_id) FROM enrollments e JOIN users u ON u.id=e.student_id WHERE e.course_id=? AND e.status='active' AND u.role IN ('student','admin') AND e.student_id<>?", [$q['course_id'], $q['course_lecturer_id']])->fetchColumn();
    $scores = array_filter($rows, fn($a) => $a['score'] !== null && $a['status'] !== 'writing');
    foreach ($rows as &$row) $row['remaining_seconds'] = $row['status'] === 'writing' ? max(0, strtotime($row['expires_at']) - time()) : 0;
    unset($row);
    $monitor = ['quiz' => $q, 'attempts' => $rows, 'stats' => [
        'Eligible students' => $eligible,
        'Students started' => count(array_unique(array_column($rows, 'student_id'))),
        'Writing' => count(array_filter($rows, fn($a) => $a['status'] === 'writing' && strtotime($a['expires_at']) > time())),
        'Finalized' => count(array_filter($rows, fn($a) => $a['status'] !== 'writing')),
        'Flagged' => count(array_filter($rows, fn($a) => $a['flagged'])),
        'Average %' => $scores ? round(array_sum(array_map(fn($a) => 100 * $a['score'] / max(1, $a['total_marks']), $scores)) / count($scores), 1) : '—'
    ]];
    require_once __DIR__ . '/online_quiz_monitor_view.php';
    return oq_monitor_presentation($monitor);
}
function oq_timeline(int $id, array $user): array
{
    oq_attempt($id, $user, false, true);
    return oq_query('SELECT event_type,is_violation,warning_number,server_at FROM online_quiz_integrity_events WHERE attempt_id=? ORDER BY id', [$id])->fetchAll();
}
