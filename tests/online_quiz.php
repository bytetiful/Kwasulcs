<?php
/** Isolated local domain tests. Never sends email or writes to real coursework. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('session.save_path',sys_get_temp_dir());
require_once dirname(__DIR__).'/includes/online_quiz.php';
if(DB_HOST!=='127.0.0.1'||DB_NAME!=='kwasulcs_lcs')throw new RuntimeException('Local database required.');
session_write_close();$pdo=db();$uids=[];$cids=[];$qids=[];$checks=0;$tag='oq-qa-'.bin2hex(random_bytes(4));
function oq_check(bool $condition,string $label): void {global $checks;if(!$condition)throw new RuntimeException('FAIL: '.$label);echo 'PASS: '.$label."\n";$checks++;}
function oq_denied(callable $fn,string $label): void {try{$fn();}catch(RuntimeException $e){oq_check(true,$label);return;}oq_check(false,$label);}
function oq_fixture(array $settings=[],array $overrides=[]): array {
    global $owner,$cids,$qids,$tag;
    $q=oq_save(0,array_merge(['course_id'=>$cids[0],'title'=>$tag,'instructions'=>'Local test instructions','opens_at'=>date('Y-m-d\TH:i',time()-60),'closes_at'=>date('Y-m-d\TH:i',time()+3600),
        'duration_minutes'=>30,'attempts_allowed'=>1,'settings'=>array_merge(['shuffle_questions'=>false,'shuffle_options'=>false],$settings),'questions'=>[
        ['question_type'=>'mcq','question_text'=>'Select the network','marks'=>2,'options'=>[['text'=>'Connected devices'],['text'=>'One computer']],'key'=>['0']],
        ['question_type'=>'truefalse','question_text'=>'A router forwards packets','marks'=>2,'key'=>['true']],
        ['question_type'=>'short','question_text'=>'Expand IP','marks'=>2,'key'=>['Internet Protocol']],
        ['question_type'=>'essay','question_text'=>'Explain your reasoning','marks'=>4,'key'=>[]]
    ]],$overrides),$owner);$qids[]=(int)$q['id'];return $q;
}
try {
    $statusFixture=['status'=>'published','opens_at'=>date('Y-m-d H:i:s',time()+60),'closes_at'=>date('Y-m-d H:i:s',time()+120)];
    oq_check(oq_display_status($statusFixture)==='Scheduled','future quiz is scheduled, not active');
    oq_check(oq_display_status($statusFixture,time()+60)==='Active','opening boundary becomes active');
    oq_check(oq_display_status($statusFixture,time()+120)==='Closed','closing boundary displays closed');
    oq_check(oq_display_status(array_merge($statusFixture,['status'=>'draft']),time()+180)==='Draft','draft status is not changed by its dates');
    foreach(['lecturer','student','student','lecturer','admin'] as $i=>$role){oq_query("INSERT INTO users(full_name,matric_no,email,password,role,pref_email_notifications) VALUES (?,?,?,'not-a-login-hash',?,0)",[$tag.$i,$tag.$i,$tag.$i.'@example.invalid',$role]);$uids[]=(int)$pdo->lastInsertId();}
    $owner=oq_query('SELECT * FROM users WHERE id=?',[$uids[0]])->fetch();$student=oq_query('SELECT * FROM users WHERE id=?',[$uids[1]])->fetch();$other=oq_query('SELECT * FROM users WHERE id=?',[$uids[2]])->fetch();$foreign=oq_query('SELECT * FROM users WHERE id=?',[$uids[3]])->fetch();$admin=oq_query('SELECT * FROM users WHERE id=?',[$uids[4]])->fetch();
    foreach([$owner,$foreign] as $i=>$u){oq_query("INSERT INTO courses(title,code,semester,lecturer_id) VALUES (?,?,'rain',?)",[$tag.$i,$tag.$i,$u['id']]);$cids[]=(int)$pdo->lastInsertId();}
    oq_query('INSERT INTO enrollments(student_id,course_id) VALUES (?,?)',[$student['id'],$cids[0]]);
    $q=oq_fixture();$qid=(int)$q['id'];oq_check($q['status']==='draft','draft created');
    oq_denied(fn()=>oq_require($qid,$student),'draft hidden from student');
    oq_denied(fn()=>oq_require($qid,$foreign,true),'foreign lecturer cannot manage');
    oq_denied(fn()=>oq_require($qid,$admin,true),'no global admin bypass');
    oq_denied(fn()=>oq_save(0,['course_id'=>$cids[1]],$owner),'creation on foreign course denied');
    oq_denied(fn()=>oq_save($qid,['opens_at'=>'2026-02-31T08:00'],$owner),'invalid rolled date rejected');
    $q=oq_save($qid,['wizard_step'=>3,'revision'=>$q['revision']],$owner);oq_check((int)$q['wizard_step']===3&&count(oq_questions($qid))===4,'step persistence preserves questions');
    oq_denied(fn()=>oq_save($qid,['revision'=>0],$owner),'stale draft revision rejected');
    oq_publish($qid,$owner);oq_publish($qid,$owner);
    oq_check((int)oq_query('SELECT COUNT(*) FROM calendar_events WHERE online_quiz_id=?',[$qid])->fetchColumn()===1,'publish calendar projection idempotent');
    oq_denied(fn()=>oq_start($qid,$other,false),'unenrolled student denied');
    $a=oq_start($qid,$student,false);$aid=$a['id'];oq_check(count($a['questions'])===4,'attempt starts with frozen questions');
    oq_check(oq_start($qid,$student,false)['id']===$aid,'resume never consumes a second attempt');
    oq_check(!str_contains(oq_json($a),'answer_key')&&!str_contains(oq_json($a),'Internet Protocol'),'writing state contains no answer key');
    oq_check($a['expires_at']<=$a['server_now']+1800,'server bounds duration');
    oq_denied(fn()=>oq_state($aid,$other),'foreign attempt denied');
    oq_denied(fn()=>oq_save($qid,['title'=>'changed'],$owner),'authoring frozen once attempt starts');
    $answers=[];foreach($a['questions'] as $i=>$r)$answers[]=['id'=>$r['id'],'revision'=>0,'value'=>['0','true','  INTERNET   protocol  ','My own reasoning'][$i]];
    $a=oq_save_answers($aid,$student,$answers);oq_check($a['questions'][0]['answer']==='0'&&$a['questions'][0]['revision']===1,'autosave acknowledges server revision');
    oq_check(oq_state($aid,$student)['questions']===$a['questions'],'reload preserves answers and option order');
    oq_check(oq_save_answers($aid,$student,$answers)['questions']===$a['questions'],'identical interrupted save retry is idempotent');
    $stale=$answers;$stale[0]['value']='1';oq_denied(fn()=>oq_save_answers($aid,$student,$stale),'stale changed answer write rejected');
    $duplicate=[['id'=>$a['questions'][0]['id'],'revision'=>1,'value'=>'1'],['id'=>$a['questions'][0]['id'],'revision'=>1,'value'=>'0']];
    oq_denied(fn()=>oq_save_answers($aid,$student,$duplicate),'duplicate question in batch rejected atomically');
    oq_check(!array_key_exists('pass_percent',$a['quiz']),'unexposed pass mark not present in writing payload');
    oq_submit($aid,$student);oq_submit($aid,$student);$r=oq_result($aid,$student);
    oq_check($r['needs_review']&&$r['score']===null,'essay requires manual review, no invented score');
    oq_check(!$r['answers_released']&&$r['questions'][0]['key']===null&&$r['questions'][0]['classification']===null,'default hides answer keys and correctness');
    oq_check((int)oq_query("SELECT COUNT(*) FROM online_quiz_audit WHERE quiz_id=? AND action='attempt_submitted'",[$qid])->fetchColumn()===1,'duplicate submit has one audit');
    oq_denied(fn()=>oq_start($qid,$student,false),'attempt limit enforced');
    $f=oq_frozen($aid);oq_check((float)$f[0]['awarded_marks']===2.0,'MCQ deterministic grade');oq_check((float)$f[1]['awarded_marks']===2.0,'true/false deterministic grade');oq_check((float)$f[2]['awarded_marks']===2.0,'short answer normalization');
    oq_review($aid,$owner,[$f[3]['id']=>['score'=>3,'feedback'=>'Clear reasoning']],'Reviewed');
    oq_check((float)oq_result($aid,$student)['score']===9.0,'manual review completes total');
    oq_manage($qid,$owner,'close');oq_check(oq_result($aid,$student)['answers_released'],'answers released after closure');
    $eventId=(int)oq_query('SELECT id FROM calendar_events WHERE online_quiz_id=?',[$qid])->fetchColumn();reminder_sync_event($eventId);
    oq_check((int)oq_query("SELECT COUNT(*) FROM reminder_delivery_jobs WHERE source_id=? AND status IN ('pending','failed','processing')",[$eventId])->fetchColumn()===0,'worker resync does not revive closed-quiz reminders');
    $q=oq_fixture(['secure'=>true,'shuffle_questions'=>true,'shuffle_options'=>true]);oq_publish((int)$q['id'],$owner);
    oq_denied(fn()=>oq_start((int)$q['id'],$student,false),'secure rules acknowledgment required');
    $a=oq_start((int)$q['id'],$student,true);$aid=$a['id'];$frozen=$a['questions'];
    foreach(['network_lost','network_restored','reload','autosave_failed'] as $type){$a=oq_integrity($aid,$student,$type,bin2hex(random_bytes(8)));oq_check($a['warning_count']===0,$type.' is neutral');}
    oq_integrity($aid,$student,'reduced_viewport','brief-exit-001');$a=oq_integrity($aid,$student,'viewport_restored','brief-return-001');oq_check($a['warning_count']===0,'brief resize does not count');
    oq_integrity($aid,$student,'focus_exit','brief-focus-001');$a=oq_integrity($aid,$student,'focus_return','brief-focus-return');oq_check($a['warning_count']===0,'brief focus change does not count');
    foreach(['focus_exit','fullscreen_exit','reduced_viewport'] as $i=>$type){
        oq_integrity($aid,$student,$type,'exit-key-'.$i);
        // Test clock seam: age only this fixture's pending observation; no sleeps or real device-clock changes.
        oq_query('UPDATE online_quiz_attempts SET pending_integrity_at=DATE_SUB(NOW(),INTERVAL 10 SECOND) WHERE id=?',[$aid]);
        $a=oq_integrity($aid,$student,'confirm','confirm-key-'.$i);
        oq_check($a['warning_count']===$i+1,$type.' confirmed once');
        oq_check($a['locked_until']-$a['server_now']>=119&&$a['locked_until']-$a['server_now']<=120,'server controls 120-second lock');
        oq_check($a['questions']===[],'locked response has no readable questions');
        $again=oq_integrity($aid,$student,'confirm','confirm-key-'.$i);oq_check($again['warning_count']===$i+1,'idempotent integrity request');
        $again=oq_integrity($aid,$student,'reload','reload-key-'.$i);oq_check($again['locked_until']===$a['locked_until'],'reload never resets or bypasses lock');
        oq_denied(fn()=>oq_save_answers($aid,$student,[]),'locked autosave rejected');
        oq_query('UPDATE online_quiz_attempts SET locked_until=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=?',[$aid]);
        $a=oq_state($aid,$student);oq_check($a['questions']===$frozen,'same randomized set/order after lockout');
    }
    oq_check($a['flagged']&&$a['status']==='writing','third offence flags without zero or immediate submission');
    oq_check(count(oq_timeline($aid,$owner))>=13,'lecturer sees neutral and offence timeline');
    $q=oq_fixture(['allow_back'=>false]);oq_publish((int)$q['id'],$owner);$a=oq_start((int)$q['id'],$student,false);$aid=$a['id'];
    oq_check(count($a['questions'])===1,'no-back mode sends only current question');
    $savedBatch=[['id'=>$a['questions'][0]['id'],'value'=>'0','revision'=>0]];
    $a=oq_save_answers($aid,$student,$savedBatch,1);
    oq_check(oq_save_answers($aid,$student,$savedBatch,1)['current_position']===1,'lost navigation acknowledgment can retry without backtracking');
    oq_denied(fn()=>oq_save_answers($aid,$student,[],0),'server rejects backtracking');
    oq_denied(fn()=>oq_save_answers($aid,$student,[],3),'server rejects skipping forward');
    oq_query('UPDATE online_quiz_attempts SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=?',[$aid]);
    $a=oq_save_answers($aid,$student,[]);oq_check($a['status']==='submitted'&&$a['questions']===[],'expired autosave cannot extend timer and auto-submits');
    $q=oq_fixture(['result_release'=>'manual','answer_release'=>'manual','auto_submit'=>false]);oq_publish((int)$q['id'],$owner);$a=oq_start((int)$q['id'],$student,false);$aid=$a['id'];
    oq_query('UPDATE online_quiz_attempts SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=?',[$aid]);
    oq_state($aid,$student);$r=oq_result($aid,$student);oq_check($r['status']==='expired'&&!$r['score_released']&&!$r['answers_released'],'auto-submit off freezes expired attempt with manual release');
    oq_manage((int)$q['id'],$owner,'release_results');$r=oq_result($aid,$student);oq_check($r['score_released']&&!$r['answers_released'],'score release independent of answer release');
    oq_manage((int)$q['id'],$owner,'release_answers');oq_check(oq_result($aid,$student)['answers_released'],'manual answer release');
    $q=oq_fixture([],['opens_at'=>date('Y-m-d\TH:i',time()+600)]);oq_publish((int)$q['id'],$owner);oq_denied(fn()=>oq_start((int)$q['id'],$student,false),'opening time enforced');
    $q=oq_fixture([],['closes_at'=>date('Y-m-d\TH:i',time()-60)]);oq_denied(fn()=>oq_publish((int)$q['id'],$owner),'expired schedule cannot publish');
    require_once dirname(__DIR__).'/includes/calendar.php';$feed=calendar_events_for_window($other,time()-100,time()+7200);oq_check(!array_filter($feed,fn($e)=>in_array((int)($e['online_quiz_id']??0),$qids,true)),'calendar does not leak to unenrolled learners');
    oq_check(oq_monitor($qids[0],$owner)['stats']['Eligible students']===1,'eligible count follows learner rules');
    echo "Completed $checks online quiz checks.\n";
} finally {
    if($pdo->inTransaction())$pdo->rollBack();
    foreach($qids as $qid){$events=oq_query('SELECT id FROM calendar_events WHERE online_quiz_id=?',[$qid])->fetchAll(PDO::FETCH_COLUMN);foreach($events as $eid){oq_query("DELETE FROM reminder_delivery_jobs WHERE source_type='calendar' AND source_id=?",[$eid]);oq_query('DELETE FROM web_notifications WHERE event_id=?',[$eid]);oq_query('DELETE FROM calendar_events WHERE id=?',[$eid]);}oq_query('DELETE FROM online_quizzes WHERE id=?',[$qid]);}
    foreach($cids as $cid){oq_query('DELETE FROM enrollments WHERE course_id=?',[$cid]);oq_query('DELETE FROM courses WHERE id=?',[$cid]);}
    foreach($uids as $uid)oq_query('DELETE FROM users WHERE id=?',[$uid]);
}
