<?php

declare(strict_types=1);

/** Public page names only; this list does not grant access or cache eligibility. */
const LCS_PUBLIC_PAGE_ROUTES = [
    'dashboard', 'courses', 'course', 'calendar', 'settings', 'notifications',
    'profile', 'privacy-policy', 'chat', 'chat_ai', 'chat_dm', 'chat_group',
    'assignment_tests', 'assignment_test', 'assignment_test_receipt',
    'assignment_test_submissions', 'assignment_test_audit', 'online_quiz',
    'online_quiz_edit', 'online_quiz_monitor', 'online_quiz_result', 'forgot_password',
];

/** Normalize a trusted scope-relative page reference; never widen destinations. */
function lcs_navigation_url(string $url): string
{
    if (!preg_match('/^([a-z_\-]+)\.php(?=$|[?#])/', $url, $match)) return $url;
    if (!in_array($match[1], LCS_PUBLIC_PAGE_ROUTES, true)) return $url;
    return $match[1] . substr($url, strlen($match[0]));
}

/** Apache/PHP can expose either the internal script or public name in the shell. */
function lcs_current_page(): string
{
    return lcs_navigation_url(basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '')));
}

/** Accept both issued calendar return forms without accepting another origin. */
function lcs_calendar_return_url(string $url): string
{
    if (!preg_match('/^calendar(?:\.php)?(?:\?[^#\r\n\\\\]*)?(?:#[^\r\n\\\\]*)?$/D', $url)) return 'calendar';
    return lcs_navigation_url($url);
}
