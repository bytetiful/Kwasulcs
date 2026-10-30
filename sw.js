/* KWASU LCS PWA service worker.
 * Authenticated HTML is network-only unless BOTH the route policy and an
 * authorized server response explicitly opt it into a per-user page cache.
 * Course downloads remain online-only; obsolete offline material caches are retired.
 */
'use strict';

const VERSION = '2026.10.29-3';
const STATIC_CACHE = `lcs-static-${VERSION}`;
const RETIRED_RESOURCE_PREFIX = 'lcs-offline-';
const PAGE_CACHE_VERSION = '2026.09.27-2';
const PAGE_PREFIX = `lcs-pages-${PAGE_CACHE_VERSION}-`;
const CONTEXT_CACHE = 'lcs-private-context-v1';
const PAGE_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;
const PAGE_MAX_ENTRIES = 24;
// Keep online navigation authoritative for forms and session tokens; only
// fall back to cached read-only HTML after this bounded network attempt fails.
const UNCACHED_NAVIGATION_TIMEOUT_MS = 12000;
const SCOPE_URL = new URL(self.registration.scope);
const BASE_PATH = SCOPE_URL.pathname.endsWith('/') ? SCOPE_URL.pathname : `${SCOPE_URL.pathname}/`;
const CONTEXT_URL = `${self.location.origin}${BASE_PATH}__pwa_private_context__`;
const OFFLINE_URL = `${BASE_PATH}offline.html`;
const PUBLIC_PAGE_ROUTES = new Set(['dashboard', 'courses', 'course', 'calendar', 'settings', 'notifications',
  'profile', 'privacy-policy', 'chat', 'chat_ai', 'chat_dm', 'chat_group', 'assignment_tests', 'assignment_test',
  'assignment_test_receipt', 'assignment_test_submissions', 'assignment_test_audit', 'online_quiz',
  'online_quiz_edit', 'online_quiz_monitor', 'online_quiz_result', 'forgot_password']);
const PRECACHE = [
  OFFLINE_URL,
  `${BASE_PATH}assets/offline.js`,
  `${BASE_PATH}assets/pwa.js`,
  `${BASE_PATH}assets/pwa.css`,
  `${BASE_PATH}assets/favicon/android-chrome-192x192.png`,
  `${BASE_PATH}assets/favicon/android-chrome-512x512.png`,
  `${BASE_PATH}assets/img/kwasu_logo.png`
];

let activeUserKey = '';

function validUserKey(value) {
  return typeof value === 'string' && /^[a-f0-9]{16,64}$/.test(value);
}

function sameApplication(url) {
  return url.origin === self.location.origin && url.pathname.startsWith(BASE_PATH);
}

function isStaticAsset(url) {
  return sameApplication(url) && url.pathname.startsWith(`${BASE_PATH}assets/`)
    && /\.(?:css|js|png|jpg|jpeg|gif|webp|svg|ico|woff2?)$/i.test(url.pathname);
}

function isSafePushDestination(raw) {
  try {
    const url = new URL(raw || `${BASE_PATH}notifications`, self.location.origin);
    if (!sameApplication(url)) return null;
    const leaf = url.pathname.slice(BASE_PATH.length);
    const page = leaf.replace(/\.php$/, '');
    if (PUBLIC_PAGE_ROUTES.has(page)) { url.pathname = `${BASE_PATH}${page}`; return url.href; }
    if (/^(?:online_quiz_take|online_quiz_api|assignment_test_action|logout|push_subscribe)\.php$/i.test(leaf)) return null;
    if (!/^[a-z0-9_\-/]+\.php$/i.test(leaf)) return null;
    return url.href;
  } catch (_) {
    return null;
  }
}

async function clearPrivateCaches(exceptKey = '') {
  const keepPages = exceptKey ? `${PAGE_PREFIX}${exceptKey}` : '';
  const names = await caches.keys();
  await Promise.all(names.filter((name) => (
    name.startsWith(RETIRED_RESOURCE_PREFIX)
      || (name.startsWith('lcs-pages-') && name !== keepPages)
  ))
    .map((name) => caches.delete(name)));
}

