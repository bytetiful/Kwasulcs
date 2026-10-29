'use strict';
(() => {
 const {api,escape:e,status}=OQ,boot=OQ.data(),root=document.getElementById('oq-workspace');
 let state=null,serverBase=0,monoBase=0,position=0,armed=false,localPending=null,confirmation=null,clockHandle=null,historyGuarded=false;
 let entryMode=boot.entry==='start'?'start':'resume',secureEntryPending=false;
 let dirty={},queue=Promise.resolve(),saveTimer=null,saveText='Loading…',offlineObserved=!navigator.onLine,conflict=false,expiryCheckAt=0,autosaveFailed=false;
 try{dirty=JSON.parse(localStorage.getItem(boot.buffer_key)||'{}');}catch{dirty={};}
 const now=()=>serverBase+(performance.now()-monoBase)/1000;
 const persist=()=>{try{localStorage.setItem(boot.buffer_key,JSON.stringify(dirty));}catch{status('Device storage is unavailable. Keep this page open until answers are saved.',true);}};
 const serial=fn=>{const p=queue.then(fn);queue=p.catch(()=>{});return p;};
 const setSave=text=>{saveText=text;const el=document.getElementById('oq-save-state');if(el)el.textContent=text;};
 function apply(next,render=false){
   const oldLock=state?.locked_until;state=next;serverBase=next.server_now;monoBase=performance.now();
   document.body.classList.toggle('oq-secure-session',Boolean(next.settings?.secure&&next.status==='writing'));
   if(next.settings?.secure&&next.status==='writing'&&!historyGuarded){history.pushState({secureQuiz:boot.attempt_id},'',location.href);historyGuarded=true;}
   if(next.status!=='writing'){try{localStorage.removeItem(boot.buffer_key);}catch{}location.replace('online_quiz_result?id='+boot.attempt_id);return;}
   if(next.locked_until){armed=false;localPending=null;clearTimeout(confirmation);root.replaceChildren();showLock();return;}
   if(oldLock){armed=false;render=true;}
   if(!next.settings.allow_back)position=next.current_position;
   if(saveText==='Loading…')setSave(Object.keys(dirty).length?'Unsaved changes':'Saved');
   if(render)draw();
 }
 function reconcile(next){
   for(const q of next.questions){const d=dirty[q.id];if(!d)continue;
     if(q.answer===d.value){delete dirty[q.id];continue;}
     if(Number(q.revision)!==Number(d.revision)){conflict=true;status('A saved answer changed in another tab. Your local response is retained. Close other quiz tabs, then choose “Use my local answers” to explicitly reconcile.',true);}
   }persist();
 }
 async function refresh(render=false){const next=await api('state',boot.attempt_id);reconcile(next);apply(next,render);}
 async function flush(nextPosition=null){
   if(conflict)throw new Error('Resolve the other-tab answer conflict before continuing.');
   const batch=Object.entries(dirty).map(([id,d])=>({id:Number(id),value:d.value,revision:d.revision,sequence:d.sequence}));
   if(!batch.length&&nextPosition===null)return;
   if(!navigator.onLine){setSave('Offline — answer stored locally');throw new Error('Reconnect before changing question or submitting.');}
   setSave('Saving…');
   try{const result=await api('answers',boot.attempt_id,{answers:batch.map(({sequence,...d})=>d),...(nextPosition!==null?{next:nextPosition}:{})});
     for(const item of batch){const pending=dirty[item.id];if(!pending)continue;if(pending.sequence===item.sequence)delete dirty[item.id];else pending.revision=item.revision+1;}
     persist();apply(result);setSave('Saved');
   }catch(err){setSave(err.server?'Save not accepted — check message':'Offline / interrupted — answer stored locally');
     if(!err.server){offlineObserved=true;autosaveFailed=true;}
     else if(err.message.includes('another tab')){await refresh();draw();}
     throw err;
   }
 }
 function scheduleSave(){clearTimeout(saveTimer);saveTimer=setTimeout(()=>serial(()=>flush()).catch(err=>status(err.message,true)),650);}
 function draw(){
   if(!state||state.status!=='writing')return;
   if(state.locked_until){showLock();return;}
   if(localPending){showCover();return;}
   if(state.settings.secure&&!armed){showReady();return;}
   const questions=(state.settings.one_per_page||!state.settings.allow_back)?state.questions.filter(q=>q.position===position):state.questions;
   root.innerHTML=`${state.settings.secure?'<div class="oq-secure-active" role="status"><i class="bi bi-shield-check" aria-hidden="true"></i><span>Secure quiz active</span></div>':''}<div class="oq-columns"><div><section class="oq-card oq-run-stats"><div>Time remaining<strong id="oq-clock"></strong></div><div>Question<strong>${position+1} of ${state.progress.length}</strong></div><div>Warnings<strong>${state.warning_count} of 3</strong></div></section>${state.flagged?'<p class="oq-error">Flagged for review. Your lecturer will review the integrity record; this is not an automatic zero.</p>':''}<p id="oq-save-state" class="oq-save-state" role="status">${e(saveText)}</p>${conflict?'<button class="btn secondary" id="oq-resolve">Use my local answers</button>':''}<div class="oq-question-area ${state.settings.secure&&state.settings.disable_selection?'oq-nosel':''}">${questions.map(q=>`<section class="oq-card" data-qid="${q.id}"><div class="oq-toolbar"><span class="oq-badge">Question ${q.position+1}</span><span>${e(q.marks)} marks</span></div><h2 class="oq-prose">${e(q.text)}</h2>${['mcq','truefalse'].includes(q.question_type)?q.options.map(o=>`<label class="oq-answer"><input type="radio" name="q${q.id}" data-answer="${q.id}" value="${e(o.id)}" ${(dirty[q.id]?.value??q.answer)===o.id?'checked':''}><span>${e(o.text)}</span></label>`).join(''):`<label>Your answer<textarea rows="${q.question_type==='essay'?8:3}" maxlength="30000" data-answer="${q.id}">${e(dirty[q.id]?.value??q.answer)}</textarea></label>`}</section>`).join('')}${state.settings.secure?'<div class="oq-watermarks" aria-hidden="true">'+Array(8).fill(`<span>${e(state.watermark)}</span>`).join('')+'</div>':''}</div><div class="oq-toolbar"><button class="btn secondary" data-nav="${position-1}" ${!state.settings.allow_back||position===0?'disabled':''}>← Previous</button><button class="btn primary" data-nav="${position+1}" ${position>=state.progress.length-1?'disabled':''}>Next Question →</button></div></div><aside class="oq-run-sidebar"><details class="oq-card" open><summary>Question Navigator</summary><div class="oq-numbers">${state.progress.map(p=>`<button data-nav="${p.position}" ${!state.settings.allow_back&&p.position!==position?'disabled':''} aria-current="${p.position===position}" class="${p.answered?'answered':''}">${p.position+1}</button>`).join('')}</div><p class="muted">${state.progress.filter(p=>p.answered).length} of ${state.progress.length} saved answers</p><button class="btn primary" id="oq-submit">Submit Quiz</button><div class="oq-tip">Your saved answers are protected on the server. The timer continues through interruptions.</div></details></aside></div>`;
   root.querySelectorAll('[data-answer]').forEach(el=>el.addEventListener('input',()=>{const q=state.questions.find(q=>q.id===Number(el.dataset.answer));const prior=dirty[q.id];dirty[q.id]={value:el.value,revision:prior?.revision??q.revision,sequence:(prior?.sequence??0)+1};persist();setSave(navigator.onLine?'Unsaved changes':'Offline — answer stored locally');scheduleSave();}));
   root.querySelectorAll('[data-nav]').forEach(el=>el.onclick=()=>serial(async()=>{await flush(Number(el.dataset.nav));if(!state.locked_until){position=Number(el.dataset.nav);draw();}}).catch(err=>status(err.message,true)));
   document.getElementById('oq-submit').onclick=()=>{if(!confirm('Submit this attempt? You cannot change answers afterward.'))return;serial(async()=>{await flush();apply(await api('submit',boot.attempt_id));}).catch(err=>status(err.message,true));};
   document.getElementById('oq-resolve')?.addEventListener('click',()=>serial(async()=>{await refresh();for(const q of state.questions)if(dirty[q.id])dirty[q.id].revision=q.revision;conflict=false;persist();await flush();status('Local answers synchronized.');draw();}).catch(err=>status(err.message,true)));
   tick();
 }
 function showReady(){const starting=entryMode==='start';root.innerHTML=`<section class="oq-card oq-lock"><span class="oq-lock-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span><h2>${starting?'Enter your secure quiz':'Return to your secure quiz'}</h2><p>${starting?'Your attempt is ready. Enter secure view to reveal the questions.':'Resume the same attempt with your saved answers and remaining time.'}</p><p>The server timer continues while this screen is open. Fullscreen will be requested where supported.</p><button class="btn primary" id="oq-secure-resume">${starting?'Start secure quiz':'Return to secure quiz'}</button></section>`;
   document.getElementById('oq-secure-resume').onclick=openSecureView;
 }
 async function openSecureView(){if(secureEntryPending)return;secureEntryPending=true;const button=document.getElementById('oq-secure-resume');if(button){button.disabled=true;button.setAttribute('aria-busy','true');button.textContent='Opening secure view…';}
   try{if(state.settings.fullscreen&&document.fullscreenEnabled&&!document.fullscreenElement){await document.documentElement.requestFullscreen();if(!document.fullscreenElement)throw new Error('Fullscreen was not entered.');}await serial(()=>refresh());if(state.locked_until)return;armed=true;entryMode='resume';draw();}
   catch(err){showReady();status('Secure view could not open. Try again, or contact your lecturer if this browser blocks fullscreen. '+err.message,true);}
   finally{secureEntryPending=false;}
 }
 function showLock(){
   root.innerHTML=`<section class="oq-card oq-lock" role="alert" aria-labelledby="oq-lock-title"><span class="oq-lock-icon"><i class="bi bi-exclamation-triangle-fill"></i></span><h2 id="oq-lock-title">Quiz access temporarily locked</h2><p>A confirmed departure from the secure quiz environment has temporarily suspended access.</p><span class="oq-badge">Warning ${state.warning_count} of 3</span><div class="oq-lock-clock" id="oq-lock-clock"></div><p>${state.warning_count===2?'The next confirmed offence will flag your submission.':state.flagged?'Flagged for lecturer review. This does not automatically give zero or submit your work.':'Three confirmed violations flag the attempt for lecturer review.'}</p><p>Questions are unavailable during lockout. Your quiz timer continues.</p><button class="btn secondary" id="oq-lock-return" disabled>Return when timer ends</button><p class="muted">Contact your course lecturer through Assessment/Chat if this happened by mistake.</p></section>`;
   document.getElementById('oq-lock-return').onclick=()=>serial(()=>refresh(true)).catch(err=>status(err.message,true));tick();
 }
 function showCover(){root.innerHTML=`<section class="oq-card oq-lock" role="alert"><h2>Return to secure view</h2><p>Question content is hidden. Restore the quiz tab, fullscreen and window size. Brief system changes are given a recovery grace period.</p><button class="btn primary" id="oq-restore">Restore secure view</button></section>`;document.getElementById('oq-restore').onclick=async()=>{if(state.settings.fullscreen&&document.fullscreenEnabled&&!document.fullscreenElement){try{await document.documentElement.requestFullscreen();}catch{status('Fullscreen could not be opened.',true);}}recoverIfSafe();};}
 function tick(){if(!state)return;const left=state.expires_at-now();const clock=document.getElementById('oq-clock');if(clock)clock.textContent=OQ.time(left);
   if(state.locked_until){const locked=state.locked_until-now();const el=document.getElementById('oq-lock-clock');if(el)el.textContent=OQ.time(locked).slice(3);const b=document.getElementById('oq-lock-return');if(b&&locked<=0){b.disabled=false;b.textContent='Return to quiz';}}
   if(left<=0){root.innerHTML='<section class="oq-card oq-lock"><h2>Time has ended</h2><p>Answers are frozen. Reconnecting to retrieve your submission status…</p></section>';armed=false;if(performance.now()>=expiryCheckAt){expiryCheckAt=performance.now()+15000;serial(()=>refresh()).catch(()=>setSave('Waiting for connection — no extra time is granted.'));}}
 }
 async function event(type){const next=await api('integrity',boot.attempt_id,{type,request_key:crypto.randomUUID()});apply(next);return next;}
 function signal(type){if(!state?.settings.secure||!armed||localPending||state.locked_until||state.status!=='writing')return;
   localPending=type;showCover();serial(()=>event(type)).then(()=>{if(localPending)confirmation=setTimeout(()=>serial(()=>event('confirm')).then(()=>draw()).catch(err=>status(err.message,true)),type==='reduced_viewport'?8300:3300);}).catch(()=>status('Secure-view observation is waiting for connection. Restore this quiz window.',true));
 }
 const reduced=()=>{
   // Width-only heuristic: ignore mobile keyboard height, pinch zoom, and normal orientation.
   if(!state?.settings.monitor_viewport||matchMedia('(pointer: coarse)').matches||screen.width<1000)return false;
   return outerWidth>0&&outerWidth<screen.availWidth*.68;
 };
 function recoverIfSafe(){if(!localPending||state?.locked_until)return;
   const safe=localPending==='focus_exit'?!document.hidden&&document.hasFocus():localPending==='fullscreen_exit'?!!document.fullscreenElement:!reduced();
   if(!safe)return;const type={focus_exit:'focus_return',fullscreen_exit:'fullscreen_return',reduced_viewport:'viewport_restored'}[localPending];clearTimeout(confirmation);localPending=null;serial(()=>event(type)).then(()=>draw()).catch(err=>{showReady();status(err.message,true);});
 }
 function blocksSecureExit(){return Boolean(state?.settings.secure&&state.status==='writing');}
 function reportBlockedExit(){status('Navigation is locked during this secure attempt. Submit the quiz before opening another KWASU LCS page.',true);}
 document.addEventListener('click',ev=>{const link=ev.target.closest('a[href]');if(!link||!blocksSecureExit())return;let target;try{target=new URL(link.href,location.href);}catch{return;}if(target.origin===location.origin&&target.pathname!==location.pathname){ev.preventDefault();ev.stopImmediatePropagation();reportBlockedExit();}},true);
 document.addEventListener('submit',ev=>{if(!blocksSecureExit()||ev.target.closest('#oq-workspace'))return;ev.preventDefault();ev.stopImmediatePropagation();reportBlockedExit();},true);
 window.addEventListener('popstate',()=>{if(!blocksSecureExit())return;history.pushState({secureQuiz:boot.attempt_id},'',location.href);reportBlockedExit();});
 document.addEventListener('visibilitychange',()=>{if(document.hidden&&state?.settings.monitor_focus)signal('focus_exit');else recoverIfSafe();});
 window.addEventListener('blur',()=>{if(state?.settings.monitor_focus)signal('focus_exit');});window.addEventListener('focus',recoverIfSafe);
 document.addEventListener('fullscreenchange',()=>{if(armed&&state?.settings.fullscreen&&state.settings.monitor_fullscreen&&!document.fullscreenElement)signal('fullscreen_exit');else recoverIfSafe();});
 window.addEventListener('resize',()=>{if(reduced())signal('reduced_viewport');else recoverIfSafe();});
 for(const [name,setting]of Object.entries({copy:'disable_copy',cut:'disable_cut',paste:'disable_paste',dragstart:'disable_drag',contextmenu:'disable_context'}))document.addEventListener(name,ev=>{if(state?.settings.secure&&armed&&state.settings[setting]&&ev.target.closest('.oq-question-area'))ev.preventDefault();});
 document.addEventListener('selectionstart',ev=>{if(state?.settings.secure&&armed&&state.settings.disable_selection&&ev.target.closest('.oq-question-area')&&!ev.target.closest('input,textarea,select'))ev.preventDefault();});
 window.addEventListener('offline',()=>{offlineObserved=true;setSave('Offline — answer stored locally');serial(()=>event('network_lost')).catch(()=>{});});
 async function reconnect(){setSave('Reconnecting…');try{await refresh();if(offlineObserved){await event('network_lost');await event('network_restored');offlineObserved=false;}if(autosaveFailed){await event('autosave_failed');autosaveFailed=false;}if(!state.locked_until){await flush();setSave('Synced');}draw();}catch(err){status(err.message,true);}}
 window.addEventListener('online',()=>serial(reconnect));
 setInterval(()=>{if(!state||state.status!=='writing'||!navigator.onLine)return;serial(async()=>{if(Object.keys(dirty).length&&!state.locked_until&&!localPending&&!conflict)await flush();else await refresh(false);}).catch(err=>{setSave('Connection interrupted — local answers retained');if(err.server)status(err.message,true);});},15000);
 window.addEventListener('pagehide',()=>persist());
 window.addEventListener('beforeunload',ev=>{if(!blocksSecureExit()||!armed)return;persist();ev.preventDefault();ev.returnValue='';});
 serial(async()=>{await refresh();await event('reload');reconcile(state);if(!state.locked_until&&!conflict)await flush();position=state.current_position;draw();if(boot.navigation_blocked)reportBlockedExit();clockHandle=setInterval(tick,1000);}).catch(err=>{status(err.message,true);root.innerHTML='<section class="oq-card"><h2>Unable to load the attempt</h2><p>Your local buffer has been retained. Check your connection, then refresh this page.</p></section>';});
})();
