<?php
require_once __DIR__ . '/config.php';
require_login();

$user = current_user();
// Students and admins can both register explicitly now (Part 2/4 of the
// explicit-registration correction) — lecturers are unaffected/unchanged:
// course.lecturer_id ownership is a different, pre-existing relationship,
// not something registration applies to.
if (!in_array($user['role'], ['student', 'admin'], true)) {
    header('Location: dashboard');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard');
    exit();
}

$courseId = (int)($_POST['course_id'] ?? 0);
if ($courseId <= 0) {
    header('Location: dashboard');
    exit();
}

$stmt = db()->prepare('SELECT * FROM courses WHERE id = ? LIMIT 1');
$stmt->execute([$courseId]);
$course = $stmt->fetch();

if (!$course) {
    header('Location: course?id=' . $courseId . '&error=course_unavailable');
    exit();
}

// Admin registration is bounded by the same department visibility
// boundary as everywhere else — an admin can only register for a course
// they could actually see in the first place (courses_for_user()), never
// a foreign department's via a manually-submitted course_id. Students are
// deliberately NOT restricted this way here — registering outside their
// normal department/level is exactly the existing borrowed/carry-over
// mechanism (classify_enrollment_access() below), not a bypass.
if ($user['role'] === 'admin' && (int) $course['department_id'] !== (int) ($user['department_id'] ?? 0)) {
    header('Location: dashboard');
    exit();
}

if (!is_enrolled((int)$user['id'], $courseId)) {
    // is_enrolled() only checks status='active', so this branch also covers
    // re-registering for a Past Course whose enrollment was demoted to
    // 'inactive' by a level transition — flip that same row back to active
    // (UNIQUE KEY uniq_student_course means a second INSERT would fail
    // outright) rather than creating a duplicate. A student with no prior
    // row for this course at all gets a fresh 'active' one, same as before.
    ensure_enrollment_schema();

    // access_type is (re)classified against the student's CURRENT
    // department/level at the moment of THIS registration action — see
    // classify_enrollment_access(). Recomputed on every registration,
    // including re-registration: a course that was 'normal' when first
    // registered can correctly become 'carryover' if the student
    // re-registers for it after leveling past it — that reclassification is
    // accurate, not a bug, since it genuinely is a carry-over at that point.
    $accessType = classify_enrollment_access(
        (int) $course['department_id'],
        $course['level'] !== null ? (int) $course['level'] : null,
        $user['department_id'] !== null ? (int) $user['department_id'] : null,
        $user['level'] !== null ? (int) $user['level'] : null
    );

    $existing = db()->prepare('SELECT id FROM enrollments WHERE student_id = ? AND course_id = ?');
    $existing->execute([$user['id'], $courseId]);
    $existingId = $existing->fetchColumn();

    if ($existingId) {
        db()->prepare("UPDATE enrollments SET status = 'active', access_type = ? WHERE id = ?")
            ->execute([$accessType, $existingId]);
    } else {
        db()->prepare('INSERT INTO enrollments (student_id, course_id, status, access_type) VALUES (?, ?, ?, ?)')
            ->execute([$user['id'], $courseId, 'active', $accessType]);
    }
}

header('Location: course?id=' . $courseId . '&registered=1');
exit();
