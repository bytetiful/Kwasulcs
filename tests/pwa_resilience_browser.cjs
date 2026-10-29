const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const assert = require('node:assert/strict');
const {spawn, execFileSync} = require('node:child_process');
const {chromium} = require('/Users/anatulu-ihemobinna/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

const root = path.resolve(__dirname, '..');
const php = '/Applications/XAMPP/xamppfiles/bin/php';
const fixture = path.join(__dirname, 'privacy_acknowledgement_fixture.php');
const sessionDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pwa-resilience-'));
const sessionId = 'pwaresilience' + Math.random().toString(16).slice(2);
let server;
let browser;
let setup;
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; console.log('PASS:', label); }
function fixtureCall(...args) {
  return execFileSync(php, [fixture, ...args.map(String)], {
    encoding:'utf8', env:{...process.env, LCS_PRIVACY_POLICY_VERSION:'2026-09'}
  }).trim();
}
async function freePort() {
  return new Promise((resolve, reject) => {
    const socket = net.createServer();
    socket.listen(0, '127.0.0.1', () => { const port = socket.address().port; socket.close(() => resolve(port)); });
    socket.on('error', reject);
  });
}
async function waitForServer(port) {
  return new Promise((resolve, reject) => {
    const started = Date.now();
    const probe = () => {
      const socket = net.connect(port, '127.0.0.1', () => { socket.end(); resolve(); });
      socket.on('error', () => Date.now() - started > 5000 ? reject(new Error('PHP server did not start')) : setTimeout(probe, 50));
    };
    probe();
  });
}

