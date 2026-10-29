<?php

/**
 * quiz_marking_executor.php — Phase 2D: concurrent, batch-aware theory
 * marking.
 *
 * Deliberately an ISOLATED file, same pattern as quiz_executor.php/
 * ai_index_worker.php before it — reuses, unmodified: quiz_planner.php's
 * build_batches_from_type_sequence() (batch reconstruction — see below) and
 * quiz_executor.php's run_concurrent_http_requests() (the exact same
 * generic curl_multi engine generation uses, not a second implementation).
 * Neither quiz_planner.php nor quiz_executor.php nor ai_quiz.php is
 * modified by this phase.
 *
 * ── KEY INSPECTION FINDING (before any code was written) ───────────────────
 * The existing ai_quiz_submit.php grades theory questions using ONLY each
 * question's own stored `question`/`format`/`marks`/`rubric` fields — it
 * has NEVER re-consulted course material/document context during marking.
 * The rubric IS the generation-time material context, already condensed
 * into checkable criteria and immutably persisted per-question. This means
 * "generation context = marking context" (an explicit hard requirement of
 * this phase) is satisfied by construction, with NO new schema and NO
 * changes to quiz_executor.php's persistence — there was never anything
 * else to preserve/carry through.
 *
 * ── SECOND KEY FINDING: batch reconstruction needs no stored batch_id ───────
 * quiz_questions has no batch_id column, and none is added here. Instead,
 * the ORIGINAL generation batches are reconstructed by feeding the STORED,
 * question_no-ordered `type` sequence back through
 * build_batches_from_type_sequence() — the exact same pure function
 * generation itself used, with the exact same QUIZ_PLAN_THEORY_BATCH_MAX
 * constant. Since that function's output depends on nothing but the type
 * sequence (no randomness, no external state), and the persisted sequence
 * is byte-identical to what was generated, this reconstruction is
 * mathematically guaranteed identical to the original batches — not an
 * approximation. Proven by a dedicated equivalence test, not just asserted.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/quiz_planner.php';   // build_batches_from_type_sequence() — unmodified
require_once __DIR__ . '/quiz_executor.php';  // run_concurrent_http_requests() — unmodified, same engine as generation

const QUIZ_MARK_MAX_BATCH_RETRIES = 2; // mirrors QUIZ_EXEC_MAX_BATCH_RETRIES's cap for symmetry

// ═════════════════════════════════════════════════════════════════════════════
// 1. BATCH RECONSTRUCTION
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Reconstructs the exact original generation batch groups from a
 * question_no-ordered list of ['question_no','type']. See the file-level
 * docblock for why this is exact, not approximate.
 */
function reconstruct_generation_batches(array $orderedQuestions): array
{
    return build_batches_from_type_sequence(array_column($orderedQuestions, 'type'));
}

/**
 * Which of the reconstructed batches need AI marking at all, and exactly
 * which question_nos within each. Batch IDs match generation's own
 * numbering exactly (batch_1, batch_2, ... in original order) — a batch
 * with zero theory questions (e.g. a pure-objective batch, or an all-MCQ
 * batch within a hybrid quiz) is simply absent from the result, never
 * renumbered, so "batch_3" here always means the SAME batch_3 generation
 * produced.
 */
function theory_marking_batches_from(array $orderedQuestions): array
{
    $groups = reconstruct_generation_batches($orderedQuestions);
    $result = [];
    foreach ($groups as $i => $group) {
        $nos = array_column(array_filter($group, fn($q) => $q['type'] === 'short'), 'question_no');
        if ($nos) {
            $result['batch_' . ($i + 1)] = $nos;
        }
    }
    return $result;
}

// ═════════════════════════════════════════════════════════════════════════════
// 2. PER-BATCH MARKING PROMPT + SCHEMA — the EXISTING grading prompt's
// quality rules (calibration examples, list_explain/general handling, the
// "grade for meaning not wording" instructions, mandatory per-criterion
// feedback) preserved VERBATIM, only the injected question subset changes.
// ═════════════════════════════════════════════════════════════════════════════

