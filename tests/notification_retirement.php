<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
ini_set('session.save_path', sys_get_temp_dir());
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . "\n";
    $checks++;
};

require_once $root . '/includes/reminders.php';
$check(function_exists('reminder_run_worker') && function_exists('telegram_run_worker'),
    'reminder and Telegram worker load without retired provider files');
$check(function_exists('lcs_notification_fanout') && function_exists('lcs_signed_link_internal_path'),
    'neutral fan-out and signed links remain available');
$check(!function_exists('whatsapp_run_worker') && !function_exists('whatsapp_fanout_notification'),
    'retired product code is not loaded by the notification runtime');

$envelope = lcs_notification_envelope('calendar_reminder', 1, [], 'calendar', 1, 'retirement-check');
$result = lcs_notification_fanout_optional($envelope, [
    'web_email' => static fn(): array => ['status' => 'sent'],
    'telegram' => static function (): array { throw new RuntimeException('synthetic failure'); },
]);
$check(($result['channels']['web_email']['status'] ?? '') === 'sent'
    && ($result['channels']['telegram']['status'] ?? '') === 'skipped',
    'optional-channel failure cannot interrupt existing delivery');

foreach (['includes/reminders.php', 'includes/assignment_test.php', 'includes/online_quiz.php',
    'includes/online_quiz_integration.php', 'includes/notifications/fanout.php', 'notify_cron.php'] as $file) {
    $source = file_get_contents($root . '/' . $file);
    $check(is_string($source) && !preg_match('/(?:require|include)(?:_once)?\s*\(?[^;]*whatsapp/i', $source),
        $file . ' has no retired runtime include');
}
$worker = file_get_contents($root . '/includes/reminders.php');
$check(is_string($worker) && !str_contains($worker, 'whatsapp_run_worker(')
    && !str_contains($worker, 'FROM whatsapp_delivery_jobs'),
    'worker does no retired provider or table work');
$check(is_file($root . '/whatsapp_notifications_migration.sql')
    && is_file($root . '/whatsapp_settings_migration.sql'),
    'historical additive migrations remain for schema history; no table is dropped');
$settings = file_get_contents($root . '/settings.php');
$policy = file_get_contents($root . '/privacy-policy.php');
$check(is_string($settings) && str_contains($settings, 'id="legal"')
    && str_contains($settings, 'href="privacy-policy"'),
    'Settings exposes the existing public Privacy Policy route');
$check(is_string($policy) && !str_contains($policy, 'require_login('),
    'Privacy Policy remains public');

echo "Notification retirement assertions passed: $checks\n";
