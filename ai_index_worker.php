<?php

/**
 * ai_index_worker.php — Phase 1C: background AI material-indexing worker.
 *
 * Consumes document_ai_index jobs created by the Phase 1B persistence layer
 * in config.php (queue_document_indexing()/claim_next_pending_index_job()/
 * complete_document_index_job()/fail_document_index_job()/
 * requeue_failed_index_job() — unchanged, reused exactly as implemented).
 *
 * Nothing in this file is wired to admin.php's upload handling yet (Phase
 * 1D), and nothing here touches ai_quiz.php or quiz generation — the
 * extraction helpers and AI-calling function below are deliberately
 * isolated copies/new code, not shared with or routed through that file.
 *
 * ON PRODUCTION (AlwaysData) — add to crontab (runs every 5 min automatically):
 *   crontab -e
 *   *\/5 * * * * php /home/kwasulcs/www/ai_index_worker.php >> /tmp/lcs_index_worker.log 2>&1
 * or trigger it manually by visiting:
 *   https://kwasulcs.alwaysdata.net/ai_index_worker.php?run=1
 *
 * ON XAMPP (local) — trigger it manually by visiting:
 *   http://localhost/LMS-portal/ai_index_worker.php?run=1
 *
 * The ?run=1 parameter is required from the browser to prevent accidental
 * runs. From CLI it always runs: php ai_index_worker.php
 *
 * Dual-mode structure (CLI always runs; browser requires admin login +
 * ?run=1) copied exactly from notify_cron.php's established convention —
 * same claim-before-act concurrency pattern too (UPDATE ... WHERE <still in
 * the expected state>, then check rowCount()), which is what
 * claim_next_pending_index_job() already does in config.php.
 */
require_once __DIR__ . '/config.php';
// Up to 5 jobs/run (main loop below), each a single 'high'-reasoning AI call
// that can legitimately take tens of seconds — same reasoning as ai_quiz.php's
// own set_time_limit(450) for its (heavier, multi-question) generation calls.
set_time_limit(600);

$isCLI = PHP_SAPI === 'cli';

// Browser must pass ?run=1 as a safety gate — identical convention to notify_cron.php.
if (!$isCLI && ($_GET['run'] ?? '') !== '1') {
    require_login();
    $user = current_user();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        exit('Forbidden');
    }
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <title>Run AI Indexing — KWASU LCS</title>
        <script>
            (function() {
                var t = localStorage.getItem('theme');
                if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme:dark)').matches)) document.documentElement.setAttribute('data-theme', 'dark')
            })();
        </script>
        <link rel="stylesheet" href="assets/style.css">
        <script src="assets/theme.js" defer></script>
    </head>

    <body class="app-body">
        <header class="topbar">
            <div>
                <div class="eyebrow">KWASU LCS</div>
                <h1>🧠 AI Indexing Worker</h1>
            </div>
            <div class="topbar-actions">
                <a class="btn secondary btn-go-dashboard" href="dashboard"><i class="bi bi-grid-1x2-fill"></i> Go to Dashboard</a>
            </div>
        </header>
        <main class="page">
            <div class="panel" style="padding:32px;max-width:560px;">
                <h2>Manual Indexing Trigger</h2>
                <p class="muted">On XAMPP, cron doesn't run automatically. Click below to process pending material-indexing jobs now.</p>
                <p class="muted">On a real server, set up a cron job instead:<br>
                    <code style="background:#f1f5f9;padding:4px 8px;border-radius:6px;font-size:13px;">
                        */5 * * * * php <?php echo __FILE__; ?>
                    </code>
                </p>
                <a class="btn primary" href="ai_index_worker.php?run=1" style="margin-top:16px;">
                    ▶ Run Indexing Now
                </a>
            </div>
        </main>
    </body>

    </html>
    <?php
    exit();
}

// ── Output helpers (identical shape to notify_cron.php) ────────────────────────
$lines = [];
function out(string $msg): void
{
    global $lines, $isCLI;
    $lines[] = $msg;
    if ($isCLI) echo $msg . "\n";
}

// ── Tuning constants (indexing-specific, independent of ai_quiz.php's) ─────────
// Phase 1E (part 2) instruction: do NOT rely on 'high' reasoning effort as
// the fix for missed structural sections — reverted to 'medium' here. The
// deterministic missing-section-recovery mechanism below is the actual
// solution to that failure mode; reasoning effort is no longer being asked
// to solve it by itself.
const DOCIDX_MAX_OUTPUT_TOKENS  = 10000;
const DOCIDX_REASONING_EFFORT   = 'medium'; // still the DEFAULT/fallback — see docidx_select_reasoning_effort() below (Phase 3D) for the adaptive initial-pass choice
const DOCIDX_TIMEOUT_SECS       = 120;
const DOCIDX_TEXT_CHAR_CAP      = 12000; // indexing reads the whole material thoroughly, not just enough for one question — deliberately larger than ai_quiz.php's 4000-char context-injection cap
const DOCIDX_STALE_CLAIM_MINUTES = 10;   // must match claim_next_pending_index_job()'s own reclaim window (config.php) — kept as a named constant here for the worker's own log/report text only, not re-implemented
const DOCUMENT_INDEX_MAX_RECOVERY_PASSES = 1; // hard cap — see the recovery section below. One pass is expected to be enough; never loop recovering.

// Phase 3D — deterministic, conservative adaptive-effort threshold. Real
// Phase 3B measurements: a document with 0 detected heading hints (a short,
// single-paragraph "small document") completed correctly and fast even at
// MEDIUM (21.2s, no completeness warning) — nothing to gain by risking LOW
// there is meaningfully different, but LOW was separately measured on a
// document with 7 heading hints and left a completeness warning UNRESOLVED
// even after recovery, in both real trials, while MEDIUM resolved the same
// document cleanly in both real trials. The threshold is set low/
// conservative on purpose: MEDIUM is the default for anything with 2+
// detected headings (the exact boundary where Phase 3B showed the risk),
// LOW is only used for the genuinely trivial case Phase 3B already proved
// safe. See docidx_select_reasoning_effort() and the Phase 3D report.
const DOCIDX_ADAPTIVE_EFFORT_HEADING_THRESHOLD = 2;

/**
 * Phase 3D — deterministic complexity signal, NOT an AI decision (the AI
 * never decides its own reasoning level). Purely a count of
 * docidx_detect_heading_hints() matches — the same regex-based structural
 * signal already used (post-hoc) for completeness-warning/recovery
 * detection, now also used (pre-hoc) to pick the initial pass's effort.
 * Deterministic: identical input text always yields the identical effort.
 */
