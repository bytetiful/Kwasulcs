<?php

/**
 * quiz_executor.php — Phase 2B: concurrent execution of a Phase 2A quiz
 * plan.
 *
 * Consumes plan_quiz() (quiz_planner.php, UNCHANGED) and executes exactly
 * its batches — one AI generation request per batch, launched concurrently
 * via curl_multi, mapped back to the plan's immutable question numbers
 * regardless of arrival order, validated, retried per-batch, and persisted
 * only once every batch has succeeded.
 *
 * Deliberately an ISOLATED file, not a modification of ai_quiz.php — same
 * pattern as ai_index_worker.php in Phase 1 (own copies of the prompt/
 * schema fragments it needs, adapted per-batch, not shared functions/
 * `require`d from that file). ai_quiz.php's own single-shot endpoint is
 * completely untouched and still the only thing quiz.php's frontend calls;
 * nothing here is wired into the live user-facing flow yet.
 *
 * AI does not plan anything here — every batch's question count, exact
 * type composition, and mark budget come from the plan and are validated
 * against, never decided by, the model's response.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/quiz_planner.php';

const QUIZ_EXEC_MAX_CONCURRENT      = 5;   // bounded — avoid hammering the provider even if a plan has many batches
const QUIZ_EXEC_MAX_BATCH_RETRIES   = 2;   // full-batch regenerations, per batch — mirrors ai_quiz.php's existing top-up attempt cap
const QUIZ_EXEC_TOPUP_MAX_ATTEMPTS  = 2;   // targeted top-up attempts for a partially-short batch, before escalating to a full retry
const QUIZ_EXEC_PER_QUESTION_TOKENS = ['mcq' => 500, 'truefalse' => 500, 'short' => 1300]; // identical to ai_quiz.php's $perQuestionTokens table — preserved, not re-measured

// ═════════════════════════════════════════════════════════════════════════════
// 1. CONCURRENT HTTP ENGINE — generic, provider-agnostic curl_multi wrapper.
// Bounded sliding-window concurrency (never more than $maxConcurrent
// in-flight at once). Used both for the real OpenAI calls AND for the
// concurrency-proof test against a local fake-delay server — same code
// path, not a mock standing in for it.
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Phase 2C follow-up (Part A instrumentation): every result now also
 * carries dispatched_at/completed_at/duration_ms (float unix timestamps +
 * ms) — PURELY ADDITIVE, existing keys (http_code/body/error) and existing
 * behavior are unchanged. dispatched_at is when curl_multi_add_handle()
 * actually happened for that request — under the bounded sliding window
 * this is NOT necessarily the moment run_concurrent_http_requests() was
 * called, so the gap between a request's own dispatched_at and the
 * function's overall start time is exactly the "queued behind the
 * concurrency cap" wait Part A asks about.
 */
