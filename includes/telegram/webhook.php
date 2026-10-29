<?php

declare(strict_types=1);

require_once __DIR__ . '/linking.php';
require_once __DIR__ . '/bot_api.php';

const TELEGRAM_WEBHOOK_MAX_BYTES = 262144;

function telegram_webhook_header(array $headers, string $wanted): ?string
{
    foreach ($headers as $name => $value) {
        if (is_string($name) && strcasecmp($name, $wanted) === 0 && is_scalar($value)) return trim((string)$value);
    }
    return null;
}

function telegram_webhook_valid_secret(array $headers, ?array $config = null): bool
{
    $expected = (string)(telegram_config($config)['webhook_secret'] ?? '');
    $provided = telegram_webhook_header($headers, 'X-Telegram-Bot-Api-Secret-Token');
    return strlen($expected) >= 32 && is_string($provided) && hash_equals($expected, $provided);
}

function telegram_webhook_parse_command(mixed $text): array
{
    if (!is_string($text) || strlen($text) > 512) return ['command' => null, 'argument' => null];
    $text = trim($text);
    if (!preg_match('/^\/(start|help|settings)(?:@[A-Za-z0-9_]{5,32})?(?:\s+([^\s]+))?\s*$/u', $text, $matches)) {
        return ['command' => null, 'argument' => null];
    }
    $argument = isset($matches[2]) && $matches[2] !== '' ? (string)$matches[2] : null;
    return ['command' => strtolower((string)$matches[1]), 'argument' => $argument];
}

/** Normalize only the identity and command data Stage 3 needs. */
function telegram_webhook_normalize_update(array $payload): ?array
{
    $updateId = telegram_normalize_integer_id($payload['update_id'] ?? '');
    if ($updateId === null || str_starts_with($updateId, '-')) return null;
    $message = is_array($payload['message'] ?? null) ? $payload['message'] : null;
    if ($message === null) {
        return ['update_id' => $updateId, 'update_type' => 'unsupported', 'telegram_user_id' => null,
            'telegram_chat_id' => null, 'chat_type' => null, 'display_name' => null, 'username' => null,
            'command' => null, 'argument' => null, 'token_hash' => null];
    }
    $from = is_array($message['from'] ?? null) ? $message['from'] : [];
    $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
    $parsed = telegram_webhook_parse_command($message['text'] ?? null);
    $argument = $parsed['argument'];
    $tokenHash = is_string($argument) && telegram_link_token_is_well_formed($argument)
        ? telegram_link_token_hash($argument) : null;
    $first = telegram_link_bounded_identity_text($from['first_name'] ?? null, 95);
    $last = telegram_link_bounded_identity_text($from['last_name'] ?? null, 95);
    $display = trim(implode(' ', array_filter([$first, $last], static fn($part): bool => is_string($part) && $part !== '')));
    return [
        'update_id' => $updateId,
        'update_type' => 'message',
        'telegram_user_id' => telegram_normalize_integer_id($from['id'] ?? ''),
        'telegram_chat_id' => telegram_normalize_integer_id($chat['id'] ?? ''),
        'chat_type' => is_string($chat['type'] ?? null) ? mb_substr((string)$chat['type'], 0, 20) : null,
        'display_name' => $display !== '' ? mb_substr($display, 0, 191) : null,
        'username' => telegram_link_bounded_identity_text($from['username'] ?? null, 191),
        'command' => $parsed['command'],
        'argument' => $argument,
        'token_hash' => $tokenHash,
    ];
}

