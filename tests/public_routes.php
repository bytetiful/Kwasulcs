<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('session.save_path', sys_get_temp_dir());
require_once dirname(__DIR__) . '/includes/notifications/signed_links.php';
require_once dirname(__DIR__) . '/includes/telegram/notifications.php';
$checks = 0;
function route_check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; echo "PASS: $label\n"; }
foreach (LCS_PUBLIC_PAGE_ROUTES as $page) {
    route_check(is_file(dirname(__DIR__) . '/' . $page . '.php'), "$page keeps its internal PHP file");
    route_check(lcs_navigation_url($page . '.php?id=12&date=2026-10-13#details') === $page . '?id=12&date=2026-10-13#details', "$page preserves query and fragment");
    $_SERVER['SCRIPT_NAME'] = '/LMS-portal/' . $page . '.php';
    route_check(lcs_current_page() === $page, "$page shell recognizes the internal script");
}
$htaccess = file_get_contents(dirname(__DIR__) . '/.htaccess');
preg_match_all('/RewriteRule \^\(([^)]+)\)/', $htaccess, $apacheLists);
$expected = LCS_PUBLIC_PAGE_ROUTES; sort($expected);
foreach ($apacheLists[1] as $list) { $actual = explode('|', $list); sort($actual); route_check($actual === $expected, 'Apache route allowlist agrees with the PHP page vocabulary'); }
$worker = file_get_contents(dirname(__DIR__) . '/sw.js');
preg_match('/PUBLIC_PAGE_ROUTES = new Set\(\[([\s\S]*?)\]\)/', $worker, $workerList);
preg_match_all("/'([^']+)'/", $workerList[1], $workerNames);
$actual = $workerNames[1]; sort($actual);
route_check($actual === $expected, 'service-worker public page vocabulary agrees with the Apache/PHP allowlist');
foreach (['download.php?id=1', 'go.php?ref=signed', 'online_quiz_take.php?id=9', 'online_quiz_api.php', 'assignment_test_action.php', 'logout.php', 'index.php', 'admin.php', 'https://other.test/course.php', '//other.test/course.php', '../course.php'] as $url) {
    route_check(lcs_navigation_url($url) === $url, "unapproved reference stays unchanged: $url");
}
route_check(lcs_calendar_return_url('calendar.php?view=week&date=2026-10-13') === 'calendar?view=week&date=2026-10-13', 'issued calendar return path stays usable');
route_check(lcs_calendar_return_url('calendar?view=day#event') === 'calendar?view=day#event', 'clean calendar return path stays usable');
foreach (['https://evil.test/calendar', '//evil.test/calendar', 'calendar.php.evil', "calendar?x=1\r\nLocation: evil", 'calendar/../logout.php'] as $url) route_check(lcs_calendar_return_url($url) === 'calendar', 'calendar return rejects foreign/malformed path');

$config = ['enabled' => true, 'bot_token' => '123456:fixture', 'base_url' => 'https://lcs.test/LMS-portal', 'link_signing_key' => 'route-fixture-only-signing-key-at-least-32-bytes'];
$expires = time() + 3600;
foreach (['assignment_test.php?id=7', 'online_quiz.php?id=7', 'online_quiz_result.php?id=7', 'course.php?id=7', 'assignment_tests.php', 'dashboard.php', 'calendar.php?date=2026-10-13&view=day'] as $legacy) {
    $clean = lcs_navigation_url($legacy);
    $old = lcs_signed_link_internal_path($legacy, 42, 'calendar', 7, $config, $expires);
    $new = lcs_signed_link_internal_path($clean, 42, 'calendar', 7, $config, $expires);
    route_check($old !== null && $old === $new, "$legacy and $clean produce identical signed typed targets");
    parse_str((string)parse_url($old, PHP_URL_QUERY), $query);
    $payload = lcs_signed_link_resolve_reference($query['ref'], $config);
    route_check(is_array($payload) && $payload['uid'] === 42 && $payload['v'] === 1, 'already-issued v1 reference verifies unchanged');
    route_check(lcs_signed_link_authorized_destination($payload, ['id' => 99]) === null, 'signed recipient check remains authoritative');
}
$payload = lcs_signed_link_target('dashboard.php');
route_check(lcs_signed_link_authorized_destination($payload + ['uid' => 42], ['id' => 42]) === 'dashboard', 'authorized signed dashboard resolves to canonical page');
$context = ['course_code' => 'CSC 402', 'title' => 'Fixture assessment', 'action_url' => 'assignment_test?id=7', 'due_at' => '2026-10-14 10:00:00'];
$rendered = telegram_render_notification('assignment_test_published', 42, $context, 'assignment_test', 7, $config);
route_check(is_array($rendered), 'Telegram rendering accepts canonical application destination');
// Inspect the renderer contract without sending a provider request.
$serialized = json_encode($rendered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
route_check(str_contains($serialized, 'https://lcs.test/LMS-portal/go.php?ref='), 'Telegram keeps the authenticated signed resolver and configured HTTPS base');
$context['action_url'] = 'assignment_test.php?id=7';
route_check(is_array(telegram_render_notification('assignment_test_published', 42, $context, 'assignment_test', 7, $config)), 'queued legacy Telegram destination still renders');
route_check(lcs_signed_link_target('https://evil.test/course?id=7') === null && lcs_signed_link_target('download.php?id=7') === null, 'signed target allowlist rejects external and file endpoints');
echo "Public route assertions passed: $checks\n";
