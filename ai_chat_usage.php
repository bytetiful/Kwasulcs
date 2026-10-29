<?php

require_once __DIR__ . '/config.php'; // AI_PROVIDER, OPENAI_MODEL, OPENAI_API_KEY, ANTHROPIC_API_KEY, anthropic_block_to_openai_part() — reused, not duplicated

/**
 * ai_chat_usage.php — Phase 3C: isolated, chatbot-specific AI-usage-aware
 * calling layer.
 *
 * config.php's call_ai_api() / call_openai_responses() /
 * call_anthropic_messages() are completely UNTOUCHED by this phase —
 * call_ai_api() has exactly one caller in the whole app (ai_chat.php), so
 * changing it directly would have been technically safe, but the brief
 * explicitly prefers an isolated path over risking the shared contract, and
 * this matches the established pattern elsewhere in the app
 * (quiz_executor.php, ai_index_worker.php: each isolated subsystem keeps
 * its own copy of what it needs rather than sharing/require-ing a
 * general-purpose helper). The functions below mirror the originals
 * EXACTLY in request construction, retry behavior, and error contract —
 * the only difference is that provider-reported usage is captured and
 * returned instead of discarded.
 *
 * Return shape of call_ai_api_with_usage() — NEVER sent to the client,
 * internal-only (ai_chat.php's existing response to the frontend is built
 * from ['text'] the same way it always used the old plain-string return):
 * [
 *   'text'  => '...',
 *   'usage' => [
 *     'input_tokens'     => int|null,
 *     'output_tokens'    => int|null,
 *     'reasoning_tokens' => int|null,   // null when the provider doesn't expose it (e.g. Anthropic) — never estimated
 *     'total_tokens'     => int|null,   // provider-reported if present, else input+output if both known, else null
 *     'duration_ms'      => int,        // wall time around the actual provider request (incl. any internal retry)
 *     'provider'         => 'openai'|'anthropic',
 *     'model'            => string,
 *   ],
 * ]
 * Throws RuntimeException on failure — identical error contract to
 * call_ai_api(), so ai_chat.php's existing try/catch handling needs no change.
 *
 * $httpRunner is an injectable seam (same request/response shape as
 * quiz_executor.php's run_concurrent_http_requests(): a callable taking
 * ['key' => ['url','headers','body','timeout']] and returning
 * ['key' => ['http_code','body','error']]) — defaults to a real single-shot
 * curl call. Exists ONLY so this file can be tested with fake provider
 * responses (per the explicit "do not spend real API credits for ordinary
 * unit/integration coverage" instruction) without touching config.php's
 * non-injectable curl_init() calls.
 */

function ai_chat_default_http_runner(array $requests): array
{
    $results = [];
    foreach ($requests as $key => $req) {
        $ch = curl_init($req['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $req['body'],
            CURLOPT_HTTPHEADER     => $req['headers'],
            CURLOPT_TIMEOUT        => $req['timeout'],
        ]);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $results[$key] = ['http_code' => $httpCode, 'body' => $response, 'error' => $curlError ?: null];
    }
    return $results;
}

/**
 * Minimal, additive observability — mirrors this codebase's existing
 * error_log('[LCS] ...') convention used throughout (config.php,
 * ai_index_worker.php, quiz_executor.php, etc.) rather than inventing a new
 * persisted usage-log table. No prior AI-usage table exists anywhere in
 * this app (quiz/marking/indexing usage is likewise never persisted —
 * it's returned to the caller and either used directly or discarded at the
 * HTTP boundary), so a dedicated table here would be new architecture, not
 * a consistent extension of an existing one. error_log already captures
 * everything Part 7 asks for (timestamp is implicit in the log line;
 * provider/model/tokens/duration/success are all included explicitly) —
 * see the Phase 3C report's section D for the full reasoning.
 *
 * Deliberately swallows its own errors — instrumentation must NEVER cause
 * the chatbot request to fail (explicit requirement).
 */
