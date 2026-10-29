const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('/Users/anatulu-ihemobinna/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');

const root = path.resolve(__dirname, '..');
let checks = 0;
function check(condition, label) { assert.ok(condition, label); checks++; console.log('PASS:', label); }
const css = '<link rel="stylesheet" href="/assets/style.css"><link rel="stylesheet" href="/assets/calendar.css"><link rel="stylesheet" href="/assets/chat.css"><link rel="stylesheet" href="/assets/dashboard-header.css"><link rel="stylesheet" href="/assets/app-header.css">';
const head = `<meta name="viewport" content="width=device-width,initial-scale=1">${css}`;
const profile = '<header class="app-header"><div class="app-header-actions"><div class="app-profile"><button class="app-profile-btn" id="appProfileBtn"><span class="app-avatar">PA</span><i class="bi bi-chevron-down">⌄</i></button></div></div></header>';
const pages = {
  '/chat.php': `<!doctype html><html><head>${head}</head><body class="app-body"><section class="app-hero chat-hero">${profile}<header class="topbar"><h1>Assessment/Chat</h1></header></section><main class="page"><div class="panel">Groups</div></main></body></html>`,
  '/dashboard.php': `<!doctype html><html><head>${head}</head><body class="app-body"><section class="dashboard-hero">${profile}<div class="dashboard-welcome"><h1>Welcome</h1></div></section></body></html>`,
  '/notifications.php': `<!doctype html><html><head>${head}</head><body class="app-body"><main class="page"><div class="panel notif-panel"><div class="notif-row notif-row--unread" style="border-left-color:#ea580c"><div class="notif-icon" style="background:#ea580c18;color:#ea580c">!</div><div class="notif-content"><div class="notif-message">Unread reminder</div><div class="notif-meta">Course and time</div></div></div><div class="notif-row notif-row--read"><div class="notif-icon">•</div><div class="notif-content"><div class="notif-message">Earlier reminder</div></div></div></div></main></body></html>`
};
const courseSource = fs.readFileSync(path.join(root, 'courses.php'), 'utf8');
const courseDisclosure = courseSource.match(/<details class="course-filter-disclosure"[\s\S]*?<\/details>/)?.[0]
  .replace(/<\?php foreach \(\$categoryCounts as \$cat => \$count\): \?>[\s\S]*?<\?php endforeach; \?>/, '<option value="CSC">CSC (2)</option><option value="MCB">MCB (1)</option>');