function docidx_select_reasoning_effort(string $extractedText): string
{
    $hintCount = count(docidx_detect_heading_hints($extractedText));
    return $hintCount < DOCIDX_ADAPTIVE_EFFORT_HEADING_THRESHOLD ? 'low' : 'medium';
}

// ═════════════════════════════════════════════════════════════════════════════
// EXTRACTION — isolated docidx_ copies of ai_quiz.php's extract_docx() /
// extract_pdf_text_cached(). Deliberately duplicated rather than shared: the
// Phase 1 instructions forbid touching ai_quiz.php, and PHP would fatal on a
// duplicate function name if these were added to config.php (already
// require_once'd by ai_quiz.php). The underlying document_text_cache TABLE
// is still genuinely shared (same schema, same (md5(path), mtime) key
// convention, created by ensure_document_index_schema() in Phase 1B) — so a
// PDF extracted for quiz generation and for indexing hit the same cache row;
// only the plain wrapper function is duplicated, not the cached data.
// ═════════════════════════════════════════════════════════════════════════════

function docidx_extract_docx(string $path): string
{
    if (!file_exists($path)) return '';
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return '';
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if (!$xml) return '';
    $xml  = str_replace(['</w:p>', '</w:tr>'], "\n", $xml);
    $text = strip_tags($xml);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $text = preg_replace('/[ \t]+/', ' ', $text);
    return preg_replace('/\n{3,}/', "\n\n", trim($text));
}

function docidx_extract_pdf_text_cached(string $absPath): string
{
    if (!is_file($absPath)) return '';
    $pathHash = md5($absPath);
    $mtime    = @filemtime($absPath) ?: 0;

    $cacheStmt = db()->prepare('SELECT extracted_text FROM document_text_cache WHERE path_hash = ? AND file_mtime = ?');
    $cacheStmt->execute([$pathHash, $mtime]);
    $cached = $cacheStmt->fetchColumn();
    if ($cached !== false) {
        return $cached;
    }

    if (!function_exists('shell_exec')) {
        return '';
    }

    $cmd  = 'pdftotext ' . escapeshellarg($absPath) . ' - 2>/dev/null';
    $text = trim((string) @shell_exec($cmd));
    if ($text === '') {
        return ''; // not installed, or no extractable text layer — caller falls back
    }

    db()->prepare('DELETE FROM document_text_cache WHERE path_hash = ? AND file_mtime <> ?')->execute([$pathHash, $mtime]);
    db()->prepare('INSERT INTO document_text_cache (path_hash, file_mtime, extracted_text) VALUES (?, ?, ?)')
        ->execute([$pathHash, $mtime, $text]);

    return $text;
}

/**
 * Dispatches by file_type. Returns ['text' => string, 'pdf_fallback_block' => ?array]
 * — text is '' with pdf_fallback_block set when a PDF has no extractable text
 * layer (matches ai_quiz.php's fallback of attaching the raw PDF to the AI
 * request instead, same idea applied to the indexing request).
 */
function docidx_extract_text(array $document, string $absPath): array
{
    $ext = strtolower($document['file_type'] ?? pathinfo($absPath, PATHINFO_EXTENSION));

    if (in_array($ext, ['docx', 'doc'], true)) {
        return ['text' => substr(docidx_extract_docx($absPath), 0, DOCIDX_TEXT_CHAR_CAP), 'pdf_fallback_block' => null];
    }
    if ($ext === 'pdf') {
        $text = docidx_extract_pdf_text_cached($absPath);
        if ($text !== '') {
            return ['text' => substr($text, 0, DOCIDX_TEXT_CHAR_CAP), 'pdf_fallback_block' => null];
        }
        return ['text' => '', 'pdf_fallback_block' => [
            'type' => 'document',
            'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode(file_get_contents($absPath))],
            'title' => $document['title'] ?? 'document.pdf',
        ]];
    }
    if ($ext === 'txt') {
        return ['text' => substr(file_get_contents($absPath), 0, DOCIDX_TEXT_CHAR_CAP), 'pdf_fallback_block' => null];
    }
    return ['text' => '', 'pdf_fallback_block' => null]; // unsupported type (ppt/pptx/zip/images etc.) — not indexable in Phase 1
}

// ═════════════════════════════════════════════════════════════════════════════
// AI REQUEST — a dedicated, ISOLATED indexing-only call. Deliberately NOT
// call_ai_api()/call_openai_responses()/call_anthropic_messages() (config.php):
// this needs raw token-usage numbers those don't expose, and the brief is
// explicit that the indexing request must never route through the existing
// quiz-generation call path. Same two-provider shape as call_ai_api() purely
// because AI_PROVIDER is a real runtime switch this app already relies on —
// not a refactor of, or dependency on, the quiz-generation functions.
// ═════════════════════════════════════════════════════════════════════════════

function call_ai_for_document_index(string $systemPrompt, string $userText, ?array $pdfBlock, array $jsonSchema, ?string $reasoningEffort = null): array
{
    if (AI_PROVIDER === 'openai') {
        return docidx_call_openai($systemPrompt, $userText, $pdfBlock, $jsonSchema, $reasoningEffort);
    }
    return docidx_call_anthropic($systemPrompt, $userText, $pdfBlock);
}

