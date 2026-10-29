'use strict';
(() => {
 const boot=OQ.data(),e=OQ.escape,id=Number(boot.monitor.quiz.id),list=document.getElementById('oq-monitor');
 const search=document.getElementById('oq-attempt-search'),filter=document.getElementById('oq-attempt-filter');
 const expanded=new Set(),rendered=new Map();let latest=boot.monitor,polling=false,managing=false;
 function applyFilters(){
  const term=search.value.trim().toLowerCase();let visible=0;
  latest.attempts.forEach(a=>{const row=document.getElementById(`oq-attempt-${a.id}`);if(!row)return;
   const matches=filter.value==='all'||a.display_status===filter.value||(filter.value==='Flagged'&&Number(a.flagged))||(filter.value==='Needs marking'&&Number(a.needs_review));
   row.hidden=!matches||!`${a.full_name} ${a.matric_no||''}`.toLowerCase().includes(term);if(!row.hidden)visible++;
  });
  document.getElementById('oq-attempt-empty').hidden=visible>0;
 }
 function rowHTML(a){
  const aid=Number(a.id),open=expanded.has(aid),flag=Number(a.flagged),warn=Number(a.warning_count);
  const score=a.status==='writing'?'—':Number(a.needs_review)?'Needs marking':a.score===null?'—':`${e(a.score)} / ${e(a.total_marks)}<small>${e(a.score_percent)}%</small>`;
  return `<div class="oq-attempt-main"><div data-label="Student"><strong>${e(a.matric_no||a.full_name)}</strong><small>${a.matric_no?e(a.full_name)+' · ':''}Attempt ${Number(a.attempt_number)}</small></div><div data-label="Progress"><div class="oq-attempt-statuses"><span class="oq-badge ${a.display_status==='Locked'?'oq-status-flag':''}">${e(a.display_status)}</span>${flag?'<span class="oq-badge oq-status-flag"><i class="bi bi-flag-fill" aria-hidden="true"></i> Flagged</span>':''}</div><small>${Number(a.answered)} of ${Number(a.question_count)} answered</small></div><div data-label="Last contact"><time>${e(a.last_contact_label)}</time></div><div data-label="Score"><strong>${score}</strong></div><button type="button" class="oq-expand" data-expand="${aid}" aria-expanded="${open}" aria-controls="oq-attempt-detail-${aid}" aria-label="${open?'Collapse':'Expand'} attempt ${Number(a.attempt_number)} for ${e(a.matric_no||a.full_name)}"><i class="bi bi-chevron-${open?'up':'down'}" aria-hidden="true"></i></button></div>
  <div class="oq-attempt-detail ${flag?'oq-attempt-detail--flagged':''}" id="oq-attempt-detail-${aid}" ${open?'':'hidden'}><span class="muted">Status details</span><div class="oq-detail-actions"><div><span class="oq-badge oq-warning">${warn} warning${warn===1?'':'s'}</span> <span class="oq-badge ${flag?'oq-status-flag':'oq-neutral'}">${flag?'Flagged for review':'Not flagged'}</span></div><a href="online_quiz_monitor?id=${id}&attempt=${aid}#oq-attempt-review">View attempt <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a></div><p>${a.status==='writing'?`${e(OQ.time(a.remaining_seconds))} remaining · `:''}Last report: ${e(a.network_state||'No report')}</p><p class="muted">Activity shows the last server contact. Refreshes and network changes are neutral.</p></div>`;
 }
 function draw(m){
  latest=m;
  const stats=[['Eligible students','Eligible students'],['Students started','Started'],['Writing','Writing'],['Finalized','Submitted'],['Flagged','Flagged'],['Average %','Average score']];
  document.getElementById('oq-monitor-stats').innerHTML=stats.map(([key,label])=>`<div><strong>${e(m.stats[key])}${key==='Average %'&&m.stats[key]!=='—'?'%':''}</strong><span>${label}</span></div>`).join('');
  const badge=document.getElementById('oq-effective-status');badge.textContent=m.display_status;badge.className='oq-badge '+(m.display_status==='Closed'?'oq-status-flag':'');
  document.getElementById('oq-configuration').textContent=m.can_edit?'Edit configuration':'View configuration';
  const close=document.querySelector('[data-manage="close"]');close.disabled=!m.can_close||managing;close.textContent=m.can_close?'Close quiz':'Quiz closed';
  for(const channel of ['results','answers']){
   const state=m.release[channel],button=document.querySelector(`[data-manage="release_${channel}"]`);
   const pill=document.querySelector(`[data-release-status="${channel}"]`);pill.textContent=state.status;pill.classList.toggle('oq-neutral',!state.released);
   document.querySelector(`[data-release-copy="${channel}"]`).textContent=state.copy;
   button.disabled=!state.can_release||managing;
  }
  document.getElementById('oq-release-policy').textContent=m.release.results.policy===m.release.answers.policy?`Release policy: ${m.release.results.policy}.`:`Release policies — Results: ${m.release.results.policy}; correct answers: ${m.release.answers.policy}.`;
  const ids=new Set();
  let previousStudent=null;
  m.attempts.forEach((a,index)=>{
   const aid=Number(a.id);ids.add(aid);let row=document.getElementById(`oq-attempt-${aid}`);const html=rowHTML(a);
   const student=String(a.student_id),groupStart=student!==previousStudent;previousStudent=student;
   if(!row){row=document.createElement('article');row.id=`oq-attempt-${aid}`;list.append(row);}
   row.className=`oq-attempt ${groupStart?'oq-attempt--group-start':'oq-attempt--same-student'}${Number(a.flagged)?' oq-attempt--flagged':''}`;
   if(rendered.get(aid)!==html){
    // Do not replace a focused control during live refresh. Its next blur/poll catches up.
    if(!row.contains(document.activeElement)){row.innerHTML=html;rendered.set(aid,html);}
   }
   if(list.children[index]!==row)list.insertBefore(row,list.children[index]||null);
  });
  [...list.children].forEach(row=>{const aid=Number(row.id.replace('oq-attempt-',''));if(!ids.has(aid)){row.remove();rendered.delete(aid);expanded.delete(aid);}});
  document.getElementById('oq-last-updated').textContent='Updated just now';applyFilters();
 }
 list.addEventListener('click',ev=>{const button=ev.target.closest('[data-expand]');if(!button)return;const aid=Number(button.dataset.expand);expanded.has(aid)?expanded.delete(aid):expanded.add(aid);const open=expanded.has(aid);button.setAttribute('aria-expanded',String(open));button.setAttribute('aria-label',button.getAttribute('aria-label').replace(/^(Expand|Collapse)/,open?'Collapse':'Expand'));button.querySelector('i').className='bi bi-chevron-'+(open?'up':'down');document.getElementById(`oq-attempt-detail-${aid}`).hidden=!open;rendered.delete(aid);});
 search.addEventListener('input',applyFilters);filter.addEventListener('change',applyFilters);
 async function refresh(){if(document.hidden||polling||managing)return;polling=true;try{draw(await OQ.api('monitor',id));}catch(err){OQ.status(err.message,true);document.getElementById('oq-last-updated').textContent='Updates paused';}finally{polling=false;}}
 document.querySelectorAll('[data-manage]').forEach(button=>button.addEventListener('click',async()=>{
  if(managing||button.disabled)return;
  if(!confirm(button.dataset.manage==='close'?'Close the quiz now? Active attempts will end at this server time.':'Confirm this release for finalized student attempts?'))return;
  managing=true;document.querySelectorAll('[data-manage]').forEach(b=>b.disabled=true);
  try{await OQ.api(button.dataset.manage,id);OQ.status('Quiz updated.');}catch(err){OQ.status(err.message,true);}finally{managing=false;await refresh();}
 }));
 document.getElementById('oq-review-form')?.addEventListener('submit',async ev=>{ev.preventDefault();const marks={};document.querySelectorAll('[data-review-score]').forEach(el=>{if(el.value!=='')marks[el.dataset.reviewScore]={score:el.value,feedback:document.querySelector(`[data-review-feedback="${el.dataset.reviewScore}"]`).value};});try{await OQ.api('review',boot.review_id,{marks,note:document.getElementById('oq-review-note').value});OQ.status('Marking and review saved.');await refresh();}catch(err){OQ.status(err.message,true);}});
 draw(boot.monitor);setInterval(refresh,15000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
})();
