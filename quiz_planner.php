<?php

/**
 * quiz_planner.php — Phase 2A: deterministic quiz batch planner.
 *
 * PURE PLANNING LAYER. Nothing in this file makes a network/AI call — it
 * only reads the existing course/enrollment/Phase-1-index data already in
 * the database and produces a structured, immutable generation plan for a
 * later phase (Phase 2B) to execute. PHP decides batch count, question
 * numbers, types, concept allocation, and mark budgets; the AI is never
 * asked to plan anything here or later — Phase 2B will just fill in the
 * PHP-decided slots.
 *
 * Reuses, unmodified: enrolled_or_staff_access() (course authorization),
 * current_document_index() (Phase 1 semantic index), the exact request
 * param names/clamps already used by ai_quiz.php (course_id, topic_id,
 * scope, count, question_type) and the exact per-question `type` enum
 * already used by quiz_questions.type ('mcq'/'short'/'truefalse' — NOT
 * 'theory'; 'theory' is a request-level MODE name, 'short' is what a theory
 * question is actually stored as, confirmed via quiz_questions' real schema).
 *
 * Does NOT call OpenAI/Anthropic, does NOT touch ai_quiz.php, does NOT
 * change current generation behavior, does NOT touch marking/submission,
 * does NOT touch course registration/borrowed-carryover/department access
 * (only READS the existing enrolled_or_staff_access() gate).
 */
require_once __DIR__ . '/config.php';

const QUIZ_PLAN_THEORY_BATCH_MAX = 3;     // "Theory is the expensive workload" — never more than 3 theory questions per AI batch
const QUIZ_PLAN_HYBRID_RATIOS    = ['mcq' => 0.40, 'short' => 0.40, 'truefalse' => 0.20];
const QUIZ_PLAN_TYPE_PRIORITY    = ['mcq', 'short', 'truefalse']; // fixed, arbitrary-but-stable tie-break order used everywhere determinism needs one

// ═════════════════════════════════════════════════════════════════════════════
// 1. REQUEST NORMALIZATION — mirrors ai_quiz.php's own existing clamps
// exactly (same min/max count, same scope logic, same default mode), so
// this is not a competing request model, just the same one made reusable.
// ═════════════════════════════════════════════════════════════════════════════

function normalize_quiz_plan_request(array $raw): array
{
    $courseId     = (int) ($raw['course_id'] ?? 0);
    $topicId      = isset($raw['topic_id']) && $raw['topic_id'] ? (int) $raw['topic_id'] : null;
    $scope        = ($raw['scope'] ?? '') === 'topic' && $topicId ? 'topic' : 'course';
    $count        = max(3, min(20, (int) ($raw['count'] ?? 10)));
    $questionType = in_array($raw['question_type'] ?? '', ['objective', 'theory', 'truefalse', 'hybrid'], true)
        ? $raw['question_type'] : 'hybrid';
    $totalMarks   = isset($raw['total_marks']) && (float) $raw['total_marks'] > 0
        ? (float) $raw['total_marks'] : 100.00; // 100 is the current system's only real value (hardcoded in ai_quiz.php's INSERT) — kept as the default, not invented as newly "configurable"

    return [
        'course_id'     => $courseId,
        'topic_id'      => $topicId,
        'scope'         => $scope,
        'count'         => $count,
        'question_type' => $questionType,
        'total_marks'   => $totalMarks,
    ];
}