function telegram_webhook_store_update(array $update): array
{
    $insert = db()->prepare("INSERT IGNORE INTO telegram_webhook_updates
        (update_id,update_type,telegram_user_id,telegram_chat_id,command,token_hash,processing_status)
        VALUES (?,?,?,?,?,?,'processing')");
    $insert->execute([$update['update_id'], $update['update_type'], $update['telegram_user_id'],
        $update['telegram_chat_id'], $update['command'], $update['token_hash']]);
    if ($insert->rowCount() !== 1) return ['created' => false, 'id' => null];
    return ['created' => true, 'id' => (int)db()->lastInsertId()];
}

function telegram_webhook_finish_update(
    int $id,
    string $status,
    ?string $errorCode = null,
    ?string $errorMessage = null
): void {
    if (!in_array($status, ['processed', 'failed', 'ignored'], true)) {
        throw new InvalidArgumentException('Invalid Telegram webhook processing status.');
    }
    db()->prepare('UPDATE telegram_webhook_updates SET processing_status=?,processed_at=NOW(),error_code=?,error_message=? WHERE id=?')
        ->execute([$status, $errorCode === null ? null : mb_substr($errorCode, 0, 100),
            $errorMessage === null ? null : telegram_redact_secrets(mb_substr(strip_tags($errorMessage), 0, 1000)), $id]);
}

/**
 * Process a verified request. The durable inbox and linking side effects are
 * committed before a best-effort, mockable Bot API response is attempted.
 */
function telegram_webhook_process_update(array $update, TelegramBotProvider $provider, ?array $config = null): array
{
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $stored = telegram_webhook_store_update($update);
        if (!$stored['created']) {
            if ($own) $pdo->commit();
            return ['duplicate' => true, 'result' => 'duplicate', 'response' => null, 'chat_id' => null];
        }
        $id = (int)$stored['id'];
        $response = null;
        $result = 'ignored';
        $status = 'ignored';
        $errorCode = null;
        $errorMessage = null;

        if ($update['update_type'] !== 'message' || $update['command'] === null) {
            $errorCode = 'unsupported_update';
            $errorMessage = 'The Telegram update is not a supported command.';
        } elseif ($update['telegram_user_id'] === null || $update['telegram_chat_id'] === null) {
            $errorCode = 'invalid_identity';
            $errorMessage = 'Telegram identity metadata is invalid.';
        } elseif ($update['chat_type'] !== 'private') {
            $errorCode = 'private_chat_required';
            $errorMessage = 'Telegram account linking is available only in a private chat.';
            $response = 'For your privacy, connect KWASU LCS only in a private chat with this bot.';
        } elseif ($update['command'] === 'help') {
            $status = 'processed';
            $result = 'help';
            $response = "This bot sends KWASU LCS notifications. Start account connection from KWASU LCS. Manage settings or disconnect from LCS. This bot does not provide AI or chat services.";
        } elseif ($update['command'] === 'settings') {
            $base = rtrim(trim((string)(telegram_config($config)['base_url'] ?? '')), '/');
            $status = 'processed';
            $result = 'settings';
            $response = filter_var($base, FILTER_VALIDATE_URL)
                && strtolower((string)parse_url($base, PHP_URL_SCHEME)) === 'https'
                ? 'Manage your Telegram notification settings in KWASU LCS: ' . $base . '/settings'
                : 'Manage your Telegram notification settings from the KWASU LCS Settings page.';
        } elseif ($update['command'] === 'start' && $update['argument'] === null) {
            $status = 'processed';
            $result = 'start_without_token';
            $response = 'Connect this Telegram account from your KWASU LCS Settings page.';
        } elseif ($update['command'] === 'start') {
            $linkUserId = telegram_link_token_user_id((string)$update['argument']);
            $linked = $linkUserId !== null && telegram_delivery_allowed_for_user($linkUserId, $config)
                ? telegram_consume_link_token((string)$update['argument'], (string)$update['telegram_user_id'],
                    (string)$update['telegram_chat_id'], (string)$update['chat_type'], $update['display_name'], $update['username'])
                : ['ok' => false, 'state' => 'invalid'];
            $status = 'processed';
            if ($linked['ok']) {
                $result = (string)$linked['state'];
                $response = 'Your Telegram account is now connected to KWASU LCS notifications.';
            } else {
                $result = 'link_rejected';
                $errorCode = 'link_rejected';
                $errorMessage = 'The link token or Telegram identity could not be accepted.';
                $response = 'This connection link is invalid or expired. Start again from KWASU LCS.';
            }
        }
        telegram_webhook_finish_update($id, $status, $errorCode, $errorMessage);
        if ($own) $pdo->commit();

        $send = null;
        if ($response !== null && $update['telegram_chat_id'] !== null) {
            try {
                $send = $provider->sendMessage((string)$update['telegram_chat_id'], $response);
            } catch (Throwable) {
                $send = ['ok' => false, 'error_code' => 'response_transport_error'];
            }
            if (empty($send['ok'])) {
                db()->prepare("UPDATE telegram_webhook_updates SET error_code=COALESCE(error_code,'response_failed'),
                    error_message=COALESCE(error_message,'Telegram command response could not be sent.') WHERE id=?")
                    ->execute([$id]);
            }
        }
        return ['duplicate' => false, 'result' => $result, 'response' => $send,
            'chat_id' => $update['telegram_chat_id'], 'update_row_id' => $id];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function telegram_webhook_handle_request(
    string $method,
    array $headers,
    string $rawBody,
    ?TelegramBotProvider $provider = null,
    ?array $config = null
): array {
    if (strtoupper($method) !== 'POST') return ['status' => 405, 'body' => ['ok' => false, 'error' => 'method_not_allowed']];
    // Authentication deliberately precedes JSON parsing and every database side effect.
    if (!telegram_webhook_valid_secret($headers, $config)) return ['status' => 403, 'body' => ['ok' => false, 'error' => 'forbidden']];
    if (strlen($rawBody) > TELEGRAM_WEBHOOK_MAX_BYTES) return ['status' => 413, 'body' => ['ok' => false, 'error' => 'payload_too_large']];
    try {
        $payload = json_decode($rawBody, true, 12, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    } catch (JsonException) {
        return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_json']];
    }
    if (!is_array($payload)) return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_update']];
    $update = telegram_webhook_normalize_update($payload);
    if ($update === null) return ['status' => 400, 'body' => ['ok' => false, 'error' => 'invalid_update_id']];
    try {
        $provider ??= new TelegramBotApiClient($config);
        $processed = telegram_webhook_process_update($update, $provider, $config);
        return ['status' => 200, 'body' => ['ok' => true, 'duplicate' => $processed['duplicate'], 'result' => $processed['result']]];
    } catch (Throwable $e) {
        error_log('[LCS Telegram webhook] update processing failed.');
        return ['status' => 500, 'body' => ['ok' => false, 'error' => 'processing_failed']];
    }
}
