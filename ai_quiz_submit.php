<?php

/**
 * ai_quiz_submit.php — Phase 2D: saves answers and scores the quiz, now via
 * the batch-aware concurrent marking executor instead of one global AI
 * grading call. Response contract to quiz.php is UNCHANGED.
 *
 * POST JSON (unchanged):
 * { "session_id": 1, "answers": {"1": "A", "2": "MIS is...", "3": "A"} }
 *
 * Marking model (unchanged, now dispatched per reconstructed generation
 * batch instead of one big call for the whole quiz):
 *   - MCQ / True-False: full marks or zero, exact letter match — PHP only, no AI.
 *   - Theory ("list_explain"): AI grades each expected item — a small mark for
 *     correctly listing it, a larger mark for correctly explaining it.
 *   - Theory ("general"): AI grades against 3-4 rubric criteria.
 *
 * See quiz_marking_executor.php's file-level docblock for the two key
 * inspection findings this phase's design rests on: (1) marking has always
 * graded against each question's own stored rubric, never fresh course
 * material, so "generation context = marking context" already held; (2)
 * the original generation batches are reconstructed from the stored,
 * question_no-ordered type sequence via quiz_planner.php's own
 * build_batches_from_type_sequence() — exact, not approximate, and
 * requires no new schema.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/quiz_marking_executor.php';

/**
 * Core request handler, testable in isolation (dependency-injectable
 * $executor) without any network/AI call. Returns ['status' => int, 'body'
 * => array].
 */
function handle_ai_quiz_submit_request(array $body, ?array $user, ?callable $executor = null): array
{
    $executor = $executor ?? 'execute_quiz_marking';

    if (!$user) {
        return ['status' => 401, 'body' => ['error' => 'Not logged in.']];
    }

    $sessionId = (int) ($body['session_id'] ?? 0);
    $answers   = is_array($body['answers'] ?? null) ? $body['answers'] : [];

    $result = $executor($sessionId, $answers, $user);

    if (isset($result['error'])) {
        error_log("[LCS] ai_quiz_submit.php marking failed: session #{$sessionId} user #{$user['id']}: " . json_encode($result));
        // 'forbidden' preserves the EXACT existing response (status + message)
        // for a session that doesn't exist OR belongs to someone else —
        // never distinguishes the two, exactly as before this phase.
        $status = match ($result['error']) {
            'forbidden' => 403,
            default => 500,
        };
        $message = $result['error'] === 'forbidden' ? 'Forbidden' : 'Could not grade this submission. Please try again.';
        return ['status' => $status, 'body' => ['error' => $message]];
    }

    // EXACT existing response shape — internal batch/usage/timeline detail
    // (present on $result for logging/tests) is stripped here, never sent
    // to the client.
    return ['status' => 200, 'body' => [
        'score'   => $result['score'],
        'total'   => $result['total'],
        'percent' => $result['percent'],
        'grade'   => $result['grade'],
        'results' => $result['results'],
    ]];
}

if (!defined('AI_QUIZ_SUBMIT_LIBRARY_ONLY')) {
    require_login();
    // Batches run concurrently, but a worst-case round of retries across
    // several theory-marking batches can still legitimately run long —
    // same reasoning as the previous flat 350s budget, kept unchanged.
    set_time_limit(350);
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit();
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $user = current_user();

    $result = handle_ai_quiz_submit_request($body, $user);
    http_response_code($result['status']);
    echo json_encode($result['body']);
    exit();
}