(async () => {
  setup = JSON.parse(fixtureCall('setup', sessionDir, sessionId));
  fixtureCall('state', setup.userId, 'current');
  const port = await freePort();
  const origin = `http://localhost:${port}`;
  server = spawn(php, ['-d', `session.save_path=${sessionDir}`, '-S', `127.0.0.1:${port}`, '-t', root, path.join(__dirname,'public_routes_router.php')], {
    env:{...process.env, LCS_PRIVACY_POLICY_VERSION:setup.version}, stdio:['ignore', 'pipe', 'pipe']
  });
  await waitForServer(port);
  browser = await chromium.launch({
    headless:true,
    executablePath:'/Users/anatulu-ihemobinna/Library/Caches/ms-playwright/chromium_headless_shell-1228/chrome-headless-shell-mac-x64/chrome-headless-shell'
  });
  const context = await browser.newContext({viewport:{width:393,height:852}, serviceWorkers:'allow'});
  await context.addCookies([{name:'PHPSESSID', value:sessionId, domain:'localhost', path:'/'}]);
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));

  let response = await page.goto(`${origin}/dashboard`, {waitUntil:'domcontentloaded'});
  check(response.status() === 200, 'authenticated Dashboard loads normally before service-worker control');
  // Wait for first registration to claim the client without reloading it.
  await page.waitForFunction(() => !!navigator.serviceWorker.controller, null, {timeout:15000});
  await page.waitForTimeout(600);
  response = await page.goto(`${origin}/dashboard`, {waitUntil:'domcontentloaded'});
  await page.waitForTimeout(400);
  const dashboardHeaders = response.headers();
  check(dashboardHeaders['x-lcs-pwa-cache'] === 'user-page', 'authorized Dashboard response explicitly opts into page caching');
  check(/^[a-f0-9]{16,64}$/.test(dashboardHeaders['x-lcs-pwa-user-key'] || ''), 'server exposes only an opaque cache scope, never a raw user id');

  await page.goto(`${origin}/courses`, {waitUntil:'domcontentloaded'});
  await page.waitForTimeout(400);
  let snapshot = await page.evaluate(async () => {
    const names = await caches.keys();
    const pageCaches = names.filter(name => name.startsWith('lcs-pages-'));
    const entries = [];
    for (const name of pageCaches) for (const request of await (await caches.open(name)).keys()) entries.push(request.url);
    return {names, pageCaches, entries};
  });
  check(snapshot.pageCaches.length === 1 && !snapshot.pageCaches[0].includes(String(setup.userId)), 'browser uses one opaque user namespace without a raw database id');
  check(snapshot.entries.some(url => url.endsWith('/dashboard')) && snapshot.entries.some(url => url.endsWith('/courses')), 'successful authorized safe GET pages populate the page cache');

  await context.setOffline(true);
  await page.goto(`${origin}/dashboard`, {waitUntil:'domcontentloaded'});
  check(await page.locator('meta[name="lcs-pwa-snapshot"]').count() === 1, 'offline Dashboard navigation renders its saved read-only snapshot');
  check(await page.locator('#offline-title').count() === 0, 'offline shell is not used when a valid safe-page snapshot exists');
  check(await page.locator('.pwa-connectivity-banner').isVisible(), 'saved authenticated page shows persistent offline status');
  check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'offline saved page has no 393px horizontal overflow');

  await context.setOffline(false);
  await page.goto(`${origin}/settings`, {waitUntil:'domcontentloaded'});
  snapshot = await page.evaluate(async () => {
    const urls = [];
    for (const name of (await caches.keys()).filter(name => name.startsWith('lcs-pages-'))) {
      for (const request of await (await caches.open(name)).keys()) urls.push(request.url);
    }
    return urls;
  });
  check(!snapshot.some(url => /settings\.php/.test(url)), 'Settings remains absent from the authenticated page cache');
  await context.setOffline(true);
  let settingsFailure = false;
  try { await page.goto(`${origin}/settings`, {waitUntil:'domcontentloaded'}); }
  catch (error) { settingsFailure = /net::ERR_|NS_ERROR_/.test(String(error)); }
  check(settingsFailure && await page.locator('#offline-title').count() === 0,
    'uncached sensitive Settings navigation fails natively without a standalone offline page');

  await context.setOffline(false);
  await page.goto(`${origin}/courses`, {waitUntil:'domcontentloaded'});
  await page.waitForTimeout(300);
  await context.setOffline(true);
  await page.goto(`${origin}/courses`, {waitUntil:'domcontentloaded'});
  check(await page.locator('meta[name="lcs-pwa-snapshot"]').count() === 1, 'another safe page remains navigable after connectivity loss');

  await context.setOffline(false);
  fs.rmSync(path.join(sessionDir, `sess_${sessionId}`), {force:true});
  await page.goto(`${origin}/courses`, {waitUntil:'domcontentloaded'});
  check(page.url().endsWith('/index.php'), 'server session loss redirects to login and remains authoritative');
  snapshot = await page.evaluate(async () => {
    const names = await caches.keys();
    const urls = [];
    for (const name of names.filter(name => name.startsWith('lcs-pages-'))) {
      for (const request of await (await caches.open(name)).keys()) urls.push(request.url);
    }
    return {names, urls};
  });
  check(!snapshot.names.some(name => name.startsWith('lcs-pages-') || name.startsWith('lcs-offline-') || name === 'lcs-private-context-v1'), 'login redirect clears all private page, resource, and user-context caches');
  check(!snapshot.urls.some(url => url.endsWith('/courses')), 'login redirect invalidates the previously authorized page snapshot');

  await context.setOffline(true);
  await page.goto(`${origin}/courses`, {waitUntil:'domcontentloaded'});
  check(await page.locator('#offline-title').count() === 1 && await page.locator('meta[name="lcs-pwa-snapshot"]').count() === 0,
    'authorization-invalidated page shows only the public fallback, never private cached content');
  await context.setOffline(false);
  check(errors.length === 0, 'logged-in resilience browser run has no page errors: ' + errors.join(', '));
  await context.close();
  console.log(`PWA resilience browser assertions passed: ${checks}`);
})().catch(error => { console.error(error); process.exitCode = 1; }).finally(async () => {
  if (browser) await browser.close();
  if (server) server.kill('SIGTERM');
  if (setup) { try { fixtureCall('cleanup', setup.userId, setup.otherUserId); } catch (_) {} }
  fs.rmSync(sessionDir, {recursive:true, force:true});
});