// Phase 3D — $reasoningEffort is an optional override (adaptive selection
// happens in docidx_process_claimed_job(), never here); null preserves the
// exact pre-3D behavior (DOCIDX_REASONING_EFFORT constant), so any existing
// caller that doesn't pass this new last argument is completely unaffected.
function docidx_call_openai(string $systemPrompt, string $userText, ?array $pdfBlock, array $jsonSchema, ?string $reasoningEffort = null): array
{
    $effort = $reasoningEffort ?? DOCIDX_REASONING_EFFORT;
    $parts = [['type' => 'input_text', 'text' => $userText]];
    if ($pdfBlock !== null) {
        $parts[] = [
            'type' => 'input_file',
            'filename' => (str_ends_with(strtolower($pdfBlock['title']), '.pdf') ? $pdfBlock['title'] : $pdfBlock['title'] . '.pdf'),
            'file_data' => 'data:application/pdf;base64,' . $pdfBlock['source']['data'],
        ];
    }

    $payload = [
        'model'             => OPENAI_MODEL,
        'input'             => [['role' => 'user', 'content' => $parts]],
        'instructions'      => $systemPrompt,
        'max_output_tokens' => DOCIDX_MAX_OUTPUT_TOKENS,
        'reasoning'         => ['effort' => $effort],
        'text'              => ['format' => ['type' => 'json_schema', 'name' => 'document_index', 'strict' => true, 'schema' => $jsonSchema]],
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . OPENAI_API_KEY],
        CURLOPT_TIMEOUT        => DOCIDX_TIMEOUT_SECS,
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new RuntimeException('[transient] Connection failed: ' . $curlError);
    }
    $data = json_decode($response, true);
    if ($httpCode !== 200) {
        // 5xx / rate-limit class responses are transient by nature; a 4xx
        // (bad request/schema) would recur identically on retry, but the
        // worker's own bounded-attempts cap still protects against an
        // infinite loop either way, so both are surfaced the same way here.
        throw new RuntimeException('[transient] OpenAI API error (HTTP ' . $httpCode . '): ' . ($data['error']['message'] ?? 'unknown'));
    }

    $text = null;
    foreach ($data['output'] ?? [] as $item) {
        if (($item['type'] ?? '') !== 'message') continue;
        foreach ($item['content'] ?? [] as $part) {
            if (($part['type'] ?? '') === 'output_text') {
                $text = $part['text'];
                break 2;
            }
            if (($part['type'] ?? '') === 'refusal') {
                throw new RuntimeException('[malformed] Model refused: ' . ($part['refusal'] ?? 'no reason given'));
            }
        }
    }
    if ($text === null) {
        // Same reasoning-exhaustion signature documented in
        // call_openai_responses() — deliberately NOT retried internally here
        // (unlike that function): a single outer retry mechanism (this
        // worker's own bounded attempts/requeue) is enough, and avoids two
        // nested retry loops for the same failure class.
        throw new RuntimeException('[transient] No text found in OpenAI response (possible reasoning-token exhaustion).');
    }

    $usage = $data['usage'] ?? [];
    return [
        'text'              => $text,
        'input_tokens'      => $usage['input_tokens'] ?? null,
        'output_tokens'     => $usage['output_tokens'] ?? null,
        'reasoning_tokens'  => $usage['output_tokens_details']['reasoning_tokens'] ?? null,
    ];
}

function docidx_call_anthropic(string $systemPrompt, string $userText, ?array $pdfBlock): array
{
    $content = [['type' => 'text', 'text' => $userText]];
    if ($pdfBlock !== null) {
        $content[] = $pdfBlock; // already Anthropic-shaped (type=document, source=base64 pdf)
    }

    $payload = [
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => DOCIDX_MAX_OUTPUT_TOKENS,
        'system'     => $systemPrompt,
        'messages'   => [['role' => 'user', 'content' => $content]],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01', 'anthropic-beta: pdfs-2024-09-25'],
        CURLOPT_TIMEOUT        => DOCIDX_TIMEOUT_SECS,
    ]);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new RuntimeException('[transient] Connection failed: ' . $curlError);
    }
    $data = json_decode($response, true);
    if ($httpCode !== 200 || !isset($data['content'][0]['text'])) {
        throw new RuntimeException('[transient] Anthropic API error (HTTP ' . $httpCode . '): ' . ($data['error']['message'] ?? 'unknown'));
    }

    $usage = $data['usage'] ?? [];
    return [
        'text'             => $data['content'][0]['text'],
        'input_tokens'     => $usage['input_tokens'] ?? null,
        'output_tokens'    => $usage['output_tokens'] ?? null,
        'reasoning_tokens' => null, // not exposed by this model/API shape
    ];
}

// ═════════════════════════════════════════════════════════════════════════════
// JSON CONTRACT — matches the Phase 1A/1B contract exactly (document_id,
// document_version, sections -> topics -> concepts -> sub_concepts,
// source_pages, source_text). Not expanded, not redesigned.
// ═════════════════════════════════════════════════════════════════════════════

function docidx_concept_schema(bool $withSubConcepts): array
{
    $props = [
        'id'            => ['type' => 'string'],
        'title'         => ['type' => 'string'],
        'source_pages'  => ['type' => ['array', 'null'], 'items' => ['type' => 'integer']],
        'source_text'   => ['type' => 'string'],
    ];
    $required = ['id', 'title', 'source_pages', 'source_text'];
    if ($withSubConcepts) {
        $props['sub_concepts'] = ['type' => 'array', 'items' => docidx_concept_schema(false)];
        $required[] = 'sub_concepts';
    }
    return ['type' => 'object', 'properties' => $props, 'required' => $required, 'additionalProperties' => false];
}

function docidx_json_schema(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'document_id'      => ['type' => 'integer'],
            'document_version' => ['type' => 'string'],
            'sections' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id'    => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'topics' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'id'       => ['type' => 'string'],
                                    'title'    => ['type' => 'string'],
                                    'concepts' => ['type' => 'array', 'items' => docidx_concept_schema(true)],
                                ],
                                'required' => ['id', 'title', 'concepts'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['id', 'title', 'topics'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['document_id', 'document_version', 'sections'],
        'additionalProperties' => false,
    ];
}

/**
 * Rules shared by both the initial indexing prompt and the recovery prompt
 * below — factored out so the recovery prompt doesn't have to duplicate the
 * full initial prompt (Phase 1E part 2, section 13: "do not duplicate the
 * full initial indexing prompt unnecessarily"). Text itself unchanged from
 * the original prompt.
 */
function docidx_indexing_strict_rules(): string
{
    return <<<RULES
- Do NOT generate quiz questions, question counts, question-type distributions, marking rubrics, or grading criteria. That is a separate system and not your task.
- Do NOT invent chapters, weeks, topics, or definitions that are not actually present in the supplied text. If the material has no formal chapter/week labels, use the semantic organization that is actually present (e.g. natural topic breaks) instead of forcing one.
- Every concept must include verbatim or near-verbatim source_text drawn from the material (a short excerpt, not a paraphrase-only summary), and source_pages when page numbers are identifiable in the supplied text (use null if genuinely unknown — never guess).
- Use short, stable, kebab-case ids for section/topic/concept/sub_concept id fields (e.g. "week-5", "inheritance", "inheritance-definition").
- sub_concepts is for genuine finer-grained sub-points under a concept — use an empty array when a concept has none. Do not force sub-concepts that aren't really there.
- Echo document_id and document_version back EXACTLY as given to you below — do not alter them.
RULES;
}