function run_concurrent_http_requests(array $requests, int $maxConcurrent = QUIZ_EXEC_MAX_CONCURRENT): array
{
    $results = [];
    $pending = $requests; // key => ['url','headers','body','timeout']
    $active  = [];        // key => curl handle
    $dispatchedAt = [];   // key => float microtime when actually added to the multi-handle
    $mh = curl_multi_init();

    $fillActive = function () use (&$pending, &$active, &$dispatchedAt, $mh, $maxConcurrent) {
        while (count($active) < $maxConcurrent && $pending) {
            $key = array_key_first($pending);
            $req = $pending[$key];
            unset($pending[$key]);

            $ch = curl_init($req['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $req['body'],
                CURLOPT_HTTPHEADER     => $req['headers'],
                CURLOPT_TIMEOUT        => $req['timeout'],
            ]);
            curl_multi_add_handle($mh, $ch);
            $active[$key] = $ch;
            $dispatchedAt[$key] = microtime(true);
        }
    };

    $fillActive();
    $running = null;
    while ($active) {
        do {
            $status = curl_multi_exec($mh, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        if ($running) {
            // curl_multi_select() has a well-documented quirk (notably on
            // macOS) of occasionally returning -1 spuriously — treating
            // that as "no activity" and trusting the full timeout would
            // mean sitting idle for up to 1s even when a transfer already
            // finished, measurably distorting elapsed-time measurements.
            // Fall back to a short sleep instead of trusting a -1 return.
            if (curl_multi_select($mh, 1.0) === -1) {
                usleep(50_000);
            }
        }

        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $key = array_search($ch, $active, true);
            if ($key === false) continue;

            $completedAt = microtime(true);
            $results[$key] = [
                'http_code'     => curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'body'          => curl_multi_getcontent($ch),
                'error'         => curl_error($ch) ?: null,
                'dispatched_at' => $dispatchedAt[$key],
                'completed_at'  => $completedAt,
                'duration_ms'   => (int) round(($completedAt - $dispatchedAt[$key]) * 1000),
            ];
            curl_multi_remove_handle($mh, $ch);
            // curl_close() deliberately omitted — a no-op since PHP 8.0
            // (curl handles are unreffed/GC'd automatically), and a
            // deprecation notice as of PHP 8.5. curl_multi_remove_handle()
            // above is what actually matters here.
            unset($active[$key]);
        }

        $fillActive(); // keep the window full as slots free up
    }
    curl_multi_close($mh);
    return $results;
}

// ═════════════════════════════════════════════════════════════════════════════
// 2. PER-BATCH PROMPT + SCHEMA — adapted from ai_quiz.php's existing
// prompt, NOT simplified. Marking-model language, rubric-grounding
// requirement, and output shape are preserved near-verbatim; only the
// scope (this batch's material only), the type composition (this batch's
// exact planned types, not a free-form "roughly N% mix"), and the mark
// target (this batch's mark_budget, not always 100) are parameterized.
// ═════════════════════════════════════════════════════════════════════════════

function quiz_batch_output_format(): string
{
    // Identical field shape to ai_quiz.php's $outputFormat — same schema,
    // same rubric formats, same everything — just without repeating the
    // full illustrative examples inline here (kept in the JSON schema
    // itself, quiz_batch_json_schema(), which is what actually constrains
    // the model under Structured Outputs).
    return <<<FMT
OUTPUT FORMAT — respond with ONLY valid JSON, no markdown, no explanation:
{
  "questions": [
    { "type": "mcq", "marks": 6, "format": "objective", "question": "...", "options": {"A":"...","B":"...","C":"...","D":"..."}, "correct": "A", "explanation": "...", "rubric": null, "image_ref": null },
    { "type": "short", "marks": 10, "format": "list_explain", "question": "...", "options": null, "correct": "...", "explanation": "...", "rubric": {"expected_items":5,"marks_per_list_item":0.4,"marks_per_explain_item":1.6,"item_key_points":["...","...","...","...","..."]}, "image_ref": null },
    { "type": "short", "marks": 12, "format": "general", "question": "...", "options": null, "correct": "...", "explanation": "...", "rubric": {"criteria":[{"description":"...","marks":3},{"description":"...","marks":3},{"description":"...","marks":4},{"description":"...","marks":2}]}, "image_ref": null }
  ]
}
FMT;
}

function quiz_batch_json_schema(int $questionCount): array
{
    // Identical shape to ai_quiz.php's $quizQuestionSchema, with one
    // deliberate tightening: minItems/maxItems bound to this batch's own
    // question_count — batches are small enough now that an exact bound is
    // both meaningful and cheap, unlike the whole-quiz case this schema
    // was originally written for.
    $item = [
        'type' => 'object',
        'properties' => [
            'type'     => ['type' => 'string', 'enum' => ['mcq', 'truefalse', 'short']],
            'marks'    => ['type' => 'number'],
            'format'   => ['type' => 'string', 'enum' => ['objective', 'list_explain', 'general']],
            'question' => ['type' => 'string'],
            'options'  => [
                'anyOf' => [
                    ['type' => 'object', 'properties' => ['A' => ['type' => 'string'], 'B' => ['type' => 'string'], 'C' => ['type' => ['string', 'null']], 'D' => ['type' => ['string', 'null']]], 'required' => ['A', 'B', 'C', 'D'], 'additionalProperties' => false],
                    ['type' => 'null'],
                ],
            ],
            'correct'     => ['type' => 'string'],
            'explanation' => ['type' => 'string'],
            'rubric' => [
                'anyOf' => [
                    ['type' => 'object', 'properties' => ['expected_items' => ['type' => 'integer'], 'marks_per_list_item' => ['type' => 'number'], 'marks_per_explain_item' => ['type' => 'number'], 'item_key_points' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['expected_items', 'marks_per_list_item', 'marks_per_explain_item', 'item_key_points'], 'additionalProperties' => false],
                    ['type' => 'object', 'properties' => ['criteria' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['description' => ['type' => 'string'], 'marks' => ['type' => 'number']], 'required' => ['description', 'marks'], 'additionalProperties' => false]]], 'required' => ['criteria'], 'additionalProperties' => false],
                    ['type' => 'null'],
                ],
            ],
            'image_ref' => ['type' => ['integer', 'null']],
        ],
        'required' => ['type', 'marks', 'format', 'question', 'options', 'correct', 'explanation', 'rubric', 'image_ref'],
        'additionalProperties' => false,
    ];
    return [
        'type' => 'object',
        'properties' => ['questions' => ['type' => 'array', 'items' => $item, 'minItems' => $questionCount, 'maxItems' => $questionCount]],
        'required' => ['questions'],
        'additionalProperties' => false,
    ];
}

/**
 * Phase 2C follow-up Part B — converts uploaded images_b64 (same raw
 * strings ai_quiz.php has always accepted: data-URI or bare base64) into
 * OpenAI Responses API input_image content parts. The SAME image set is
 * attached to every batch's request (per the explicit rule: images are not
 * split by batch, only TEXT material is) — scope enforcement for images
 * happens entirely in the PROMPT (see build_batch_prompt()'s
 * IMAGE SCOPE RULE below), never by physically withholding the image from
 * a batch that doesn't need it.
 */
function quiz_batch_image_content_parts(array $imagesB64): array
{
    $parts = [];
    foreach ($imagesB64 as $img) {
        $mime = 'image/jpeg';
        $clean = $img;
        if (is_string($img) && str_starts_with($img, 'data:')) {
            preg_match('/data:([^;]+);base64,/', $img, $m);
            $mime  = $m[1] ?? 'image/jpeg';
            $clean = preg_replace('/^data:[^;]+;base64,/', '', $img);
        }
        if (!is_string($clean) || $clean === '') continue;
        $parts[] = ['type' => 'input_image', 'image_url' => "data:{$mime};base64,{$clean}"];
    }
    return $parts;
}

/**
 * Phase 2E — shared IMAGE SCOPE RULE + image_ref index-convention prompt
 * block, factored out so it is used VERBATIM by every request type that can
 * carry images: the initial per-batch request, a full-batch retry (which
 * reuses build_batch_prompt() unchanged), AND a targeted top-up request
 * (run_batch_topup(), below) — previously the top-up path built its own,
 * separate, much shorter prompt that never mentioned images at all (gap #2
 * of this phase). $batchConcepts is used only to describe "your assigned
 * material" in the rule text — never changes which images are attached.
 *
 * Also new this phase: the explicit image_ref INDEX CONVENTION. Previously
 * the model was told it "may reference an image via image_ref" but never
 * told what value to put there (the schema just said integer|null) — with
 * no defined meaning for the integer, there was no reliable way for the
 * model to populate it correctly (gap #1). This block states plainly that
 * image_ref is the 0-based position of the image as attached to THIS
 * request, in attachment order.
 */
