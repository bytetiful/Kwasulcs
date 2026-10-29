<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

interface TelegramBotProvider
{
    public function sendMessage(int|string $chatId, string $text, array $options = []): array;
    public function sendContact(int|string $chatId, string $phoneNumber, string $firstName, string $lastName = ''): array;
}

function telegram_failure_is_transient(int $httpStatus, bool $transportFailure = false): bool
{
    return $transportFailure || $httpStatus === 408 || $httpStatus === 429 || $httpStatus >= 500;
}

final class TelegramBotApiClient implements TelegramBotProvider
{
    private array $config;
    private $transport;

    public function __construct(?array $config = null, ?callable $transport = null)
    {
        $this->config = telegram_config($config);
        $this->transport = $transport;
    }

    public function sendMessage(int|string $chatId, string $text, array $options = []): array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 4096) {
            return $this->failure(400, 'invalid_request', 'Telegram message content is invalid.');
        }
        $allowed = [];
        foreach (['parse_mode', 'disable_web_page_preview', 'disable_notification', 'reply_markup'] as $key) {
            if (array_key_exists($key, $options)) $allowed[$key] = $options[$key];
        }
        // Paid broadcasts are intentionally unsupported and cannot pass through.
        return $this->request('sendMessage', array_merge(['chat_id' => $chatId, 'text' => $text], $allowed));
    }

    public function sendContact(int|string $chatId, string $phoneNumber, string $firstName, string $lastName = ''): array
    {
        $phoneNumber = trim($phoneNumber);
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        if ($phoneNumber === '' || mb_strlen($phoneNumber) > 32 || $firstName === '' || mb_strlen($firstName) > 64
            || mb_strlen($lastName) > 64) {
            return $this->failure(400, 'invalid_request', 'Telegram contact content is invalid.');
        }
        $payload = ['chat_id' => $chatId, 'phone_number' => $phoneNumber, 'first_name' => $firstName];
        if ($lastName !== '') $payload['last_name'] = $lastName;
        return $this->request('sendContact', $payload);
    }

    /** Explicit administrator-only identity check. This method never sends a message. */
    public function getMe(): array
    {
        $response = $this->performRequest('getMe', []);
        $decoded = json_decode((string)($response['body'] ?? ''), true);
        if ((int)($response['http_status'] ?? 0) >= 200 && (int)($response['http_status'] ?? 0) < 300
            && is_array($decoded) && ($decoded['ok'] ?? false) === true && is_array($decoded['result'] ?? null)) {
            $id = telegram_normalize_integer_id($decoded['result']['id'] ?? '');
            $username = trim((string)($decoded['result']['username'] ?? ''));
            if ($id !== null && preg_match('/^[A-Za-z0-9_]{5,32}$/', $username) === 1) {
                return ['ok' => true, 'bot_id' => $id, 'username' => $username,
                    'is_bot' => ($decoded['result']['is_bot'] ?? false) === true];
            }
        }
        return $this->normalizeAdministrativeFailure($response);
    }

    /** Explicitly register the configured HTTPS webhook and secret-token header. */
    public function setWebhook(): array
    {
        $status = telegram_configuration_status($this->config);
        $url = trim((string)($this->config['webhook_url'] ?? ''));
        $secret = (string)($this->config['webhook_secret'] ?? '');
        if (!$status['webhook_ready'] || parse_url($url, PHP_URL_QUERY) !== null
            || parse_url($url, PHP_URL_FRAGMENT) !== null
            || preg_match('/^[A-Za-z0-9_-]{32,256}$/', $secret) !== 1) {
            return $this->failure(0, 'configuration_incomplete', 'Telegram webhook configuration is incomplete.');
        }
        $response = $this->performRequest('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message'],
            'drop_pending_updates' => false,
        ]);
        $decoded = json_decode((string)($response['body'] ?? ''), true);
        if ((int)($response['http_status'] ?? 0) >= 200 && (int)($response['http_status'] ?? 0) < 300
            && is_array($decoded) && ($decoded['ok'] ?? false) === true) {
            return ['ok' => true, 'registered' => true];
        }
        return $this->normalizeAdministrativeFailure($response);
    }

    /** Return only the non-secret operational fields required for readiness validation. */
    public function getWebhookInfo(): array
    {
        $response = $this->performRequest('getWebhookInfo', []);
        $decoded = json_decode((string)($response['body'] ?? ''), true);
        if ((int)($response['http_status'] ?? 0) >= 200 && (int)($response['http_status'] ?? 0) < 300
            && is_array($decoded) && ($decoded['ok'] ?? false) === true && is_array($decoded['result'] ?? null)) {
            $result = $decoded['result'];
            $url = trim((string)($result['url'] ?? ''));
            $configuredUrl = trim((string)($this->config['webhook_url'] ?? ''));
            if ($url === '' || !hash_equals($configuredUrl, $url)) $url = '';
            return [
                'ok' => true,
                'url' => $url,
                'pending_update_count' => max(0, (int)($result['pending_update_count'] ?? 0)),
                'last_error_date' => isset($result['last_error_date']) ? max(0, (int)$result['last_error_date']) : null,
                'last_error_message' => isset($result['last_error_message'])
                    ? telegram_redact_secrets(strip_tags((string)$result['last_error_message']), $this->config) : null,
                'max_connections' => isset($result['max_connections']) ? max(0, (int)$result['max_connections']) : null,
            ];
        }
        return $this->normalizeAdministrativeFailure($response);
    }

    /** Explicit rollback operation. Existing queued updates are retained. */
    public function deleteWebhook(): array
    {
        $response = $this->performRequest('deleteWebhook', ['drop_pending_updates' => false]);
        $decoded = json_decode((string)($response['body'] ?? ''), true);
        if ((int)($response['http_status'] ?? 0) >= 200 && (int)($response['http_status'] ?? 0) < 300
            && is_array($decoded) && ($decoded['ok'] ?? false) === true) {
            return ['ok' => true, 'removed' => true];
        }
        return $this->normalizeAdministrativeFailure($response);
    }

    private function request(string $method, array $payload): array
    {
        if (!telegram_configuration_status($this->config)['send_ready']
            || !telegram_delivery_runtime_active($this->config)) {
            return $this->failure(0, 'configuration_incomplete', 'Telegram delivery is not configured.');
        }
        $chatId = telegram_normalize_integer_id($payload['chat_id'] ?? '');
        if ($chatId === null) return $this->failure(400, 'invalid_chat', 'Telegram destination is invalid.', false, true);
        $payload['chat_id'] = $chatId;
        unset($payload['allow_paid_broadcast']);
        return $this->normalizeResponse($this->performRequest($method, $payload));
    }

    private function performRequest(string $method, array $payload): array
    {
        $status = telegram_configuration_status($this->config);
        if (!$status['send_ready']) {
            return ['http_status' => 0, 'body' => '', 'transport_error' => 'configuration_incomplete'];
        }
        if (!in_array($method, ['sendMessage', 'sendContact', 'getMe', 'setWebhook', 'getWebhookInfo', 'deleteWebhook'], true)) {
            return ['http_status' => 0, 'body' => '', 'transport_error' => 'unsupported_method'];
        }
        unset($payload['allow_paid_broadcast']);
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, 12);
        } catch (JsonException) {
            return ['http_status' => 400, 'body' => '', 'transport_error' => 'invalid_request'];
        }
        if (strlen($body) > 65536) return ['http_status' => 400, 'body' => '', 'transport_error' => 'invalid_request'];
        $endpoint = rtrim((string)$this->config['api_base_url'], '/') . '/bot' . $this->config['bot_token'] . '/' . $method;
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        try {
            $response = $this->transport
                ? ($this->transport)($endpoint, $headers, $body, $this->config)
                : $this->curlRequest($endpoint, $headers, $body);
        } catch (Throwable $e) {
            error_log('[LCS Telegram] transport error: ' . telegram_redact_secrets($e->getMessage(), $this->config));
            return ['http_status' => 0, 'body' => '', 'transport_error' => 'transport_error'];
        }
        return is_array($response) ? $response : ['http_status' => 0, 'body' => '', 'transport_error' => 'transport_error'];
    }

    private function normalizeAdministrativeFailure(array $response): array
    {
        $httpStatus = (int)($response['http_status'] ?? 0);
        $decoded = json_decode((string)($response['body'] ?? ''), true);
        $description = is_array($decoded) ? trim((string)($decoded['description'] ?? '')) : '';
        $transport = trim((string)($response['transport_error'] ?? ''));
        $code = $httpStatus === 401 ? 'invalid_bot_configuration'
            : ($transport === 'configuration_incomplete' ? 'configuration_incomplete'
                : ($transport !== '' ? 'transport_error' : ($httpStatus > 0 ? 'telegram_' . $httpStatus : 'provider_error')));
        if ($description !== '') {
            error_log('[LCS Telegram] administrative request failed: '
                . telegram_redact_secrets($description, $this->config));
        }
        return ['ok' => false, 'error_code' => $code,
            'error_message' => $httpStatus === 401
                ? 'Telegram rejected the bot configuration.'
                : 'Telegram administrative request failed.'];
    }

    private function normalizeResponse(array $response): array
    {
        $httpStatus = (int)($response['http_status'] ?? 0);
        $transportError = trim((string)($response['transport_error'] ?? ''));
        $decoded = json_decode((string)($response['body'] ?? ''), true);
        if ($httpStatus >= 200 && $httpStatus < 300 && is_array($decoded) && ($decoded['ok'] ?? false) === true) {
            $messageId = telegram_normalize_integer_id($decoded['result']['message_id'] ?? '');
            if ($messageId !== null) {
                return ['ok' => true, 'transient' => false, 'status' => 'sent', 'http_status' => $httpStatus,
                    'provider_message_id' => $messageId, 'retry_after' => null, 'connection_inaccessible' => false,
                    'error_code' => null, 'error_message' => null];
            }
        }
        $description = is_array($decoded) ? trim((string)($decoded['description'] ?? '')) : '';
        $providerCode = is_array($decoded) ? (int)($decoded['error_code'] ?? $httpStatus) : $httpStatus;
        $search = strtolower($description . ' ' . $transportError);
        $blocked = str_contains($search, 'bot was blocked') || str_contains($search, 'user is deactivated')
            || str_contains($search, 'bot was kicked');
        $invalidChat = str_contains($search, 'chat not found') || str_contains($search, 'peer_id_invalid');
        $unauthorized = $httpStatus === 401 || $providerCode === 401;
        $transient = telegram_failure_is_transient($httpStatus, $transportError !== '');
        if ($blocked || $invalidChat || $unauthorized || ($httpStatus >= 400 && $httpStatus < 500 && $httpStatus !== 408 && $httpStatus !== 429)) {
            $transient = false;
        }
        $code = $blocked ? 'bot_blocked' : ($invalidChat ? 'invalid_chat' : ($unauthorized ? 'invalid_bot_configuration'
            : ($transportError !== '' ? 'transport_error' : ($providerCode > 0 ? 'telegram_' . $providerCode : 'provider_error'))));
        $retryAfter = is_array($decoded) ? (int)($decoded['parameters']['retry_after'] ?? 0) : 0;
        if ($retryAfter < 1 || $httpStatus !== 429) $retryAfter = 0;
        if ($description !== '' || $transportError !== '') {
            error_log('[LCS Telegram] provider error ' . $code . ': '
                . telegram_redact_secrets($description !== '' ? $description : $transportError, $this->config));
        }
        return $this->failure($httpStatus, $code,
            $transient ? 'Telegram delivery is temporarily unavailable.' : 'Telegram rejected the request.',
            $transient, $blocked || $invalidChat, $retryAfter ?: null);
    }

    private function failure(
        int $httpStatus,
        string $code,
        string $message,
        bool $transient = false,
        bool $inaccessible = false,
        ?int $retryAfter = null
    ): array {
        return ['ok' => false, 'transient' => $transient, 'status' => $transient ? 'retry' : 'failed',
            'http_status' => $httpStatus, 'provider_message_id' => null, 'retry_after' => $retryAfter,
            'connection_inaccessible' => $inaccessible, 'error_code' => $code,
            'error_message' => telegram_redact_secrets($message, $this->config)];
    }

    private function curlRequest(string $endpoint, array $headers, string $body): array
    {
        $ch = curl_init($endpoint);
        if ($ch === false) throw new RuntimeException('Unable to initialize HTTP transport.');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => (int)$this->config['connect_timeout'],
            CURLOPT_TIMEOUT => (int)$this->config['request_timeout'],
        ]);
        $responseBody = curl_exec($ch);
        $result = ['http_status' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'body' => $responseBody === false ? '' : $responseBody,
            'transport_error' => $responseBody === false ? curl_error($ch) : ''];
        curl_close($ch);
        return $result;
    }
}