/**
 * The indexing prompt is a NEW prompt, deliberately scoped to organization
 * only — no quiz questions, no counts, no rubrics, no marking, no question
 * rotation, no future-request planning. Faithful indexing only: describe
 * what's actually present, don't invent a chapter/week structure the
 * material doesn't have.
 *
 * Phase 1E part 2: reasoning effort alone is NOT relied on to guarantee
 * coverage — see DOCUMENT_INDEX_MAX_RECOVERY_PASSES and
 * docidx_find_missing_hints()/docidx_build_recovery_prompt() below for the
 * actual (deterministic, PHP-side) mechanism that catches a dropped
 * section. The heading-hint checklist here is still a cheap, harmless
 * first line of defense, kept as-is.
 */
function docidx_build_prompt(array $document, array $topic, array $course, string $documentVersion, string $extractedText): array
{
    $system = "You are a semantic indexing assistant for a university course-material library. Your ONLY job is to read the supplied material and produce a structured, faithful semantic index of its actual content — sections, topics, concepts, and (where genuinely present) sub-concepts.\n\n"
        . "COVERAGE:\n"
        . "- Analyze the ENTIRE supplied material from start to end before producing your answer. Do not stop as soon as you find one obvious structure and treat that as the whole document.\n"
        . "- If the material has more than one heading, chapter/week marker, or clear topic break, EVERY one of them should produce its own entry somewhere in your sections/topics where reasonably possible.\n"
        . "- Include every materially relevant concept: definitions, processes/procedures, examples, comparisons/contrasts, and stated principles or rules all count — not just formal definitions.\n"
        . "- You do NOT need a 1:1 mapping between paragraphs and concepts. Combining multiple closely related paragraphs into one well-described concept is correct and preferred over many trivial, near-duplicate concepts.\n"
        . "- Equally, do not invent extra concepts, sections, or sub-concepts just to look thorough — every concept must be genuinely grounded in the supplied text.\n\n"
        . "STRICT RULES:\n" . docidx_indexing_strict_rules();

    $courseLabel = trim(($course['code'] ?? '') . ' — ' . ($course['title'] ?? ''));
    $user = "COURSE: {$courseLabel}\n"
        . "TOPIC: Week {$topic['week_number']}: {$topic['title']}\n"
        . "MATERIAL TITLE: {$document['title']}\n"
        . "document_id: {$document['id']}\n"
        . "document_version: {$documentVersion}\n\n";

    // Deterministic coverage aid — a plain regex scan of the raw text for
    // heading-shaped lines, extracted directly from the ACTUAL material
    // (never invented), handed to the model purely as a checklist
    // reminder. Advisory only — never forces these to become section
    // titles, never part of the JSON contract. The REAL backstop for a
    // dropped heading is the deterministic recovery pass below, not this.
    $headingHints = docidx_detect_heading_hints($extractedText);
    if ($headingHints) {
        $user .= "HEADING-LIKE LINES DETECTED IN THE RAW TEXT (for your awareness only — a mechanical scan, not a semantic judgment; use it as a checklist to avoid silently skipping a section, not as the definitive structure):\n";
        foreach ($headingHints as $h) {
            $user .= "- {$h['title']}\n";
        }
        $user .= "\n";
    }

    $user .= "MATERIAL TEXT:\n" . ($extractedText !== '' ? $extractedText : '(no extractable text layer — see attached PDF file)');

    return [$system, $user];
}

/**
 * Conservative, deterministic scan for heading-SHAPED lines: "Week 3",
 * "Chapter 2", "Chapter Two", "Unit 4", numbered-outline style ("1.
 * Introduction", "2.3 Networking"), and similar clearly-marked structural
 * headings. Deliberately NOT a generic "any short capitalized line" catch-
 * all (that was tried in an earlier iteration and is exactly the kind of
 * over-eager heuristic that risks manufacturing dozens of fake sections out
 * of ordinary short sentences — removed here in favor of only the
 * genuinely distinctive marker forms this phase's brief calls out).
 *
 * Returns a STRUCTURED hint per match — used for two different purposes
 * that need two different strings:
 *   - 'marker': the bare structural anchor (e.g. "Week 4", "Introduction"
 *     for a numbered-outline item) — used for conservative matching against
 *     an AI-produced section title (see docidx_heading_matches_title()):
 *     the source-side anchor is expected to be a PREFIX of a possibly more
 *     descriptive index title ("Week 4" is a prefix of "Week 4 — Routing"),
 *     never the other way around.
 *   - 'title': the full original heading LINE, for human-readable display
 *     in the recovery prompt.
 *   - 'start_offset'/'end_offset': byte offsets into $text — end_offset is
 *     the start of the NEXT hint (or end-of-text for the last one), so
 *     substr($text, start, end-start) is exactly that section's content.
 *
 * Capped at 30 hints so a large/degenerate document can't blow up the
 * prompt or the recovery-context size.
 */
function docidx_detect_heading_hints(string $text): array
{
    static $ordinalWords = [
        'one' => 1,
        'two' => 2,
        'three' => 3,
        'four' => 4,
        'five' => 5,
        'six' => 6,
        'seven' => 7,
        'eight' => 8,
        'nine' => 9,
        'ten' => 10,
        'eleven' => 11,
        'twelve' => 12,
        'thirteen' => 13,
        'fourteen' => 14,
        'fifteen' => 15,
        'sixteen' => 16,
        'seventeen' => 17,
        'eighteen' => 18,
        'nineteen' => 19,
        'twenty' => 20,
    ];
    $ordinalPattern = implode('|', array_keys($ordinalWords));

    $pieces = preg_split('/\r\n|\r|\n/', $text, -1, PREG_SPLIT_OFFSET_CAPTURE);
    $hints = [];
    foreach ($pieces as [$lineRaw, $lineOffset]) {
        $line = trim($lineRaw);
        if ($line === '' || strlen($line) > 100) continue;

        $marker = null;
        if (preg_match('/^(week|chapter|unit|module|lesson|topic|section)\s+(\d+)\b/i', $line, $m)) {
            $marker = ucfirst(strtolower($m[1])) . ' ' . $m[2];
        } elseif (preg_match('/^(week|chapter|unit|module|lesson|topic|section)\s+(' . $ordinalPattern . ')\b/i', $line, $m)) {
            $marker = ucfirst(strtolower($m[1])) . ' ' . ucfirst(strtolower($m[2]));
        } elseif (
            preg_match('/^\d+(?:\.\d+)*\.\s+([A-Z][^.]{1,60})$/', $line, $m)
            && substr_count($line, ' ') <= 8
        ) {
            // "1. Introduction" / "2.3 Networking" — the number itself is a
            // weak/unreliable anchor for matching (an AI-produced section
            // title is unlikely to keep a bare leading "1."), so the marker
            // is the descriptive text AFTER the number instead.
            $marker = trim($m[1]);
        }

        if ($marker === null) continue;

        $hints[] = ['marker' => $marker, 'title' => $line, 'start_offset' => $lineOffset, 'end_offset' => null];
        if (count($hints) >= 30) break;
    }

    $n = count($hints);
    for ($i = 0; $i < $n; $i++) {
        $hints[$i]['end_offset'] = ($i + 1 < $n) ? $hints[$i + 1]['start_offset'] : strlen($text);
    }
    return $hints;
}