function quiz_marking_prompt_body(): string
{
    // Copied verbatim from ai_quiz_submit.php's existing $gradingPrompt —
    // same calibration examples, same list_explain/general rules, same
    // "grade for meaning" priority, same feedback requirement. Not
    // simplified, not reworded.
    return <<<TXT
YOUR #1 PRIORITY: grade what the student actually UNDERSTANDS, not how closely their wording matches a model answer. Two different graders making the same mistake in opposite directions are both wrong: (a) a grader who deducts marks because the student used different words, a different order, a more casual tone, or skipped a phrase from the "expected" answer even though the underlying concept is correctly conveyed — that grader is TOO HARSH and is the failure mode you must actively avoid; (b) a grader who awards marks for confident-sounding but substantively wrong or empty answers. Aim for (c): a fair university lecturer who reads for MEANING.

CALIBRATION EXAMPLES — use these as your anchor for how generous correct-but-differently-worded answers should be:
- Criterion: "Correctly explains CSMA/CD". Student writes, in their own words and order, that Ethernet devices listen before sending, detect if two signals collide, and stop and retry after a random wait. This is CSMA/CD correctly explained in different language — award FULL marks for this criterion, not a partial deduction for not using the term "carrier sense" verbatim.
- Criterion: "Explains the purpose of a subnet mask". Student says it separates the network and host parts of an IP address (without using the exact phrase "network portion"/"host portion"). This is substantively correct — award FULL or near-full marks, not a low score for imprecise terminology.
- Only award LOW marks (not zero, unless truly unaddressed) when the core concept itself is missing, confused, or factually wrong — never merely because the phrasing, structure, or level of formality differs from an idealized model answer.

For questions with "format": "list_explain":
- The rubric has "expected_items" (N), "marks_per_list_item", "marks_per_explain_item", and "item_key_points" (the N correct items with what a good explanation covers — these describe the CONCEPT expected, not a script to match verbatim).
- For EACH of the N expected items, check independently whether the student's answer:
  (a) correctly LISTED/NAMED that item anywhere in their answer (any reasonable synonym or rephrasing counts) — if yes, award marks_per_list_item for that item
  (b) correctly EXPLAINED that item with genuine understanding of the concept, in ANY wording — if yes, award marks_per_explain_item IN FULL. Only reduce this below full marks when the explanation is genuinely incomplete or partly wrong, and even then award the proportion that reflects what WAS correctly conveyed (e.g. an explanation that captures the main idea but misses one secondary detail should still get the large majority of marks_per_explain_item, not half or less).
- The student does not need to present items in the same order as item_key_points — match by meaning, not position.
- If the student lists/explains MORE items than expected, only grade up to the expected_items count using the best N matches — do not penalize extra correct items, but do not award more than the question's total marks.
- Sum all awarded item marks for the question's total awarded_marks.

For questions with "format": "general":
- The rubric has "criteria": an array of {description, marks}.
- For EACH criterion, judge SEMANTICALLY whether the underlying concept the criterion is checking for was correctly conveyed anywhere in the student's answer — regardless of phrasing, order, or which exact words were used. Award a proportional mark on a genuine spectrum:
  - Concept fully and correctly conveyed (even briefly, even in different words) -> full marks for that criterion.
  - Concept mostly correct with a minor gap or imprecision -> a small deduction only, most of the marks (roughly 70-90%), not a heavy penalty.
  - Concept partially addressed or only vaguely gestured at -> genuine partial credit proportional to what's actually there (roughly 30-60%), not near-zero.
  - Concept entirely missing, confused, or contradicted -> 0 for that criterion.
- Do NOT default to the middle or low end of this spectrum out of caution — if the substance is there, award it.
- Sum all awarded criterion marks for the question's total awarded_marks.

General grading principles:
- Be fair, not harsh — a real university lecturer rewards genuine understanding expressed in the student's own words; they do not run a keyword-matching script. Only withhold marks for what's actually missing or wrong, never for stylistic differences from a model answer.
- If the student's answer is empty or completely irrelevant to the question, award 0 for every part — but "different from the model answer's wording" is NOT the same thing as "irrelevant".
- Round every individual awarded mark to 1 decimal place.
- The question's total awarded_marks MUST equal the sum of that question's own item/criterion awarded marks below — never state a different total than what the breakdown actually adds up to, and never let it exceed that question's total "marks" value.
- EVERY item/criterion's "feedback" field must be a substantive 1-2 sentence explanation of what was and wasn't demonstrated (e.g. "Correctly explained that devices listen before transmitting and back off after a collision, capturing the core CSMA/CD mechanism; did not mention the random backoff timer, but this is a minor omission.") — never a bare restatement of the criterion's description, and never an empty string.
TXT;
}