function build_image_scope_rule_text(int $imageCount, array $batchConcepts): string
{
    if ($imageCount <= 0) return '';
    $conceptTitles = implode('; ', array_map(fn($c) => $c['title'], $batchConcepts)) ?: '(no material — see above)';
    $imagePlural = $imageCount === 1 ? 'image is' : 'images are';
    $maxIndex = $imageCount - 1;
    return <<<IMGRULE

IMAGE SCOPE RULE (read carefully — the SAME uploaded {$imagePlural} being sent to every batch/request in this quiz, covering the whole course, not just yours):
- The uploaded image(s) may contain information about MULTIPLE different topics/sections across the course — not only the material assigned to you.
- You are authorized to use ONLY the parts of the image(s) that are relevant to YOUR assigned material for this request: {$conceptTitles}.
- If an image (or a region/part of an image) covers a DIFFERENT topic than your assigned material above, you MUST ignore that part entirely — do not reference it, do not use facts from it, do not let it influence a question or answer.
- If NONE of the image content is relevant to your assigned material, ignore the image completely and generate questions from the text material above only.
- A question may reference an image via image_ref ONLY when the specific diagram/figure is genuinely relevant to your assigned material — never for an out-of-scope diagram.

IMAGE_REF CONVENTION (how to report which image a question uses):
- The {$imageCount} uploaded image(s) are attached to this request in order: image index 0 is the FIRST attached image, index 1 is the SECOND, and so on, up to index {$maxIndex}.
- If a question genuinely depends on one specific uploaded image, set that question's "image_ref" to that image's 0-based index (an integer).
- If a question does not depend on any uploaded image, "image_ref" MUST be null. Most questions should be null — only set an index when the image is genuinely the basis of the question, never as a guess.
IMGRULE;
}

/**
 * Phase 2E — validates an AI-returned image_ref against the CURRENT
 * request's own uploaded-image count. There is no global/cross-session
 * image-id lookup anywhere in this file: the only images that can ever
 * exist for a given generation are the ones in that request's own
 * $imagesB64 array, so "a reference to another quiz/session's image" is
 * structurally impossible, not merely checked for — any integer outside
 * [0, $imageCount-1], or anything that isn't a clean integer, is treated as
 * "no image used" (null) rather than trusted, clamped, or guessed at.
 */
function sanitize_image_ref($imageRef, int $imageCount): ?int
{
    if (!is_int($imageRef)) return null;
    if ($imageRef < 0 || $imageRef >= $imageCount) return null;
    return $imageRef;
}

/**
 * Builds the system prompt for ONE batch. $batchConcepts is this batch's
 * OWN concept material only (looked up from the plan's concept_ids) — this
 * is the material-isolation boundary: nothing from another batch, another
 * topic, or the rest of the course ever appears here.
 *
 * $imageCount > 0 triggers the IMAGE SCOPE RULE block (Phase 2C follow-up
 * Part B, extended Phase 2E): the same uploaded image(s) are sent to every
 * batch, but each batch is explicitly told its own material scope and
 * instructed to use ONLY the portion of any image relevant to that scope,
 * ignoring the rest — this is a hard, explicit instruction, not an
 * assumption the model will infer scope correctly on its own.
 */
function build_batch_prompt(array $course, array $batch, array $batchConcepts, string $scopeDesc, float $markBudget, int $imageCount = 0): array
{
    $typeCounts = array_count_values(array_column($batch['questions'], 'type'));
    $typeLines = [];
    foreach (['mcq' => 'multiple-choice (MCQ)', 'short' => 'theory/short-answer', 'truefalse' => 'true/false'] as $t => $label) {
        if (!empty($typeCounts[$t])) $typeLines[] = "{$typeCounts[$t]} {$label} question(s)";
    }
    $typeRequirement = implode(', ', $typeLines);
    $questionCount = count($batch['questions']);

    $materialText = '';
    foreach ($batchConcepts as $c) {
        $materialText .= "- {$c['title']}: {$c['source_text']}\n";
    }
    if ($materialText === '') {
        $materialText = "(no indexed material was available for this batch's scope — base questions on general course knowledge for {$course['code']} at an appropriate level, staying strictly within the topic implied by the course)";
    }

    $outputFormat = quiz_batch_output_format();
    $imageScopeRule = build_image_scope_rule_text($imageCount, $batchConcepts);

    $system = <<<PROMPT
You are an expert university examiner for KWASU (Kwara State University) generating quiz questions for {$course['code']} — {$course['title']}.

ASSIGNED MATERIAL FOR THIS BATCH (use ONLY this material — do not substitute or introduce concepts from elsewhere in the course):
{$materialText}
{$imageScopeRule}

YOUR TASK:
Generate exactly {$questionCount} quiz questions covering {$scopeDesc}, using ONLY the assigned material above.

REQUIRED QUESTION TYPE COMPOSITION FOR THIS BATCH (must match exactly — {$typeRequirement}; internal order does not matter, but the counts must be exact):
{$typeRequirement}

MARKING MODEL (very important):
- This batch has a TOTAL of exactly {$markBudget} marks. Distribute these marks UNEVENLY across all {$questionCount} questions in THIS batch, weighted by each question's actual complexity and depth.
- DO NOT default to an equal or near-equal split — a simple MCQ/true-false should be noticeably lower than a multi-part theory question. All question "marks" values in this batch MUST sum to exactly {$markBudget}.
- For MCQ and True/False questions: format = "objective", rubric = null.
- For THEORY (short) questions, choose "list_explain" (a specific NUMBER of items to list+explain — expected_items, marks_per_list_item, marks_per_explain_item, item_key_points; (marks_per_list_item + marks_per_explain_item) * expected_items MUST equal the question's marks) or "general" (3-4 criteria objects with description + marks, summing to the question's marks).

RUBRIC CRITERIA MUST BE GROUNDED IN THE QUESTION AS WRITTEN:
- Every criteria description / item_key_points entry must be directly traceable to something the question text itself explicitly asks for. Do not invent additional implicit expectations the question never requested.

OTHER RULES:
1. Base questions STRICTLY on the assigned material above — do not invent facts, and do not draw on unassigned course material.
2. Match the style and difficulty of a university exam for this course.
3. For MCQs: always provide exactly 4 options (A, B, C, D) with only one correct answer.
4. Make distractors (wrong options) plausible but clearly wrong on reflection.
5. Double-check before responding: the sum of every question's "marks" value must equal exactly {$markBudget}.
6. Double-check every rubric's criteria/item_key_points against the question's own text.

{$outputFormat}
PROMPT;

    $user = "Generate {$questionCount} quiz question(s) now ({$typeRequirement}). Respond with ONLY the JSON object.";
    return [$system, $user];
}

