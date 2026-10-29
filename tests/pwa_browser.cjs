const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('/Users/anatulu-ihemobinna/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root = path.resolve(__dirname, '..');
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; console.log('PASS:', label); }

const html = `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<link rel="stylesheet" href="/assets/style.css"><link rel="stylesheet" href="/assets/pwa.css"></head>
<body class="app-body"><header class="app-header"><span class="app-icon-badge">4</span></header>
<main class="page"><section class="panel pwa-settings-panel"><div class="pwa-capability-grid">
<article class="pwa-capability-card"><h3>Install LCS</h3><p data-pwa-install-support></p><button class="btn primary" data-pwa-install hidden>Install app</button></article>
<article class="pwa-capability-card"><h3>Browser notifications</h3><p>Optional notifications.</p><button class="btn secondary" data-pwa-push>Enable on this device</button></article>
</div></section><div class="doc-list-row"><a class="doc-preview"><span class="doc-preview-title">Course handbook</span></a><a class="doc-download-btn" href="download.php?type=document&id=7">Download</a></div>
</main>
<script>window.LCSPWA={authenticated:true,userKey:'abcdef123456abcdef123456',csrf:'test',vapidPublicKey:'',unreadCount:4};</script>
<script src="/assets/pwa.js" defer></script></body></html>`;

