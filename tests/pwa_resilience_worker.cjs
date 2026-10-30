const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const ORIGIN = 'https://lcs.test';
const BASE = `${ORIGIN}/LMS-portal/`;
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; console.log('PASS:', label); }

class FakeRequest {
  constructor(input, init = {}) {
    const source = typeof input === 'string' ? { url: input } : input;
    this.url = source.url;
    this.method = init.method || source.method || 'GET';
    this.mode = init.mode || source.mode || 'cors';
    this.headers = init.headers || source.headers || new Headers();
    this.signal = init.signal || source.signal;
  }
  clone() { return new FakeRequest(this); }
}

class MemoryCache {
  constructor(fetcher) { this.entries = new Map(); this.fetcher = fetcher; }
  key(input) { return new URL(typeof input === 'string' ? input : input.url, BASE).href; }
  async match(input) { const value = this.entries.get(this.key(input)); return value ? value.clone() : undefined; }
  async put(input, response) { this.entries.set(this.key(input), response.clone()); }
  async delete(input) { return this.entries.delete(this.key(input)); }
  async keys() { return [...this.entries.keys()].map(url => new FakeRequest(url)); }
  async addAll(urls) { for (const url of urls) await this.put(url, await this.fetcher(url)); }
}

class MemoryCaches {
  constructor(fetcher) { this.stores = new Map(); this.fetcher = fetcher; }
  async open(name) { if (!this.stores.has(name)) this.stores.set(name, new MemoryCache(this.fetcher)); return this.stores.get(name); }
  async keys() { return [...this.stores.keys()]; }
  async delete(name) { return this.stores.delete(name); }
  async match(input) {
    for (const cache of this.stores.values()) {
      const response = await cache.match(input);
      if (response) return response;
    }
    return undefined;
  }
}

function htmlResponse(body, userKey = 'aaaaaaaaaaaaaaaaaaaaaaaa', status = 200, optedIn = true) {
  const headers = new Headers({ 'Content-Type': 'text/html; charset=utf-8', 'Set-Cookie': 'PHPSESSID=secret' });
  if (optedIn) {
    headers.set('X-LCS-PWA-Cache', 'user-page');
    headers.set('X-LCS-PWA-User-Key', userKey);
  }
  return new Response(body, { status, headers });
}

