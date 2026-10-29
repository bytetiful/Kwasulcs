const fs = require('node:fs');
const net = require('node:net');
const path = require('node:path');
const {spawn} = require('node:child_process');
const assert = require('node:assert/strict');
const {chromium} = require('/Users/anatulu-ihemobinna/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

const root = path.resolve(__dirname, '..');
const php = '/Applications/XAMPP/xamppfiles/bin/php';
const testEmail = 'privacy-contact-test@example.invalid';
const expectedDescription = 'Privacy Policy for the KWASU Lecture Capturing System (LCS), including information about academic data, notifications, AI-assisted features, WhatsApp communications and user privacy rights.';
let browser;
let server;
let checks = 0;

function check(value, label) {
  assert.ok(value, label);
  checks++;
  console.log('PASS:', label);
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
      const socket = net.connect(port, '127.0.0.1', () => {
        socket.end();
        resolve();
      });
      socket.on('error', () => {
        if (Date.now() - started > 5000) reject(new Error('PHP server did not start'));
        else setTimeout(probe, 40);
      });
    };
    probe();
  });
}

(async () => {
  const source = fs.readFileSync(path.join(root, 'privacy-policy.php'), 'utf8');
  check(!source.includes('@example.') && !source.includes('@kwasu'), 'policy source contains no invented email address');
  check(!/header\s*\(\s*['"]Location:/i.test(source), 'policy source has no authentication redirect');
  check(!source.includes('db()') && !source.includes('->prepare(') && !source.includes('->exec('), 'policy source performs no database access or mutation');
  check((source.match(/<section class="privacy-section"/g) || []).length === 17, 'policy source contains all 17 numbered sections');
  check(!source.includes('Effective Date') && !source.includes('Last Updated'), 'policy omits Effective Date and Last Updated');
  check(source.includes('recognised by the Faculty of Information and Communication Technology (ICT), Kwara State University, Malete'), 'required Faculty of ICT recognition wording is unchanged');
  check(!source.includes('officially operated by KWASU') && !source.includes('owned by KWASU') && !source.includes('official KWASU platform'), 'policy contains no prohibited ownership or operation claim');

  const port = await freePort();
  server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', root], {
    env: {...process.env, LCS_PRIVACY_EMAIL: testEmail},
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  await waitForServer(port);

  browser = await chromium.launch({
    headless: true,
    executablePath: '/Users/anatulu-ihemobinna/Library/Caches/ms-playwright/chromium_headless_shell-1228/chrome-headless-shell-mac-x64/chrome-headless-shell',
  });

  const viewports = [
    {width: 1440, height: 1000, label: 'desktop'},
    {width: 393, height: 852, label: '393x852'},
    {width: 375, height: 812, label: '375x812'},
  ];

  for (const viewport of viewports) {
    for (const theme of ['light', 'dark']) {
      const context = await browser.newContext({viewport: {width: viewport.width, height: viewport.height}});
      await context.addInitScript(value => localStorage.setItem('theme', value), theme);
      const page = await context.newPage();
      const browserErrors = [];
      const foreignRequests = [];
      page.on('pageerror', error => browserErrors.push(error.message));
      page.on('request', request => {
        if (!request.url().startsWith(`http://127.0.0.1:${port}/`)) foreignRequests.push(request.url());
      });

      const response = await page.goto(`http://127.0.0.1:${port}/privacy-policy.php`, {waitUntil: 'networkidle'});
      check(response.status() === 200, `${viewport.label} ${theme} public route returns 200`);
      check(page.url().endsWith('/privacy-policy.php'), `${viewport.label} ${theme} route does not redirect to login`);
      check(await page.title() === 'KWASU LCS Privacy Policy', `${viewport.label} ${theme} title is correct`);
      check(await page.locator('meta[name="description"]').getAttribute('content') === expectedDescription, `${viewport.label} ${theme} meta description is correct`);
      check(await page.locator('.privacy-section').count() === 17, `${viewport.label} ${theme} renders 17 policy sections`);
      check(await page.locator('.privacy-section-number').allTextContents().then(values => values.join(' ') === Array.from({length: 17}, (_, index) => `${index + 1}.`).join(' ')), `${viewport.label} ${theme} renders sections numbered 1 through 17 in order`);
      check((await page.locator('.privacy-email').innerText()).trim() === testEmail, `${viewport.label} ${theme} renders the configured contact email`);
      check(await page.locator('.privacy-back-link').getAttribute('href') === 'index.php', `${viewport.label} ${theme} exposes a safe Back to LCS link`);
      check(await page.locator('.privacy-skip-link').getAttribute('href') === '#main-content', `${viewport.label} ${theme} exposes a keyboard skip link`);
      check((await page.locator('.privacy-footer').innerText()).includes(String(new Date().getFullYear())), `${viewport.label} ${theme} renders the current year dynamically`);
      check(await page.locator('.app-sidebar, .bottom-nav, form.login-form').count() === 0, `${viewport.label} ${theme} exposes no authenticated application navigation`);
      check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `${viewport.label} ${theme} has no horizontal overflow`);
      check(await page.evaluate(() => {
        const parse = value => (value.match(/[\d.]+/g) || []).slice(0, 3).map(Number);
        const luminance = rgb => {
          const adjusted = rgb.map(value => {
            const channel = value / 255;
            return channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
          });
          return 0.2126 * adjusted[0] + 0.7152 * adjusted[1] + 0.0722 * adjusted[2];
        };
        const text = getComputedStyle(document.querySelector('.privacy-section p')).color;
        const background = getComputedStyle(document.querySelector('.privacy-document')).backgroundColor;
        const first = luminance(parse(text));
        const second = luminance(parse(background));
        return (Math.max(first, second) + 0.05) / (Math.min(first, second) + 0.05);
      }) >= 4.5, `${viewport.label} ${theme} body copy meets WCAG AA contrast`);
      check(browserErrors.length === 0, `${viewport.label} ${theme} has zero browser errors: ${browserErrors.join(', ')}`);
      check(foreignRequests.length === 0, `${viewport.label} ${theme} makes no external requests`);

      if (viewport.width < 400) {
        await page.evaluate(() => { document.documentElement.style.fontSize = '125%'; });
        check(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), `${viewport.label} ${theme} remains overflow-free at 125% root text size`);
      }

      await page.screenshot({path: `/private/tmp/privacy-policy-${viewport.label}-${theme}.png`});
      await page.screenshot({path: `/private/tmp/privacy-policy-${viewport.label}-${theme}-full.png`, fullPage: true});
      await context.close();
    }
  }

  const publicPage = await browser.newPage({viewport: {width: 393, height: 852}});
  const indexResponse = await publicPage.goto(`http://127.0.0.1:${port}/index.php`, {waitUntil: 'domcontentloaded'});
  check(indexResponse.status() === 200, 'existing public login page still returns 200');
  check(await publicPage.locator('a[href="privacy-policy"], a[href="privacy-policy.php"]').count() === 1, 'login footer includes one unobtrusive Privacy Policy link');
  check(await publicPage.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'login page remains overflow-free at 393px');
  await publicPage.close();

  console.log(`${checks} Privacy Policy assertions passed; screenshots in /private/tmp/privacy-policy-*.png.`);
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
}).finally(async () => {
  if (browser) await browser.close();
  if (server) server.kill('SIGTERM');
});