// ═════════════════════════════════════════════════════════════════════════════
// 3. BATCH REQUEST CONSTRUCTION — one OpenAI Responses API request per
// batch. Reasoning effort/token budget rules preserved from ai_quiz.php,
// evaluated per-batch instead of per-quiz (a batch containing ANY theory
// question uses 'medium', a purely mcq/truefalse batch uses 'low' — same
// underlying rule, "only theory questions actually need that depth", just
// applied at the new, smaller granularity).
// ═════════════════════════════════════════════════════════════════════════════

function build_batch_http_request(array $course, array $batch, array $batchConcepts, string $scopeDesc, array $imagesB64 = []): array
{
    [$system, $user] = build_batch_prompt($course, $batch, $batchConcepts, $scopeDesc, $batch['mark_budget'], count($imagesB64));
    $questionCount = count($batch['questions']);
    $hasTheory = in_array('short', array_column($batch['questions'], 'type'), true);
    $effort = $hasTheory ? 'medium' : 'low';

    $tokenBudget = 8000; // same flat base ai_quiz.php uses — preserved, not re-measured for this smaller-request case (errs toward "clearly enough" per its own documented bias)
    foreach ($batch['questions'] as $q) {
        $tokenBudget += QUIZ_EXEC_PER_QUESTION_TOKENS[$q['type']] ?? 900;
    }
    $timeout = min(180, 60 + ($questionCount * 6)); // identical formula to ai_quiz.php's $genTimeoutSecs

    // Same image content parts attached to EVERY batch's request (Phase 2C
    // follow-up Part B) — scope isolation for images is enforced entirely
    // by the IMAGE SCOPE RULE prompt text above, not by withholding the
    // image itself, per the explicit requirement.
    $content = [['type' => 'input_text', 'text' => $user]];
    foreach (quiz_batch_image_content_parts($imagesB64) as $part) {
        $content[] = $part;
    }

    $payload = [
        'model'             => OPENAI_MODEL,
        'input'             => [['role' => 'user', 'content' => $content]],
        'instructions'      => $system,
        'max_output_tokens' => $tokenBudget,
        'reasoning'         => ['effort' => $effort],
        'text'              => ['format' => ['type' => 'json_schema', 'name' => 'quiz_questions', 'strict' => true, 'schema' => quiz_batch_json_schema($questionCount)]],
    ];

    return [
        'url'     => 'https://api.openai.com/v1/responses',
        'headers' => ['Content-Type: application/json', 'Authorization: Bearer ' . OPENAI_API_KEY],
        'body'    => json_encode($payload),
        'timeout' => $timeout,
    ];
}

/** Extracts the model's visible text from a raw OpenAI Responses API HTTP response, or throws. */
function parse_openai_response_text(int $httpCode, ?string $body, ?string $curlError): string
{
    if ($curlError) {
        throw new RuntimeException('[transient] Connection failed: ' . $curlError);
    }
    $data = json_decode((string) $body, true);
    if ($httpCode !== 200) {
        throw new RuntimeException('[transient] OpenAI API error (HTTP ' . $httpCode . '): ' . ($data['error']['message'] ?? 'unknown'));
    }
    foreach ($data['output'] ?? [] as $item) {
        if (($item['type'] ?? '') !== 'message') continue;
        foreach ($item['content'] ?? [] as $part) {
            if (($part['type'] ?? '') === 'output_text') return $part['text'];
            if (($part['type'] ?? '') === 'refusal') throw new RuntimeException('[malformed] Model refused: ' . ($part['refusal'] ?? ''));
        }
    }
    throw new RuntimeException('[transient] No text found in OpenAI response (possible reasoning-token exhaustion).');
}

function parse_openai_response_usage(?string $body): array
{
    $data = json_decode((string) $body, true);
    $usage = $data['usage'] ?? [];
    return [
        'input_tokens'     => $usage['input_tokens'] ?? null,
        'output_tokens'    => $usage['output_tokens'] ?? null,
        'reasoning_tokens' => $usage['output_tokens_details']['reasoning_tokens'] ?? null,
    ];
}

// ═════════════════════════════════════════════════════════════════════════════
// 4. RESPONSE -> PLAN-SLOT MAPPING — the AI never returns question numbers
// or batch ids; PHP retains that mapping independently (per the explicit
// instruction). Matches returned questions to planned slots by TYPE only,
// first-match, in the plan's own slot order — tolerates the AI returning
// its questions in any internal order, silently discards any genuine
// surplus, and reports a per-type shortfall for anything unmatched.
// ═════════════════════════════════════════════════════════════════════════════

