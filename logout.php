<?php
require_once __DIR__ . '/config.php';

// Logging out is also an internal escape route. An active secure attempt must
// return to its runner; the normal sign-out flow resumes after finalization.
$user = current_user();
if ($user) {
    require_once __DIR__ . '/includes/secure_quiz_guard.php';
    secure_quiz_enforce_route($user);
}

// Revoke the remember-me token too, not just the session — otherwise a
// "logged out" browser would silently log itself back in on the next visit
// via attempt_remember_login().
$cookie = $_COOKIE[REMEMBER_COOKIE_NAME] ?? '';
if ($cookie !== '' && str_contains($cookie, ':')) {
    [$selector] = explode(':', $cookie, 2);
    ensure_remember_tokens_table();
    db()->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]);
}
clear_remember_cookie();

session_destroy();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex"><title>Signing out — KWASU LCS</title></head>
<body><p>Signing out securely…</p>
<script>
(function () {
    'use strict';
    var destination = 'index.php';
    var jobs = [];
    try {
        localStorage.removeItem('lcs-pwa-user-key');
        localStorage.removeItem('lcs-pwa-last-safe-path');
        if ('indexedDB' in window) jobs.push(new Promise(function (resolve) {
            var request = indexedDB.deleteDatabase('lcs-pwa-resources');
            request.onsuccess = request.onerror = request.onblocked = resolve;
        }));
        if ('caches' in window) jobs.push(caches.keys().then(function (names) {
            return Promise.all(names.filter(function (name) {
                return name.indexOf('lcs-offline-') === 0
                    || name.indexOf('lcs-pages-') === 0
                    || name === 'lcs-private-context-v1';
            }).map(function (name) { return caches.delete(name); }));
        }));
        if ('serviceWorker' in navigator) jobs.push(navigator.serviceWorker.ready.then(function (registration) {
            var worker = navigator.serviceWorker.controller || registration.active;
            if (worker) worker.postMessage({type: 'CLEAR_PRIVATE_DATA'});
        }));
    } catch (_) { /* logout remains authoritative on the server */ }
    Promise.race([Promise.allSettled(jobs), new Promise(function (resolve) { setTimeout(resolve, 1200); })])
        .finally(function () { location.replace(destination); });
}());
</script>
<noscript><meta http-equiv="refresh" content="0;url=index.php"><a href="index.php">Continue</a></noscript>
</body></html>
