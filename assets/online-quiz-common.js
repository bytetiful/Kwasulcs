'use strict';
window.OQ = {
  escape: value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),
  data: () => JSON.parse(document.getElementById('oq-data')?.textContent || '{}'),
  status(message, error=false) {const el=document.getElementById('oq-status');el.textContent=message;el.className=error?'oq-error':'oq-message';},
  async api(action,id,extra={}) {
    const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),12000);
    let response,body;
    try{
      response=await fetch('online_quiz_api.php',{method:'POST',credentials:'same-origin',signal:controller.signal,headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('[data-csrf]').dataset.csrf},body:JSON.stringify({action,id,...extra})});
      body=await response.json();
    }catch{throw new Error('Connection interrupted. Your unsent answers remain on this device.');}
    finally{clearTimeout(timeout);}
    if(!response.ok||!body.ok){const err=new Error(body.error||'Request failed.');err.server=true;err.status=response.status;throw err;}return body.data;
  },
  time(seconds){seconds=Math.max(0,Math.floor(seconds));return [Math.floor(seconds/3600),Math.floor(seconds/60)%60,seconds%60].map(n=>String(n).padStart(2,'0')).join(':');}
};
document.getElementById('oq-search')?.addEventListener('input',e=>{let visible=0;document.querySelectorAll('[data-search]').forEach(el=>{el.hidden=!el.dataset.search.includes(e.target.value.toLowerCase());if(!el.hidden)visible++;});document.getElementById('oq-empty').hidden=visible>0;});
