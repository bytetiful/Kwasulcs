'use strict';
// Pure JS unit tests: no browser automation, network, credentials or database.
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const source=fs.readFileSync(require('node:path').join(__dirname,'../assets/online-quiz-common.js'),'utf8');
const runnerSource=fs.readFileSync(require('node:path').join(__dirname,'../assets/online-quiz-runner.js'),'utf8');
const startSource=fs.readFileSync(require('node:path').join(__dirname,'../assets/online-quiz-start.js'),'utf8');
const monitorSource=fs.readFileSync(require('node:path').join(__dirname,'../assets/online-quiz-monitor.js'),'utf8');
const monitorCss=fs.readFileSync(require('node:path').join(__dirname,'../assets/online-quiz-monitor.css'),'utf8');
let checks=0;
function check(ok,label){assert.ok(ok,label);console.log('PASS: '+label);checks++;}
function client(fetch){
  let expire,cleared=false,delay;
  const context={window:{},AbortController,fetch,document:{getElementById:()=>null,querySelector:()=>({dataset:{csrf:'fixture-token'}})},
    setTimeout(fn,ms){expire=fn;delay=ms;return 1;},clearTimeout(){cleared=true;}};
  vm.runInNewContext(source,context);
  return {api:context.window.OQ,expire:()=>expire(),cleared:()=>cleared,delay:()=>delay};
}
(async()=>{
  let sent;
  const happy=client(async(url,request)=>{sent={url,...request};return {ok:true,json:async()=>({ok:true,data:{id:7}})};});
  check((await happy.api.api('answers',7,{answers:[]})).id===7,'successful API response returned');
  check(sent.method==='POST'&&sent.credentials==='same-origin','writes use authenticated same-origin POST');
  check(sent.headers['X-CSRF-Token']==='fixture-token','request carries the page CSRF token');
  check(JSON.parse(sent.body).action==='answers','request action serialized');
  check(happy.delay()===12000&&happy.cleared(),'request timeout cleared after success');
  const failure=client(async()=>({ok:false,status:422,json:async()=>({error:'Attempt is temporarily locked.'})}));
  let error;try{await failure.api.api('answers',7);}catch(e){error=e;}
  check(error.server&&error.status===422&&error.message.includes('locked'),'server rejection distinguished from network loss');
  check(failure.cleared(),'request timeout cleared after server rejection');
  const hanging=client((url,{signal})=>new Promise((resolve,reject)=>signal.addEventListener('abort',()=>reject(new Error('aborted')))));
  const pending=hanging.api.api('answers',7);hanging.expire();
  try{await pending;}catch(e){error=e;}
  check(!error.server&&error.message.includes('unsent answers remain'),'hung request aborts with recovery message');
  check(hanging.cleared(),'aborted request releases timeout');
  const malformed=client(async()=>({ok:true,json:async()=>{throw new Error('partial JSON');}}));
  try{await malformed.api.api('state',7);}catch(e){error=e;}
  check(!error.server&&error.message.includes('Connection interrupted'),'truncated JSON treated as recoverable interruption');
  check(happy.api.time(120)==='00:02:00'&&happy.api.time(-1)==='00:00:00','countdown formatting clamps at zero');
  check(happy.api.escape('<script>"&')==='&lt;script&gt;&quot;&amp;','dynamic strings escaped');
  check(runnerSource.includes("boot.entry==='start'")&&runnerSource.includes('Enter your secure quiz')&&runnerSource.includes('Return to your secure quiz'),'runner distinguishes first entry from resume copy');
  check(runnerSource.includes('secureEntryPending')&&runnerSource.includes("setAttribute('aria-busy','true')"),'secure runner entry is guarded against repeated activation');
  check(startSource.includes("dataset.pending")&&startSource.includes("setAttribute('aria-busy','true')"),'pre-start action is guarded against repeated activation');
  check(runnerSource.includes('Secure quiz active')&&runnerSource.includes('oq-secure-active'),'active secure mode has a visible textual status');
  check(runnerSource.includes('blocksSecureExit')&&runnerSource.includes('target.pathname!==location.pathname')&&runnerSource.includes("window.addEventListener('popstate'")&&runnerSource.includes("window.addEventListener('beforeunload'"),'secure runner blocks links, browser back and accidental unload paths');
  check(runnerSource.includes("addEventListener('selectionstart'")&&runnerSource.includes("input,textarea,select"),'secure selection hardening preserves answer controls');
  check(monitorSource.includes('oq-attempt-detail--flagged')&&monitorCss.includes('.oq-attempt-detail--flagged'),'flagged attempt detail has a dedicated persistent presentation state');
  check(monitorSource.includes('oq-attempt--flagged')&&monitorSource.includes('bi-flag-fill')&&monitorCss.includes('.oq-attempt--flagged'),'collapsed flagged attempt has a visible text-and-color state');
  console.log(`Completed ${checks} client unit checks.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