// ═════════════════════════════════════════════════════════════════════════════
// 2. AUTHORIZATION + MATERIAL SCOPE SELECTION — reuses
// enrolled_or_staff_access() unmodified (config.php). Only fetches topics
// actually inside the requested course/scope (never "every course in the
// database"), and only INDEXED (Phase 1 completed) material within that
// scope — never invents/guesses content for un-indexed documents.
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Flat list of allocatable "concept units" for a course/topic scope — one
 * entry per concept AND per sub-concept (a sub-concept is just as
 * allocatable as its parent), each with a globally-unique concept_id built
 * from its real document/section/topic/concept ids (Phase 1's ids are only
 * unique WITHIN one document's index, not across documents). Order is
 * stable (document id, then the index's own section/topic/concept order)
 * — this stability is what determinism (same inputs -> same plan) relies on.
 *
 * Only documents with a COMPLETED current index (current_document_index(),
 * Phase 1B/1C/1D — unmodified) contribute concepts; a document still
 * pending/failed/never-indexed simply contributes none, rather than being
 * skipped as an error — Phase 2A must still be able to produce a
 * structurally valid plan (with fewer/no concept_ids per question) even
 * when Phase 1 hasn't finished indexing everything yet.
 */
function quiz_plan_available_concepts(int $courseId, ?int $topicId, string $scope): array
{
    $topicWhere  = $scope === 'topic' ? 'WHERE course_id = ? AND id = ?' : 'WHERE course_id = ?';
    $topicParams = $scope === 'topic' ? [$courseId, $topicId] : [$courseId];
    $topicsStmt  = db()->prepare("SELECT id, week_number, title FROM topics {$topicWhere} ORDER BY week_number, id");
    $topicsStmt->execute($topicParams);
    $topics = $topicsStmt->fetchAll();

    $concepts = [];
    foreach ($topics as $topic) {
        $docStmt = db()->prepare('SELECT id, title FROM documents WHERE topic_id = ? ORDER BY id');
        $docStmt->execute([$topic['id']]);
        foreach ($docStmt->fetchAll() as $doc) {
            $current = current_document_index((int) $doc['id']);
            if (!$current || !is_array($current['index_json'])) {
                continue; // not indexed / not completed yet — contributes no concepts, not an error
            }
            foreach ($current['index_json']['sections'] ?? [] as $section) {
                foreach ($section['topics'] ?? [] as $idxTopic) {
                    foreach ($idxTopic['concepts'] ?? [] as $concept) {
                        $concepts[] = [
                            'concept_id'   => "{$doc['id']}:{$section['id']}:{$idxTopic['id']}:{$concept['id']}",
                            'title'        => $concept['title'],
                            'source_text'  => $concept['source_text'],
                            'document_id'  => (int) $doc['id'],
                            'topic_id'     => (int) $topic['id'],
                        ];
                        foreach ($concept['sub_concepts'] ?? [] as $sub) {
                            $concepts[] = [
                                'concept_id'  => "{$doc['id']}:{$section['id']}:{$idxTopic['id']}:{$concept['id']}:{$sub['id']}",
                                'title'       => $sub['title'],
                                'source_text' => $sub['source_text'],
                                'document_id' => (int) $doc['id'],
                                'topic_id'    => (int) $topic['id'],
                            ];
                        }
                    }
                }
            }
        }
    }
    return $concepts;
}

// ═════════════════════════════════════════════════════════════════════════════
// 3. CONCEPT ROTATION HISTORY — minimal persistence, per explicit
// instruction not to build an unnecessary history system. One row per
// (user, course, concept): what type this exact concept was LAST asked as
// for THIS student in THIS course, so a later plan can prefer a different
// type. Phase 2A only READS this (via quiz_concept_history_for()) when
// planning; WRITING it (record_quiz_concept_usage()) happens once a plan is
// actually realized as a real quiz — Phase 2A never calls its own write
// path automatically, since planning must stay a pure, side-effect-free
// function of (request, existing history, existing index). Phase 2B is
// expected to call the write path after real generation.
// ═════════════════════════════════════════════════════════════════════════════

function ensure_quiz_concept_history_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS quiz_concept_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            course_id INT NOT NULL,
            concept_id VARCHAR(255) NOT NULL,
            last_type ENUM('mcq','short','truefalse') NOT NULL,
            use_count INT NOT NULL DEFAULT 1,
            last_used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_course_concept (user_id, course_id, concept_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) { /* already exists */
    }
}

/** concept_id => last_type, for this user/course only. Missing entries simply mean "never used" — not a special value. */
function quiz_concept_history_for(int $userId, int $courseId): array
{
    ensure_quiz_concept_history_schema();
    $stmt = db()->prepare('SELECT concept_id, last_type FROM quiz_concept_history WHERE user_id = ? AND course_id = ?');
    $stmt->execute([$userId, $courseId]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[$row['concept_id']] = $row['last_type'];
    }
    return $map;
}

/**
 * Records what type each concept in a REALIZED plan was actually used as —
 * intended to be called by Phase 2B after real generation, not by Phase 2A
 * itself (see docblock above). Included and tested now because the
 * rotation tests need a way to seed realistic prior-history fixtures.
 */
function record_quiz_concept_usage(int $userId, int $courseId, array $plan): void
{
    ensure_quiz_concept_history_schema();
    $stmt = db()->prepare('
        INSERT INTO quiz_concept_history (user_id, course_id, concept_id, last_type, use_count)
        VALUES (?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE last_type = VALUES(last_type), use_count = use_count + 1, last_used_at = CURRENT_TIMESTAMP
    ');
    foreach ($plan['batches'] as $batch) {
        foreach ($batch['questions'] as $q) {
            foreach ($q['concept_ids'] as $conceptId) {
                $stmt->execute([$userId, $courseId, $conceptId, $q['type']]);
            }
        }
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// 4. GENERIC DETERMINISTIC APPORTIONMENT — the one algorithm behind both
// the hybrid MCQ/theory/T-F count split AND the batch mark-budget split.
// Largest-remainder (Hare-Niemeyer) apportionment: floor each weighted
// share, then hand out the leftover units one at a time to whichever
// share(s) had the largest fractional remainder, breaking ties by a FIXED
// key order (never randomness) so the same inputs always produce the same
// output.
// ═════════════════════════════════════════════════════════════════════════════

function apportion(array $weights, int $total, array $tieBreakOrder): array
{
    $sumWeights = array_sum($weights);
    $shares = [];
    $floors = [];
    $remainders = [];
    foreach ($weights as $key => $w) {
        $share = $sumWeights > 0 ? ($w / $sumWeights) * $total : 0;
        $floors[$key] = (int) floor($share);
        $remainders[$key] = $share - $floors[$key];
    }
    $allocated = array_sum($floors);
    $remaining = $total - $allocated;

    // Sort keys by remainder desc; ties broken by $tieBreakOrder's position
    // (earlier in the list = wins the tie) — never by insertion order alone,
    // which PHP's sort would otherwise make incidental/fragile.
    $keys = array_keys($weights);
    usort($keys, function ($a, $b) use ($remainders, $tieBreakOrder) {
        if ($remainders[$a] !== $remainders[$b]) {
            return $remainders[$b] <=> $remainders[$a]; // larger remainder first
        }
        return array_search($a, $tieBreakOrder) <=> array_search($b, $tieBreakOrder);
    });

    $result = $floors;
    for ($i = 0; $i < $remaining; $i++) {
        $result[$keys[$i % count($keys)]]++;
    }
    return $result;
}

// ═════════════════════════════════════════════════════════════════════════════
// 5. PURE-MODE / THEORY BATCHING
// ═════════════════════════════════════════════════════════════════════════════

/** Objective-only or true/false-only: always exactly one batch, uncapped. Never split "for consistency". */
function plan_uniform_batch_sizes(int $count): array
{
    return [$count];
}

/** Theory: batches of at most QUIZ_PLAN_THEORY_BATCH_MAX, sequential, deterministic — matches every example in the brief exactly. */
function plan_theory_batch_sizes(int $count): array
{
    $sizes = [];
    $remaining = $count;
    while ($remaining > 0) {
        $take = min(QUIZ_PLAN_THEORY_BATCH_MAX, $remaining);
        $sizes[] = $take;
        $remaining -= $take;
    }
    return $sizes;
}

// ═════════════════════════════════════════════════════════════════════════════
// 6. HYBRID TYPE DISTRIBUTION + ORDERING
// ═════════════════════════════════════════════════════════════════════════════

/** 40/40/20 MCQ/short/T-F integer split via apportion() above. */
function plan_hybrid_counts(int $count): array
{
    return apportion(QUIZ_PLAN_HYBRID_RATIOS, $count, QUIZ_PLAN_TYPE_PRIORITY);
}

/**
 * Deterministic, evenly-spread interleaving of the three type counts into
 * one ordered sequence (a weighted round-robin / "Bresenham-style"
 * scheduler: each type accumulates "credit" every step equal to its target
 * count, the type with the highest credit fires next and is debited by the
 * total). Produces a naturally alternating pattern (never all of one type
 * clustered together) without hardcoding any specific permanent sequence —
 * the exact output is a function of the counts, not a fixed template.
 */
function plan_hybrid_type_sequence(array $counts): array
{
    $total = array_sum($counts);
    $remaining = $counts;
    $credit = array_fill_keys(array_keys($counts), 0);
    $sequence = [];

    for ($i = 0; $i < $total; $i++) {
        foreach ($counts as $type => $c) {
            $credit[$type] += $c;
        }
        $bestType = null;
        $bestCredit = -INF;
        foreach (QUIZ_PLAN_TYPE_PRIORITY as $type) {
            if (($remaining[$type] ?? 0) <= 0) continue;
            if ($credit[$type] > $bestCredit) {
                $bestCredit = $credit[$type];
                $bestType = $type;
            }
        }
        $sequence[] = $bestType;
        $credit[$bestType] -= $total;
        $remaining[$bestType]--;
    }
    return $sequence;
}

// ═════════════════════════════════════════════════════════════════════════════
// 7. BATCH CONSTRUCTION FROM AN ORDERED TYPE SEQUENCE
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Groups an ordered type sequence into batches, splitting whenever the
 * NEXT question would push a batch's 'short' (theory) question count past
 * QUIZ_PLAN_THEORY_BATCH_MAX. Objective-only/true-false-only sequences
 * (zero 'short' questions) never split under this rule, matching those
 * modes' "always exactly one batch" requirement automatically — this one
 * function correctly handles all four modes without a special case per mode.
 */
function build_batches_from_type_sequence(array $typeSequence): array
{
    $batches = [];
    $current = [];
    $currentTheoryCount = 0;
    $questionNo = 1;

    foreach ($typeSequence as $type) {
        if ($type === 'short' && $currentTheoryCount >= QUIZ_PLAN_THEORY_BATCH_MAX) {
            $batches[] = $current;
            $current = [];
            $currentTheoryCount = 0;
        }
        $current[] = ['question_no' => $questionNo, 'type' => $type];
        if ($type === 'short') $currentTheoryCount++;
        $questionNo++;
    }
    if ($current) $batches[] = $current;
    return $batches;
}

// ═════════════════════════════════════════════════════════════════════════════
// 8. CONCEPT ALLOCATION (rotation-aware, coverage-first, deterministic)
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Assigns one concept_id per question, in question order. Preference order
 * per question: (1) an unused concept whose last recorded type for this
 * user/course is DIFFERENT from this question's type (rotation), (2) any
 * unused concept regardless of history (coverage over rotation, per
 * explicit instruction), (3) once every concept has been used once, wrap
 * around deterministically (never random) rather than refusing to allocate
 * — "do not invent an impossible rotation if there are too few concepts".
 * Zero available concepts -> every question simply gets an empty
 * concept_ids array (Phase 2B/AI would fall back to whole-scope context in
 * that case — not this phase's concern).
 */
function allocate_concepts_to_questions(array $questionsWithTypes, array $availableConcepts, array $history): array
{
    if (!$availableConcepts) {
        return array_map(fn($q) => [], $questionsWithTypes);
    }

    $used = [];
    $result = [];
    foreach ($questionsWithTypes as $q) {
        $type = $q['type'];
        $pick = null;

        foreach ($availableConcepts as $c) {
            if (isset($used[$c['concept_id']])) continue;
            $last = $history[$c['concept_id']] ?? null;
            // No history at all is just as eligible as a different-type
            // history — both count as "not this exact type last time".
            // $last !== $type already covers null correctly (null !== 'mcq'
            // is true), so a concept only fails this pass when its history
            // is EXACTLY this question's type.
            if ($last !== $type) { $pick = $c; break; }
        }
        if ($pick === null) {
            foreach ($availableConcepts as $c) {
                if (!isset($used[$c['concept_id']])) { $pick = $c; break; }
            }
        }
        if ($pick === null) {
            // every concept already used this quiz — wrap around deterministically
            $pick = $availableConcepts[count($used) % count($availableConcepts)];
        }

        $used[$pick['concept_id']] = true;
        $result[] = [$pick['concept_id']];
    }
    return $result;
}

// ═════════════════════════════════════════════════════════════════════════════
// 9. MARK BUDGET ALLOCATION — proportional to batch question count, via the
// same apportion() algorithm, guaranteed to sum to exactly total_marks.
// ═════════════════════════════════════════════════════════════════════════════

function plan_batch_mark_budgets(array $batchSizes, float $totalMarks): array
{
    $weights = [];
    foreach ($batchSizes as $i => $size) {
        $weights["batch_{$i}"] = $size;
    }
    $tieOrder = array_keys($weights);
    $apportioned = apportion($weights, (int) round($totalMarks), $tieOrder);
    return array_values($apportioned);
}

// ═════════════════════════════════════════════════════════════════════════════
// 10. FULL PLAN ASSEMBLY + VALIDATION
// ═════════════════════════════════════════════════════════════════════════════

/**
 * The single entry point. Returns either ['error' => '...'] (authorization
 * or course-not-found failure — no material is ever fetched in that case)
 * or a full, internally-validated plan. Never makes a network call.
 */
function plan_quiz(array $rawRequest, array $user): array
{
    $req = normalize_quiz_plan_request($rawRequest);

    // registered_or_staff_ai_access() — quiz generation is an AI-powered
    // student feature and requires actual registration (not just course
    // authorization), same tier as ai_quiz.php's own outer check above this.
    if ($req['course_id'] <= 0 || !registered_or_staff_ai_access($req['course_id'], $user)) {
        return ['error' => 'not_authorized'];
    }
    if ($req['scope'] === 'topic') {
        $topicCheck = db()->prepare('SELECT id FROM topics WHERE id = ? AND course_id = ?');
        $topicCheck->execute([$req['topic_id'], $req['course_id']]);
        if (!$topicCheck->fetch()) {
            return ['error' => 'topic_not_found'];
        }
    }

    $availableConcepts = quiz_plan_available_concepts($req['course_id'], $req['topic_id'], $req['scope']);
    $history = quiz_concept_history_for((int) $user['id'], $req['course_id']);

    $count = $req['count'];
    switch ($req['question_type']) {
        case 'objective':
            $typeSequence = array_fill(0, $count, 'mcq');
            break;
        case 'truefalse':
            $typeSequence = array_fill(0, $count, 'truefalse');
            break;
        case 'theory':
            $typeSequence = array_fill(0, $count, 'short');
            break;
        default: // hybrid
            $counts = plan_hybrid_counts($count);
            $typeSequence = plan_hybrid_type_sequence($counts);
            break;
    }

    $batchGroups = build_batches_from_type_sequence($typeSequence);
    $questionsWithTypes = array_map(fn($t, $i) => ['question_no' => $i + 1, 'type' => $t], $typeSequence, array_keys($typeSequence));
    $conceptAllocations = allocate_concepts_to_questions($questionsWithTypes, $availableConcepts, $history);

    $batchSizes = array_map('count', $batchGroups);
    $markBudgets = plan_batch_mark_budgets($batchSizes, $req['total_marks']);

    $batches = [];
    $cursor = 0;
    foreach ($batchGroups as $i => $group) {
        $questions = [];
        foreach ($group as $q) {
            $questions[] = [
                'question_no'  => $q['question_no'],
                'type'         => $q['type'],
                'concept_ids'  => $conceptAllocations[$q['question_no'] - 1],
            ];
            $cursor++;
        }
        $batches[] = [
            'batch_id'         => 'batch_' . ($i + 1),
            'question_start'   => $group[0]['question_no'],
            'question_count'   => count($group),
            'question_numbers' => array_column($group, 'question_no'),
            'questions'        => $questions,
            'mark_budget'      => $markBudgets[$i],
        ];
    }

    $plan = [
        'quiz_request'   => $req,
        'user_id'        => (int) $user['id'],
        'question_count' => $count,
        'total_marks'    => $req['total_marks'],
        'mode'           => $req['question_type'],
        'batches'        => $batches,
    ];

    [$ok, $errors] = validate_quiz_plan($plan, array_column($availableConcepts, 'concept_id'));
    if (!$ok) {
        // A validation failure here means a genuine planner bug, not a data
        // issue Phase 2B could recover from — surfaced explicitly rather
        // than silently returning a broken plan.
        return ['error' => 'plan_validation_failed', 'details' => $errors];
    }

    return $plan;
}

/**
 * Rejects a plan for any of: wrong total count, missing/duplicate/gapped
 * question numbers, wrong hybrid counts, a batch exceeding the theory cap,
 * missing/duplicate batch ids, an incorrect mark total, or a concept_id
 * that isn't actually in the supplied available-concepts set (which itself
 * is always scope-filtered — see quiz_plan_available_concepts() — so this
 * also structurally catches any material reference that would have been
 * outside the requested scope).
 */
function validate_quiz_plan(array $plan, array $availableConceptIds): array
{
    $errors = [];
    $expectedCount = $plan['question_count'];
    $allQuestionNos = [];
    $typeCounts = ['mcq' => 0, 'short' => 0, 'truefalse' => 0];
    $batchIds = [];
    $markSum = 0.0;
    $availableSet = array_flip($availableConceptIds);

    if (!$plan['batches']) {
        $errors[] = 'plan has no batches';
    }

    foreach ($plan['batches'] as $batch) {
        if (empty($batch['batch_id'])) {
            $errors[] = 'batch missing batch_id';
        } elseif (isset($batchIds[$batch['batch_id']])) {
            $errors[] = "duplicate batch_id: {$batch['batch_id']}";
        } else {
            $batchIds[$batch['batch_id']] = true;
        }

        $theoryInBatch = 0;
        foreach ($batch['questions'] as $q) {
            $allQuestionNos[] = $q['question_no'];
            $typeCounts[$q['type']] = ($typeCounts[$q['type']] ?? 0) + 1;
            if ($q['type'] === 'short') $theoryInBatch++;
            foreach ($q['concept_ids'] as $cid) {
                if (!isset($availableSet[$cid])) {
                    $errors[] = "question {$q['question_no']} references a concept_id outside the supplied index: {$cid}";
                }
            }
        }
        if ($theoryInBatch > QUIZ_PLAN_THEORY_BATCH_MAX) {
            $errors[] = "batch {$batch['batch_id']} has {$theoryInBatch} theory questions (max " . QUIZ_PLAN_THEORY_BATCH_MAX . ')';
        }
        if (count($batch['questions']) !== $batch['question_count']) {
            $errors[] = "batch {$batch['batch_id']} question_count does not match its actual questions array";
        }
        $markSum += $batch['mark_budget'];
    }

    if (count($allQuestionNos) !== $expectedCount) {
        $errors[] = 'total question count mismatch: expected ' . $expectedCount . ', got ' . count($allQuestionNos);
    }
    $uniqueNos = array_unique($allQuestionNos);
    if (count($uniqueNos) !== count($allQuestionNos)) {
        $errors[] = 'duplicate question numbers present';
    }
    sort($allQuestionNos);
    $expectedSeq = range(1, count($allQuestionNos));
    if ($allQuestionNos !== $expectedSeq) {
        $errors[] = 'question numbers have gaps or do not start at 1 sequentially';
    }

    if ($plan['mode'] === 'hybrid') {
        $expected = plan_hybrid_counts($expectedCount);
        foreach (['mcq', 'short', 'truefalse'] as $t) {
            if (($typeCounts[$t] ?? 0) !== ($expected[$t] ?? 0)) {
                $errors[] = "hybrid type count mismatch for {$t}: expected {$expected[$t]}, got " . ($typeCounts[$t] ?? 0);
            }
        }
    } elseif ($plan['mode'] === 'objective' && $typeCounts['mcq'] !== $expectedCount) {
        $errors[] = 'objective mode must be 100% mcq';
    } elseif ($plan['mode'] === 'truefalse' && $typeCounts['truefalse'] !== $expectedCount) {
        $errors[] = 'truefalse mode must be 100% truefalse';
    } elseif ($plan['mode'] === 'theory' && $typeCounts['short'] !== $expectedCount) {
        $errors[] = 'theory mode must be 100% short (theory)';
    }

    if (abs($markSum - $plan['total_marks']) > 0.01) {
        $errors[] = "batch mark budgets sum to {$markSum}, expected {$plan['total_marks']}";
    }

    return [empty($errors), $errors];
}
