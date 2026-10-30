<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$checks = 0;
function pwa_check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . "\n";
    $checks++;
}
function pwa_text(string $path): string
{
    $value = file_get_contents(dirname(__DIR__) . '/' . $path);
    if ($value === false) throw new RuntimeException('Missing fixture: ' . $path);
    return $value;
}

$manifest = json_decode(pwa_text('assets/favicon/site.webmanifest'), true, 32, JSON_THROW_ON_ERROR);
pwa_check($manifest['name'] === 'KWASU Lecture Capturing System' && $manifest['short_name'] === 'KWASU LCS', 'manifest has the correct LCS expansion');
pwa_check($manifest['display'] === 'standalone', 'manifest requests standalone display');
pwa_check($manifest['start_url'] === '../../dashboard?source=pwa' && $manifest['scope'] === '../../', 'canonical start URL stays inside manifest scope');
pwa_check(count($manifest['icons']) >= 2 && $manifest['icons'][0]['sizes'] === '192x192' && $manifest['icons'][1]['sizes'] === '512x512', 'manifest declares existing install icons');
$htaccess = pwa_text('.htaccess');
pwa_check(str_contains($htaccess, 'application/manifest+json') && str_contains($htaccess, 'no-cache, must-revalidate'), 'manifest and service worker use correct MIME/revalidation headers');
pwa_check(array_column($manifest['shortcuts'], 'name') === ['Courses', 'Calendar', 'AI Study Assistant', 'Assessment and Chat'], 'manifest exposes four approved shortcuts');
foreach ($manifest['shortcuts'] as $shortcut) pwa_check(str_starts_with($shortcut['url'], '../../'), 'shortcut remains in LCS scope: ' . $shortcut['name']);

$head = pwa_text('partials/head.php');
pwa_check(str_contains($head, 'site.webmanifest') && str_contains($head, 'apple-mobile-web-app-capable'), 'shared head links install metadata');
pwa_check(str_contains($head, 'viewport-fit=cover'), 'shared viewport enables safe-area insets');
pwa_check(str_contains($head, 'assets/pwa.js') && str_contains($head, 'window.LCSPWA'), 'shared head registers PWA runtime configuration');
pwa_check(str_contains($head, '$__pwaNeedsCsrf') && str_contains($head, "lcs_current_page() === 'settings'"), 'PWA CSRF still belongs only to Settings');

$sw = pwa_text('sw.js');
pwa_check((bool)preg_match("/const VERSION = '[^']+';/", $sw), 'service worker has explicit cache versioning');
pwa_check(str_contains($sw, "const VERSION = '2026.10.29-3'"), 'static cache version installs network-first navigation and connectivity notices');
pwa_check(str_contains($sw, "const PAGE_CACHE_VERSION = '2026.09.27-2'"), 'old course page snapshots with retired controls are invalidated');
pwa_check(str_contains($sw, 'PAGE_CACHE_VERSION') && str_contains($sw, 'PAGE_MAX_AGE_MS') && str_contains($sw, 'PAGE_MAX_ENTRIES'), 'page cache version, age, and size are bounded constants');
pwa_check(str_contains($sw, 'UNCACHED_NAVIGATION_TIMEOUT_MS = 12000') && str_contains($sw, 'Only use a saved page immediately when the browser reports offline'), 'online navigation waits for a fresh server response before using read-only cache fallback');
pwa_check(str_contains($sw, 'return result.response || offlineNavigationResponse()') && str_contains($sw, 'OFFLINE_URL'), 'failed approved navigation uses the public offline fallback');
pwa_check(str_contains($sw, 'canonicalNavigationUrl') && str_contains($sw, "leaf === 'dashboard'") && str_contains($sw, "leaf === 'assignment_tests'"), 'authenticated page caching uses the same strict route and query allowlist');
pwa_check(str_contains($sw, "leaf === 'chat' || leaf === 'notifications'") && str_contains($sw, "leaf === 'chat_group'") && str_contains($sw, "leaf === 'chat_dm'"), 'authorized chat and notification screens use the strict per-user page-cache allowlist');
pwa_check(!str_contains($sw, "leaf === 'settings'") && !str_contains($sw, "leaf === 'online_quiz_take'"), 'settings and secure quiz routes remain excluded from the page allowlist');
pwa_check(str_contains($sw, 'isStaticAsset') && str_contains($sw, 'url.pathname.startsWith(`${BASE_PATH}assets/`)'), 'only public static assets enter automatic runtime cache');
pwa_check(!str_contains($sw, 'isExplicitResource') && !str_contains($sw, 'CACHE_RESOURCE') && !str_contains($sw, 'REMOVE_RESOURCE'), 'service worker no longer saves course materials');
pwa_check(str_contains($sw, 'RETIRED_RESOURCE_PREFIX') && str_contains($sw, 'name.startsWith(RETIRED_RESOURCE_PREFIX)'), 'retired material caches are cleaned on activation and private-data reset');
pwa_check(!str_contains($sw, "caches.put(event.request") && !str_contains($sw, "cache.put(event.request.clone"), 'service worker does not generically cache authenticated requests');
pwa_check(!str_contains($sw, "addEventListener('sync'"), 'sensitive submissions are not background replayed');
pwa_check(str_contains($sw, "[401, 403].includes(response.status)") && str_contains($sw, "[404, 410].includes(response.status)") && str_contains($sw, 'response.status >= 500'), 'session loss clears private state, missing resources invalidate, and server errors preserve the last valid page');
pwa_check(str_contains($sw, "response.headers.get('X-LCS-PWA-Cache')") && str_contains($sw, "response.headers.get('X-LCS-PWA-User-Key')"), 'cache population requires server opt-in and matching opaque user scope');
pwa_check(str_contains($sw, "headers.delete('Set-Cookie')") && str_contains($sw, 'sanitizeSnapshotHtml'), 'cached HTML strips cookie headers and reusable mutation tokens');
pwa_check(str_contains($sw, 'CONTEXT_CACHE') && str_contains($sw, 'clearAllPrivateData') && str_contains($sw, 'setActiveUserKey'), 'opaque user scope survives worker restart and is cleared atomically');
pwa_check(str_contains($sw, 'PAGE_CACHE_REFRESHED') && str_contains($sw, 'event.waitUntil(refresh.then'), 'offline snapshots refresh in the background and notify the client when fresh');
pwa_check(str_contains($sw, 'online_quiz_take') && str_contains($sw, 'push_subscribe'), 'push destinations deny sensitive execution endpoints');
pwa_check(str_contains($sw, "data.type === 'SKIP_WAITING'") && !str_contains($sw, "addEventListener('install', () => self.skipWaiting"), 'updates require explicit activation');