/** Lowercase, alphanumeric-only tokens — the unit conservative matching compares. */
function docidx_normalize_heading_tokens(string $s): array
{
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    return array_values(array_filter(explode(' ', trim($s))));
}

/**
 * Conservative "is this the same structural section" check (Phase 1E part
 * 2, section 4): the source-side marker's tokens must be an exact,
 * boundary-respecting PREFIX of the candidate index title's tokens.
 * "Week 4" matches "Week 4 — Routing" (tokens ["week","4"] is a prefix of
 * ["week","4","routing"]) but does NOT match "Week 40" (tokens
 * ["week","40"] — "4" != "40" at the token level, so no false match on a
 * different number that merely shares a string prefix). No semantic
 * similarity, no fuzzy matching — exact token-prefix only.
 */
function docidx_heading_matches_title(string $marker, string $candidateTitle): bool
{
    $markerTokens = docidx_normalize_heading_tokens($marker);
    $titleTokens  = docidx_normalize_heading_tokens($candidateTitle);
    if (!$markerTokens || count($titleTokens) < count($markerTokens)) {
        return false;
    }
    foreach ($markerTokens as $i => $t) {
        if (($titleTokens[$i] ?? null) !== $t) {
            return false;
        }
    }
    return true;
}

/**
 * Which detected heading hints have NO corresponding section anywhere in
 * the AI's index. This is the deterministic PHP-side structural comparison
 * — never "ask the AI if it missed anything". An empty result means no
 * recovery is warranted, including the common case of zero heading hints
 * at all (a document with no formal structural markers never triggers
 * recovery — matches section 12's explicit instruction not to force
 * recovery when no reliable mismatch can be established).
 */
function docidx_find_missing_hints(array $hints, array $sections): array
{
    $missing = [];
    foreach ($hints as $hint) {
        $found = false;
        foreach ($sections as $section) {
            if (docidx_heading_matches_title($hint['marker'], $section['title'] ?? '')) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $missing[] = $hint;
        }
    }
    return $missing;
}

/**
 * Recovery prompt — deliberately short, reuses docidx_indexing_strict_rules()
 * rather than repeating the full initial prompt. Receives ONLY the missing
 * section(s)' own exact source text (via each hint's start/end offsets),
 * never the whole document, never unrelated sections (Phase 1E part 2,
 * section 6).
 */
function docidx_build_recovery_prompt(array $document, string $documentVersion, array $missingHints, string $extractedText): array
{
    $system = "You are the SAME semantic indexing assistant for a university course-material library, now running in RECOVERY MODE. "
        . "A previous indexing pass on this material appears to have missed one or more structural sections. You are given ONLY the text belonging to those specific missing section(s) below — index ONLY what is supplied, following the same rules as full indexing.\n\n"
        . "RECOVERY RULES:\n"
        . "- Cover all materially relevant concepts, definitions, characteristics, examples, processes, and comparisons within the supplied section(s) only.\n"
        . "- Do not invent content beyond what is supplied below.\n"
        . "- Return ONLY the section(s) you were given — do not attempt to reproduce or guess at other parts of the document you were not given.\n"
        . "- Preserve document_id and document_version exactly as given.\n\n"
        . "STRICT RULES:\n" . docidx_indexing_strict_rules();

    $user = "document_id: {$document['id']}\n"
        . "document_version: {$documentVersion}\n\n"
        . "MISSING SECTION(S) TO INDEX (index exactly these, nothing else):\n\n";
    foreach ($missingHints as $hint) {
        $span = substr($extractedText, $hint['start_offset'], $hint['end_offset'] - $hint['start_offset']);
        $user .= "--- {$hint['title']} ---\n" . trim($span) . "\n\n";
    }

    return [$system, $user];
}

/**
 * Finds the first section in $sections (skipping any index already in
 * $excludeIdx) whose title matches $marker, or null. Used by the merge
 * below to place each recovered/original section at most once.
 */
function docidx_find_matching_section_index(array $sections, string $marker, array $excludeIdx): ?int
{
    foreach ($sections as $idx => $section) {
        if (in_array($idx, $excludeIdx, true)) continue;
        if (docidx_heading_matches_title($marker, $section['title'] ?? '')) {
            return $idx;
        }
    }
    return null;
}

/**
 * Merges a recovery response's sections into the original index, ordered by
 * the SOURCE material's own heading order (Phase 1E part 2, section 8) —
 * e.g. source Week 3/Week 4/Week 5, original index has Week 3 + Week 5,
 * recovery returns Week 4 -> final order is Week 3, Week 4, Week 5, not
 * Week 3, Week 5, Week 4. Never duplicates a section, never drops one:
 * every original and every recovered section ends up in the result exactly
 * once, matched-by-hint ones placed in source order, any leftover
 * unmatched ones (e.g. an unheaded preamble section) appended afterward in
 * their own original relative order.
 */
function docidx_merge_recovered_sections(array $originalIndex, array $recoveredIndex, array $allHeadingHints): array
{
    $originalSections  = $originalIndex['sections'] ?? [];
    $recoveredSections = $recoveredIndex['sections'] ?? [];

    $usedOriginal  = [];
    $usedRecovered = [];
    $ordered = [];

    foreach ($allHeadingHints as $hint) {
        $idx = docidx_find_matching_section_index($originalSections, $hint['marker'], $usedOriginal);
        if ($idx !== null) {
            $ordered[] = $originalSections[$idx];
            $usedOriginal[] = $idx;
            continue;
        }
        $idx = docidx_find_matching_section_index($recoveredSections, $hint['marker'], $usedRecovered);
        if ($idx !== null) {
            $ordered[] = $recoveredSections[$idx];
            $usedRecovered[] = $idx;
        }
        // Neither has it — the recovery pass didn't restore this one either;
        // it simply stays absent (still reflected as a completeness_warning
        // by the caller, never silently claimed as fixed).
    }

    foreach ($originalSections as $idx => $s) {
        if (!in_array($idx, $usedOriginal, true)) $ordered[] = $s;
    }
    foreach ($recoveredSections as $idx => $s) {
        if (in_array($idx, $usedRecovered, true)) continue;
        // A recovery response can redundantly restate a section that was
        // ALREADY satisfied from the original index (e.g. the recovery
        // model echoes "Week 3" back even though only "Week 4" was actually
        // missing). If this leftover recovered section matches ANY heading
        // hint at all — even one already resolved above — it's a duplicate
        // restatement, not new information, so it's dropped rather than
        // appended. Only a recovered section matching NO hint whatsoever
        // (shouldn't normally happen — recovery is scoped to specific
        // missing hints — but handled safely rather than silently lost) is
        // treated as genuinely extra and kept.
        $isDuplicateOfAnyHint = false;
        foreach ($allHeadingHints as $hint) {
            if (docidx_heading_matches_title($hint['marker'], $s['title'] ?? '')) {
                $isDuplicateOfAnyHint = true;
                break;
            }
        }
        if (!$isDuplicateOfAnyHint) {
            $ordered[] = $s;
        }
    }

    $merged = $originalIndex;
    $merged['sections'] = $ordered;
    return $merged;
}

