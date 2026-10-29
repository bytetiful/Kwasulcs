/** Read-only smoke checks against the existing local Apache site. No login/DB fixtures. */
const assert = require('node:assert/strict');
const base = 'http://localhost/LMS-portal/';
const pages = ['dashboard','courses','course','calendar','settings','notifications','profile','privacy-policy','chat','chat_ai','chat_dm','chat_group','assignment_tests','assignment_test','assignment_test_receipt','assignment_test_submissions','assignment_test_audit','online_quiz','online_quiz_edit','online_quiz_monitor','online_quiz_result','forgot_password'];
let checks=0;
function check(ok,label){assert.ok(ok,label);checks++;console.log('PASS:',label);}
(async()=>{
  for(const page of pages){
    const response=await fetch(base+page,{method:'HEAD',redirect:'manual'});
    check(response.status===(['privacy-policy','forgot_password'].includes(page)?200:302),`local Apache resolves ${page} with its existing public/auth behavior`);
    const old=await fetch(base+page+'.php?id=12&date=2026-10-13',{method:'HEAD',redirect:'manual'});
    check(old.status===301 && old.headers.get('location')===base+page+'?id=12&date=2026-10-13',`local legacy ${page} redirect preserves the application scope and query`);
  }
  const policy=await fetch(base+'privacy-policy');const html=await policy.text();
  check(policy.status===200 && html.includes('Privacy Policy'),'canonical public policy renders without login');
  const assets=[...html.matchAll(/(?:href|src)="(assets\/[^"<>]+)"/g)].map(match=>match[1].replaceAll('&amp;','&'));
  for(const asset of new Set(assets)) check((await fetch(new URL(asset,policy.url),{method:'HEAD'})).status===200,`policy asset resolves from canonical URL: ${asset.split('?')[0]}`);
  for(const asset of ['assets/pwa.js','assets/offline.js','assets/favicon/site.webmanifest','assets/fonts/inter-latin.woff2']) check((await fetch(new URL(asset,base+'courses'),{method:'HEAD'})).status===200,`shared asset remains reachable: ${asset}`);
  console.log(`Local Apache smoke assertions passed: ${checks}`);
})().catch(error=>{console.error(error);process.exitCode=1;});
