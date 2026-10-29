<?php

declare(strict_types=1);

/** Small, server-authoritative helpers for the optional PWA capabilities. */
function pwa_csrf_token(): string
{
    if (empty($_SESSION['pwa_csrf'])) $_SESSION['pwa_csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['pwa_csrf'];
}

function pwa_verify_csrf(?string $token): bool
{
    return is_string($token) && $token !== '' && !empty($_SESSION['pwa_csrf'])
        && hash_equals((string)$_SESSION['pwa_csrf'], $token);
}

function pwa_vapid_public_key(): string
{
    if (!in_array(strtolower(trim((string)(getenv('WEB_PUSH_ENABLED') ?: '0'))), ['1', 'true', 'yes', 'on'], true)) return '';
    return trim((string)(getenv('WEB_PUSH_VAPID_PUBLIC_KEY') ?: ''));
}

function pwa_user_cache_key(int $userId): string
{
    $secret = (string)(getenv('APP_KEY') ?: getenv('LCS_LINK_SIGNING_KEY') ?: 'lcs-local-cache-scope');
    return substr(hash_hmac('sha256', (string)$userId, $secret), 0, 24);
}

/**
 * Explicitly opt an already-authorized, read-oriented HTML response into the
 * service worker's per-user page cache. Route code must call this only after
 * its normal authentication and object-level authorization checks succeed.
 */
function pwa_mark_cacheable_page(int $userId): void
{
    if ($userId <= 0 || strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET' || headers_sent()) return;
    // Never persist the acknowledgement dialog or its one-time CSRF value.
    // After acknowledgement, the next safe navigation becomes eligible.
    require_once __DIR__ . '/privacy_policy.php';
    if (!privacy_policy_has_acknowledged($userId)) return;
    header('X-LCS-PWA-Cache: user-page');
    header('X-LCS-PWA-User-Key: ' . pwa_user_cache_key($userId));
    header('Cache-Control: no-cache, must-revalidate, max-age=0');
    header('Vary: Cookie', false);
}