const snapshotHtml = `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="lcs-pwa-snapshot" content="1"><link rel="stylesheet" href="/assets/style.css"><link rel="stylesheet" href="/assets/pwa.css"></head>
<body class="app-body"><main class="page"><h1>Saved calendar</h1><form id="mutation"><input name="title"><button type="submit">Save event</button></form><button data-embed="https://youtube.com/embed/example">Lecture video</button></main>
<script>window.LCSPWA={authenticated:true,userKey:'abcdef123456abcdef123456',csrf:'',vapidPublicKey:'',unreadCount:0};</script>
<script src="/assets/pwa.js" defer></script></body></html>`;

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: '/Users/anatulu-ihemobinna/Library/Caches/ms-playwright/chromium_headless_shell-1228/chrome-headless-shell-mac-x64/chrome-headless-shell' });
  try {
    for (const viewport of [{ width: 375, height: 812 }, { width: 393, height: 852 }, { width: 812, height: 375 }, { width: 1440, height: 900 }]) {
      const page = await browser.newPage({ viewport });
      const errors = [];
      await page.addInitScript(() => {
        window.__workerMessages = []; window.__shares = []; window.__badge = 0;
        const worker = { postMessage: (message) => window.__workerMessages.push(message) };
        const registration = { active: worker, waiting: null, installing: null, addEventListener() { }, pushManager: { getSubscription: async () => null } };
        Object.defineProperty(navigator, 'serviceWorker', {
          configurable: true, value: {
            controller: worker, register: async () => registration, addEventListener() { }, ready: Promise.resolve(registration)
          }
        });
        navigator.setAppBadge = async (count) => { window.__badge = count; };
        navigator.clearAppBadge = async () => { window.__badge = 0; };
        navigator.share = async (data) => { window.__shares.push(data); };
      });
      page.on('pageerror', error => errors.push(error.message));
      await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        if (url.hostname !== 'pwa.test') return route.abort();
        if (url.pathname === '/') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: html });
        if (url.pathname.startsWith('/assets/')) {
          const file = path.join(root, url.pathname);
          if (fs.existsSync(file)) return route.fulfill({ path: file });
        }
        if (url.pathname === '/pwa_ping.php') return route.fulfill({ contentType: 'application/json', body: '{"status":"ok"}' });
        return route.abort();
      });
      await page.goto('https://pwa.test/');
      await page.waitForSelector('.pwa-settings-panel');
      check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${viewport.width} no horizontal overflow`);
      check(await page.locator('.pwa-capability-card').count() === 2, `${viewport.width} capability controls render`);
      if (viewport.width < 640) check(await page.locator('.pwa-capability-grid').evaluate(el => getComputedStyle(el).gridTemplateColumns.split(' ').length === 1), `${viewport.width} settings controls stack`);
      check(await page.locator('.pwa-offline-btn').count() === 0, `${viewport.width} document row has no offline-copy control`);
      check(await page.locator('[data-pwa-share]').count() === 0, `${viewport.width} retired course share control is absent`);
      check(await page.evaluate(() => window.__badge === 4), `${viewport.width} unread count reaches optional badge API`);
      check(await page.evaluate(() => window.__workerMessages.some(message => message.type === 'SET_USER_CONTEXT')), `${viewport.width} authenticated user context reaches worker`);
      await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
      check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${viewport.width} remains free of horizontal overflow at 200% text size`);
      await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
      check(await page.locator('.pwa-capability-card').first().evaluate(el => getComputedStyle(el).backgroundColor !== 'rgba(0, 0, 0, 0)'), `${viewport.width} dark mode keeps capability surfaces`);
      await page.waitForLoadState('networkidle');
      await page.evaluate(() => {
        Object.defineProperty(navigator, 'onLine', { configurable: true, value: false });
        window.dispatchEvent(new Event('offline'));
      });
      await page.locator('.pwa-connectivity-banner.is-offline').waitFor();
      const statusText = await page.locator('.pwa-connectivity-banner').textContent();
      check(statusText.includes('Offline — live information is unavailable.'),
        `${viewport.width} in-app offline status appears without a standalone page: ${statusText}`);
      check(await page.locator('#offline-title').count() === 0, `${viewport.width} loaded app page keeps its in-app connectivity status`);
      check(errors.length === 0, `${viewport.width} zero browser errors: ${errors.join(', ')}`);
      await page.close();
    }
    const restorePage = await browser.newPage({ viewport: { width: 393, height: 852 } });
    await restorePage.addInitScript(() => {
      localStorage.setItem('lcs-pwa-user-key', 'abcdef123456abcdef123456');
      localStorage.setItem('lcs-pwa-last-safe-path', '/course.php?id=9');
      const worker = { postMessage() { } };
      const registration = { active: worker, waiting: null, installing: null, addEventListener() { }, pushManager: { getSubscription: async () => null } };
      Object.defineProperty(navigator, 'serviceWorker', {
        configurable: true, value: {
          controller: worker, register: async () => registration, addEventListener() { }, ready: Promise.resolve(registration)
        }
      });
      const nativeMatchMedia = window.matchMedia.bind(window);
      window.matchMedia = query => query === '(display-mode: standalone)'
        ? { matches: true, media: query, onchange: null, addListener() { }, removeListener() { }, addEventListener() { }, removeEventListener() { }, dispatchEvent() { return true; } }
        : nativeMatchMedia(query);
    });
    await restorePage.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.hostname !== 'pwa.test') return route.abort();
      if (url.pathname === '/dashboard') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: html });
      if (url.pathname === '/course') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: '<!doctype html><title>Restored course</title>' });
      if (url.pathname.startsWith('/assets/')) {
        const file = path.join(root, url.pathname);
        if (fs.existsSync(file)) return route.fulfill({ path: file });
      }
      return route.abort();
    });
    await restorePage.goto('https://pwa.test/dashboard?source=pwa');
    await restorePage.waitForURL('**/course?id=9');
    check(restorePage.url().endsWith('/course?id=9'), 'standalone launch restores the previous allowlisted safe page');
    await restorePage.close();

    const snapshotPage = await browser.newPage({ viewport: { width: 393, height: 852 }, reducedMotion: 'reduce' });
    await snapshotPage.addInitScript(() => {
      window.__workerMessages = []; window.__serviceWorkerListeners = {};
      const worker = { postMessage: message => window.__workerMessages.push(message) };
      const registration = { active: worker, waiting: null, installing: null, addEventListener() { }, pushManager: { getSubscription: async () => null } };
      Object.defineProperty(navigator, 'serviceWorker', {
        configurable: true, value: {
          controller: worker, register: async () => registration,
          addEventListener: (type, listener) => { window.__serviceWorkerListeners[type] = listener; },
          ready: Promise.resolve(registration)
        }
      });
    });
    await snapshotPage.route('**/*', async route => {
      const url = new URL(route.request().url());
      if (url.hostname !== 'pwa.test') return route.abort();
      if (url.pathname === '/snapshot.php') return route.fulfill({ contentType: 'text/html; charset=utf-8', body: snapshotHtml });
      if (url.pathname.startsWith('/assets/')) {
        const file = path.join(root, url.pathname);
        if (fs.existsSync(file)) return route.fulfill({ path: file });
      }
      if (url.pathname === '/pwa_ping.php') return route.fulfill({ contentType: 'application/json', body: '{"status":"ok"}' });
      return route.abort();
    });
    await snapshotPage.goto('https://pwa.test/snapshot.php');
    await snapshotPage.waitForSelector('.pwa-connectivity-banner:not([hidden])');
    check((await snapshotPage.locator('.pwa-connectivity-banner').textContent()).includes('saved content'), 'saved-page state is shown in a subtle persistent live region');
    check(await snapshotPage.locator('.pwa-connectivity-banner').getAttribute('role') === 'status', 'connectivity status is non-modal and announced accessibly');
    check(await snapshotPage.locator('#mutation button').isDisabled(), 'mutation submit control is disabled in a cached snapshot');
    check(await snapshotPage.evaluate(() => document.querySelector('#mutation').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })) === false), 'cached snapshot prevents form submission');
    check(await snapshotPage.evaluate(async () => fetch('/ai_chat.php', { method: 'POST', body: '{}' }).then(() => false, error => /read-only/i.test(error.message))), 'cached snapshot blocks JavaScript-driven POST mutations such as AI sends');
    check(await snapshotPage.locator('.pwa-online-required').textContent() === 'Internet required', 'cached video listing clearly states that playback needs internet');
    check(await snapshotPage.locator('[role="dialog"]').count() === 0, 'poor-network status does not use a disruptive dialog');
    check(await snapshotPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'saved-page banner has no mobile horizontal overflow');
    let snapshotLoads = 0;
    snapshotPage.on('framenavigated', frame => { if (frame === snapshotPage.mainFrame()) snapshotLoads++; });
    await snapshotPage.evaluate(() => {
      window.__serviceWorkerListeners.message({ data: { type: 'PAGE_CACHE_REFRESHED', url: location.href } });
    });
    await snapshotPage.waitForTimeout(500);
    check(!((await snapshotPage.locator('.pwa-connectivity-banner').textContent()).includes('Back online')), 'back-online notice waits before appearing');
    await snapshotPage.locator('.pwa-connectivity-banner').filter({ hasText: 'Back online.' }).waitFor({ timeout: 1500 });
    check((await snapshotPage.locator('.pwa-connectivity-banner').textContent()).includes('Back online.'), 'background refresh uses the back-online message');
    check(await snapshotPage.locator('.pwa-connectivity-banner button').textContent() === 'Refresh', 'back-online notice offers the explicit refresh action');
    await snapshotPage.waitForTimeout(1200);
    check(snapshotLoads === 0, 'visible cached page never auto-reloads after background refresh');
    await snapshotPage.evaluate(() => window.dispatchEvent(new Event('online')));
    await snapshotPage.waitForTimeout(1200);
    check(snapshotLoads === 0, 'reconnection never schedules a saved-page reload');
    await snapshotPage.evaluate(() => window.__serviceWorkerListeners.controllerchange());
    check(snapshotLoads === 0, 'worker claim does not automatically reload the current page');
    await snapshotPage.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
    check(await snapshotPage.locator('.pwa-connectivity-banner').evaluate(el => getComputedStyle(el).backgroundColor !== 'rgba(0, 0, 0, 0)'), 'connectivity banner remains legible in dark mode');
    check(await snapshotPage.locator('.pwa-connectivity-banner').evaluate(el => getComputedStyle(el).transitionDuration === '0s'), 'connectivity UI respects reduced-motion preference');
    await snapshotPage.locator('.pwa-connectivity-banner').waitFor({ state: 'hidden', timeout: 4000 });
    check(snapshotLoads === 0, 'back-online notice disappears automatically after three seconds');
    await snapshotPage.evaluate(() => window.__serviceWorkerListeners.message({ data: { type: 'PAGE_CACHE_REFRESHED', url: location.href } }));
    await snapshotPage.waitForTimeout(1100);
    await snapshotPage.locator('.pwa-connectivity-banner button').click();
    await snapshotPage.waitForLoadState('domcontentloaded');
    check(snapshotLoads === 1, 'Refresh remains an explicit user-controlled reload');
    await snapshotPage.close();
    console.log(`PWA browser assertions passed: ${checks}`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