function log_ai_chat_usage(array $entry): void
{
    try {
        $summary = sprintf(
            '[LCS] ai_chat usage: success=%s provider=%s model=%s input=%s output=%s reasoning=%s total=%s duration_ms=%s%s',
            $entry['success'] ? 'true' : 'false',
            $entry['provider'] ?? '?',
            $entry['model'] ?? '?',
            $entry['input_tokens'] ?? 'null',
            $entry['output_tokens'] ?? 'null',
            $entry['reasoning_tokens'] ?? 'null',
            $entry['total_tokens'] ?? 'null',
            $entry['duration_ms'] ?? '?',
            isset($entry['error']) ? (' error=' . $entry['error']) : ''
        );
        error_log($summary);
    } catch (\Throwable $e) {
        // Never let logging itself break anything — silently ignored.
    }
}

function call_ai_api_with_usage(string $systemPrompt, array $messages, int $maxTokens, ?array $jsonSchema = null, int $timeoutSecs = 60, string $reasoningEffort = 'minimal', ?callable $httpRunner = null): array
{
    $httpRunner = $httpRunner ?? 'ai_chat_default_http_runner';
    $provider   = AI_PROVIDER;
    $tStart     = microtime(true);

    try {
        if ($provider === 'openai') {
            $result = call_openai_responses_with_usage($systemPrompt, $messages, $maxTokens, $jsonSchema, $timeoutSecs, $reasoningEffort, $httpRunner);
        } else {
            $result = call_anthropic_messages_with_usage($systemPrompt, $messages, $maxTokens, $timeoutSecs, $httpRunner);
        }
    } catch (\Throwable $e) {
        $durationMs = (int) round((microtime(true) - $tStart) * 1000);
        log_ai_chat_usage([
            'success' => false,
            'provider' => $provider,
            'model' => $provider === 'openai' ? OPENAI_MODEL : 'claude-sonnet-4-6',
            'input_tokens' => null,
            'output_tokens' => null,
            'reasoning_tokens' => null,
            'total_tokens' => null,
            'duration_ms' => $durationMs,
            'error' => $e->getMessage(),
        ]);
        throw $e; // existing chatbot error behavior preserved exactly — instrumentation never blocks/alters it
    }

    $durationMs = (int) round((microtime(true) - $tStart) * 1000);
    $result['usage']['duration_ms'] = $durationMs;
    log_ai_chat_usage(array_merge(['success' => true], $result['usage']));
    return $result;
}

