const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {spawn, execFileSync} = require('node:child_process');
const assert = require('node:assert/strict');
const {chromium} = require('/Users/anatulu-ihemobinna/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

const root = path.resolve(__dirname, '..');
const php = '/Applications/XAMPP/xamppfiles/bin/php';
const fixture = path.join(__dirname, 'telegram_settings_browser_fixture.php');
const sessionDir = fs.mkdtempSync(path.join(os.tmpdir(), 'tg4-settings-'));
const sessionId = 'tg4settings' + Math.random().toString(16).slice(2);
const setup = JSON.parse(execFileSync(php, [fixture, 'setup', sessionDir, sessionId], {encoding: 'utf8'}));
let server, browser, checks = 0;
function check(value, label) { assert.ok(value, label); checks++; console.log('PASS:', label); }
function setState(state) { execFileSync(php, [fixture, 'state', String(setup.userId), state]); }
function inspect() { return JSON.parse(execFileSync(php, [fixture, 'inspect', String(setup.userId)], {encoding: 'utf8'})); }
async function freePort() {
  return await new Promise((resolve, reject) => {
    const socket = net.createServer();
    socket.listen(0, '127.0.0.1', () => { const port = socket.address().port; socket.close(() => resolve(port)); });
    socket.on('error', reject);
  });
}

(async () => {
  const port = await freePort();
  const serverEnv = Object.assign({}, process.env, {
    TELEGRAM_ENABLED: '1',
    TELEGRAM_BOT_USERNAME: 'KwasuLcsBot',
    TELEGRAM_BOT_TOKEN: '123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ_abc',
    TELEGRAM_WEBHOOK_SECRET: 'browser-fixture-secret-that-must-not-appear',
  });
  server = spawn(php, ['-d', `session.save_path=${sessionDir}`, '-S', `127.0.0.1:${port}`, '-t', root,
    path.join(__dirname, 'public_routes_router.php')], {
    stdio: ['ignore', 'pipe', 'pipe'], env: serverEnv
  });
  await new Promise((resolve, reject) => {
    const started = Date.now();
    const probe = () => {
      const socket = net.connect(port, '127.0.0.1', () => { socket.end(); resolve(); });
      socket.on('error', () => Date.now() - started > 5000 ? reject(new Error('PHP server did not start')) : setTimeout(probe, 40));
    };
    probe();
  });
  browser = await chromium.launch({headless: true, executablePath: '/Users/anatulu-ihemobinna/Library/Caches/ms-playwright/chromium_headless_shell-1228/chrome-headless-shell-mac-x64/chrome-headless-shell'});

  const unauthenticated = await browser.newContext();
  const unauthStatus = await unauthenticated.request.get(`http://127.0.0.1:${port}/telegram_connection_status.php`);
  check(unauthStatus.status() === 401, 'unauthenticated connection status is blocked');
  await unauthenticated.close();

  for (const width of [1440, 393, 375]) {
    const height = width === 1440 ? 1000 : (width === 393 ? 852 : 812);
    const context = await browser.newContext({viewport: {width, height}});
    await context.addCookies([{name: 'PHPSESSID', value: sessionId, domain: '127.0.0.1', path: '/'}]);
    let telegramRequestUrl = '';
    context.on('request', request => {
      if (request.url().startsWith('https://t.me/')) telegramRequestUrl = request.url();
    });
    await context.route('https://t.me/**', route => route.abort());
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    setState('connected');
    for (const theme of ['light', 'dark']) {
      await page.addInitScript(value => localStorage.setItem('theme', value), theme);
      const response = await page.goto(`http://127.0.0.1:${port}/settings.php`);
      check(response.status() === 200, `${width} ${theme} Settings route loads`);
      await page.waitForSelector('#telegram-notifications');
      check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${width} ${theme} has no horizontal overflow`);
      check(await page.locator('.telegram-category-row').count() === 3, `${width} ${theme} renders exactly three category controls`);
      check((await page.locator('#telegram-notifications').innerText()).includes('Telegram Browser QA'), `${width} ${theme} renders safe connected identity`);
      const text = await page.locator('body').innerText();
      check(!text.includes('123456:ABCDEFGHIJKLMNOPQRSTUVWXYZ_abc') && !text.includes('browser-fixture-secret'), `${width} ${theme} exposes no Telegram credentials`);
      check(!text.includes('WhatsApp notifications'), `${width} ${theme} has no active WhatsApp settings copy`);
      check(await page.locator('form input[name="telegram_csrf"]').first().getAttribute('value').then(value => /^[a-f0-9]{64}$/.test(value)), `${width} ${theme} renders CSRF on Telegram mutations`);
      const statusPayload = await page.request.get(`http://127.0.0.1:${port}/telegram_connection_status.php`).then(result => result.json());
      const statusJson = JSON.stringify(statusPayload);
      check(!statusJson.includes('telegram_user_id') && !statusJson.includes('telegram_chat_id'), `${width} ${theme} status response omits raw Telegram IDs`);
      await page.screenshot({path: `/private/tmp/telegram-settings-${width}-${theme}.png`, fullPage: true});
    }

    if (width === 1440) {
      const rejected = await page.request.post(`http://127.0.0.1:${port}/settings.php`, {form: {telegram_action: 'set_global', enabled: '0'}});
      check(rejected.status() === 403, 'real Settings handler rejects missing CSRF');
      check(inspect().enabled, 'rejected CSRF leaves Telegram state unchanged');

      await page.reload();
      const firstSwitch = page.locator('.telegram-mini-switch').first();
      const category = await firstSwitch.getAttribute('aria-label');
      await Promise.all([page.waitForNavigation(), firstSwitch.click()]);
      check(category.startsWith('Pause ') && inspect().preferences.calendar_lecture === false, 'category switch persists through authenticated Settings POST');
    }

    if (width === 393) {
      setState('not_connected');
      await page.goto(`http://127.0.0.1:${port}/settings.php`);
      check(await page.locator('.telegram-empty-state').isVisible(), 'not-connected state is clear on mobile');
      const popupPromise = page.waitForEvent('popup');
      await page.locator('[data-telegram-connect]').click();
      const popup = await popupPromise;
      await page.waitForSelector('[data-telegram-waiting]:not([hidden])');
      check(await page.locator('[data-telegram-waiting]').isVisible(), 'connect flow shows waiting instead of falsely reporting connected');
      check(inspect().active_tokens === 1, 'connect flow creates one server-side one-time token');
      check(/^https:\/\/t\.me\/KwasuLcsBot\?start=[A-Za-z0-9_-]{43}$/.test(telegramRequestUrl), 'connect launches only the tokenized Telegram deep link');
      check(await page.evaluate(() => sessionStorage.getItem('lcsTelegramConnectionWaiting') === '1' && !JSON.stringify(sessionStorage).includes('?start=')), 'browser stores only waiting state, never the link token');
      await popup.close().catch(() => {});

      setState('connected');
      await Promise.all([page.waitForNavigation(), page.locator('[data-telegram-check]').click()]);
      check(await page.locator('.telegram-connected-bar').isVisible(), 'manual status check refreshes to connected state');

      setState('disabled'); await page.reload();
      check((await page.locator('.telegram-status-pill').innerText()).includes('Paused'), 'disabled connection is presented as paused, not disconnected');
      check((await page.locator('.telegram-paused-note').innerText()).includes('paused globally'), 'disabled state explains category choices are preserved');

      setState('blocked'); await page.reload();
      check((await page.locator('.telegram-state-card').innerText()).includes('connection unavailable'), 'blocked connection has neutral unavailable state');
      check(await page.locator('[data-telegram-connect]').isVisible(), 'blocked state offers reconnect');

      setState('connected'); await page.reload();
      const trigger = page.locator('[data-telegram-disconnect-open]');
      await trigger.focus();
      await trigger.press('Enter');
      check(await page.locator('#telegramDisconnectDialog').isVisible(), 'disconnect requires a confirmation dialog');
      check(await page.evaluate(() => document.activeElement.hasAttribute('data-telegram-disconnect-close')), 'confirmation dialog moves keyboard focus to safe action');
      await page.locator('[data-telegram-disconnect-close]').press('Enter');
      check(await trigger.evaluate(element => element === document.activeElement), 'dialog restores focus to its trigger');

      await trigger.click();
      await Promise.all([page.waitForNavigation(), page.locator('#telegramDisconnectDialog button[type="submit"]').click()]);
      check(inspect().state === 'disconnected', 'confirmed disconnect persists historical disconnected state');
      check(await page.locator('[data-telegram-connect]').isVisible(), 'disconnected historical state offers reconnect');
    }

    check(errors.length === 0, `${width} has zero browser errors: ${errors.join(', ')}`);
    await context.close();
  }
  console.log(`${checks} Telegram Settings browser assertions passed; screenshots in /private/tmp/telegram-settings-*.png.`);
})().catch(error => { console.error(error); process.exitCode = 1; }).finally(async () => {
  if (browser) await browser.close();
  if (server) server.kill('SIGTERM');
  try { execFileSync(php, [fixture, 'cleanup', String(setup.userId)]); } catch (_) {}
  fs.rmSync(sessionDir, {recursive: true, force: true});
});