function quiz_marking_output_format(): string
{
    return <<<FMT
OUTPUT FORMAT — respond with ONLY valid JSON, no markdown, no explanation:
{
  "graded": [
    { "question_no": 3, "awarded_marks": 7.5, "breakdown": { "format": "list_explain", "items": [
      {"key_point": "...", "listed": true, "list_marks_awarded": 0.4, "explained": true, "explain_marks_awarded": 1.2, "feedback": "..."}
    ] } },
    { "question_no": 5, "awarded_marks": 9.0, "breakdown": { "format": "general", "criteria": [
      {"description": "...", "max_marks": 3, "awarded_marks": 3, "feedback": "..."}
    ] } }
  ]
}
FMT;
}

function quiz_marking_json_schema(int $questionCount): array
{
    // Identical shape to ai_quiz_submit.php's existing $gradingSchema, with
    // one tightening (minItems/maxItems bound to THIS batch's own count) —
    // same rationale as the equivalent tightening in quiz_executor.php's
    // generation schema.
    return [
        'type' => 'object',
        'properties' => [
            'graded' => [
                'type' => 'array', 'minItems' => $questionCount, 'maxItems' => $questionCount,
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'question_no'   => ['type' => 'integer'],
                        'awarded_marks' => ['type' => 'number'],
                        'breakdown' => [
                            'anyOf' => [
                                ['type' => 'object', 'properties' => [
                                    'format' => ['type' => 'string', 'enum' => ['list_explain']],
                                    'items'  => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                        'key_point' => ['type' => 'string'], 'listed' => ['type' => 'boolean'],
                                        'list_marks_awarded' => ['type' => 'number'], 'explained' => ['type' => 'boolean'],
                                        'explain_marks_awarded' => ['type' => 'number'], 'feedback' => ['type' => 'string', 'minLength' => 15],
                                    ], 'required' => ['key_point', 'listed', 'list_marks_awarded', 'explained', 'explain_marks_awarded', 'feedback'], 'additionalProperties' => false]],
                                ], 'required' => ['format', 'items'], 'additionalProperties' => false],
                                ['type' => 'object', 'properties' => [
                                    'format' => ['type' => 'string', 'enum' => ['general']],
                                    'criteria' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                                        'description' => ['type' => 'string'], 'max_marks' => ['type' => 'number'],
                                        'awarded_marks' => ['type' => 'number'], 'feedback' => ['type' => 'string', 'minLength' => 15],
                                    ], 'required' => ['description', 'max_marks', 'awarded_marks', 'feedback'], 'additionalProperties' => false]],
                                ], 'required' => ['format', 'criteria'], 'additionalProperties' => false],
                            ],
                        ],
                    ],
                    'required' => ['question_no', 'awarded_marks', 'breakdown'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['graded'],
        'additionalProperties' => false,
    ];
}