function map_batch_response_to_slots(array $batch, array $returnedQuestions): array
{
    $slots = $batch['questions']; // [['question_no','type','concept_ids'], ...] in plan order
    $pool = $returnedQuestions;   // consumed as matches are found
    $mapped = []; // question_no => question data
    $shortfallByType = [];

    foreach ($slots as $slot) {
        $foundIdx = null;
        foreach ($pool as $idx => $rq) {
            if (($rq['type'] ?? null) === $slot['type']) { $foundIdx = $idx; break; }
        }
        if ($foundIdx === null) {
            $shortfallByType[$slot['type']] = ($shortfallByType[$slot['type']] ?? 0) + 1;
            continue;
        }
        $mapped[$slot['question_no']] = $pool[$foundIdx];
        unset($pool[$foundIdx]);
    }

    return ['mapped' => $mapped, 'shortfall_by_type' => $shortfallByType];
}

// ═════════════════════════════════════════════════════════════════════════════
// 5. MARK NORMALIZATION (per batch, to its own budget) — same defensive
// pattern ai_quiz.php already uses (re-derive "marks" from a rubric when
// the sibling field disagrees, then proportionally rescale to the exact
// target), evaluated against the BATCH's mark_budget instead of always
// 100. A genuinely degenerate response (raw marks summing to <= 0 — i.e.
// nothing meaningful to rescale) is treated as a rejectable batch failure
// rather than silently accepted, per the explicit "reject, don't silently
// alter the score" instruction; a normal response (positive but imprecise
// raw marks) is rescaled to the batch budget exactly, exactly as the
// existing app already does for the whole quiz — that rescaling is the
// established, already-relied-upon safety net, not a new workaround.
// ═════════════════════════════════════════════════════════════════════════════

function normalize_batch_marks(array $questions, float $markBudget): array
{
    foreach ($questions as &$q) {
        $rubric = $q['rubric'] ?? null;
        if (!is_array($rubric)) continue;
        if (isset($rubric['item_key_points'])) {
            $derived = ((float) ($rubric['marks_per_list_item'] ?? 0) + (float) ($rubric['marks_per_explain_item'] ?? 0)) * (int) ($rubric['expected_items'] ?? 0);
        } elseif (isset($rubric['criteria']) && is_array($rubric['criteria'])) {
            $derived = array_sum(array_column($rubric['criteria'], 'marks'));
        } else {
            continue;
        }
        if ($derived > 0 && abs($derived - (float) ($q['marks'] ?? 0)) > 0.01) {
            $q['marks'] = $derived;
        }
    }
    unset($q);

    $rawSum = array_sum(array_map(fn($q) => (float) ($q['marks'] ?? 0), $questions));
    if ($rawSum <= 0) {
        return ['ok' => false, 'questions' => $questions]; // degenerate — caller rejects the batch
    }

    foreach ($questions as &$q) {
        $q['marks'] = round(((float) ($q['marks'] ?? 0) / $rawSum) * $markBudget, 2);
    }
    unset($q);
    $sumCheck = array_sum(array_column($questions, 'marks'));
    $diff = round($markBudget - $sumCheck, 2);
    if (abs($diff) >= 0.01 && $questions) {
        $lastKey = array_key_last($questions);
        $questions[$lastKey]['marks'] = round($questions[$lastKey]['marks'] + $diff, 2);
    }
    return ['ok' => true, 'questions' => $questions];
}

// ═════════════════════════════════════════════════════════════════════════════
// 6. ONE BATCH ROUND — builds+runs (via the injected $httpRunner) requests
// for a set of batches, maps + validates + normalizes each response.
// Separated from the retry loop below so both the initial round and every
// retry round call the exact same logic.
// ═════════════════════════════════════════════════════════════════════════════

