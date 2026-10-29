const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {spawn, execFileSync} = require('node:child_process');
const assert = require('node:assert/strict');
const {chromium} = require('/Users/anatulu-ihemobinna/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

const root = path.resolve(__dirname, '..');
const php = '/Applications/XAMPP/xamppfiles/bin/php';
const fixture = path.join(__dirname, 'privacy_acknowledgement_fixture.php');
const sessionDir = fs.mkdtempSync(path.join(os.tmpdir(), 'privacy-ack-'));
const sessionId = 'privacyack' + Math.random().toString(16).slice(2);
let server;
let browser;
let setup;
let checks = 0;

function check(value, label) {
  assert.ok(value, label);
  checks++;
  console.log('PASS:', label);
}

function fixtureCall(...args) {
  return execFileSync(php, [fixture, ...args.map(String)], {
    encoding: 'utf8',
    env: {...process.env, LCS_PRIVACY_POLICY_VERSION: '2026-09'},
  }).trim();
}

function setState(state) {
  fixtureCall('state', setup.userId, state);
}

function countFor(userId, version = setup.version) {
  return Number(fixtureCall('count', userId, version));
}

async function freePort() {
  return await new Promise((resolve, reject) => {
    const socket = net.createServer();
    socket.listen(0, '127.0.0.1', () => {
      const port = socket.address().port;
      socket.close(() => resolve(port));
    });
    socket.on('error', reject);
  });
}

async function waitForServer(port) {
  await new Promise((resolve, reject) => {
    const started = Date.now();
    const probe = () => {
      const socket = net.connect(port, '127.0.0.1', () => { socket.end(); resolve(); });
      socket.on('error', () => Date.now() - started > 5000
        ? reject(new Error('PHP server did not start'))
        : setTimeout(probe, 40));
    };
    probe();
  });
}

async function signedInContext(viewport, theme = 'light') {
  const context = await browser.newContext({viewport});
  await context.addInitScript(value => localStorage.setItem('theme', value), theme);
  await context.addCookies([{name: 'PHPSESSID', value: sessionId, domain: '127.0.0.1', path: '/'}]);
  return context;
}

(async () => {
  setup = JSON.parse(fixtureCall('setup', sessionDir, sessionId));
  const port = await freePort();
  server = spawn(php, ['-d', `session.save_path=${sessionDir}`, '-S', `127.0.0.1:${port}`, '-t', root,
    path.join(__dirname, 'public_routes_router.php')], {
    env: {...process.env, LCS_PRIVACY_POLICY_VERSION: setup.version},
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  await waitForServer(port);
  browser = await chromium.launch({
    headless: true,
    executablePath: '/Users/anatulu-ihemobinna/Library/Caches/ms-playwright/chromium_headless_shell-1228/chrome-headless-shell-mac-x64/chrome-headless-shell',
  });

  const anonymous = await browser.newContext({viewport: {width: 393, height: 852}});
  const anonymousPage = await anonymous.newPage();
  let response = await anonymousPage.goto(`http://127.0.0.1:${port}/index.php`);
  check(response.status() === 200 && await anonymousPage.locator('#privacyAcknowledgementModal').count() === 0, 'logged-out login page shows no acknowledgement modal');
  response = await anonymousPage.goto(`http://127.0.0.1:${port}/privacy-policy.php`);
  check(response.status() === 200 && anonymousPage.url().endsWith('/privacy-policy.php'), 'privacy-policy.php remains public');
  check(await anonymousPage.locator('#privacyAcknowledgementModal').count() === 0, 'public Privacy Policy shows no authenticated modal');
  response = await anonymousPage.request.post(`http://127.0.0.1:${port}/privacy_policy_acknowledge.php`, {data: {}});
  check(response.status() === 401, 'acknowledgement POST requires authentication');
  await anonymous.close();

  setState('clear');
  const loginContext = await browser.newContext({viewport: {width: 393, height: 852}});
  const loginPage = await loginContext.newPage();
  await loginPage.goto(`http://127.0.0.1:${port}/index.php`);
  await loginPage.locator('input[name="matric_no"]').fill(setup.matric);
  await loginPage.locator('input[name="password"]').fill(setup.password);
  await Promise.all([
    loginPage.waitForURL(url => /\/dashboard(?:\.php)?$/.test(url.pathname)),
    loginPage.locator('button[type="submit"]').click(),
  ]);
  check(await loginPage.locator('#privacyAcknowledgementModal').isVisible(), 'successful existing login continues to Dashboard and shows the modal');
  await loginContext.close();

  const context = await signedInContext({width: 1280, height: 900});
  const page = await context.newPage();
  setState('clear');
  await page.goto(`http://127.0.0.1:${port}/dashboard.php`);
  const modal = page.locator('#privacyAcknowledgementModal');
  check(await modal.isVisible(), 'first-time authenticated user sees the modal');
  check(await modal.getAttribute('aria-modal') === 'true' && await modal.getAttribute('role') === 'dialog', 'modal exposes accessible dialog semantics');
  check(await modal.locator('button[aria-label*="close" i], .modal-close').count() === 0, 'modal has no ordinary close control');
  check(!(await modal.innerText()).includes('I Agree'), 'acknowledgement copy does not use contractual agreement wording');
  check(await page.evaluate(() => document.activeElement?.textContent?.includes('Read Privacy Policy')), 'initial keyboard focus is on Read Privacy Policy');

  await page.keyboard.press('Shift+Tab');
  check(await page.evaluate(() => document.activeElement?.id === 'privacyAcknowledgementSubmit'), 'focus trap wraps backward to the acknowledgement action');
  await page.keyboard.press('Tab');
  check(await page.evaluate(() => document.activeElement?.textContent?.includes('Read Privacy Policy')), 'focus trap wraps forward to the read action');
  await page.keyboard.press('Escape');
  check(await modal.isVisible(), 'Escape cannot dismiss the modal prematurely');
  await page.mouse.click(3, 3);
  check(await modal.isVisible(), 'backdrop click cannot dismiss the modal prematurely');

  const [policyTab] = await Promise.all([
    page.waitForEvent('popup'),
    modal.locator('a', {hasText: 'Read Privacy Policy'}).click(),
  ]);
  await policyTab.waitForLoadState('domcontentloaded');
  check(/\/privacy-policy(?:\.php)?$/.test(new URL(policyTab.url()).pathname), 'Read Privacy Policy opens the public policy in a new tab');
  check(countFor(setup.userId) === 0, 'reading the policy does not record acknowledgement');
  await policyTab.close();

  const csrf = await modal.getAttribute('data-csrf');
  response = await page.request.get(`http://127.0.0.1:${port}/privacy_policy_acknowledge.php`);
  check(response.status() === 405, 'acknowledgement endpoint is POST-only');
  response = await page.request.post(`http://127.0.0.1:${port}/privacy_policy_acknowledge.php`, {data: {}});
  check(response.status() === 403, 'acknowledgement POST requires CSRF');
  response = await page.request.post(`http://127.0.0.1:${port}/privacy_policy_acknowledge.php`, {
    data: {user_id: setup.otherUserId},
    headers: {'X-CSRF-Token': csrf},
  });
  check(response.status() === 200 && (await response.json()).status === 'ok', 'valid authenticated acknowledgement succeeds');
  check(countFor(setup.userId) === 1 && countFor(setup.otherUserId) === 0, 'client-supplied user ID cannot acknowledge for another user');
  response = await page.request.post(`http://127.0.0.1:${port}/privacy_policy_acknowledge.php`, {
    data: {user_id: setup.otherUserId},
    headers: {'X-CSRF-Token': csrf},
  });
  check(response.status() === 200 && countFor(setup.userId) === 1, 'duplicate acknowledgement is harmless and remains unique');

  setState('clear');
  await page.reload();
  await page.locator('#privacyAcknowledgementModal').evaluate(element => { element.dataset.csrf = 'invalid-token'; });
  await page.locator('#privacyAcknowledgementSubmit').click();
  await page.locator('#privacyAcknowledgementError').waitFor({state: 'visible'});
  check(await page.locator('#privacyAcknowledgementModal').isVisible(), 'failed acknowledgement keeps the modal open');
  check((await page.locator('#privacyAcknowledgementError').innerText()).includes('Invalid request token'), 'failed acknowledgement presents an inline retryable error');

  setState('old');
  await page.reload();
  check(await page.locator('#privacyAcknowledgementModal').isVisible(), 'acknowledgement for an old version still shows the current modal');
  setState('current');
  await page.reload();
  check(await page.locator('#privacyAcknowledgementModal').count() === 0, 'current-version acknowledgement suppresses the modal');

  setState('clear');
  await page.reload();
  await page.locator('#privacyAcknowledgementSubmit').click();
  await page.locator('#privacyAcknowledgementModal').waitFor({state: 'hidden'});
  check(countFor(setup.userId) === 1, 'acknowledgement button records the server-side current version');
  check(await page.evaluate(() => !document.body.classList.contains('privacy-ack-open') && !document.querySelector('body > [inert]')), 'successful acknowledgement restores normal page interaction');
  await page.reload();
  check(await page.locator('#privacyAcknowledgementModal').count() === 0, 'successful acknowledgement remains suppressed after reload');
  await context.close();

  for (const route of ['dashboard.php', 'courses.php', 'calendar.php', 'chat.php']) {
    setState('clear');
    const routeContext = await signedInContext({width: 393, height: 852});
    const routePage = await routeContext.newPage();
    response = await routePage.goto(`http://127.0.0.1:${port}/${route}`);
    check(response.status() === 200 && await routePage.locator('#privacyAcknowledgementModal').isVisible(), `${route} receives the modal through the shared authenticated shell`);
    await routeContext.close();
  }

  for (const viewport of [{width: 375, height: 812}, {width: 393, height: 852}]) {
    for (const theme of ['light', 'dark']) {
      setState('clear');
      const responsiveContext = await signedInContext(viewport, theme);
      const responsivePage = await responsiveContext.newPage();
      await responsivePage.goto(`http://127.0.0.1:${port}/dashboard.php`);
      const dialog = responsivePage.locator('.privacy-ack-dialog');
      check(await dialog.isVisible(), `${viewport.width}x${viewport.height} ${theme} renders the modal`);
      check(await responsivePage.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${viewport.width}x${viewport.height} ${theme} has no horizontal overflow`);
      check(await dialog.evaluate(element => {
        const rect = element.getBoundingClientRect();
        return rect.left >= 0 && rect.right <= innerWidth && rect.top >= 0 && rect.bottom <= innerHeight;
      }), `${viewport.width}x${viewport.height} ${theme} dialog fits inside the viewport`);
      check(await responsivePage.evaluate(expected => {
        const active = document.documentElement.getAttribute('data-theme');
        return expected === 'dark' ? active === 'dark' : active !== 'dark';
      }, theme), `${viewport.width}x${viewport.height} ${theme} applies the requested theme`);
      await responsivePage.screenshot({path: `/private/tmp/privacy-ack-${viewport.width}-${theme}.png`});
      await responsiveContext.close();
    }
  }

  setState('clear');
  const landscapeContext = await signedInContext({width: 812, height: 375});
  const landscapePage = await landscapeContext.newPage();
  await landscapePage.goto(`http://127.0.0.1:${port}/dashboard.php`);
  check(await landscapePage.locator('.privacy-ack-dialog').isVisible(), 'mobile landscape renders the acknowledgement dialog');
  check(await landscapePage.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'mobile landscape has no horizontal overflow');
  check(await landscapePage.locator('.privacy-ack-dialog').evaluate(element => {
    const rect = element.getBoundingClientRect();
    return rect.left >= 0 && rect.right <= innerWidth && rect.top >= 0 && rect.bottom <= innerHeight;
  }), 'mobile landscape keeps the scrollable dialog inside the viewport');
  await landscapeContext.close();

  setState('clear');
  const accessibilityContext = await browser.newContext({
    viewport: {width: 393, height: 852},
    reducedMotion: 'reduce',
  });
  await accessibilityContext.addInitScript(() => {
    localStorage.setItem('theme', 'dark');
    document.documentElement.style.fontSize = '125%';
  });
  await accessibilityContext.addCookies([{name: 'PHPSESSID', value: sessionId, domain: '127.0.0.1', path: '/'}]);
  const accessibilityPage = await accessibilityContext.newPage();
  await accessibilityPage.goto(`http://127.0.0.1:${port}/dashboard.php`);
  check(await accessibilityPage.locator('.privacy-ack-dialog').isVisible(), 'reduced-motion mode with 125% text scaling renders the dialog');
  check(await accessibilityPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth), '125% text scaling has no horizontal overflow');
  check(await accessibilityPage.locator('.privacy-ack-button').evaluateAll(elements => elements.every(element => {
    const rect = element.getBoundingClientRect();
    return rect.height >= 44 && rect.width >= 44;
  })), 'modal actions retain accessible touch targets');
  await accessibilityContext.close();

  console.log(`${checks} Privacy acknowledgement assertions passed; screenshots in /private/tmp/privacy-ack-*.png.`);
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
}).finally(async () => {
  if (browser) await browser.close();
  if (server) server.kill('SIGTERM');
  if (setup) {
    try { fixtureCall('cleanup', setup.userId, setup.otherUserId); } catch (_) {}
  }
  fs.rmSync(sessionDir, {recursive: true, force: true});
});
