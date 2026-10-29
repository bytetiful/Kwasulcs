const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const { chromium } = require('/Users/anatulu-ihemobinna/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

const root = path.resolve(__dirname, '..');
const userKey = 'abcdef123456abcdef123456';
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; console.log('PASS:', label); }

const assets = new Map([
  ['sw.js', 'application/javascript; charset=utf-8'],
  ['assets/pwa.js', 'application/javascript; charset=utf-8'],
  ['assets/pwa.css', 'text/css; charset=utf-8'],
  ['offline.html', 'text/html; charset=utf-8'],
  ['assets/offline.js', 'application/javascript; charset=utf-8'],
  ['assets/favicon/android-chrome-192x192.png', 'image/png'],
  ['assets/favicon/android-chrome-512x512.png', 'image/png'],
  ['assets/img/kwasu_logo.png', 'image/png'],
]);
function pageHtml(title) {
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${title}</title><link rel="stylesheet" href="assets/pwa.css"></head><body><main><h1>${title}</h1><nav><a href="dashboard">Dashboard</a> <a href="courses">Courses</a> <a href="course?id=9">Course</a> <a href="calendar?view=week">Calendar</a></nav></main><script>window.LCSPWA={authenticated:true,userKey:'${userKey}',csrf:'',vapidPublicKey:'',unreadCount:0};</script><script src="assets/pwa.js" defer></script></body></html>`;
}

(async () => {
  let courseDelay = 0;
  let courseTitle = 'Courses';
  let courseStatus = 200;
  const server = http.createServer((request, response) => {
    const url = new URL(request.url, 'http://localhost');
    const leaf = url.pathname.replace(/^\/LMS-portal\//, '');
    if (assets.has(leaf)) {
      response.writeHead(200, { 'Content-Type': assets.get(leaf) });
      response.end(fs.readFileSync(path.join(root, leaf)));
      return;
    }
    if (leaf === 'pwa_ping.php') {
      response.writeHead(200, { 'Content-Type': 'application/json' });
      response.end('{"status":"ok"}');
      return;
    }
    if (leaf === 'download.php' && url.searchParams.get('type') === 'document') {
      response.writeHead(200, { 'Content-Type': 'application/pdf', 'Content-Disposition': 'attachment; filename="fixture.pdf"' });
      response.end('%PDF-1.4 fixture');
      return;
    }
    const titles = {
      'dashboard': 'Dashboard', 'courses': 'Courses', 'course': 'Course 9',
      'calendar': 'Calendar', 'assignment_tests': 'Assessment workspace',
    };
    if (titles[leaf]) {
      const title = leaf === 'courses' ? courseTitle : titles[leaf];
      setTimeout(() => {
        response.writeHead(leaf === 'courses' ? courseStatus : 200, {
          'Content-Type': 'text/html; charset=utf-8',
          'X-LCS-PWA-Cache': 'user-page', 'X-LCS-PWA-User-Key': userKey,
        });
        response.end(leaf === 'courses' && courseStatus === 401 ? '<!doctype html><h1>Session expired</h1>' : pageHtml(title));
      }, leaf === 'courses' ? courseDelay : 0);
      return;
    }
    response.writeHead(404, { 'Content-Type': 'text/plain' });
    response.end('Not found');
  });
  await new Promise(resolve => server.listen(0, 'localhost', resolve));
  const base = `http://localhost:${server.address().port}/LMS-portal/`;
  let browser;
  try {
    browser = await chromium.launch({ headless: true,
      executablePath: '/Users/anatulu-ihemobinna/Library/Caches/ms-playwright/chromium_headless_shell-1228/chrome-headless-shell-mac-x64/chrome-headless-shell' });
    const context = await browser.newContext({ viewport: { width: 393, height: 852 }, serviceWorkers: 'allow', acceptDownloads: true });
    const page = await context.newPage();
    const downloads = []; const errors = [];
    page.on('download', download => downloads.push(download));
    page.on('pageerror', error => errors.push(error.message));

    await page.goto(base + 'dashboard');
    await page.waitForFunction(() => !!navigator.serviceWorker.controller);
    await page.waitForTimeout(300);
    await page.goto(base + 'dashboard');
    for (const leaf of ['courses', 'course?id=9', 'calendar?view=week']) await page.goto(base + leaf);
    const cachedPaths = await page.evaluate(async () => {
      const names = await caches.keys();
      const requests = (await Promise.all(names.map(async name => (await caches.open(name)).keys()))).flat();
      return requests.map(request => new URL(request.url).pathname);
    });
    check(cachedPaths.filter(name => /\/(?:dashboard|courses|course|calendar)$/.test(name)).length >= 4,
      'real service worker caches approved safe pages in the user namespace');
    check(cachedPaths.includes('/LMS-portal/offline.html') && cachedPaths.includes('/LMS-portal/assets/offline.js'),
      'installed service worker stores the public offline fallback and action script');

    check(!(await page.evaluate(async () => (await caches.keys()).some(name => name.startsWith('lcs-offline-')))),
      'real service worker has no material-copy cache');

    courseDelay = 700;
    courseTitle = 'Courses updated';
    const cacheNavigationStarted = Date.now();
    await page.goto(base + 'courses', { waitUntil: 'domcontentloaded' });
    const cacheNavigationElapsed = Date.now() - cacheNavigationStarted;
    check(await page.locator('h1').textContent() === 'Courses' && cacheNavigationElapsed < 550,
      `real worker renders saved page before the 700ms network response (${cacheNavigationElapsed}ms)`);
    let automaticNavigations = 0;
    page.on('framenavigated', frame => { if (frame === page.mainFrame()) automaticNavigations++; });
    const noticeStartedAt = Date.now();
    await page.waitForFunction(() => document.querySelector('.pwa-connectivity-banner')?.textContent.includes('Back online.'));
    check(Date.now() - noticeStartedAt >= 850, 'back-online notice waits about one second before appearing');
    await page.waitForTimeout(1200);
    check(automaticNavigations === 0 && await page.locator('h1').textContent() === 'Courses', 'real background refresh leaves the cached page open without automatic reload');
    courseDelay = 0;
    await page.locator('.pwa-connectivity-banner button').click();
    await page.waitForFunction(() => document.querySelector('h1')?.textContent === 'Courses updated');
    check(await page.locator('meta[name="lcs-pwa-snapshot"]').count() === 0, 'manual Refresh loads the current live page');
    await page.goto(base + 'calendar?view=week');

    await context.setOffline(true);
    await page.locator('.pwa-connectivity-banner.is-offline').waitFor();
    check((await page.locator('.pwa-connectivity-banner').textContent()).includes('Offline — live information is unavailable.'),
      'the in-app offline status remains visible on the current page');
    for (const [leaf, heading] of [
      ['dashboard', 'Dashboard'], ['courses', 'Courses updated'], ['course?id=9', 'Course 9'],
      ['calendar?view=week', 'Calendar'],
    ]) {
      await page.goto(base + leaf);
      check(await page.locator('h1').textContent() === heading, `${heading} loads from its safe page cache offline`);
    }
    await page.goto(base + 'courses.php');
    check(page.url() === base + 'courses' && await page.locator('h1').textContent() === 'Courses updated', 'old PHP bookmark redirects to the cached clean page while offline');
    check(await page.evaluate(async () => {
      const names=(await caches.keys()).filter(name=>name.startsWith('lcs-pages-'));
      const urls=(await Promise.all(names.map(async name=>(await (await caches.open(name)).keys()).map(r=>r.url)))).flat();
      return !urls.some(url=>new URL(url).pathname.endsWith('.php'));
    }), 'actual private cache has only canonical entries, not clean/PHP duplicates');
    await page.goto(base + 'course?id=9');
    await page.goto(base + 'calendar?view=week');
    await page.goBack();
    check(await page.locator('h1').textContent() === 'Course 9', 'browser Back restores a cached application page');
    await page.goto(base + 'calendar?view=week');
    const documentOffline = await page.evaluate(async baseUrl => {
      try { await fetch(baseUrl + 'download.php?type=document&id=7'); return false; }
      catch (_) { return true; }
    }, base);
    check(documentOffline, 'document download stays network-only while offline');
    const resourcePage = await context.newPage();
    resourcePage.on('download', download => downloads.push(download));
    let documentNavigationFailed = false;
    try { await resourcePage.goto(base + 'download.php?type=document&id=7'); }
    catch (error) { documentNavigationFailed = /net::ERR_|NS_ERROR_/.test(String(error)); }
    check(documentNavigationFailed && await resourcePage.locator('#offline-title').count() === 0, 'offline document navigation receives no substitute HTML document');
    await resourcePage.close();

    await page.goto(base + 'assignment_tests?category=all');
    check(await page.locator('#offline-title').textContent() === "You're offline",
      'uncached approved page receives the minimal offline document');
    check(await page.locator('#offline-retry').getAttribute('href') === base + 'assignment_tests?category=all',
      'Try again targets the failed read page without a redirect or download URL');
    await page.locator('#offline-home').click();
    check(await page.locator('h1').textContent() === 'Dashboard', 'Go back home opens the cached app home while offline');
    await page.goto(base + 'assignment_tests?category=all');
    await context.setOffline(false);
    await page.waitForTimeout(1200);
    check(await page.locator('#offline-title').count() === 1, 'offline fallback stays put after reconnect until a user chooses an action');
    await page.locator('#offline-retry').click();
    check(await page.locator('h1').textContent() === 'Assessment workspace' && downloads.length === 0,
      'Try again loads current content after reconnect without triggering a download');

    courseDelay = 700;
    courseStatus = 401;
    courseTitle = 'Session expired';
    await page.goto(base + 'courses', { waitUntil: 'domcontentloaded' });
    check(await page.locator('h1').textContent() === 'Courses updated', 'cached read-only page wins while a delayed session check runs');
    await page.waitForFunction(() => document.querySelector('h1')?.textContent === 'Session expired');
    check(!(await page.evaluate(async () => (await caches.keys()).some(name => name.startsWith('lcs-pages-') || name === 'lcs-private-context-v1'))),
      'delayed auth failure revalidates the visible page and clears all private state');
    check(errors.length === 0, 'real service-worker browser flow has no page errors: ' + errors.join(', '));
    await context.close();
    console.log(`PWA service-worker browser assertions passed: ${checks}`);
  } finally {
    if (browser) await browser.close();
    await new Promise(resolve => server.close(resolve));
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