/** $batchQuestions: [['no'=>int,'row'=>quiz_questions row,'user_answer'=>string], ...] — ONLY this batch's own theory questions. */
function build_marking_http_request(array $batchQuestions): array
{
    $payload = [];
    foreach ($batchQuestions as $item) {
        $payload[] = [
            'question_no'     => $item['no'],
            'question'        => $item['row']['question'],
            'format'          => $item['row']['format'],
            'marks'           => (float) $item['row']['marks'],
            'rubric'          => $item['row']['rubric'] ? json_decode($item['row']['rubric'], true) : null,
            'student_answer'  => $item['user_answer'],
        ];
    }
    $gradingJson = json_encode($payload, JSON_PRETTY_PRINT);
    $count = count($batchQuestions);

    $prompt = "You are grading university theory exam answers for a Nigerian university course. Below is a JSON array of questions, each with its rubric and the student's actual submitted answer.\n\n"
        . quiz_marking_prompt_body()
        . "\n\nQUESTIONS AND ANSWERS TO GRADE:\n{$gradingJson}\n\n"
        . quiz_marking_output_format();

    $maxTokens = 10000 + ($count * 1500); // same formula as the existing whole-quiz call, now scaled by this batch's own (small, capped-at-3) count
    $timeout   = min(180, 80 + ($count * 10));

    $payload = [
        'model'             => OPENAI_MODEL,
        'input'             => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Grade these ' . $count . ' answer(s) now. Respond with ONLY the JSON object.']]]],
        'instructions'      => $prompt,
        'max_output_tokens' => $maxTokens,
        'reasoning'         => ['effort' => 'medium'], // same as existing — judging semantic correctness is harder than pure arithmetic
        'text'              => ['format' => ['type' => 'json_schema', 'name' => 'quiz_grading', 'strict' => true, 'schema' => quiz_marking_json_schema($count)]],
    ];

    return [
        'url'     => 'https://api.openai.com/v1/responses',
        'headers' => ['Content-Type: application/json', 'Authorization: Bearer ' . OPENAI_API_KEY],
        'body'    => json_encode($payload),
        'timeout' => $timeout,
    ];
}

// ═════════════════════════════════════════════════════════════════════════════
// 3. RESPONSE VALIDATION — all-or-nothing per batch (no analogue of
// generation's "targeted top-up" here — a marking response either covers
// every question in the batch correctly or the whole batch is retried).
// Preserves the EXISTING derive-from-breakdown safeguard verbatim: the
// breakdown's own item/criterion sum is authoritative over any top-level
// "awarded_marks" the AI also stated, since Structured Outputs can't cross-
// validate two sibling fields agree with each other (the same confirmed
// bug class as generation's marks/rubric mismatch).
// ═════════════════════════════════════════════════════════════════════════════

function validate_and_derive_marking_batch(array $graded, array $expectedNos, array $questionsByNo): array
{
    if (count($graded) !== count($expectedNos)) {
        return [false, 'wrong question count in marking response', []];
    }
    $seen = [];
    $derived = [];
    foreach ($graded as $g) {
        $no = (int) ($g['question_no'] ?? -1);
        if (!in_array($no, $expectedNos, true)) {
            return [false, "marking response references question_no {$no} outside this batch (batch mismatch)", []];
        }
        if (isset($seen[$no])) {
            return [false, "duplicate question_no {$no} in marking response", []];
        }
        $seen[$no] = true;

        $breakdown = $g['breakdown'] ?? null;
        if (!is_array($breakdown)) {
            return [false, "question {$no}: missing/invalid breakdown", []];
        }

        $sum = null;
        if (($breakdown['format'] ?? null) === 'list_explain' && isset($breakdown['items']) && is_array($breakdown['items'])) {
            $sum = 0.0;
            foreach ($breakdown['items'] as $it) {
                $sum += (float) ($it['list_marks_awarded'] ?? 0) + (float) ($it['explain_marks_awarded'] ?? 0);
            }
        } elseif (($breakdown['format'] ?? null) === 'general' && isset($breakdown['criteria']) && is_array($breakdown['criteria'])) {
            $sum = (float) array_sum(array_column($breakdown['criteria'], 'awarded_marks'));
        } else {
            return [false, "question {$no}: breakdown missing required rubric criteria for its format", []];
        }

        // Both negative and above-max are REJECTED (trigger a batch retry),
        // not silently clamped — a deliberate strengthening over the old
        // single-shot code's clamp-only behavior (`min($awarded, $marks)`),
        // per this phase's explicit test list treating both as "rejected".
        // Rationale: if the AI already got an easy constraint like "don't
        // exceed max" wrong, the per-criterion breakdown backing that sum
        // is more likely to be flawed too — retrying is more likely to
        // yield a trustworthy result than silently truncating a probably-
        // bad breakdown. (The keyword fallback below is still the final
        // safety net if retries are exhausted, exactly as before.)
        $maxMarks = (float) ($questionsByNo[$no]['marks'] ?? 0);
        if ($sum < -0.001) {
            return [false, "question {$no}: derived awarded_marks is negative ({$sum})", []];
        }
        if ($sum > $maxMarks + 0.01) {
            return [false, "question {$no}: derived awarded_marks ({$sum}) exceeds max_marks ({$maxMarks})", []];
        }

        $derived[$no] = ['awarded_marks' => round($sum, 1), 'breakdown' => $breakdown];
    }
    foreach ($expectedNos as $no) {
        if (!isset($derived[$no])) {
            return [false, "missing question_no {$no} in marking response", []];
        }
    }
    return [true, null, $derived];
}

