const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { chromium } = require('/Users/anatulu-ihemobinna/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root = path.resolve(__dirname, '..');
let checks = 0;
function check(value, label) { assert.ok(value, label); checks++; console.log('PASS:', label); }

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: '/Users/anatulu-ihemobinna/Library/Caches/ms-playwright/chromium_headless_shell-1228/chrome-headless-shell-mac-x64/chrome-headless-shell' });
  try {
    for (const viewport of [{ width: 375, height: 812 }, { width: 393, height: 852 }, { width: 812, height: 375 }, { width: 1440, height: 900 }]) {
      for (const colorScheme of ['light', 'dark']) {
        const page = await browser.newPage({ viewport, colorScheme, reducedMotion: 'reduce' });
        const errors = [];
        const downloads = [];
        let connected = false;
        page.on('pageerror', error => errors.push(error.message));
        page.on('download', download => downloads.push(download));
        await page.route('**/*', async route => {
          const url = new URL(route.request().url());
          if (url.hostname !== 'offline.test') return route.abort();
          if (url.pathname.endsWith('/assets/offline.js')) return route.fulfill({ path: path.join(root, 'assets/offline.js'), contentType: 'text/javascript' });
          if (connected && /\/(?:dashboard|calendar)(?:\.php)?$/.test(url.pathname)) return route.fulfill({ body: '<!doctype html><h1>Live page</h1>', contentType: 'text/html' });
          return route.fulfill({ path: path.join(root, 'offline.html'), contentType: 'text/html' });
        });
        const base = 'https://offline.test/LMS-portal/';
        await page.goto(base + 'calendar?view=day&date=2026-10-12');
        check(await page.locator('#offline-title').textContent() === "You're offline", `${viewport.width} ${colorScheme} minimal offline message renders`);
        check(await page.locator('.actions a').count() === 2, `${viewport.width} ${colorScheme} has only retry and home actions`);
        check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${viewport.width} ${colorScheme} no horizontal overflow`);
        if (process.argv[2] && viewport.width === 393) await page.screenshot({ path: path.join(process.argv[2], `offline-393-${colorScheme}.png`) });
        check(await page.locator('#offline-retry').getAttribute('href') === base + 'calendar?view=day&date=2026-10-12', `${viewport.width} ${colorScheme} retry preserves the failed read page`);
        check(await page.locator('#offline-home').getAttribute('href') === base + 'dashboard', `${viewport.width} ${colorScheme} home stays inside app scope`);
        await page.evaluate(() => document.documentElement.style.fontSize = '200%');
        check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${viewport.width} ${colorScheme} no overflow with enlarged text`);
        connected = true;
        await page.evaluate(() => window.dispatchEvent(new Event('online')));
        check(await page.locator('#offline-title').count() === 1, `${viewport.width} ${colorScheme} reconnect does not navigate automatically`);
        await page.locator('#offline-retry').click();
        check(await page.locator('h1').textContent() === 'Live page', `${viewport.width} ${colorScheme} Try again navigates on click`);
        connected = false;
        await page.goto(base + 'offline.html?url=download.php%3Fid%3D9');
        check(await page.locator('#offline-retry').getAttribute('href') === base + 'dashboard', `${viewport.width} ${colorScheme} direct fallback ignores a supplied download destination`);
        connected = true;
        await page.locator('#offline-home').click();
        check(page.url() === base + 'dashboard', `${viewport.width} ${colorScheme} Go back home navigates cleanly`);
        check(downloads.length === 0 && errors.length === 0, `${viewport.width} ${colorScheme} actions cause no downloads or page errors`);
        await page.close();
      }
    }
    console.log(`Offline navigation browser assertions passed: ${checks}`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