function call_openai_responses_with_usage(string $systemPrompt, array $messages, int $maxTokens, ?array $jsonSchema, int $timeoutSecs, string $reasoningEffort, callable $httpRunner, int $retriesRemaining = 2): array
{
    // Identical message/content translation to config.php's call_openai_responses().
    $input = [];
    foreach ($messages as $m) {
        $role    = $m['role'] ?? 'user';
        $content = $m['content'] ?? '';
        if (is_string($content)) {
            $parts = [['type' => $role === 'assistant' ? 'output_text' : 'input_text', 'text' => $content]];
        } else {
            $parts = [];
            foreach ($content as $block) {
                $part = anthropic_block_to_openai_part($block, $role); // reused verbatim from config.php — pure translation helper, no return-shape dependency
                if ($part !== null) {
                    $parts[] = $part;
                }
            }
        }
        $input[] = ['role' => $role, 'content' => $parts];
    }

    $payload = [
        'model'             => OPENAI_MODEL,
        'input'             => $input,
        'max_output_tokens' => $maxTokens,
        'reasoning'         => ['effort' => $reasoningEffort],
    ];
    if ($systemPrompt !== '') {
        $payload['instructions'] = $systemPrompt;
    }
    if ($jsonSchema !== null) {
        $payload['text'] = ['format' => ['type' => 'json_schema', 'name' => $jsonSchema['name'], 'strict' => true, 'schema' => $jsonSchema['schema']]];
    }

    $responses = $httpRunner(['req' => [
        'url' => 'https://api.openai.com/v1/responses',
        'headers' => ['Content-Type: application/json', 'Authorization: Bearer ' . OPENAI_API_KEY],
        'body' => json_encode($payload),
        'timeout' => $timeoutSecs,
    ]]);
    $resp = $responses['req'] ?? ['http_code' => 0, 'body' => null, 'error' => 'no response'];

    if ($resp['error']) {
        throw new RuntimeException('Connection failed: ' . $resp['error']);
    }
    $data = json_decode((string) $resp['body'], true);
    if ($resp['http_code'] !== 200) {
        throw new RuntimeException($data['error']['message'] ?? 'Unknown API error');
    }

    foreach ($data['output'] ?? [] as $item) {
        if (($item['type'] ?? '') !== 'message') continue;
        foreach ($item['content'] ?? [] as $part) {
            if (($part['type'] ?? '') === 'output_text') {
                $usage = $data['usage'] ?? [];
                $inputTokens     = $usage['input_tokens'] ?? null;
                $outputTokens    = $usage['output_tokens'] ?? null;
                $reasoningTokens = $usage['output_tokens_details']['reasoning_tokens'] ?? null; // null, never estimated, when absent
                $totalTokens     = $usage['total_tokens'] ?? (($inputTokens !== null && $outputTokens !== null) ? $inputTokens + $outputTokens : null);
                return [
                    'text' => $part['text'],
                    'usage' => [
                        'input_tokens' => $inputTokens,
                        'output_tokens' => $outputTokens,
                        'reasoning_tokens' => $reasoningTokens,
                        'total_tokens' => $totalTokens,
                        'provider' => 'openai',
                        'model' => OPENAI_MODEL,
                    ],
                ];
            }
            if (($part['type'] ?? '') === 'refusal') {
                throw new RuntimeException('Model refused: ' . ($part['refusal'] ?? 'no reason given'));
            }
        }
    }

    // Same reasoning-exhaustion retry signature as config.php's call_openai_responses().
    $isReasoningExhaustion = ($data['status'] ?? '') === 'incomplete' && ($data['incomplete_details']['reason'] ?? '') === 'max_output_tokens';
    if ($isReasoningExhaustion && $retriesRemaining > 0) {
        error_log(sprintf('[LCS] ai_chat (usage-aware): OpenAI Responses API exhausted max_output_tokens=%d on reasoning alone (effort=%s, model=%s) — retrying (%d attempt(s) left).', $maxTokens, $reasoningEffort, OPENAI_MODEL, $retriesRemaining - 1));
        return call_openai_responses_with_usage($systemPrompt, $messages, $maxTokens, $jsonSchema, $timeoutSecs, $reasoningEffort, $httpRunner, $retriesRemaining - 1);
    }

    throw new RuntimeException('No text found in OpenAI response.');
}

function call_anthropic_messages_with_usage(string $systemPrompt, array $messages, int $maxTokens, int $timeoutSecs, callable $httpRunner): array
{
    $payload = ['model' => 'claude-sonnet-4-6', 'max_tokens' => $maxTokens, 'messages' => $messages];
    if ($systemPrompt !== '') {
        $payload['system'] = $systemPrompt;
    }

    $responses = $httpRunner(['req' => [
        'url' => 'https://api.anthropic.com/v1/messages',
        'headers' => ['Content-Type: application/json', 'x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01', 'anthropic-beta: pdfs-2024-09-25'],
        'body' => json_encode($payload),
        'timeout' => $timeoutSecs,
    ]]);
    $resp = $responses['req'] ?? ['http_code' => 0, 'body' => null, 'error' => 'no response'];

    if ($resp['error']) {
        throw new RuntimeException('Connection failed: ' . $resp['error']);
    }
    $data = json_decode((string) $resp['body'], true);
    if ($resp['http_code'] !== 200 || !isset($data['content'][0]['text'])) {
        throw new RuntimeException($data['error']['message'] ?? 'Unknown API error');
    }

    $usage = $data['usage'] ?? [];
    $inputTokens  = $usage['input_tokens'] ?? null;
    $outputTokens = $usage['output_tokens'] ?? null;
    // Anthropic's Messages API (as currently called here — no extended-thinking
    // param sent) does not expose a reasoning-token count — null, per the
    // explicit "never estimate missing values" instruction, not a guess.
    $totalTokens = ($inputTokens !== null && $outputTokens !== null) ? $inputTokens + $outputTokens : null;

    return [
        'text' => $data['content'][0]['text'],
        'usage' => [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => null,
            'total_tokens' => $totalTokens,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-6',
        ],
    ];
}