function run_batch_round(array $batchesToRun, array $course, array $conceptsById, string $scopeDesc, callable $httpRunner, array $imagesB64 = []): array
{
    // Instrumentation (Phase 2C follow-up, Part A) — request CONSTRUCTION
    // (prompt/schema/payload building, pure PHP, no I/O) timed separately
    // from the actual HTTP round below, so a slow run can be attributed to
    // the right stage rather than assumed to be "the AI is slow".
    $tBuildStart = microtime(true);
    $requests = [];
    foreach ($batchesToRun as $batch) {
        $batchConcepts = [];
        foreach ($batch['questions'] as $q) {
            foreach ($q['concept_ids'] as $cid) {
                if (isset($conceptsById[$cid])) $batchConcepts[$cid] = $conceptsById[$cid];
            }
        }
        $requests[$batch['batch_id']] = build_batch_http_request($course, $batch, array_values($batchConcepts), $scopeDesc, $imagesB64);
    }
    $buildMs = (int) round((microtime(true) - $tBuildStart) * 1000);

    $tStart = microtime(true);
    $responses = $httpRunner($requests);
    $elapsedMs = (int) round((microtime(true) - $tStart) * 1000);

    $outcomes = [];
    foreach ($batchesToRun as $batch) {
        $batchId = $batch['batch_id'];
        $resp = $responses[$batchId] ?? ['http_code' => 0, 'body' => null, 'error' => 'no response received'];
        // Per-request timing (present when $httpRunner is the real
        // run_concurrent_http_requests(); absent/null for a fake test
        // runner that doesn't populate it — never required downstream).
        $timing = [
            'round_dispatched_at' => $tStart,
            'build_ms'            => $buildMs,
            'dispatched_at'       => $resp['dispatched_at'] ?? null,
            'completed_at'        => $resp['completed_at'] ?? null,
            'duration_ms'         => $resp['duration_ms'] ?? null,
            'queue_wait_ms'       => isset($resp['dispatched_at']) ? (int) round(($resp['dispatched_at'] - $tStart) * 1000) : null,
        ];
        try {
            $text = parse_openai_response_text($resp['http_code'], $resp['body'], $resp['error']);
            $usage = parse_openai_response_usage($resp['body']);
        } catch (\Throwable $e) {
            $outcomes[$batchId] = ['status' => 'failed', 'reason' => $e->getMessage(), 'elapsed_ms' => $elapsedMs, 'timing' => $timing];
            continue;
        }

        $parsed = json_decode($text, true);
        if (!is_array($parsed) || !isset($parsed['questions']) || !is_array($parsed['questions'])) {
            $outcomes[$batchId] = ['status' => 'failed', 'reason' => '[malformed] could not parse questions array', 'elapsed_ms' => $elapsedMs, 'usage' => $usage, 'timing' => $timing];
            continue;
        }

        // strip null-valued option keys (Structured Outputs strict-mode artifact — same fix as ai_quiz.php)
        foreach ($parsed['questions'] as &$q) {
            if (isset($q['options']) && is_array($q['options'])) {
                $q['options'] = array_filter($q['options'], fn($v) => $v !== null);
            }
        }
        unset($q);

        $mapResult = map_batch_response_to_slots($batch, $parsed['questions']);
        $outcomes[$batchId] = [
            'status'            => 'mapped',
            'mapped'            => $mapResult['mapped'],
            'shortfall_by_type' => $mapResult['shortfall_by_type'],
            'usage'             => $usage,
            'elapsed_ms'        => $elapsedMs,
            'timing'            => $timing,
        ];
    }
    return $outcomes;
}

// ═════════════════════════════════════════════════════════════════════════════
// 7. EXECUTOR ENTRY POINT
// ═════════════════════════════════════════════════════════════════════════════

/**
 * $httpRunner is injectable — defaults to the real run_concurrent_http_requests()
 * against OpenAI. Tests inject a fake closure that simulates normal/
 * delayed/out-of-order/malformed/transient/short/wrong-type/wrong-marks
 * responses without any network call, OR the concurrency-proof test passes
 * the REAL run_concurrent_http_requests() pointed at a local fake-delay
 * server instead of OpenAI (same code path, not a mock of it).
 */
