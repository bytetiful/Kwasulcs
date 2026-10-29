'use strict';
document.getElementById('oq-start').onclick=async function(){
 if(this.dataset.pending)return;
 const boot=OQ.data(),label=this.textContent;
 this.dataset.pending='1';this.disabled=true;this.setAttribute('aria-busy','true');this.textContent=boot.resume?'Resuming…':'Starting…';
 try{const state=await OQ.api('start',boot.quiz_id,{ack:document.getElementById('oq-ack')?.checked??false});location.href=(state.status==='writing'?'online_quiz_take.php':'online_quiz_result')+'?id='+state.id+(state.status==='writing'?'&entry='+(boot.resume?'resume':'start'):'');}
 catch(err){OQ.status(err.message,true);delete this.dataset.pending;this.disabled=false;this.removeAttribute('aria-busy');this.textContent=label;}
};