async function getActiveUserKey() {
  if (validUserKey(activeUserKey)) return activeUserKey;
  try {
    const cache = await caches.open(CONTEXT_CACHE);
    const response = await cache.match(CONTEXT_URL);
    const stored = response ? (await response.text()).trim() : '';
    if (validUserKey(stored)) activeUserKey = stored;
  } catch (_) { /* a missing context cache is a normal first-run state */ }
  return activeUserKey;
}

async function setActiveUserKey(value) {
  const nextKey = validUserKey(value) ? value : '';
  const previousKey = await getActiveUserKey();
  if (!nextKey) {
    activeUserKey = '';
    await clearPrivateCaches();
    await caches.delete(CONTEXT_CACHE);
    return '';
  }
  if (previousKey && previousKey !== nextKey) await clearPrivateCaches(nextKey);
  activeUserKey = nextKey;
  const cache = await caches.open(CONTEXT_CACHE);
  await cache.put(CONTEXT_URL, new Response(nextKey, {
    headers: { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-store' }
  }));
  return nextKey;
}

async function clearAllPrivateData() {
  activeUserKey = '';
  await clearPrivateCaches();
  await caches.delete(CONTEXT_CACHE);
}

function allowedQueryOnly(url, names) {
  const seen = new Set();
  for (const key of url.searchParams.keys()) {
    if (!names.includes(key) || seen.has(key)) return false;
    seen.add(key);
  }
  return true;
}

/** Return a canonical cache key only for explicitly approved read routes. */
function canonicalNavigationUrl(url) {
  if (!sameApplication(url)) return '';
  const leaf = url.pathname.slice(BASE_PATH.length).replace(/\.php$/, '');
  const clean = new URL(`${BASE_PATH}${leaf}`, self.location.origin);
  if (leaf === 'dashboard') {
    if (!allowedQueryOnly(url, ['source']) || (url.searchParams.has('source') && url.searchParams.get('source') !== 'pwa')) return '';
    return clean.href;
  }
  if (leaf === 'courses') return url.search === '' ? clean.href : '';
  if (leaf === 'chat' || leaf === 'notifications') return url.search === '' ? clean.href : '';
  if (leaf === 'chat_group') {
    if (!allowedQueryOnly(url, ['course_id'])) return '';
    const courseId = url.searchParams.get('course_id') || '';
    if (!/^\d+$/.test(courseId) || Number(courseId) < 1) return '';
    clean.searchParams.set('course_id', courseId);
    return clean.href;
  }
  if (leaf === 'chat_dm') {
    if (!allowedQueryOnly(url, ['thread_id', 'with'])) return '';
    const hasThread = url.searchParams.has('thread_id');
    const hasWith = url.searchParams.has('with');
    if (hasThread === hasWith) return '';
    const name = hasThread ? 'thread_id' : 'with';
    const value = url.searchParams.get(name) || '';
    if (!/^\d+$/.test(value) || Number(value) < 1) return '';
    clean.searchParams.set(name, value);
    return clean.href;
  }
  if (leaf === 'course') {
    if (!allowedQueryOnly(url, ['id']) || !/^\d+$/.test(url.searchParams.get('id') || '') || Number(url.searchParams.get('id')) < 1) return '';
    clean.searchParams.set('id', url.searchParams.get('id'));
    return clean.href;
  }
  if (leaf === 'calendar') {
    const allowed = ['y', 'm', 'date', 'view', 'course'];
    if (!allowedQueryOnly(url, allowed)) return '';
    const validators = {
      y: (v) => /^\d{4}$/.test(v) && Number(v) >= 1970 && Number(v) <= 2100,
      m: (v) => /^\d{1,2}$/.test(v) && Number(v) >= 1 && Number(v) <= 12,
      date: (v) => /^\d{4}-\d{2}-\d{2}$/.test(v),
      view: (v) => ['day', 'week', 'month'].includes(v),
      course: (v) => /^\d+$/.test(v)
    };
    for (const name of allowed) {
      if (!url.searchParams.has(name)) continue;
      const value = url.searchParams.get(name) || '';
      if (!validators[name](value)) return '';
      clean.searchParams.set(name, value);
    }
    return clean.href;
  }
  if (leaf === 'assignment_tests') {
    if (!allowedQueryOnly(url, ['category'])) return '';
    const category = url.searchParams.get('category');
    if (category && !['all', 'assignment', 'quiz'].includes(category)) return '';
    if (category) clean.searchParams.set('category', category);
    return clean.href;
  }
  return '';
}

function pageCacheName(userKey) {
  return `${PAGE_PREFIX}${userKey}`;
}

function legacyPageKey(cacheKey) {
  const url = new URL(cacheKey);
  url.pathname += '.php';
  return url.href;
}

/** Move existing snapshots without changing their age, body, or user scope. */
async function canonicalSnapshot(cache, cacheKey) {
  const legacyKey = legacyPageKey(cacheKey);
  const current = await cache.match(cacheKey);
  const legacy = await cache.match(legacyKey);
  if (!legacy) return current;
  const newest = !current || Number(legacy.headers.get('X-LCS-PWA-Cached-At') || 0) > Number(current.headers.get('X-LCS-PWA-Cached-At') || 0) ? legacy : current;
  if (newest === legacy) await cache.put(cacheKey, legacy.clone());
  await cache.delete(legacyKey);
  return newest;
}

async function migratePageCache(cache) {
  for (const request of await cache.keys()) {
    const key = canonicalNavigationUrl(new URL(request.url));
    if (!key) await cache.delete(request);
    else await canonicalSnapshot(cache, key);
  }
}

function sanitizeSnapshotHtml(html) {
  let output = String(html || '');
  output = output.replace(/\sdata-csrf=(?:"[^"]*"|'[^']*')/gi, '');
  output = output.replace(/<input\b(?=[^>]*(?:name|id)=(?:"[^"]*csrf[^"]*"|'[^']*csrf[^']*'))[^>]*>/gi, '');
  output = output.replace(/("csrf"\s*:\s*)"[^"]*"/g, '$1""');
  const marker = '<meta name="lcs-pwa-snapshot" content="1">';
  return /<\/head>/i.test(output) ? output.replace(/<\/head>/i, `${marker}</head>`) : marker + output;
}

async function snapshotResponse(response) {
  const headers = new Headers(response.headers);
  headers.delete('Set-Cookie');
  headers.delete('Set-Cookie2');
  headers.set('X-LCS-PWA-Snapshot', '1');
  headers.set('X-LCS-PWA-Cached-At', String(Date.now()));
  headers.set('Cache-Control', 'no-store');
  return new Response(sanitizeSnapshotHtml(await response.clone().text()), {
    status: 200,
    statusText: 'OK',
    headers
  });
}

async function prunePageCache(cache) {
  await migratePageCache(cache);
  const records = [];
  for (const request of await cache.keys()) {
    const response = await cache.match(request);
    const cachedAt = Number(response?.headers.get('X-LCS-PWA-Cached-At') || 0);
    if (!cachedAt || Date.now() - cachedAt > PAGE_MAX_AGE_MS) await cache.delete(request);
    else records.push({ request, cachedAt });
  }
  records.sort((a, b) => b.cachedAt - a.cachedAt);
  await Promise.all(records.slice(PAGE_MAX_ENTRIES).map((record) => cache.delete(record.request)));
}

async function readPageSnapshot(cacheKey) {
  const userKey = await getActiveUserKey();
  if (!userKey) return null;
  const cache = await caches.open(pageCacheName(userKey));
  const response = await canonicalSnapshot(cache, cacheKey);
  if (!response) return null;
  const cachedAt = Number(response.headers.get('X-LCS-PWA-Cached-At') || 0);
  if (!cachedAt || Date.now() - cachedAt > PAGE_MAX_AGE_MS) {
    await cache.delete(cacheKey);
    return null;
  }
  return { response, cachedAt, userKey };
}

async function deletePageSnapshot(userKey, cacheKey) {
  if (!validUserKey(userKey)) return;
  const cache = await caches.open(pageCacheName(userKey));
  await cache.delete(cacheKey);
  await cache.delete(legacyPageKey(cacheKey));
}

function responseCanPopulatePageCache(response, requestedCacheKey) {
  if (!response || response.status !== 200 || response.type === 'opaque') return false;
  if ((response.headers.get('X-LCS-PWA-Cache') || '') !== 'user-page') return false;
  if (!validUserKey(response.headers.get('X-LCS-PWA-User-Key') || '')) return false;
  if (!/^text\/html\b/i.test(response.headers.get('Content-Type') || '')) return false;
  try {
    const finalUrl = response.url ? canonicalNavigationUrl(new URL(response.url)) : requestedCacheKey;
    return finalUrl === requestedCacheKey;
  } catch (_) { return false; }
}

async function fetchAndUpdateNavigation(request, cacheKey) {
  const priorUserKey = await getActiveUserKey();
  try {
    const response = await fetch(request);
    // Navigation requests can expose a login redirect as `opaqueredirect`
    // inside the worker. Treat every redirect away from a cached page as an
    // authorization/state change and invalidate before the browser follows it.
    if (response.type === 'opaqueredirect' || response.redirected || (response.status >= 300 && response.status < 400)) {
      await clearAllPrivateData();
      return { response, useCached: false, updated: false, authChanged: true };
    }
    if ([401, 403].includes(response.status)) {
      await clearAllPrivateData();
      return { response, useCached: false, updated: false, authChanged: true };
    }
    if ([404, 410].includes(response.status)) {
      await deletePageSnapshot(priorUserKey, cacheKey);
      return { response, useCached: false, updated: false };
    }
    if (response.status >= 500) return { response, useCached: true, updated: false };
    if (!responseCanPopulatePageCache(response, cacheKey)) {
      if (response.status === 200) await deletePageSnapshot(priorUserKey, cacheKey);
      return { response, useCached: false, updated: false };
    }
    const responseUserKey = response.headers.get('X-LCS-PWA-User-Key') || '';
    await setActiveUserKey(responseUserKey);
    const cache = await caches.open(pageCacheName(responseUserKey));
    await cache.put(cacheKey, await snapshotResponse(response));
    await prunePageCache(cache);
    return { response, useCached: false, updated: true, authChanged: !!priorUserKey && priorUserKey !== responseUserKey };
  } catch (_) {
    return { response: null, useCached: true, updated: false };
  }
}

async function postToClient(clientId, message) {
  if (!clientId) return;
  try {
    const client = await self.clients.get(clientId);
    if (client) client.postMessage(message);
  } catch (_) { /* the tab may have closed */ }
}

async function offlineNavigationResponse() {
  const cache = await caches.open(STATIC_CACHE);
  return (await cache.match(OFFLINE_URL)) || Response.error();
}

async function handleCacheableNavigation(event, cacheKey) {
  const cached = await readPageSnapshot(cacheKey);
  if (cached) {
    // Only use a saved page immediately when the browser reports offline.
    // Serving a snapshot during a merely slow online response marks the page
    // read-only and strips its CSRF token, which can silently disable normal
    // form, AI, chat-history, and calendar actions for the rest of the visit.
    if (self.navigator && self.navigator.onLine === false) {
      const refresh = fetchAndUpdateNavigation(event.request, cacheKey);
      event.waitUntil(refresh.then((result) => {
        const clientId = event.resultingClientId || event.clientId;
        if (result.authChanged) return postToClient(clientId, { type: 'PAGE_CACHE_AUTH_CHANGED', url: cacheKey });
        if (result.updated) return postToClient(clientId, { type: 'PAGE_CACHE_REFRESHED', url: cacheKey });
      }));
      return cached.response;
    }

    // Online navigation is network-first. Keep the existing bounded wait,
    // then fall back to the last authorized snapshot only if the request
    // actually fails or stalls; never make a healthy but slow page read-only.
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), UNCACHED_NAVIGATION_TIMEOUT_MS);
    try {
      const request = new Request(event.request, { signal: controller.signal });
      const result = await fetchAndUpdateNavigation(request, cacheKey);
      if (result.useCached) return cached.response;
      return result.response || cached.response;
    } finally {
      clearTimeout(timer);
    }
  }

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), UNCACHED_NAVIGATION_TIMEOUT_MS);
  try {
    const request = new Request(event.request, { signal: controller.signal });
    const result = await fetchAndUpdateNavigation(request, cacheKey);
    // Only approved HTML page navigations can receive the public fallback.
    // Resource/signed URLs and sensitive execution routes never enter here.
    return result.response || offlineNavigationResponse();
  } finally {
    clearTimeout(timer);
  }
}

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(STATIC_CACHE).then((cache) => cache.addAll(PRECACHE)));
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const names = await caches.keys();
    await Promise.all(names.filter((name) => (
      (name.startsWith('lcs-static-') && name !== STATIC_CACHE)
      || name.startsWith(RETIRED_RESOURCE_PREFIX)
      || (name.startsWith('lcs-pages-') && !name.startsWith(PAGE_PREFIX))
    ))
      .map((name) => caches.delete(name)));
    const userKey = await getActiveUserKey();
    if (userKey) await prunePageCache(await caches.open(pageCacheName(userKey)));
    await self.clients.claim();
  })());
});