/** Existing keyword-match fallback from ai_quiz_submit.php, preserved verbatim, now scoped per-batch (was previously applied per-question globally on any AI failure). */
function marking_keyword_fallback(array $questionRow, string $userAnswer): array
{
    $correctWords = array_filter(str_word_count(strtolower($questionRow['correct']), 1));
    $answerLower  = strtolower($userAnswer);
    $matchCount   = 0;
    foreach ($correctWords as $word) {
        if (strlen($word) > 3 && str_contains($answerLower, $word)) $matchCount++;
    }
    $ratio = count($correctWords) > 0 ? ($matchCount / count($correctWords)) : 0;
    return [
        'awarded_marks' => round($ratio * (float) $questionRow['marks'], 1),
        'breakdown' => ['format' => 'fallback', 'note' => 'AI grading unavailable — used keyword fallback'],
    ];
}

// ═════════════════════════════════════════════════════════════════════════════
// 4. ONE MARKING ROUND — mirrors quiz_executor.php's run_batch_round()
// structurally (build requests -> dispatch concurrently -> parse+validate),
// reusing the exact same run_concurrent_http_requests() engine.
// ═════════════════════════════════════════════════════════════════════════════

function run_marking_round(array $batchIds, array $theoryBatches, array $questionsByNo, array $answersByNo, callable $httpRunner): array
{
    $requests = [];
    $batchQuestionsById = [];
    foreach ($batchIds as $batchId) {
        $nos = $theoryBatches[$batchId];
        $items = [];
        foreach ($nos as $no) {
            $items[] = ['no' => $no, 'row' => $questionsByNo[$no], 'user_answer' => $answersByNo[$no] ?? ''];
        }
        $batchQuestionsById[$batchId] = $items;
        $requests[$batchId] = build_marking_http_request($items);
    }

    $tStart = microtime(true);
    $responses = $httpRunner($requests);
    $elapsedMs = (int) round((microtime(true) - $tStart) * 1000);

    $outcomes = [];
    foreach ($batchIds as $batchId) {
        $resp = $responses[$batchId] ?? ['http_code' => 0, 'body' => null, 'error' => 'no response received'];
        $timing = [
            'dispatched_at' => $resp['dispatched_at'] ?? null,
            'completed_at'  => $resp['completed_at'] ?? null,
            'duration_ms'   => $resp['duration_ms'] ?? null,
        ];
        try {
            $text  = parse_openai_response_text($resp['http_code'], $resp['body'], $resp['error']); // quiz_executor.php, unmodified
            $usage = parse_openai_response_usage($resp['body']);
        } catch (\Throwable $e) {
            $outcomes[$batchId] = ['status' => 'failed', 'reason' => $e->getMessage(), 'elapsed_ms' => $elapsedMs, 'timing' => $timing];
            continue;
        }

        $parsed = json_decode($text, true);
        if (!is_array($parsed) || !isset($parsed['graded']) || !is_array($parsed['graded'])) {
            $outcomes[$batchId] = ['status' => 'failed', 'reason' => '[malformed] could not parse graded array', 'elapsed_ms' => $elapsedMs, 'usage' => $usage, 'timing' => $timing];
            continue;
        }

        $expectedNos = $theoryBatches[$batchId];
        $questionsSubset = array_intersect_key($questionsByNo, array_flip($expectedNos));
        [$ok, $error, $derived] = validate_and_derive_marking_batch($parsed['graded'], $expectedNos, $questionsSubset);
        if (!$ok) {
            $outcomes[$batchId] = ['status' => 'failed', 'reason' => "[malformed] {$error}", 'elapsed_ms' => $elapsedMs, 'usage' => $usage, 'timing' => $timing];
            continue;
        }

        $outcomes[$batchId] = ['status' => 'marked', 'derived' => $derived, 'usage' => $usage, 'elapsed_ms' => $elapsedMs, 'timing' => $timing];
    }
    return $outcomes;
}