$runtime = pwa_text('assets/pwa.js');
pwa_check(str_contains($runtime, "navigator.serviceWorker.register('sw.js'"), 'runtime registers root service worker');
pwa_check(str_contains($runtime, 'updateIsSensitive') && str_contains($runtime, 'online_quiz_take'), 'runtime suppresses forced updates during secure work');
pwa_check(str_contains($runtime, 'navigator.onLine') && str_contains($runtime, "fetch('pwa_ping.php"), 'connectivity uses both browser signal and backend probe');
pwa_check(str_contains($runtime, 'performance.now()') && str_contains($runtime, 'setConnectivityState'), 'poor-connection state incorporates request timing and uses a persistent non-modal status');
pwa_check(str_contains($runtime, 'SUCCESS_NOTICE_DELAY_MS = 1000') && str_contains($runtime, 'SUCCESS_NOTICE_DURATION_MS = 3000'), 'back-online notice is delayed one second and auto-dismisses after three seconds');
pwa_check(str_contains($runtime, 'POOR_CONNECTION_DELAY_MS = 30000') && str_contains($runtime, 'poorConnectionCandidate'), 'poor-connection notice waits 30 seconds and stays until recovery');
pwa_check(str_contains($runtime, 'applySnapshotMode') && str_contains($runtime, 'data-pwa-readonly'), 'saved HTML is explicitly read-only until fresh content is available');
pwa_check(str_contains($runtime, "data.type === 'PAGE_CACHE_REFRESHED'") && str_contains($runtime, "'Back online.'") && str_contains($runtime, "'Refresh'"), 'background refresh offers a delayed back-online notice and user-controlled update');
pwa_check(!str_contains($runtime, 'scheduleFreshSnapshotReload') && !str_contains($runtime, 'snapshotRefreshTimer'), 'background refresh and reconnection never schedule automatic snapshot reloads');
pwa_check(str_contains($runtime, 'PAGE_CACHE_AUTH_CHANGED') && str_contains($runtime, '!reloadForWorkerUpdate'), 'session changes stay authoritative and initial worker claim does not reload');
pwa_check(str_contains($runtime, 'beforeinstallprompt') && str_contains($runtime, 'window.navigator.standalone'), 'install UX progressively supports Chromium and iOS state');
pwa_check(!str_contains($runtime, 'data-pwa-share') && !str_contains(pwa_text('course.php'), 'Share Course'), 'removed course sharing has no dormant runtime handler');
pwa_check(str_contains($runtime, 'navigator.setAppBadge') && str_contains($runtime, 'navigator.clearAppBadge'), 'Badging API is optional and guarded');
pwa_check(str_contains($runtime, 'Notification.requestPermission') && str_contains($runtime, "method: 'POST'"), 'push permission is user initiated and subscription uses POST');
pwa_check(!str_contains($runtime, 'enhanceOfflineResources') && !str_contains($runtime, 'CACHE_RESOURCE'), 'runtime never adds offline-copy controls');
pwa_check(!str_contains(pwa_text('assets/pwa.css'), '.pwa-offline-btn'), 'offline-copy control styles are removed');
pwa_check(str_contains($runtime, 'restoreStandaloneState') && str_contains($runtime, 'safePersistedPath'), 'standalone launch restores only allowlisted non-sensitive app state');