self.addEventListener('message', (event) => {
  const data = event.data || {};
  if (data.type === 'SKIP_WAITING') {
    self.skipWaiting();
    return;
  }
  if (data.type === 'SET_USER_CONTEXT') {
    event.waitUntil(setActiveUserKey(data.userKey));
    return;
  }
  if (data.type === 'CLEAR_PRIVATE_DATA') {
    event.waitUntil(clearAllPrivateData());
    return;
  }
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (!sameApplication(url)) return;

  // A login POST may establish a different account. Clear the previous opaque
  // user context before following its redirect; the POST itself is never cached.
  if (event.request.method === 'POST' && url.pathname === `${BASE_PATH}index.php`) {
    event.respondWith((async () => {
      await clearAllPrivateData();
      return fetch(event.request);
    })());
    return;
  }
  if (event.request.method !== 'GET') return;

  if (url.pathname === OFFLINE_URL) {
    event.respondWith(offlineNavigationResponse());
    return;
  }

  if (event.request.mode === 'navigate') {
    const cacheKey = canonicalNavigationUrl(url);
    if (cacheKey && url.pathname.endsWith('.php')) {
      // An offline legacy bookmark must also arrive at the canonical URL;
      // this navigation redirect is not an authentication redirect.
      url.pathname = url.pathname.slice(0, -4);
      event.respondWith(Response.redirect(url.href, 301));
    } else if (cacheKey) event.respondWith(handleCacheableNavigation(event, cacheKey));
    // Other routes remain network-only and use the browser's native failure
    // behavior when unavailable. They never receive a cached page substitute.
    return;
  }

  if (isStaticAsset(url)) {
    event.respondWith((async () => {
      const cached = await caches.match(event.request, { ignoreSearch: true });
      const fresh = fetch(event.request).then(async (response) => {
        if (response.ok && response.type === 'basic') {
          const cache = await caches.open(STATIC_CACHE);
          await cache.put(event.request, response.clone());
        }
        return response;
      }).catch(() => cached);
      return cached || fresh;
    })());
  }
  // API/auth/chat/AI/quiz/submission/grading/signed download traffic intentionally
  // falls through to network-only. There is no Background Sync replay.
});

