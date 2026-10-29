<?php

declare(strict_types=1);

// Administrator operations are CLI-only and never run from an HTTP request.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("CLI execution only.\n");
}

ini_set('session.save_path', sys_get_temp_dir());
require_once __DIR__ . '/includes/telegram/live.php';
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$options = getopt('', ['readiness', 'get-me', 'set-webhook', 'delete-webhook', 'webhook-info', 'send-test', 'user-id:', 'confirm-live']);
$commands = array_values(array_filter(['readiness', 'get-me', 'set-webhook', 'delete-webhook', 'webhook-info', 'send-test'],
    static fn(string $name): bool => isset($options[$name])));

try {
    if (count($commands) !== 1) {
        throw new InvalidArgumentException('Choose exactly one command: --readiness, --get-me, --set-webhook, --delete-webhook, --webhook-info, or --send-test.');
    }
    $command = $commands[0];
    $config = telegram_config();
    if ($command === 'readiness') {
        echo json_encode(telegram_live_readiness($config), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }
    if (!isset($options['confirm-live'])) {
        throw new DomainException('External Telegram action refused. Re-run with --confirm-live only after explicit approval.');
    }

    $client = new TelegramBotApiClient($config);
    if ($command === 'get-me') {
        echo json_encode(telegram_live_verify_identity($client, $config), JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }
    if ($command === 'set-webhook') {
        telegram_live_verify_identity($client, $config);
        echo json_encode(telegram_live_register_webhook($client, $config), JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }
    if ($command === 'delete-webhook') {
        telegram_live_verify_identity($client, $config);
        echo json_encode(telegram_live_remove_webhook($client, $config), JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }
    if ($command === 'webhook-info') {
        telegram_live_verify_identity($client, $config);
        echo json_encode(telegram_live_webhook_info($client, $config), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    $userId = isset($options['user-id']) && preg_match('/^[1-9][0-9]*$/', (string)$options['user-id']) === 1
        ? (int)$options['user-id'] : 0;
    if ($userId < 1) throw new InvalidArgumentException('--send-test requires a positive --user-id.');
    telegram_live_verify_identity($client, $config);
    $result = telegram_live_send_test($userId, $client, $config, new TelegramRateLimiter($config));
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
    exit($result['status'] === 'sent' || $result['status'] === 'existing_sent' ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, 'Telegram admin: ' . telegram_redact_secrets($e->getMessage()) . "\n");
    exit(1);
}