function execute_quiz_plan(array $rawRequest, array $user, ?callable $httpRunner = null): array
{
    $httpRunner = $httpRunner ?? 'run_concurrent_http_requests';
    $tGenerationStart = microtime(true); // Phase 2C follow-up Part A instrumentation

    $plan = plan_quiz($rawRequest, $user); // Phase 2A, unmodified — re-validates authorization/scope itself
    if (isset($plan['error'])) {
        return ['error' => $plan['error']];
    }
    [$planOk, $planErrors] = validate_quiz_plan($plan, array_column(
        quiz_plan_available_concepts($plan['quiz_request']['course_id'], $plan['quiz_request']['topic_id'], $plan['quiz_request']['scope']),
        'concept_id'
    ));
    if (!$planOk) {
        return ['error' => 'plan_invalid', 'details' => $planErrors]; // defensive re-check — should never actually fire, plan_quiz() already self-validates
    }

    $courseStmt = db()->prepare('SELECT * FROM courses WHERE id = ?');
    $courseStmt->execute([$plan['quiz_request']['course_id']]);
    $course = $courseStmt->fetch();

    $scopeDesc = $plan['quiz_request']['scope'] === 'topic' ? "the assigned week's material" : "the course's assigned material";

    $availableConcepts = quiz_plan_available_concepts($plan['quiz_request']['course_id'], $plan['quiz_request']['topic_id'], $plan['quiz_request']['scope']);
    $conceptsById = [];
    foreach ($availableConcepts as $c) $conceptsById[$c['concept_id']] = $c;

    // Phase 2C follow-up Part B — images are a GENERATION-time concern only
    // (the Phase 2A plan itself carries no image metadata, deliberately not
    // redesigned this phase); read directly off the original raw request,
    // same cap ai_quiz.php has always applied. The SAME set is handed to
    // every batch below — see build_batch_prompt()'s IMAGE SCOPE RULE for
    // how per-batch scope isolation is actually enforced.
    $imagesB64 = is_array($rawRequest['images_b64'] ?? null) ? array_slice($rawRequest['images_b64'], 0, 5) : [];

    // ── Round 1: every batch, concurrently ──────────────────────────────────
    $pendingBatches = $plan['batches'];
    $finalized = []; // batch_id => ordered [question_no => question]
    $usage = [];     // batch_id => ['initial'=>..., 'topup'=>?..., 'retries'=>n]
    $attempts = array_fill_keys(array_column($plan['batches'], 'batch_id'), 0);

    while ($pendingBatches) {
        $outcomes = run_batch_round($pendingBatches, $course, $conceptsById, $scopeDesc, $httpRunner, $imagesB64);
        $stillPending = [];

        foreach ($pendingBatches as $batch) {
            $batchId = $batch['batch_id'];
            $outcome = $outcomes[$batchId];
            $usage[$batchId]['initial'] = $usage[$batchId]['initial'] ?? $outcome;

            if ($outcome['status'] !== 'mapped') {
                $attempts[$batchId]++;
                if ($attempts[$batchId] < QUIZ_EXEC_MAX_BATCH_RETRIES) {
                    $stillPending[] = $batch; // retried next round — successful sibling batches are NOT re-run
                    continue;
                }
                return ['error' => 'batch_failed', 'batch_id' => $batchId, 'reason' => $outcome['reason'] ?? 'unknown', 'usage' => $usage];
            }

            $mapped = $outcome['mapped'];
            $shortfall = $outcome['shortfall_by_type'];

            if ($shortfall) {
                // Targeted top-up — belongs strictly to THIS batch's question
                // range, never the whole quiz (per explicit instruction).
                $topUpResult = run_batch_topup($batch, $shortfall, $course, $conceptsById, $scopeDesc, $httpRunner, $imagesB64, array_keys($mapped));
                $usage[$batchId]['topup'] = $topUpResult['usage'] ?? null;
                $mapped = $mapped + $topUpResult['mapped']; // union — top-up only fills the gaps, never overwrites an already-mapped slot
            }

            if (count($mapped) !== $batch['question_count']) {
                $attempts[$batchId]++;
                if ($attempts[$batchId] < QUIZ_EXEC_MAX_BATCH_RETRIES) {
                    $stillPending[] = $batch;
                    continue;
                }
                return ['error' => 'batch_incomplete_after_retries', 'batch_id' => $batchId, 'usage' => $usage];
            }

            ksort($mapped);
            $normalized = normalize_batch_marks(array_values($mapped), $batch['mark_budget']);
            if (!$normalized['ok']) {
                $attempts[$batchId]++;
                if ($attempts[$batchId] < QUIZ_EXEC_MAX_BATCH_RETRIES) {
                    $stillPending[] = $batch;
                    continue;
                }
                return ['error' => 'batch_marks_degenerate', 'batch_id' => $batchId, 'usage' => $usage];
            }
            $markSum = round(array_sum(array_column($normalized['questions'], 'marks')), 2);
            if (abs($markSum - $batch['mark_budget']) > 0.01) {
                return ['error' => 'batch_mark_budget_violation', 'batch_id' => $batchId, 'usage' => $usage]; // should be unreachable given normalize_batch_marks()'s own rescale — defensive
            }

            // Phase 2E — sanitize every question's image_ref against THIS
            // request's own uploaded-image count, at the single chokepoint
            // every path (initial batch, full-batch retry, and top-up-
            // merged questions) funnels through before persistence/
            // response. The AI's raw output is never trusted directly —
            // see sanitize_image_ref()'s docblock for why this is safe by
            // construction, not just by convention.
            $imageCount = count($imagesB64);
            foreach ($normalized['questions'] as &$sanitizedQ) {
                $sanitizedQ['image_ref'] = sanitize_image_ref($sanitizedQ['image_ref'] ?? null, $imageCount);
            }
            unset($sanitizedQ);

            $questionNos = array_keys($mapped);
            $finalized[$batchId] = array_combine($questionNos, $normalized['questions']);
        }

        $pendingBatches = $stillPending;
    }

    // ── Final assembly — only reached once every batch is finalized ────────
    $tAssemblyStart = microtime(true);
    $allQuestions = [];
    foreach ($plan['batches'] as $batch) { // iterate in PLAN order, never response arrival order
        foreach ($finalized[$batch['batch_id']] as $qNo => $q) {
            $allQuestions[$qNo] = $q;
        }
    }
    ksort($allQuestions);

    $expectedNos = range(1, $plan['question_count']);
    if (array_keys($allQuestions) !== $expectedNos) {
        return ['error' => 'final_assembly_gap', 'expected' => $expectedNos, 'got' => array_keys($allQuestions)];
    }
    $finalTypeCounts = array_count_values(array_column($allQuestions, 'type'));
    if ($plan['mode'] === 'hybrid') {
        $expectedTypeCounts = plan_hybrid_counts($plan['question_count']);
        foreach (['mcq', 'short', 'truefalse'] as $t) {
            if (($finalTypeCounts[$t] ?? 0) !== ($expectedTypeCounts[$t] ?? 0)) {
                return ['error' => 'final_type_distribution_mismatch'];
            }
        }
    }
    $finalMarkSum = round(array_sum(array_column($allQuestions, 'marks')), 2);
    if (abs($finalMarkSum - $plan['total_marks']) > 0.02) {
        return ['error' => 'final_mark_total_mismatch', 'sum' => $finalMarkSum, 'expected' => $plan['total_marks']];
    }

    // ── Persist — same tables/shape ai_quiz.php already uses, so the
    // existing frontend/marking code needs zero changes to read this. ──────
    $sessStmt = db()->prepare("INSERT INTO quiz_sessions (user_id, course_id, topic_id, scope, status, total, total_marks) VALUES (?,?,?,?,'active',?,?)");
    $sessStmt->execute([$user['id'], $plan['quiz_request']['course_id'], $plan['quiz_request']['topic_id'], $plan['quiz_request']['scope'], count($allQuestions), $plan['total_marks']]);
    $sessionId = (int) db()->lastInsertId();

    $qStmt = db()->prepare('INSERT INTO quiz_questions (session_id, question_no, type, marks, format, question, options, rubric, image_ref, correct, explanation) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($allQuestions as $qNo => $q) {
        $qStmt->execute([
            $sessionId, $qNo, $q['type'] ?? 'short', $q['marks'] ?? 0, $q['format'] ?? 'general', $q['question'] ?? '',
            isset($q['options']) ? json_encode($q['options']) : null,
            isset($q['rubric']) && $q['rubric'] ? json_encode($q['rubric']) : null,
            $q['image_ref'] ?? null, $q['correct'] ?? '', $q['explanation'] ?? '',
        ]);
    }

    // Concept usage recorded ONLY now — a successfully realized, persisted
    // quiz — never for a batch that failed/was rejected, and never merely
    // because a plan was produced (Phase 2A's own function, unmodified).
    record_quiz_concept_usage((int) $user['id'], $plan['quiz_request']['course_id'], $plan);

    $tEnd = microtime(true);
    return [
        'session_id'  => $sessionId,
        'questions'   => array_values($allQuestions),
        'total'       => count($allQuestions),
        'total_marks' => $plan['total_marks'],
        'batch_count' => count($plan['batches']),
        'usage'       => $usage,
        // Additive, internal-only (stripped at the ai_quiz.php endpoint
        // boundary — never reaches the client) — Phase 2C follow-up Part A.
        'timeline' => [
            'generation_start_at' => $tGenerationStart,
            'assembly_start_at'   => $tAssemblyStart,
            'assembly_ms'         => (int) round(($tEnd - $tAssemblyStart) * 1000),
            'end_at'              => $tEnd,
            'total_wall_ms'       => (int) round(($tEnd - $tGenerationStart) * 1000),
        ],
    ];
}

