<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/notifications/fanout.php';

$checks = 0;
function ni_check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . "\n";
    $checks++;
}

$definitions = lcs_notification_type_definitions();
ni_check(count($definitions) === 12, 'generic registry contains exactly the existing optional-channel notification types');
ni_check(lcs_notification_category('calendar_reminder') === LCS_NOTIFICATION_CATEGORY_CALENDAR_LECTURE,
    'calendar reminder has a stable generic category');
ni_check(lcs_notification_category('assignment_test_returned') === LCS_NOTIFICATION_CATEGORY_ASSIGNMENT_TEST,
    'Assignment/Test result event has a stable generic category');
ni_check(lcs_notification_category('online_quiz_result_available') === LCS_NOTIFICATION_CATEGORY_ONLINE_QUIZ_TEST,
    'Online Quiz/Test result event has a stable generic category');
ni_check(!lcs_notification_type_is_supported('invented_event'), 'unsupported application events are not invented');

$notice = lcs_notification_envelope('assignment_test_published', 42, ['title' => 'Fixture'], 'assignment_test', 7, 'published');
ni_check(is_array($notice) && $notice['user_id'] === 42 && $notice['logical_key'] === 'published',
    'valid logical notice becomes a channel-neutral envelope');
ni_check(lcs_notification_envelope('invented_event', 42, [], 'assignment_test', 7, 'x') === null,
    'unknown type fails closed before optional fan-out');

$handled = 0;
$fanout = lcs_notification_fanout_optional($notice, [
    'first' => static function (array $candidate) use (&$handled): array {
        $handled++;
        return ['status' => 'queued', 'logical_key' => $candidate['logical_key']];
    },
    'broken' => static function (): array {
        throw new RuntimeException('synthetic optional-channel failure');
    },
    'second' => static function () use (&$handled): array {
        $handled++;
        return ['status' => 'existing'];
    },
]);
ni_check($fanout['status'] === 'complete' && $fanout['channels']['first']['status'] === 'queued',
    'generic fan-out preserves successful adapter results');
ni_check($fanout['channels']['broken'] === ['status' => 'skipped', 'reason' => 'optional_channel_failed'],
    'optional-channel failure is contained without leaking exception details');
ni_check($fanout['channels']['second']['status'] === 'existing' && $handled === 2,
    'one failed optional channel does not block later channels');
ni_check(lcs_notification_fanout_optional(['notification_type' => 'invented_event'], [])['status'] === 'skipped',
    'fan-out rejects unsupported logical notification types');

echo "Completed $checks generic notification infrastructure checks.\n";