(async () => {
  const listeners = {};
  const clientMessages = [];
  let fetchPlan = async () => { throw new Error('offline'); };
  const self = {
    registration: { scope: BASE, showNotification: async () => {} },
    location: { origin: ORIGIN },
    navigator: { onLine: true },
    clients: {
      claim: async () => {},
      get: async () => ({ postMessage: message => clientMessages.push(message) }),
      matchAll: async () => [],
      openWindow: async () => {}
    },
    addEventListener: (type, handler) => { listeners[type] = handler; },
    skipWaiting: () => {}
  };
  const caches = new MemoryCaches(request => fetchPlan(request));
  const context = vm.createContext({
    self, caches, URL, Headers, Response, Request: FakeRequest, AbortController,
    setTimeout, clearTimeout, Promise, console,
    fetch: request => fetchPlan(request)
  });
  vm.runInContext(fs.readFileSync(require('node:path').resolve(__dirname, '../sw.js'), 'utf8'), context, { filename: 'sw.js' });

  const serviceWorkerVersion = vm.runInContext('VERSION', context);
  const staticCache = await caches.open(`lcs-static-${serviceWorkerVersion}`);
  const precache = vm.runInContext('PRECACHE', context);
  check(precache.includes('/LMS-portal/offline.html') && precache.includes('/LMS-portal/assets/offline.js') && precache.includes('/LMS-portal/assets/pwa.js'),
    'service-worker precaches the minimal fallback and fresh client script');
  await staticCache.put('/LMS-portal/offline.html', new Response(fs.readFileSync(require('node:path').resolve(__dirname, '../offline.html'), 'utf8'), { headers: { 'Content-Type': 'text/html; charset=utf-8' } }));
  const retiredStaticCache = await caches.open('lcs-static-2026.09.26-1');
  await retiredStaticCache.put(`${BASE}offline.html`, new Response('retired offline page'));
  const retiredMaterials = await caches.open('lcs-offline-aaaaaaaaaaaaaaaaaaaaaaaa');
  await retiredMaterials.put(`${BASE}download.php?type=document&id=2`, new Response('retired document'));
  const retiredPages = await caches.open('lcs-pages-2026.09.23-1-aaaaaaaaaaaaaaaaaaaaaaaa');
  await retiredPages.put(`${BASE}course?id=1`, new Response('old course controls'));
  const retainedPages = await caches.open('lcs-pages-2026.09.27-2-aaaaaaaaaaaaaaaaaaaaaaaa');
  const priorTime = Date.now() - 1000;
  await retainedPages.put(`${BASE}courses.php`, new Response('current safe snapshot', {headers:{'X-LCS-PWA-Cached-At':String(priorTime),'X-LCS-PWA-Snapshot':'1'}}));
  const storedContext = await caches.open('lcs-private-context-v1');
  await storedContext.put(`${BASE}__pwa_private_context__`, new Response('aaaaaaaaaaaaaaaaaaaaaaaa'));
  const activation = [];
  listeners.activate({ waitUntil: promise => activation.push(Promise.resolve(promise)) });
  await Promise.all(activation);
  check(!(await caches.keys()).includes('lcs-static-2026.09.26-1') && !!(await staticCache.match(`${BASE}offline.html`)),
    'activation removes the retired static cache and keeps the current offline fallback');
  check(!(await caches.keys()).includes('lcs-offline-aaaaaaaaaaaaaaaaaaaaaaaa'), 'activation removes obsolete offline material caches');
  check(!(await caches.keys()).includes('lcs-pages-2026.09.23-1-aaaaaaaaaaaaaaaaaaaaaaaa'), 'activation removes snapshots with retired course controls');
  check(!!(await retainedPages.match(`${BASE}courses`)), 'static-cache upgrade preserves current-version private page snapshots');
  check(!(await retainedPages.match(`${BASE}courses.php`)) && (await retainedPages.match(`${BASE}courses`)).headers.get('X-LCS-PWA-Cached-At') === String(priorTime), 'activation migrates the legacy key without resetting age or duplicating the snapshot');

  async function sendMessage(data) {
    const waits = [];
    listeners.message({ data, source: { postMessage: message => clientMessages.push(message) }, waitUntil: promise => waits.push(Promise.resolve(promise)) });
    await Promise.allSettled(waits);
  }

  async function dispatch(request) {
    let responsePromise;
    const waits = [];
    listeners.fetch({
      request,
      clientId: 'client-1',
      respondWith: promise => { responsePromise = Promise.resolve(promise); },
      waitUntil: promise => waits.push(Promise.resolve(promise))
    });
    if (!responsePromise) return { response: null, done: Promise.resolve() };
    const response = await responsePromise;
    return { response, done: Promise.allSettled(waits) };
  }

  async function navigate(pathname) {
    return dispatch(new FakeRequest(new URL(pathname, BASE).href, { method: 'GET', mode: 'navigate' }));
  }

  const userA = 'aaaaaaaaaaaaaaaaaaaaaaaa';
  const userB = 'bbbbbbbbbbbbbbbbbbbbbbbb';
  await sendMessage({ type: 'SET_USER_CONTEXT', userKey: userA });
  const legacyNavigation = await dispatch(new FakeRequest(`${BASE}courses.php`, {method:'GET',mode:'navigate'}));
  check(legacyNavigation.response.status === 301 && legacyNavigation.response.headers.get('location') === `${BASE}courses`, 'legacy cached navigation redirects canonically even offline');
  check(vm.runInContext(`canonicalNavigationUrl(new URL('${BASE}course.php?id=12')) === canonicalNavigationUrl(new URL('${BASE}course?id=12'))`, context), 'clean and PHP forms share exactly one cache key');

  const sourceHtml = '<!doctype html><html><head><script>window.LCSPWA={"csrf":"secret-token"}</script></head><body><form><input type="hidden" name="csrf" value="secret-token"><button type="submit">Save</button></form><h1>Dashboard A</h1></body></html>';
  fetchPlan = async () => htmlResponse(sourceHtml, userA);
  let run = await navigate('dashboard');
  check((await run.response.text()).includes('secret-token'), 'fresh authorized response is returned without client-side rewriting');
  await run.done;
  let names = await caches.keys();
  const pageCacheAName = names.find(name => name.startsWith('lcs-pages-2026.09.27-2-a'));
  check(!!pageCacheAName, 'authorized safe route creates an opaque per-user page cache');
  let pageCache = await caches.open(pageCacheAName);
  let saved = await pageCache.match(`${BASE}dashboard`);
  const savedHtml = await saved.text();
  check(savedHtml.includes('lcs-pwa-snapshot') && !savedHtml.includes('secret-token'), 'cached HTML is marked as a snapshot and reusable CSRF values are removed');
  check(!saved.headers.has('Set-Cookie') && saved.headers.get('X-LCS-PWA-Snapshot') === '1', 'cached snapshot strips Set-Cookie and carries controlled metadata');

  fetchPlan = async () => { throw new Error('offline'); };
  run = await navigate('dashboard');
  check((await run.response.text()).includes('Dashboard A'), 'offline safe navigation returns the prior user-scoped snapshot');

  fetchPlan = async () => new Response('server error', { status: 500 });
  run = await navigate('dashboard');
  check((await run.response.text()).includes('Dashboard A'), 'server 500 preserves and serves the last valid snapshot');
  check(!!(await pageCache.match(`${BASE}dashboard`)), 'server 500 does not overwrite or delete valid cached content');

  fetchPlan = async () => new Response('forbidden', { status: 403 });
  run = await navigate('dashboard');
  check(run.response.status === 403, 'server 403 remains authoritative');
  check(!(await caches.keys()).some(name => name.startsWith('lcs-pages-') || name.startsWith('lcs-offline-') || name === 'lcs-private-context-v1'), 'server 403 clears every private cache and persisted user context');

  for (const status of [401, 404]) {
    fetchPlan = async () => htmlResponse(`<h1>Dashboard before ${status}</h1>`, userA);
    run = await navigate('dashboard'); await run.done;
    pageCache = await caches.open(pageCacheAName);
    fetchPlan = async () => new Response(`status ${status}`, { status });
    run = await navigate('dashboard');
    check(run.response.status === status, `server ${status} remains authoritative`);
    if (status === 401) {
      check(!(await caches.keys()).some(name => name.startsWith('lcs-pages-') || name.startsWith('lcs-offline-') || name === 'lcs-private-context-v1'), 'server 401 clears every private cache and persisted user context');
    } else {
      check(!(await pageCache.match(`${BASE}dashboard`)), 'server 404 invalidates only the requested cached page');
    }
  }

  fetchPlan = async () => htmlResponse('<!doctype html><h1>Courses old</h1>', userA);
  run = await navigate('courses'); await run.done;
  pageCache = await caches.open(pageCacheAName);
  fetchPlan = async () => new Promise(resolve => setTimeout(() => resolve(htmlResponse('<!doctype html><h1>Courses fresh</h1>', userA)), 600));
  const started = Date.now();
  run = await navigate('courses');
  check((await run.response.text()).includes('Courses fresh') && Date.now() - started >= 550, 'online navigation waits for the fresh server page instead of serving a read-only snapshot');
  await run.done;
  check(!clientMessages.some(message => message.type === 'PAGE_CACHE_REFRESHED'), 'successful online navigation does not show a misleading background-refresh notice');
  saved = await pageCache.match(`${BASE}courses`);
  check((await saved.clone().text()).includes('Courses fresh'), 'successful background refresh replaces the snapshot');
  const oldHeaders = new Headers(saved.headers);
  oldHeaders.set('X-LCS-PWA-Cached-At', String(Date.now() - 6 * 60 * 1000));
  await pageCache.put(`${BASE}courses`, new Response(await saved.text(), { headers: oldHeaders }));
  const oldStarted = Date.now();
  run = await navigate('courses');
  check((await run.response.text()).includes('Courses fresh') && Date.now() - oldStarted < 1500, 'a quick online response remains responsive while using the latest server content');
  await run.done;

  fetchPlan = async () => new Promise(resolve => setTimeout(() => resolve(new Response('expired session', { status: 401 })), 400));
  run = await navigate('courses');
  check(run.response.status === 401, 'online navigation waits for delayed session validation instead of showing an authenticated snapshot');
  await run.done;
  check(!clientMessages.some(message => message.type === 'PAGE_CACHE_AUTH_CHANGED'), 'session validation is handled directly on the current navigation');
  check(!(await caches.keys()).some(name => name.startsWith('lcs-pages-') || name === 'lcs-private-context-v1'), 'delayed session loss clears private snapshots and context');

  fetchPlan = async () => { throw new Error('offline'); };
  run = await navigate('calendar?view=week&date=2026-09-14');
  check((await run.response.text()).includes('id="offline-title"'),
    'uncached safe navigation receives the minimal public offline page');

  run = await navigate('settings');
  check(run.response === null, 'network-only navigation remains with the browser and receives no substitute document');

  run = await navigate('download.php?type=document&id=2');
  check(run.response === null, 'document navigation is network-only with no offline substitution');
  await sendMessage({ type: 'SET_USER_CONTEXT', userKey: userA });
  const savedMaterials = await caches.open(`lcs-offline-${userA}`);
  await savedMaterials.put(`${BASE}download.php?type=document&id=2`, new Response('old saved document'));
  run = await navigate('download.php?type=document&id=2');
  check(run.response === null, 'obsolete saved document is never served by the worker');
  await sendMessage({ type: 'CACHE_RESOURCE', requestId: 'retired-save', url: `${BASE}download.php?type=document&id=2` });
  check(!clientMessages.some(message => message.requestId === 'retired-save'), 'retired save message has no handler');
  await sendMessage({ type: 'CLEAR_PRIVATE_DATA' });
  check(!(await caches.keys()).some(name => name.startsWith('lcs-offline-')), 'private-data reset removes legacy offline material');
  await sendMessage({ type: 'SET_USER_CONTEXT', userKey: userA });
  run = await navigate('dashboard');
  check((await run.response.text()) !== 'old saved document', 'normal page navigation never becomes an obsolete document download');

  const sensitive = [
    'online_quiz_take.php?id=7', 'online_quiz_api.php', 'assignment_test_submission.php?id=3',
    'assignment_test_mark.php?id=3', 'chat_ai', 'ai_chat.php', 'settings',
    'download.php?type=document&id=2', 'download.php?type=document&id=2&view=1',
    'go.php?token=secret', 'privacy_policy_acknowledge.php',
    'course?id=1&id=2', 'calendar?view=week&token=secret'
  ];
  for (const route of sensitive) {
    fetchPlan = async () => new Response(`network:${route}`, { status: 200, headers: { 'Content-Type': 'text/html' } });
    run = await navigate(route);
    check(run.response === null, `${route.split('?')[0]} stays with native network-only navigation`);
  }
  names = await caches.keys();
  const pageEntries = (await Promise.all(names.filter(name => name.startsWith('lcs-pages-')).map(async name => (await (await caches.open(name)).keys()).map(request => request.url)))).flat();
  check(!pageEntries.some(url => sensitive.some(route => url.includes(route))), 'no sensitive route appears in any page cache');

  fetchPlan = async () => new Response('posted', { status: 200 });
  run = await dispatch(new FakeRequest(`${BASE}assignment_test_action.php`, { method: 'POST', mode: 'navigate' }));
  check(run.response === null, 'non-login POST requests fall through without cache handling or replay');

  fetchPlan = async () => htmlResponse('<h1>Course one</h1>', userA);
  run = await navigate('course?id=1'); await run.done;
  pageCache = await caches.open(pageCacheAName);
  check(!!(await pageCache.match(`${BASE}course?id=1`)), 'canonical positive course id is cacheable after server opt-in');
  fetchPlan = async () => htmlResponse('<h1>Login redirect</h1>', userA, 200, false);
  run = await navigate('course?id=1'); await run.done;
  check(!(await pageCache.match(`${BASE}course?id=1`)), '200 response without explicit server opt-in invalidates an old snapshot');

  fetchPlan = async () => htmlResponse('<h1>User B dashboard</h1>', userB);
  run = await navigate('dashboard'); await run.done;
  names = await caches.keys();
  check(names.some(name => name.endsWith(userB)) && !names.some(name => name.endsWith(userA)), 'account change moves to a distinct namespace and clears the previous account caches');

  const logoutResourceCache = await caches.open(`lcs-offline-${userB}`);
  await logoutResourceCache.put(`${BASE}download.php?type=document&id=9`, new Response('private resource'));
  await sendMessage({ type: 'CLEAR_PRIVATE_DATA' });
  names = await caches.keys();
  check(!names.some(name => name.startsWith('lcs-pages-') || name.startsWith('lcs-offline-') || name === 'lcs-private-context-v1'), 'logout/private-data clear removes page, resource, and persisted user context caches');

  fetchPlan = async () => htmlResponse('<h1>Anonymous-looking</h1>', userA);
  run = await dispatch(new FakeRequest(`${BASE}index.php`, { method: 'POST', mode: 'navigate' }));
  check(run.response && (await run.response.text()).includes('Anonymous-looking'), 'login POST remains a direct network request');
  names = await caches.keys();
  check(!names.some(name => name.startsWith('lcs-pages-') || name.startsWith('lcs-offline-') || name === 'lcs-private-context-v1'), 'login POST clears any previous account context before redirect processing');

  console.log(`PWA resilience worker assertions passed: ${checks}`);
})().catch(error => { console.error(error); process.exitCode = 1; });