/**
 * Targeted top-up for exactly the shortfall types of ONE batch — never the
 * whole quiz. Phase 2E: now accepts the SAME $imagesB64 set the original
 * batch request carried — previously this request silently dropped images
 * entirely (gap #2), producing an inconsistent "original had images,
 * top-up didn't" split for the same batch. A regenerated question is free
 * to pick a DIFFERENT relevant image (or none) than whatever the original,
 * incomplete response chose — nothing here pins or reuses a prior
 * image_ref, the model simply sees the same image set + the same
 * image_ref convention and decides fresh, exactly like the original batch.
 */
function run_batch_topup(array $batch, array $shortfallByType, array $course, array $conceptsById, string $scopeDesc, callable $httpRunner, array $imagesB64 = [], array $alreadyMappedNos = []): array
{
    $batchConcepts = [];
    foreach ($batch['questions'] as $q) {
        foreach ($q['concept_ids'] as $cid) {
            if (isset($conceptsById[$cid])) $batchConcepts[$cid] = $conceptsById[$cid];
        }
    }
    $shortfallCount = array_sum($shortfallByType);
    $typeLines = [];
    foreach ($shortfallByType as $t => $n) {
        $label = ['mcq' => 'multiple-choice (MCQ)', 'short' => 'theory/short-answer', 'truefalse' => 'true/false'][$t] ?? $t;
        $typeLines[] = "{$n} {$label}";
    }
    $requirement = implode(', ', $typeLines);

    $materialText = '';
    foreach ($batchConcepts as $c) $materialText .= "- {$c['title']}: {$c['source_text']}\n";

    $imageScopeRule = build_image_scope_rule_text(count($imagesB64), $batchConcepts);

    $system = "You are continuing a batch of quiz questions for {$course['code']} — {$course['title']}, covering {$scopeDesc}. "
        . "Generate EXACTLY {$shortfallCount} more question(s): {$requirement}. Use ONLY this material:\n{$materialText}\n"
        . "{$imageScopeRule}\n"
        . quiz_batch_output_format();
    $user = "Generate {$shortfallCount} quiz question(s) now ({$requirement}). Respond with ONLY the JSON object.";

    // Same image content parts as the original batch request (Phase 2E) —
    // built via the one shared quiz_batch_image_content_parts() helper, not
    // a second implementation.
    $content = [['type' => 'input_text', 'text' => $user]];
    foreach (quiz_batch_image_content_parts($imagesB64) as $part) {
        $content[] = $part;
    }

    $payload = [
        'model' => OPENAI_MODEL,
        'input' => [['role' => 'user', 'content' => $content]],
        'instructions' => $system,
        'max_output_tokens' => 4000 + ($shortfallCount * 1300),
        'reasoning' => ['effort' => in_array('short', array_keys($shortfallByType), true) ? 'medium' : 'low'],
        'text' => ['format' => ['type' => 'json_schema', 'name' => 'quiz_questions', 'strict' => true, 'schema' => quiz_batch_json_schema($shortfallCount)]],
    ];
    $requests = [$batch['batch_id'] . '_topup' => [
        'url' => 'https://api.openai.com/v1/responses',
        'headers' => ['Content-Type: application/json', 'Authorization: Bearer ' . OPENAI_API_KEY],
        'body' => json_encode($payload),
        'timeout' => min(150, 60 + ($shortfallCount * 8)),
    ]];

    $responses = $httpRunner($requests);
    $resp = $responses[$batch['batch_id'] . '_topup'] ?? ['http_code' => 0, 'body' => null, 'error' => 'no response'];

    try {
        $text = parse_openai_response_text($resp['http_code'], $resp['body'], $resp['error']);
        $usage = parse_openai_response_usage($resp['body']);
        $parsed = json_decode($text, true);
        $returned = is_array($parsed['questions'] ?? null) ? $parsed['questions'] : [];
    } catch (\Throwable $e) {
        return ['mapped' => [], 'usage' => null];
    }

    // Map top-up results to the STILL-missing slots only (never touches an
    // already-filled slot). Phase 3A fix: matching by TYPE alone is
    // ambiguous when a batch has 2+ slots of the same shortfall type —
    // $alreadyMappedNos (the round-1 mapped question_nos) excludes any slot
    // that's already filled, so only the genuinely missing slot(s) of that
    // type are ever considered "missing" here, never a sibling slot that
    // already succeeded.
    $missingSlots = [];
    foreach ($batch['questions'] as $slot) {
        if (in_array($slot['question_no'], $alreadyMappedNos, true)) continue;
        if (($shortfallByType[$slot['type']] ?? 0) > 0) {
            $missingSlots[] = $slot;
        }
    }
    $mapResult = map_batch_response_to_slots(['questions' => $missingSlots], $returned);
    return ['mapped' => $mapResult['mapped'], 'usage' => $usage];
}