final class TelegramMockProvider implements TelegramBotProvider
{
    private array $outcomes;
    public array $requests = [];

    public function __construct(array $outcomes = ['success'])
    {
        $this->outcomes = $outcomes;
    }

    public function sendMessage(int|string $chatId, string $text, array $options = []): array
    {
        $this->requests[] = ['method' => 'sendMessage', 'chat_id' => (string)$chatId, 'text' => $text, 'options' => $options];
        return $this->outcome();
    }

    public function sendContact(int|string $chatId, string $phoneNumber, string $firstName, string $lastName = ''): array
    {
        $this->requests[] = compact('chatId', 'phoneNumber', 'firstName', 'lastName') + ['method' => 'sendContact'];
        return $this->outcome();
    }

    private function outcome(): array
    {
        $outcome = array_shift($this->outcomes) ?? 'success';
        if (is_array($outcome)) return $outcome;
        return match ($outcome) {
            'success' => ['ok' => true, 'transient' => false, 'status' => 'sent', 'http_status' => 200,
                'provider_message_id' => (string)random_int(1, 2147483647), 'retry_after' => null,
                'connection_inaccessible' => false, 'error_code' => null, 'error_message' => null],
            'rate_limit' => ['ok' => false, 'transient' => true, 'status' => 'retry', 'http_status' => 429,
                'provider_message_id' => null, 'retry_after' => 7, 'connection_inaccessible' => false,
                'error_code' => 'telegram_429', 'error_message' => 'Telegram delivery is temporarily unavailable.'],
            'network', 'transient' => ['ok' => false, 'transient' => true, 'status' => 'retry',
                'http_status' => $outcome === 'network' ? 0 : 503, 'provider_message_id' => null,
                'retry_after' => null, 'connection_inaccessible' => false, 'error_code' => 'transport_error',
                'error_message' => 'Telegram delivery is temporarily unavailable.'],
            'blocked', 'invalid_chat' => ['ok' => false, 'transient' => false, 'status' => 'failed',
                'http_status' => $outcome === 'blocked' ? 403 : 400, 'provider_message_id' => null,
                'retry_after' => null, 'connection_inaccessible' => true,
                'error_code' => $outcome === 'blocked' ? 'bot_blocked' : 'invalid_chat',
                'error_message' => 'Telegram rejected the request.'],
            default => ['ok' => false, 'transient' => false, 'status' => 'failed', 'http_status' => 400,
                'provider_message_id' => null, 'retry_after' => null, 'connection_inaccessible' => false,
                'error_code' => 'invalid_request', 'error_message' => 'Telegram rejected the request.'],
        };
    }
}