// ═════════════════════════════════════════════════════════════════════════════
// ORCHESTRATION — processes ONE already-claimed job. $aiCaller is injectable
// so this function is independently testable without a real network call
// (defaults to the real call_ai_for_document_index() above). This is the
// worker's core unit — the main loop below just claims jobs and calls this.
// ═════════════════════════════════════════════════════════════════════════════

function docidx_process_claimed_job(array $job, ?callable $aiCaller = null): array
{
    $aiCaller = $aiCaller ?? 'call_ai_for_document_index';
    $jobId          = (int) $job['id'];
    $documentId     = (int) $job['source_id'];
    $queuedVersion  = $job['document_version'];

    // 5. Verify the source document still exists at all.
    $stmt = db()->prepare('
        SELECT d.*, t.course_id, t.week_number, t.title AS topic_title
        FROM documents d JOIN topics t ON t.id = d.topic_id
        WHERE d.id = ?
    ');
    $stmt->execute([$documentId]);
    $doc = $stmt->fetch();
    if (!$doc) {
        fail_document_index_job($jobId, '[missing_source] Document no longer exists.');
        return ['outcome' => 'failed', 'category' => 'missing_source'];
    }

    // 6. Verify the queued version still matches the CURRENT source version
    // (early check, before spending anything on extraction/AI). A mismatch
    // here means the file was replaced after this job was queued — this
    // exact job must never be allowed to complete and be treated as
    // current. The NEW version gets its own independent job instead.
    if ($doc['content_hash'] !== $queuedVersion) {
        fail_document_index_job($jobId, "[stale_version] content_hash changed since this job was queued (queued=" . substr((string)$queuedVersion, 0, 12) . "..., current=" . substr((string)$doc['content_hash'], 0, 12) . "...).");
        try {
            queue_document_indexing($documentId); // safe no-op if already queued for the current hash
        } catch (\Throwable $e) { /* content_hash briefly null mid-race — nothing to queue yet, next run will catch it */
        }
        return ['outcome' => 'failed', 'category' => 'stale_version'];
    }

    $abs = PRIVATE_UPLOAD_ROOT . '/' . ltrim($doc['file_path'], '/');
    if (!is_file($abs)) {
        fail_document_index_job($jobId, '[missing_source] File missing on disk: ' . $abs);
        return ['outcome' => 'failed', 'category' => 'missing_source'];
    }
    $sourceFileSize = @filesize($abs) ?: null;

    // 7. Extract text (cached where applicable).
    $tExtractStart = microtime(true);
    $extracted = docidx_extract_text($doc, $abs);
    $extractionMs = (int) round((microtime(true) - $tExtractStart) * 1000);
    if ($extracted['text'] === '' && $extracted['pdf_fallback_block'] === null) {
        fail_document_index_job($jobId, '[permanent] No extractable text and no PDF fallback available (unsupported or empty file type).');
        return ['outcome' => 'failed', 'category' => 'permanent'];
    }

    $courseStmt = db()->prepare('SELECT * FROM courses WHERE id = ?');
    $courseStmt->execute([$doc['course_id']]);
    $course = $courseStmt->fetch() ?: [];
    $topic  = ['week_number' => $doc['week_number'], 'title' => $doc['topic_title']];

    [$systemPrompt, $userText] = docidx_build_prompt($doc, $topic, $course, $queuedVersion, $extracted['text']);

    // Phase 3D — deterministic adaptive effort for the INITIAL pass only,
    // based purely on structural signal (never an AI decision).
    $adaptiveEffort = docidx_select_reasoning_effort($extracted['text']);

    // 8. Call the dedicated document-indexing AI function (never the quiz path).
    $tAiStart = microtime(true);
    try {
        $result = $aiCaller($systemPrompt, $userText, $extracted['pdf_fallback_block'], docidx_json_schema(), $adaptiveEffort);
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        $category = str_starts_with($msg, '[malformed]') ? 'malformed' : 'transient';
        fail_document_index_job($jobId, $msg);
        if ($category === 'transient' && requeue_failed_index_job($jobId)) {
            return ['outcome' => 'requeued', 'category' => $category];
        }
        return ['outcome' => 'failed', 'category' => $category === 'transient' ? 'transient_exhausted' : $category];
    }
    $aiMs = (int) round((microtime(true) - $tAiStart) * 1000);

    $parsed = json_decode($result['text'] ?? '', true);
    if (!is_array($parsed)) {
        fail_document_index_job($jobId, '[malformed] AI response was not valid JSON.');
        return ['outcome' => 'failed', 'category' => 'malformed'];
    }

    // 9 (again, defense in depth). Re-verify version currency right before
    // persisting — the document could have changed WHILE the AI call was in
    // flight (this can take tens of seconds). Never overwrite a newer
    // version's slot with an older indexing result.
    $freshHash = db()->prepare('SELECT content_hash FROM documents WHERE id = ?');
    $freshHash->execute([$documentId]);
    $freshHash = $freshHash->fetchColumn();
    if ($freshHash !== $queuedVersion) {
        fail_document_index_job($jobId, '[stale_version] Document changed during indexing — discarding result, not persisting as current.');
        try {
            queue_document_indexing($documentId);
        } catch (\Throwable $e) {
        }
        return ['outcome' => 'failed', 'category' => 'stale_version'];
    }

    // validate_document_index() is called again inside complete_document_index_job()
    // itself (Phase 1B's own write gate) — checking here too just gives a
    // more specific 'malformed' outcome/category at this layer instead of a
    // generic one, not a second independent guarantee.
    [$ok, $error] = validate_document_index($parsed, $documentId, $queuedVersion);
    if (!$ok) {
        fail_document_index_job($jobId, '[malformed] ' . $error);
        return ['outcome' => 'failed', 'category' => 'malformed'];
    }

    // 9b. Deterministic missing-section detection + targeted recovery
    // (Phase 1E part 2). PHP performs the structural comparison — the AI is
    // never asked to self-certify completeness. Bounded to
    // DOCUMENT_INDEX_MAX_RECOVERY_PASSES (1): if that one pass fails for any
    // reason, the ORIGINAL already-valid index is still what gets persisted
    // — a failed recovery attempt never destroys a valid result, and never
    // silently claims completeness it didn't actually achieve.
    $headingHints = docidx_detect_heading_hints($extracted['text']);
    $missingHints = docidx_find_missing_hints($headingHints, $parsed['sections'] ?? []);
    $recovery = ['attempted' => false, 'outcome' => null, 'ai_ms' => null, 'input_tokens' => null, 'output_tokens' => null, 'reasoning_tokens' => null];

    if ($missingHints && DOCUMENT_INDEX_MAX_RECOVERY_PASSES > 0) {
        $recovery['attempted'] = true;
        [$recSystem, $recUser] = docidx_build_recovery_prompt($doc, $queuedVersion, $missingHints, $extracted['text']);

        $tRecStart = microtime(true);
        try {
            // Recovery always uses 'medium' regardless of the initial pass's
            // adaptive effort (Phase 3D): recovery exists specifically to
            // resolve a real structural gap, and Phase 3B's real
            // measurements showed LOW-effort recovery left the completeness
            // warning unresolved in both trials while MEDIUM resolved it in
            // both — recovery is exactly the wrong place to risk LOW.
            $recResult = $aiCaller($recSystem, $recUser, null, docidx_json_schema(), 'medium');
            $recovery['ai_ms']             = (int) round((microtime(true) - $tRecStart) * 1000);
            $recovery['input_tokens']      = $recResult['input_tokens'] ?? null;
            $recovery['output_tokens']     = $recResult['output_tokens'] ?? null;
            $recovery['reasoning_tokens']  = $recResult['reasoning_tokens'] ?? null;

            $recParsed = json_decode($recResult['text'] ?? '', true);
            if (!is_array($recParsed)) {
                $recovery['outcome'] = 'malformed';
                error_log("[LCS] document_ai_index job #{$jobId}: recovery response was not valid JSON — keeping original (incomplete) index, not merging.");
            } else {
                [$recOk, $recError] = validate_document_index($recParsed, $documentId, $queuedVersion);
                if (!$recOk) {
                    $recovery['outcome'] = 'malformed';
                    error_log("[LCS] document_ai_index job #{$jobId}: recovery response failed validation ({$recError}) — keeping original (incomplete) index, not merging.");
                } else {
                    $parsed = docidx_merge_recovered_sections($parsed, $recParsed, $headingHints);
                    $recovery['outcome'] = 'merged';
                }
            }
        } catch (\Throwable $e) {
            // Recovery's OWN AI call failed (transient/provider error) —
            // treated exactly like an initial-call transient failure: the
            // existing bounded job-attempts retry mechanism applies to the
            // WHOLE job (no partial-progress persistence exists to resume
            // just the recovery step from, and none is being added here —
            // that would be exactly the architecture change this phase was
            // told not to make).
            fail_document_index_job($jobId, '[transient] recovery call failed: ' . $e->getMessage());
            $recovery['ai_ms'] = (int) round((microtime(true) - $tRecStart) * 1000);
            if (requeue_failed_index_job($jobId)) {
                return ['outcome' => 'requeued', 'category' => 'transient', 'recovery' => $recovery];
            }
            return ['outcome' => 'failed', 'category' => 'transient_exhausted', 'recovery' => $recovery];
        }

        // Re-verify version currency once more after the recovery round-trip
        // — same reasoning as the check above; a slow recovery call can race
        // a document replacement just as easily as the initial call can.
        $freshHash2 = db()->prepare('SELECT content_hash FROM documents WHERE id = ?');
        $freshHash2->execute([$documentId]);
        $freshHash2 = $freshHash2->fetchColumn();
        if ($freshHash2 !== $queuedVersion) {
            fail_document_index_job($jobId, '[stale_version] Document changed during recovery — discarding result, not persisting as current.');
            try {
                queue_document_indexing($documentId);
            } catch (\Throwable $e) {
            }
            return ['outcome' => 'failed', 'category' => 'stale_version', 'recovery' => $recovery];
        }
    }

    // 10. Success — persist. When recovery ran, the persisted token/duration
    // totals are the REAL combined cost (initial + recovery) — the separate
    // initial-vs-recovery breakdown is only available via this function's
    // own return value (see $recovery above), not as new DB columns (no
    // schema redesign for this phase).
    $totalInputTokens     = $recovery['attempted'] ? ($result['input_tokens'] ?? 0) + ($recovery['input_tokens'] ?? 0) : ($result['input_tokens'] ?? null);
    $totalOutputTokens    = $recovery['attempted'] ? ($result['output_tokens'] ?? 0) + ($recovery['output_tokens'] ?? 0) : ($result['output_tokens'] ?? null);
    $totalReasoningTokens = $recovery['attempted'] ? ($result['reasoning_tokens'] ?? 0) + ($recovery['reasoning_tokens'] ?? 0) : ($result['reasoning_tokens'] ?? null);
    $totalDurationMs      = $aiMs + ($recovery['ai_ms'] ?? 0);

    complete_document_index_job($jobId, $parsed, [
        'source_file_size'    => $sourceFileSize,
        'extracted_text_size' => strlen($extracted['text']),
        'input_tokens'        => $totalInputTokens,
        'output_tokens'       => $totalOutputTokens,
        'reasoning_tokens'    => $totalReasoningTokens,
        'duration_ms'         => $totalDurationMs,
    ]);

    // Advisory only (Phase 1E) — see docidx_completeness_warning()'s own
    // docblock for why this is deliberately NOT a validation/pass-fail rule.
    // Recomputed against the FINAL (possibly recovery-merged) $parsed, so a
    // successful recovery correctly clears the warning, and a failed one
    // correctly still shows it — never falsely claims recovery fixed
    // something it didn't.
    $headingHintCount = count($headingHints);
    $completenessWarning = docidx_completeness_warning($parsed, strlen($extracted['text']), $headingHintCount);
    if ($recovery['attempted'] && $recovery['outcome'] !== 'merged') {
        $completenessWarning = ($completenessWarning ? $completenessWarning . ' ' : '')
            . "(recovery was attempted but did not succeed: {$recovery['outcome']} — original index preserved, still incomplete.)";
    }
    if ($completenessWarning !== null) {
        error_log("[LCS] document_ai_index job #{$jobId} (document #{$documentId}): {$completenessWarning}");
    }

    return [
        'outcome' => 'completed',
        'category' => null,
        'extraction_ms' => $extractionMs,
        'ai_ms' => $aiMs,
        'completeness_warning' => $completenessWarning,
        'recovery' => $recovery,
    ];
}

/**
 * ADVISORY ONLY — never used to pass/fail a job, never persisted to the
 * database, never blocks completion. Phase 1E finding: no fully reliable
 * DETERMINISTIC check for semantic completeness exists — whether an index
 * faithfully covers a document's meaning is a judgment call a dumb metric
 * can't make, and a strict rule like "concept count must roughly match
 * paragraph count" is explicitly wrong (a legitimate index can combine many
 * paragraphs into one concept, or a document can genuinely have only one
 * real concept worth indexing). Real testing during Phase 1E confirmed this
 * empirically: even at 'high' reasoning effort with an explicit coverage
 * checklist in the prompt, the model still sometimes dropped an entire
 * detected heading on a short, sparse test document — prompting and
 * reasoning effort measurably reduced this, but did not eliminate it. Two
 * lightweight, best-effort signals are checked here, purely advisory:
 *
 *  1. Heading-count mismatch — docidx_detect_heading_hints() found N
 *     heading-shaped lines in the RAW text (a plain regex scan, not
 *     semantic understanding) but the completed index has fewer than N
 *     top-level sections. This directly targets the exact failure mode a
 *     real test run exposed (a dropped "Week 4" heading) and is a much
 *     sharper signal than raw text size, but is still just a mechanical
 *     line-count — it can under- or over-fire on material the heading
 *     regex mis-detects.
 *  2. Large-text/low-concept-count — extracted_text_size is large but the
 *     index has 0-1 total concepts, a coarse fallback for documents with no
 *     detectable headings at all.
 */
function docidx_completeness_warning(array $index, int $extractedTextSize, int $headingHintCount = 0): ?string
{
    $totalConcepts = 0;
    foreach ($index['sections'] ?? [] as $section) {
        foreach ($section['topics'] ?? [] as $topic) {
            foreach ($topic['concepts'] ?? [] as $concept) {
                $totalConcepts++;
                $totalConcepts += count($concept['sub_concepts'] ?? []);
            }
        }
    }
    $sectionCount = count($index['sections'] ?? []);

    if ($headingHintCount >= 2 && $sectionCount < $headingHintCount) {
        return "possible dropped section(s): {$headingHintCount} heading-like line(s) detected in the source but only {$sectionCount} section(s) in the index — worth a manual check.";
    }
    if ($extractedTextSize > 2000 && $totalConcepts <= 1) {
        return "possible under-indexing: {$extractedTextSize} chars of extracted text produced only {$totalConcepts} concept(s) — worth a manual check.";
    }
    return null;
}

// ═════════════════════════════════════════════════════════════════════════════
// MAIN LOOP — only runs when this file is executed directly (CLI or the
// browser ?run=1 path above), never when another script/test require_once's
// it purely to reuse the functions defined here.
// ═════════════════════════════════════════════════════════════════════════════

if (!defined('DOCIDX_WORKER_LIBRARY_ONLY')) {
    ensure_document_index_schema();

    $maxJobsPerRun = 5;
    $processed = 0;
    $tRunStart = microtime(true);

    while ($processed < $maxJobsPerRun) {
        $job = claim_next_pending_index_job();
        if (!$job) {
            out($processed === 0 ? 'No claimable jobs (pending or stale-processing).' : 'No more claimable jobs.');
            break;
        }
        out("Claimed job #{$job['id']} — document #{$job['source_id']}, version " . substr($job['document_version'], 0, 12) . "...");
        $result = docidx_process_claimed_job($job);
        $detail = $result['category'] ? " ({$result['category']})" : '';
        out("  -> {$result['outcome']}{$detail}"
            . (isset($result['extraction_ms']) ? " | extraction={$result['extraction_ms']}ms" : '')
            . (isset($result['ai_ms']) ? " ai={$result['ai_ms']}ms" : ''));
        if (!empty($result['completeness_warning'])) {
            out("  [advisory] {$result['completeness_warning']}");
        }
        if (!empty($result['recovery']['attempted'])) {
            out("  [recovery] outcome={$result['recovery']['outcome']} ai={$result['recovery']['ai_ms']}ms"
                . " tokens(in={$result['recovery']['input_tokens']},out={$result['recovery']['output_tokens']},reasoning={$result['recovery']['reasoning_tokens']})");
        }
        $processed++;
    }

    $totalMs = (int) round((microtime(true) - $tRunStart) * 1000);
    out("Done. Processed {$processed} job(s) in {$totalMs}ms.");

    if (!$isCLI) {
        require_login();
    ?>
        <!DOCTYPE html>
        <html lang="en">

        <head>
            <meta charset="UTF-8">
            <title>Indexing Run Result — KWASU LCS</title>
            <script>
                (function() {
                    var t = localStorage.getItem('theme');
                    if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme:dark)').matches)) document.documentElement.setAttribute('data-theme', 'dark')
                })();
            </script>
            <link rel="stylesheet" href="assets/style.css">
            <script src="assets/theme.js" defer></script>
        </head>

        <body class="app-body">
            <header class="topbar">
                <div>
                    <div class="eyebrow">KWASU LCS</div>
                    <h1>🧠 AI Indexing Run</h1>
                </div>
                <div class="topbar-actions">
                    <a class="btn secondary btn-go-dashboard" href="dashboard"><i class="bi bi-grid-1x2-fill"></i> Go to Dashboard</a>
                </div>
            </header>
            <main class="page">
                <div class="panel" style="padding:24px;">
                    <h2>Result</h2>
                    <pre style="background:#0f172a;color:#e2e8f0;padding:16px;border-radius:12px;font-size:13px;line-height:1.6;overflow-x:auto;"><?php echo htmlspecialchars(implode("\n", $lines)); ?></pre>
                    <div style="margin-top:16px;display:flex;gap:10px;">
                        <a class="btn primary" href="ai_index_worker.php?run=1">Run Again</a>
                        <a class="btn secondary btn-go-dashboard" href="dashboard"><i class="bi bi-grid-1x2-fill"></i> Go to Dashboard</a>
                    </div>
                </div>
            </main>
        </body>

        </html>
<?php
    }
}
