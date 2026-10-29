<?php

/**
 * ai_quiz.php — Phase 2C: live quiz-generation endpoint, now wired to the
 * Phase 2A/2B planner + concurrent batch executor instead of the old
 * monolithic single-call generation. quiz.php's request/response contract
 * is UNCHANGED — this file is the only thing that changed.
 *
 * POST JSON (unchanged):
 * { "course_id": 3, "topic_id": null, "scope": "course", "count": 10,
 *   "images_b64": ["data:image/...", ...], "question_type": "hybrid" }
 *
 * OLD flow (removed): parse -> load course -> build whole-quiz context ->
 * one big AI call -> global top-up loop -> renormalize marks to 100 ->
 * persist -> respond.
 *
 * NEW flow: parse -> authorize (enrolled_or_staff_access(), explicit and
 * first — this endpoint previously had NO such check at all) -> plan_quiz()
 * -> execute_quiz_plan() (concurrent per-batch generation, per-batch
 * validation/retry/top-up, persistence) -> map the executor's result onto
 * the EXACT SAME response shape the old code returned.
 *
 * The planner is authoritative: nothing here recomputes hybrid counts,
 * batches, concept allocation, mark budgets, or question numbers — see
 * quiz_planner.php / quiz_executor.php (both unmodified by this phase).
 *
 * Marking model (unchanged, now enforced per-batch by quiz_executor.php):
 *   - Total marks for the quiz = 100, split unevenly across questions by the AI
 *     based on complexity (not divided equally).
 *   - MCQ / True-False: full marks or zero, exact match.
 *   - Theory questions carry a "rubric":
 *       format = "list_explain" -> items (N), marks_per_list_item, marks_per_explain_item
 *       format = "general"      -> criteria: [{description, marks}, ...] (3-4 criteria)
 *
 * Image handling — KNOWN, DOCUMENTED LIMITATION (Phase 2A's plan never
 * carried images_b64 as an input, so the new per-batch generation prompts
 * do not yet reference uploaded images/image_ref the way the old single
 * prompt did): images are still accepted, stored on quiz_sessions, and
 * echoed back in the response exactly as before (so the response contract
 * and the quiz_sessions.uploaded_images column both keep working), but they
 * are not yet incorporated into what the AI is asked to generate from.
 * Every image_ref on a newly generated question will be null this phase —
 * structurally harmless (quiz.php's image-rendering branch simply never
 * fires), but a real capability gap versus the old path, called out here
 * rather than silently dropped.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/quiz_executor.php';

/**
 * Core request handler, testable in isolation (dependency-injectable
 * $executor) without any network/AI call. Returns ['status' => int, 'body'
 * => array] — the live script below just sets the HTTP status and echoes
 * json_encode($body). Error bodies are always the same generic shape the
 * old endpoint used ({"error": "..."}) — internal detail (batch id,
 * failure category, provider messages) is logged via error_log(), never
 * returned to the client.
 */
function handle_ai_quiz_request(array $body, ?array $user, ?callable $executor = null): array
{
    $executor = $executor ?? 'execute_quiz_plan';

    if (!$user) {
        return ['status' => 401, 'body' => ['error' => 'Not logged in.']];
    }

    $courseId = (int) ($body['course_id'] ?? 0);
    if ($courseId <= 0) {
        return ['status' => 400, 'body' => ['error' => 'Missing course_id']];
    }

    // ── Authorization BEFORE any course/material access — this endpoint
    // previously had no such check at all (a real pre-existing gap; any
    // logged-in user could POST any course_id). Quiz generation is an
    // AI-powered STUDENT feature and specifically requires REGISTRATION
    // (registered_or_staff_ai_access()), not just course authorization —
    // an admin who is merely department-authorized but not personally
    // enrolled is deliberately still rejected here, exactly like an
    // unregistered student would be. This is the existing, unmodified
    // authorization mechanism used everywhere this same distinction
    // applies — not a new/second one. plan_quiz() (inside the executor)
    // re-checks this same thing independently as an additional safeguard,
    // not a replacement for this check. ─────────────────────────────────
    if (!registered_or_staff_ai_access($courseId, $user)) {
        error_log("[LCS] ai_quiz.php: user #{$user['id']} denied access to course #{$courseId} (not registered/staff).");
        return ['status' => 403, 'body' => ['error' => 'You are not authorized to generate a quiz for this course.']];
    }

    $imagesB64 = is_array($body['images_b64'] ?? null) ? array_slice($body['images_b64'], 0, 5) : [];

    // ── Plan + execute. Nothing here recalculates anything the planner
    // already decided — see quiz_planner.php / quiz_executor.php. ─────────
    $result = $executor($body, $user);

    if (isset($result['error'])) {
        // Full detail server-side only — never in the HTTP response.
        error_log('[LCS] ai_quiz.php generation failed: ' . json_encode($result));
        $status = match ($result['error']) {
            'not_authorized' => 403,
            'topic_not_found' => 404,
            default => 500,
        };
        return ['status' => $status, 'body' => ['error' => 'Could not generate quiz questions. Please try again.']];
    }

    // Images aren't part of the plan/executor at all this phase (see the
    // file-level docblock) — attached here, at the endpoint boundary, as a
    // narrowly-scoped follow-up update rather than changing
    // execute_quiz_plan()'s tested signature/behavior for something it
    // doesn't otherwise need to know about.
    if ($imagesB64) {
        db()->prepare('UPDATE quiz_sessions SET uploaded_images = ? WHERE id = ?')
            ->execute([json_encode($imagesB64), $result['session_id']]);
    }

    // ── EXACT same response shape the old endpoint returned. ───────────────
    return ['status' => 200, 'body' => [
        'session_id'      => $result['session_id'],
        'questions'       => $result['questions'],
        'total'           => $result['total'],
        'total_marks'     => (int) round($result['total_marks']),
        'uploaded_images' => $imagesB64,
    ]];
}

if (!defined('AI_QUIZ_LIBRARY_ONLY')) {
    require_login();
    // Batches run concurrently (quiz_executor.php), but a worst-case round
    // of retries/top-ups across several batches can still legitimately run
    // long — same reasoning as the old flat 450s budget, kept unchanged.
    set_time_limit(450);
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit();
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $user = current_user();

    $result = handle_ai_quiz_request($body, $user);
    http_response_code($result['status']);
    echo json_encode($result['body']);
    exit();
}