const courseScriptStart = courseSource.lastIndexOf('<script>', courseSource.indexOf('const rowsWrap ='));
const courseScriptEnd = courseSource.indexOf('</script>', courseScriptStart) + '</script>'.length;
check(Boolean(courseDisclosure) && courseScriptStart >= 0 && courseScriptEnd > courseScriptStart, 'course disclosure and live filter script are available for browser QA');
pages['/courses.php'] = `<!doctype html><html><head>${head}</head><body class="app-body"><main class="page"><div class="course-toolbar"><label class="course-search-input"><i>⌕</i><input type="search" id="courseSearchInput" placeholder="Search courses…"></label>${courseDisclosure}</div><div class="course-rows" id="courseRows"><div class="course-row" data-search="csc 402 networks" data-category="CSC" data-semester="harmattan" data-status="registered" data-code="CSC 402" data-title="Networks" data-created="2">Networks</div><div class="course-row" data-search="mcb 101 biology" data-category="MCB" data-semester="rain" data-status="open" data-code="MCB 101" data-title="Biology" data-created="3">Biology</div><p id="courseNoResults" hidden>No courses match your filters.</p></div></main>${courseSource.slice(courseScriptStart, courseScriptEnd)}</body></html>`;

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: '/Users/anatulu-ihemobinna/Library/Caches/ms-playwright/chromium_headless_shell-1228/chrome-headless-shell-mac-x64/chrome-headless-shell' });
  try {
    for (const width of [375, 393, 720]) {
      const page = await browser.newPage({ viewport: { width, height: 812 } });
      await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        if (url.hostname !== 'ui.test') return route.abort();
        if (pages[url.pathname]) return route.fulfill({ contentType: 'text/html', body: pages[url.pathname] });
        if (url.pathname.startsWith('/assets/')) {
          const file = path.join(root, url.pathname);
          if (fs.existsSync(file)) return route.fulfill({ path: file });
        }
        return route.abort();
      });
      for (const theme of ['light', 'dark']) {
        await page.goto('http://ui.test/chat.php');
        await page.evaluate(value => document.documentElement.setAttribute('data-theme', value), theme);
        const hero = await page.locator('.chat-hero').evaluate(el => ({ top: el.getBoundingClientRect().top, radius: getComputedStyle(el).borderTopLeftRadius, width: el.getBoundingClientRect().width }));
        check(hero.top === 0 && hero.radius === '0px' && hero.width <= width, `${width} ${theme} chat hero has no extra top whitespace`);
        await page.goto('http://ui.test/dashboard.php');
        await page.evaluate(value => document.documentElement.setAttribute('data-theme', value), theme);
        const caret = await page.locator('#appProfileBtn > i').evaluate(el => getComputedStyle(el).color);
        check(caret === 'rgb(255, 255, 255)', `${width} ${theme} profile chevron is white on green`);
        await page.goto('http://ui.test/notifications.php');
        await page.evaluate(value => document.documentElement.setAttribute('data-theme', value), theme);
        await page.waitForTimeout(180);
        if (theme === 'dark') {
          const colors = await page.locator('.notif-panel').evaluate(el => {
            const unread = el.querySelector('.notif-row--unread');
            return { surface: getComputedStyle(unread).backgroundColor, text: getComputedStyle(unread.querySelector('.notif-message')).color,
              read: getComputedStyle(el.querySelector('.notif-row--read .notif-message')).color };
          });
          check(colors.surface === 'rgb(36, 49, 71)' && colors.text === 'rgb(226, 232, 240)' && colors.read === 'rgb(197, 210, 228)', `${width} dark notification surfaces and text have matching contrast: ${JSON.stringify(colors)}`);
        }
        check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${width} ${theme} no horizontal overflow`);
      }
      await page.close();
    }
    for (const width of [375, 393, 720]) {
      const page = await browser.newPage({ viewport: { width, height: 812 } });
      await page.route('**/*', async route => {
        const url = new URL(route.request().url());
        if (url.hostname !== 'ui.test') return route.abort();
        if (pages[url.pathname]) return route.fulfill({ contentType: 'text/html', body: pages[url.pathname] });
        if (url.pathname.startsWith('/assets/')) {
          const file = path.join(root, url.pathname);
          if (fs.existsSync(file)) return route.fulfill({ path: file });
        }
        return route.abort();
      });
      await page.goto('http://ui.test/courses.php');
      check(await page.locator('#courseFilters').count() === 1 && !(await page.locator('#courseFilters').evaluate(el => el.open)), `${width} has one closed Categories & Filters control`);
      await page.locator('#courseFilters > summary').click();
      check(await page.locator('#courseFilters select:visible').count() === 3 && await page.locator('#courseSortMenu summary:visible').count() === 1, `${width} disclosure exposes category, semester, status, and sort together`);
      await page.locator('#courseCategoryFilter').selectOption('CSC');
      await page.locator('#courseSemesterFilter').selectOption('harmattan');
      check(await page.locator('.course-row:visible').count() === 1 && await page.locator('#courseFilterState').textContent() === '2 active', `${width} combined filters preserve matching and active count`);
      await page.locator('#courseCategoryFilter').selectOption('');
      await page.locator('#courseSemesterFilter').selectOption('');
      await page.locator('#courseStatusFilter').selectOption('open');
      check(await page.locator('.course-row:visible').count() === 1 && await page.locator('.course-row:visible').textContent() === 'Biology', `${width} status filter remains functional`);
      await page.locator('#courseStatusFilter').selectOption('');
      const sortHit = await page.locator('#courseSortMenu summary').evaluate(el => {
        const rect = el.getBoundingClientRect();
        const hit = document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2);
        return el === hit || el.contains(hit);
      });
      check(sortHit, `${width} sort control is not covered by another element`);
      await page.locator('#courseSortMenu summary').click();
      check(await page.locator('#courseSortMenu [data-sort]:visible').count() === 3, `${width} Recently Added opens the sort choices`);
      check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${width} open sort choices have no horizontal overflow`);
      await page.locator('#courseSortMenu [data-sort="code"]').click();
      check(await page.locator('.course-row').first().textContent() === 'Networks' && await page.locator('#courseFilterState').textContent() === '1 active', `${width} sort works inside disclosure`);
      check(!(await page.locator('#courseSortMenu').evaluate(el => el.open)) && await page.locator('#courseSortLabel').textContent() === 'Course Code', `${width} sort selection closes menu and updates label`);
      await page.locator('#courseSortMenu summary').focus();
      await page.keyboard.press('Enter');
      check(await page.locator('#courseSortMenu').evaluate(el => el.open), `${width} keyboard can open sort choices`);
      await page.locator('#courseSortMenu [data-sort="recent"]').focus();
      await page.keyboard.press('Enter');
      check(await page.locator('#courseSortSelect').inputValue() === 'recent' && !(await page.locator('#courseSortMenu').evaluate(el => el.open)), `${width} keyboard can choose Recently Added`);
      await page.locator('#courseSearchInput').fill('biology');
      check(await page.locator('.course-row:visible').count() === 1 && await page.locator('.course-row:visible').textContent() === 'Biology', `${width} search remains independent of disclosure`);
      await page.locator('#courseSearchInput').fill('');
      check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${width} course disclosure has no horizontal overflow`);
      await page.close();
    }
    console.log(`${checks} browser-comment UI checks passed.`);
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