self.addEventListener('push', (event) => {
  let data = { title: 'KWASU LCS', body: 'You have a new notification.', url: `${BASE_PATH}notifications` };
  try { if (event.data) data = { ...data, ...event.data.json() }; } catch (_) { /* use safe defaults */ }
  const destination = isSafePushDestination(data.url) || `${self.location.origin}${BASE_PATH}notifications`;
  const options = {
    body: String(data.body || '').slice(0, 300),
    icon: `${BASE_PATH}assets/favicon/android-chrome-192x192.png`,
    badge: `${BASE_PATH}assets/favicon/favicon-32x32.png`,
    tag: String(data.tag || 'lcs-notification').slice(0, 100),
    renotify: false,
    data: { url: destination }
  };
  event.waitUntil(self.registration.showNotification(String(data.title || 'KWASU LCS').slice(0, 120), options));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const destination = isSafePushDestination(event.notification.data?.url)
    || `${self.location.origin}${BASE_PATH}notifications`;
  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of windows) {
      if (sameApplication(new URL(client.url)) && 'focus' in client) {
        if ('navigate' in client) await client.navigate(destination);
        return client.focus();
      }
    }
    return self.clients.openWindow(destination);
  })());
});

self.addEventListener('pushsubscriptionchange', (event) => {
  // Never silently manufacture consent. Settings will ask the user to restore
  // notifications during the next authenticated visit.
  event.waitUntil(Promise.resolve());
});