$push = pwa_text('push_subscribe.php');
pwa_check(str_contains($push, "REQUEST_METHOD") && str_contains($push, "!== 'POST'"), 'push subscription endpoint is POST only');
pwa_check(str_contains($push, 'current_user()') && str_contains($push, 'pwa_verify_csrf'), 'push subscription requires authentication and CSRF');
pwa_check(!str_contains($push, 'CREATE TABLE'), 'request path never mutates schema');
pwa_check(!str_contains($push, "getMessage()") || str_contains($push, 'error_log'), 'ordinary push response does not expose exception detail');

$logout = pwa_text('logout.php');
pwa_check(str_contains($logout, 'CLEAR_PRIVATE_DATA') && str_contains($logout, "caches.delete"), 'logout clears user-specific offline state');
pwa_check(str_contains($logout, "name.indexOf('lcs-pages-')") && str_contains($logout, 'lcs-private-context-v1'), 'logout directly clears page namespaces and persisted user context');
$pwaHelpers = pwa_text('includes/pwa.php');
pwa_check(str_contains($pwaHelpers, 'pwa_mark_cacheable_page') && str_contains($pwaHelpers, 'X-LCS-PWA-Cache: user-page'), 'server has a centralized authorized page opt-in helper');
foreach (['dashboard.php', 'courses.php', 'course.php', 'calendar.php', 'chat.php', 'chat_dm.php', 'chat_group.php', 'notifications.php'] as $page) {
    pwa_check(str_contains(pwa_text($page), 'pwa_mark_cacheable_page'), $page . ' explicitly opts in after its authorization path');
}
pwa_check(str_contains(pwa_text('assignment_tests.php'), 'if (!$courses && !$editItem)') && str_contains(pwa_text('assignment_tests.php'), 'pwa_mark_cacheable_page'), 'assessment caching is limited to the learner read view');
foreach (['settings.php', 'online_quiz_take.php', 'online_quiz_api.php', 'assignment_test_submission.php', 'assignment_test_mark.php', 'download.php', 'go.php'] as $page) {
    pwa_check(!str_contains(pwa_text($page), 'pwa_mark_cacheable_page'), $page . ' never opts into HTML page caching');
}
$css = pwa_text('assets/pwa.css') . pwa_text('assets/style.css') . pwa_text('assets/app-header.css');
pwa_check(str_contains($css, 'safe-area-inset-top') && str_contains($css, 'safe-area-inset-bottom') && str_contains($css, 'safe-area-inset-left') && str_contains($css, 'safe-area-inset-right'), 'standalone layout handles all safe-area edges');
pwa_check(str_contains($css, 'prefers-reduced-motion'), 'new interaction UI respects reduced motion');
pwa_check(str_contains($css, '.pwa-connectivity-banner') && str_contains($css, 'safe-area-inset-right'), 'persistent connection status avoids the mobile bottom navigation and floating action area');
pwa_check(is_file($root . '/offline.html') && is_file($root . '/assets/offline.js'), 'minimal standalone offline page and action script exist');
pwa_check(!str_contains($runtime, 'offline.html') && !str_contains($pwaHelpers, 'offline.html')
    && !str_contains($head, 'offline.html') && !str_contains(pwa_text('assets/favicon/site.webmanifest'), 'offline.html')
    && !str_contains(pwa_text('assets/pwa.css'), '.pwa-offline-page'), 'PWA bootstrap, manifest, and stylesheet have no standalone-page dependency');
pwa_check(str_contains($runtime, 'Offline — live information is unavailable.'), 'the in-app offline status remains available');
$envExample = pwa_text('.env.example');
$envProduction = pwa_text('.env.production.example');
pwa_check(str_contains($envExample, 'WEB_PUSH_ENABLED=0') && str_contains($envProduction, 'WEB_PUSH_ENABLED=0'), 'Web Push remains disabled in both configuration examples');

echo "PWA checks passed: $checks\n";
