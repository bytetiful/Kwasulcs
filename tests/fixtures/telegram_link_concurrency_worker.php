<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/includes/telegram/linking.php';
$input = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($input)) exit(2);
$result = telegram_consume_link_token((string)($input['token'] ?? ''), (string)($input['telegram_user_id'] ?? ''),
    (string)($input['telegram_chat_id'] ?? ''), 'private', 'Concurrent Fixture');
echo json_encode(['ok' => (bool)($result['ok'] ?? false), 'state' => (string)($result['state'] ?? 'invalid')]);