// ═════════════════════════════════════════════════════════════════════════════
// 5. MAIN ENTRY POINT
// ═════════════════════════════════════════════════════════════════════════════

/**
 * $httpRunner injectable exactly like execute_quiz_plan() — defaults to the
 * real run_concurrent_http_requests(). Ownership check, MCQ/T-F PHP
 * marking, and the final aggregate/persist/response shape all mirror
 * ai_quiz_submit.php's existing logic as closely as possible; only the
 * theory path is newly batched+concurrent.
 */
function execute_quiz_marking(int $sessionId, array $answers, array $user, ?callable $httpRunner = null): array
{
    $httpRunner = $httpRunner ?? 'run_concurrent_http_requests';
    $tStart = microtime(true);

    // ── Ownership check — EXACT existing query/shape, unchanged: a single
    // WHERE id=? AND user_id=? means a nonexistent session and a session
    // belonging to someone else are indistinguishable to the caller
    // (matches the "don't leak existence" requirement, already true today). ──
    $sess = db()->prepare('SELECT * FROM quiz_sessions WHERE id = ? AND user_id = ?');
    $sess->execute([$sessionId, $user['id']]);
    $session = $sess->fetch();
    if (!$session) {
        return ['error' => 'forbidden'];
    }

    $qStmt = db()->prepare('SELECT * FROM quiz_questions WHERE session_id = ? ORDER BY question_no');
    $qStmt->execute([$sessionId]);
    $questions = $qStmt->fetchAll();
    if (!$questions) {
        return ['error' => 'no_questions'];
    }

    // Preserved existing semantic: no idempotency guard existed before this
    // phase (re-submission always re-grades and overwrites) — that exact
    // behavior is kept, not newly restricted, per the explicit instruction
    // not to invent behavior the current system doesn't already have.

    $questionsByNo = [];
    $answersByNo = [];
    foreach ($questions as $q) {
        $no = (int) $q['question_no'];
        $questionsByNo[$no] = $q;
        $answersByNo[$no] = trim((string) ($answers[$no] ?? ''));
    }

    // ── MCQ/True-False — deterministic PHP marking, NO AI call, EXACT
    // existing comparison rule (case-insensitive exact letter match). ──────
    $objectiveResults = [];
    foreach ($questionsByNo as $no => $q) {
        if ($q['type'] !== 'mcq' && $q['type'] !== 'truefalse') continue;
        $userAnswer = $answersByNo[$no];
        $correct    = trim($q['correct']);
        $isCorrect  = strtoupper($userAnswer) === strtoupper($correct);
        $awarded    = $isCorrect ? (float) $q['marks'] : 0;

        db()->prepare('UPDATE quiz_questions SET user_answer=?, is_correct=?, awarded_marks=? WHERE id=?')
            ->execute([$userAnswer, $isCorrect ? 1 : 0, $awarded, $q['id']]);

        $objectiveResults[$no] = [
            'question_no' => $no, 'type' => $q['type'], 'question' => $q['question'],
            'options' => $q['options'] ? json_decode($q['options'], true) : null,
            'user_answer' => $userAnswer, 'correct' => $correct, 'is_correct' => $isCorrect,
            'marks' => (float) $q['marks'], 'awarded_marks' => $awarded,
            'explanation' => $q['explanation'], 'breakdown' => null,
        ];
    }

    // ── Theory — reconstruct ORIGINAL batches, mark concurrently ────────────
    $orderedForReconstruction = array_map(fn($q) => ['question_no' => (int) $q['question_no'], 'type' => $q['type']], $questions);
    $theoryBatches = theory_marking_batches_from($orderedForReconstruction); // batch_id => [question_no, ...], original order/IDs preserved

    $theoryResults = [];
    $usage = [];
    if ($theoryBatches) {
        $pending = array_keys($theoryBatches);
        $attempts = array_fill_keys($pending, 0);
        $derivedByNo = [];

        while ($pending) {
            $outcomes = run_marking_round($pending, $theoryBatches, $questionsByNo, $answersByNo, $httpRunner);
            $stillPending = [];
            foreach ($pending as $batchId) {
                $outcome = $outcomes[$batchId];
                $usage[$batchId] = $usage[$batchId] ?? $outcome;

                if ($outcome['status'] === 'marked') {
                    foreach ($outcome['derived'] as $no => $d) {
                        $derivedByNo[$no] = $d;
                    }
                    continue;
                }

                $attempts[$batchId]++;
                if ($attempts[$batchId] < QUIZ_MARK_MAX_BATCH_RETRIES) {
                    $stillPending[] = $batchId; // retry ONLY this batch — siblings already marked are untouched
                    continue;
                }

                // Retry budget exhausted for this batch — existing product
                // rule preserved: fall back to the keyword-match heuristic
                // (capped, never zero, never blocks the submission) rather
                // than publish no result at all, scoped to just this
                // batch's questions instead of the whole quiz.
                error_log("[LCS] quiz marking: batch {$batchId} (session #{$sessionId}) exhausted retries — using keyword fallback for its questions.");
                foreach ($theoryBatches[$batchId] as $no) {
                    $derivedByNo[$no] = marking_keyword_fallback($questionsByNo[$no], $answersByNo[$no]);
                }
            }
            $pending = $stillPending;
        }

        foreach ($theoryBatches as $batchId => $nos) {
            foreach ($nos as $no) {
                $q = $questionsByNo[$no];
                $d = $derivedByNo[$no];
                $awarded = $d['awarded_marks'];

                db()->prepare('UPDATE quiz_questions SET user_answer=?, is_correct=?, awarded_marks=?, grading_breakdown=? WHERE id=?')
                    ->execute([
                        $answersByNo[$no],
                        $awarded >= (float) $q['marks'] * 0.5 ? 1 : 0, // same legacy correct/incorrect flag rule as before
                        $awarded,
                        json_encode($d['breakdown']),
                        $q['id'],
                    ]);

                $theoryResults[$no] = [
                    'question_no' => $no, 'type' => $q['type'], 'format' => $q['format'], 'question' => $q['question'],
                    'options' => null, 'user_answer' => $answersByNo[$no], 'correct' => $q['correct'],
                    'marks' => (float) $q['marks'], 'awarded_marks' => $awarded,
                    'explanation' => $q['explanation'], 'breakdown' => $d['breakdown'],
                ];
            }
        }
    }

    // ── Merge in question order (never batch/response arrival order) ───────
    $results = [];
    foreach ($questions as $q) {
        $no = (int) $q['question_no'];
        $results[] = $objectiveResults[$no] ?? $theoryResults[$no] ?? null;
    }
    $results = array_values(array_filter($results));

    // ── PHP-authoritative final aggregate — EXACT existing formula ─────────
    $totalAwarded  = array_sum(array_column($results, 'awarded_marks'));
    $totalPossible = array_sum(array_column($results, 'marks'));
    $percent       = $totalPossible > 0 ? round(($totalAwarded / $totalPossible) * 100) : 0;
    $grade         = match (true) {
        $percent >= 70 => 'A', $percent >= 60 => 'B', $percent >= 50 => 'C',
        $percent >= 45 => 'D', $percent >= 40 => 'E', default => 'F',
    };

    db()->prepare("UPDATE quiz_sessions SET status='completed', score=?, total=100, completed_at=NOW() WHERE id=?")
        ->execute([$percent, $sessionId]);

    return [
        'score' => round($totalAwarded, 1), 'total' => round($totalPossible, 1),
        'percent' => $percent, 'grade' => $grade, 'results' => $results,
        // Internal-only — stripped at the ai_quiz_submit.php endpoint boundary.
        'timeline' => ['total_wall_ms' => (int) round((microtime(true) - $tStart) * 1000)],
        'usage' => $usage,
        'batch_count' => count($theoryBatches),
    ];
}
