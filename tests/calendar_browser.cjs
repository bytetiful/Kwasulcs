// Synthetic view QA against the actual PHP partial and production JS/CSS.
// No account sessions, external network, DB or production app are accessed.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const {chromium}=require('/Users/anatulu-ihemobinna/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root=path.resolve(__dirname,'..');
const html=execFileSync('/Applications/XAMPP/xamppfiles/bin/php',[path.join(__dirname,'calendar_view_fixture.php')],{encoding:'utf8'});
const base={course_code:'CSC 402',course_title:'Data Communication and Networking',organizer_name:'QA lecturer',scope:'course',created_by:0,notify_email:0,notify_web:0,is_recurring:0,_can_manage:false,location:'LT 2'};
const sample=(id,title,start,end,type='lecture')=>({...base,id,title,start_datetime:start,end_datetime:end,event_type:type,_key:`${id}@${start}`});
const events=[sample(1,'A very long event title with '.repeat(12)+' <img src=x onerror=alert(1)>','2026-09-04 09:00:00','2026-09-04 10:30:00'),
sample(2,'Overlapping tutorial','2026-09-04 09:30:00','2026-09-04 11:00:00','tutorial'),sample(3,'All-day project','2026-09-04 00:00:00','2026-09-05 00:00:00','general'),
{...sample(4,'Assignment deadline','2026-09-04 12:00:00','2026-09-04 12:00:00','assignment_test'),assignment_test_id:100,assignment_type:'assignment',assignment_marks:20,assignment_status:'closed',assignment_status_label:'Closed',action_url:'assignment_test.php?id=100'},
sample(5,'Weekly lecture','2026-09-07 14:00:00','2026-09-07 15:00:00'),
sample(7,'Legacy calendar test','2026-09-05 14:00:00','2026-09-05 15:00:00','test'),
{...sample(6,'End-of-day deadline','2026-09-04 23:59:00','2026-09-04 23:59:00','assignment_test'),assignment_test_id:101,assignment_type:'test',assignment_marks:10,assignment_status:'closed',assignment_status_label:'Closed',action_url:'assignment_test.php?id=101'}];
let checks=0;function check(ok,label){assert.ok(ok,label);checks++;console.log('PASS:',label);}
(async()=>{
const browser=await chromium.launch({headless:true,executablePath:'/Users/anatulu-ihemobinna/Library/Caches/ms-playwright/chromium_headless_shell-1228/chrome-headless-shell-mac-x64/chrome-headless-shell'});
try{
for(const width of [1440,393,375]){
 const page=await browser.newPage({viewport:{width,height:width===375?812:width===393?852:1000}}),errors=[];
 page.on('pageerror',error=>errors.push(error.message));
 await page.addInitScript(data=>{window.fixtureEvents=data;},events);
 await page.route('**/*',async route=>{
   const url=new URL(route.request().url());
   if(url.hostname!=='calendar.test')return route.abort();
   if(url.pathname==='/calendar.php')return route.fulfill({contentType:'text/html',body:html});
   if(url.pathname==='/calendar_feed.php')return route.fulfill({contentType:'application/json',body:JSON.stringify({events})});
   if(url.pathname.startsWith('/assets/')&&!url.pathname.includes('..')){
     const filename=path.join(root,url.pathname);if(fs.existsSync(filename))return route.fulfill({path:filename});
   }
   return route.abort();
 });
 await page.goto('http://calendar.test/calendar.php');
 await page.waitForSelector('.cv-month-grid');
 check(await page.locator('[data-calendar-type="test"]').count()===0,`${width} no separate Test filter`);
 check(await page.locator('[data-event-key^="7@"] ').first().evaluate(el=>el.style.getPropertyValue('--event-color'))==='#ea580c',`${width} ordinary Test uses Assignment/Test orange`);
 await page.locator('#calendarFilters summary').click();
 const filterStyle=await page.locator('[data-calendar-type="assignment_test"]').evaluate(el=>({
   radius:getComputedStyle(el).borderRadius,background:getComputedStyle(el).backgroundColor,
   type:getComputedStyle(el).getPropertyValue('--filter-color').trim()
 }));
 check(filterStyle.radius==='50%'&&filterStyle.background==='rgb(234, 88, 12)'&&filterStyle.type==='#ea580c',`${width} selected assessment filter is circular and orange`);
 if(width===375) await page.screenshot({path:'/private/tmp/calendar-colored-filters-375.png'});
 await page.locator('[data-calendar-type="assignment_test"]').uncheck();
 check(await page.locator('[data-calendar-type="assignment_test"]').evaluate(el=>getComputedStyle(el).backgroundColor!=='rgb(234, 88, 12)'),`${width} deselected filter loses its category fill`);
 check(await page.locator('[data-event-key^="7@"], [data-event-key^="4@"], [data-event-key^="6@"] ').count()===0,`${width} combined filter hides tests and assignments`);
 await page.locator('[data-calendar-type="assignment_test"]').check();
 await page.locator('#calendarFilters summary').click();
 for(const theme of ['light','dark']){
  await page.evaluate(theme=>document.documentElement.setAttribute('data-theme',theme),theme);
  for(const view of ['month','week','day']){
   await page.locator(`[data-calendar-view="${view}"]`).click();
   check(await page.locator('#calendarWorkspace').getAttribute('data-view')===view,`${width} ${theme} ${view} switch`);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${width} ${theme} ${view} no page overflow`);
   if(width<769){
    check(!(await page.locator('#calendarMini').isVisible()),`${theme} mobile ${view} no duplicate mini calendar`);
    if(view==='week'){
      check(await page.locator('.cv-mobile-strip button').count()===7&&await page.locator('.cv-mobile-timeline').isVisible()&&!(await page.locator('.cv-desktop-timeline').isVisible()),`${theme} mobile week strip and selected-day timeline`);
      check(await page.locator('.cv-strip-dots b').count()>0,`${theme} mobile week event indicators`);
    }
    if(view==='day'){
      check(await page.locator('.cv-mobile-timeline').isVisible(),`${theme} mobile day duration timeline`);
      const first=page.locator('.cv-event--timeline[data-event-key^="1@"]'),second=page.locator('.cv-event--timeline[data-event-key^="2@"]');
      check(Math.round(await first.evaluate(el=>el.getBoundingClientRect().height))===108,`${theme} mobile 90-minute event has faithful height`);
      check(await first.evaluate((el,other)=>el.getBoundingClientRect().left!==other.getBoundingClientRect().left,await second.elementHandle()),`${theme} mobile overlaps use distinct readable lanes`);
      check(await page.locator('.cv-mobile-deadlines [data-event-key^="6@"]').isVisible(),`${theme} mobile late deadline is compact instead of creating empty-day height`);
      check(await page.locator('.cv-context-actions [data-calendar-add]').isVisible(),`${theme} mobile Add Event is visible beside filters`);
      check(await page.locator('.cv-mobile-brand .feedback-fab').count()===1,`${theme} mobile feedback control is docked away from event content`);
    }
    if(view==='month')check(await page.locator('.cv-month-dots').first().isVisible()&&await page.locator('#calendarAgenda').isVisible(),`${theme} mobile month dots and selected agenda`);
   }else if(view!=='month'){
    check(await page.locator('.cv-time-grid').isVisible(),`${theme} desktop ${view} timed grid`);
    check(await page.locator('.cv-time-column [data-event-key^="6@"] ').evaluate(el=>el.getBoundingClientRect().bottom<=el.parentElement.getBoundingClientRect().bottom+1),`${theme} desktop ${view} 23:59 deadline not clipped`);
   }
   await page.screenshot({path:`/private/tmp/calendar-${width}-${theme}-${view}.png`,fullPage:true});
  }
 }
 await page.locator('[data-calendar-view="month"]').click();
 await page.locator('#calendarAgenda [data-event-key]').first().click();
 check(await page.locator('#calendarEventTitle').textContent()===events[2].title,`${width} event details opened`);
 if(width<769)check(await page.locator('.feedback-fab').evaluate(el=>getComputedStyle(el).visibility==='hidden'),`${width} event details do not collide with feedback control`);
 await page.locator('[data-close-event]').click();
 await page.locator('#calendarAgenda [data-event-key]').nth(1).click();
 check(await page.locator('#calendarEventTitle').textContent()===events[0].title,`${width} long title full detail`);
 check(await page.locator('#calendarDetails img').count()===0,`${width} event title HTML escaped`);
 await page.locator('[data-close-event]').click();
 await page.locator('#calendarAgenda [data-event-key^="4@"] ').click();
 check(await page.locator('#calendarDetails a').getAttribute('href')==='assignment_test.php?id=100',`${width} linked event action`);
 check(await page.locator('#calendarDetails').innerText().then(t=>t.includes('Closed')),`${width} linked status displayed`);
 check(errors.length===0,`${width} zero browser errors: ${errors.join(',')}`);
 await page.close();
}
console.log(`${checks} browser assertions passed; screenshots in /private/tmp/calendar-*.png.`);
}finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
