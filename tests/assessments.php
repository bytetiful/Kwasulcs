<?php
/** Isolated local integration fixtures. No real accounts, SMTP or global worker run. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('session.save_path',sys_get_temp_dir());
require_once dirname(__DIR__).'/includes/assessments.php';
if(DB_HOST!=='127.0.0.1'||DB_NAME!=='kwasulcs_lcs')throw new RuntimeException('Local database required.');
session_write_close();$tag='workspace-qa-'.bin2hex(random_bytes(4));$users=[];$cid=0;$qid=0;$atid=0;$checks=0;
function aw_check(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo "PASS: $label\n";}
function aw_count(string $sql,array $p=[]): int {return (int)oq_query($sql,$p)->fetchColumn();}
try {
    foreach(['lecturer','student','student','admin'] as $i=>$role){oq_query("INSERT INTO users(full_name,matric_no,email,password,role,pref_web_notifications,pref_email_notifications) VALUES (?,?,?,'invalid-test-hash',?,1,1)",[$tag.$i,$tag.$i,$tag.$i.'@example.invalid',$role]);$users[]=oq_query('SELECT * FROM users WHERE id=?',[(int)db()->lastInsertId()])->fetch();}
    [$owner,$student,$inactive,$outsider]=$users;
    oq_query("INSERT INTO courses(title,code,semester,lecturer_id) VALUES (?,?,'rain',?)",[$tag,$tag,$owner['id']]);$cid=(int)db()->lastInsertId();
    oq_query("INSERT INTO enrollments(student_id,course_id,status) VALUES (?,?,'active'),(?,?,'inactive')",[$student['id'],$cid,$inactive['id'],$cid]);
    $q=oq_save(0,['course_id'=>$cid,'title'=>$tag.' quiz','instructions'=>'Fixture only','opens_at'=>date('Y-m-d\TH:i',time()+600),'closes_at'=>date('Y-m-d\TH:i',time()+3600),'settings'=>['result_release'=>'manual','answer_release'=>'manual'],'questions'=>[['question_type'=>'truefalse','question_text'=>'Private question','marks'=>3,'key'=>['true'],'explanation'=>'PRIVATE_ANSWER_EXPLANATION']]],$owner);$qid=(int)$q['id'];
    oq_query("INSERT INTO assignment_tests(course_id,created_by,type,title,instructions,due_at,total_marks,status) VALUES (?,?,'test',?,'Fixture only',DATE_ADD(NOW(),INTERVAL 1 DAY),10,'draft')",[$cid,$owner['id'],$tag.' file test']);$atid=(int)db()->lastInsertId();
    aw_check(count(assessment_rows($owner))===2,'owner sees both draft domains');
    aw_check(assessment_snapshot($student)['courses']===[],'course filter does not disclose courses with only hidden drafts');
    aw_check(assessment_rows($student)===[],'student sees neither domain draft');
    aw_check(assessment_rows($outsider)===[],'unassigned admin has no global discovery bypass');
    assignment_test_publish($atid,$owner);assignment_test_publish($atid,$owner);
    aw_check(aw_count('SELECT COUNT(*) FROM chat_messages WHERE assignment_test_id=?',[$atid])===1,'existing assignment publish card remains single');
    oq_publish($qid,$owner);oq_publish($qid,$owner);
    $message=(int)oq_query('SELECT id FROM chat_messages WHERE online_quiz_id=?',[$qid])->fetchColumn();
    aw_check(aw_count('SELECT COUNT(*) FROM chat_messages WHERE online_quiz_id=?',[$qid])===1,'repeat quiz publish creates exactly one card');
    $snapshot=assessment_snapshot($student);
    aw_check($snapshot['counts']===['all'=>2,'assignment'=>1,'quiz'=>1,'active'=>1],'authorized counts include scheduled quiz but not as active');
    aw_check(count($snapshot['courses'])===1&&isset($snapshot['courses'][$cid]),'course filter options derive from authorized mixed rows');
    aw_check(str_contains($snapshot['html'],'assessment-title-line')&&!str_contains($snapshot['html'],'data-label="Type"'),'type badge is beside title rather than a separate column');
    aw_check(str_contains($snapshot['html'],'assessment-course-separator')&&str_contains($snapshot['html'],'assessment-meta-label'),'learner rows expose compact mobile card semantics');
    aw_check(!str_contains($snapshot['html'],'assessment-overflow'),'learner rows have no management-style overflow menu');
    aw_check(str_contains(assessment_snapshot($owner)['html'],'assessment-overflow')&&str_contains(assessment_snapshot($owner)['html'],'assignment_test_submissions?id='),'owner secondary actions preserve domain destinations in overflow');
    aw_check(str_contains($snapshot['html'],'data-category="assignment"')&&str_contains($snapshot['html'],'data-category="quiz"'),'mixed list has distinct filter identities');
    aw_check(str_contains($snapshot['html'],'data-search="'.$tag)&&str_contains($snapshot['html'],'data-status="scheduled"'),'search and effective status metadata present');
    aw_check(!str_contains($snapshot['html'],'data-assignment-action')&&!str_contains($snapshot['html'],'online_quiz_edit.php'),'learner rows expose no lecturer mutations');
    aw_check(assessment_rows($inactive)===[],'inactive enrollment cannot discover assessments');
    aw_check(aw_count("SELECT COUNT(*) FROM reminder_delivery_jobs WHERE source_type='online_quiz' AND source_id=? AND occurrence_key='published'",[$qid])===2,'one eligible learner receives independent web and email jobs');
    aw_check(aw_count("SELECT COUNT(*) FROM web_notifications n JOIN reminder_delivery_jobs j ON j.id=n.reminder_delivery_job_id WHERE j.source_type='online_quiz' AND j.source_id=?",[$qid])===1,'one immediate web notice across repeated publication');
    aw_check(aw_count("SELECT COUNT(*) FROM reminder_delivery_jobs WHERE source_type='online_quiz' AND source_id=? AND channel='email' AND status='pending'",[$qid])===1,'browser publication queues but does not send email');
    $job=oq_query("SELECT * FROM reminder_delivery_jobs WHERE source_type='online_quiz' AND source_id=? AND channel='email'",[$qid])->fetch();
    $calls=0;$claimed=reminder_claim((int)$job['id']);
    aw_check(reminder_deliver($claimed,function()use(&$calls){$calls++;return true;})==='sent'&&$calls===1,'existing CLI delivery adapter handles quiz publication');
    aw_check(reminder_claim((int)$job['id'])===null,'sent email job cannot be claimed twice');
    oq_query('UPDATE users SET pref_email_notifications=0 WHERE id=?',[$student['id']]);
    aw_check(oq_notice_delivery_context($job)===null,'delivery rechecks changed email consent');
    oq_query('UPDATE users SET pref_email_notifications=1 WHERE id=?',[$student['id']]);
    aw_check(str_contains(oq_chat_bundle($qid,$student)['online_quiz_card_html'],'Scheduled'),'scheduled card uses server availability');
    aw_check(!str_contains(oq_json(oq_chat_bundle($qid,$outsider)),$tag),'foreign chat viewer receives no quiz title');
    $q=oq_save($qid,['title'=>$tag.' edited','opens_at'=>date('Y-m-d\TH:i',time()-60)],$owner);
    $card=oq_chat_bundle($qid,$student)['online_quiz_card_html'];
    aw_check(str_contains($card,'edited')&&str_contains($card,'Start quiz'),'edit and opening update same rendered reference');
    aw_check((int)oq_query('SELECT id FROM chat_messages WHERE online_quiz_id=?',[$qid])->fetchColumn()===$message,'editing keeps publication message identity');
    aw_check(assessment_snapshot($student)['counts']['active']===2,'active count follows opening time');
    aw_check(!str_contains($card,'Private question')&&!str_contains($card,'PRIVATE_ANSWER_EXPLANATION'),'card has no protected questions or answers');
    $event=(int)oq_query('SELECT id FROM calendar_events WHERE online_quiz_id=?',[$qid])->fetchColumn();
    aw_check(aw_count('SELECT COUNT(*) FROM calendar_events WHERE online_quiz_id=?',[$qid])===1,'calendar projection remains unique after timing edit');
    aw_check(aw_count("SELECT COUNT(*) FROM reminder_delivery_jobs WHERE source_type='calendar' AND source_id=? AND user_id=? AND channel='email' AND status='pending'",[$event,$student['id']])===1,'scheduled quiz email uses existing calendar worker ledger');
    $a=oq_start($qid,$student,false);$aid=$a['id'];
    aw_check(str_contains(oq_chat_bundle($qid,$student)['online_quiz_card_html'],'Resume quiz'),'start updates personal card to in-progress action');
    aw_check(str_contains(assessment_snapshot($owner)['html'],'View configuration'),'owner quiz overflow retains a legitimate read-only configuration action after attempts start');
    oq_query('UPDATE online_quiz_attempts SET locked_until=DATE_ADD(NOW(),INTERVAL 120 SECOND) WHERE id=?',[$aid]);
    $monitor=oq_monitor($qid,$owner);
    aw_check($monitor['attempts'][0]['display_status']==='Locked'&&$monitor['stats']['Writing']===1,'monitor presents lock without changing writing statistics');
    aw_check(!$monitor['can_edit']&&$monitor['release']['results']['can_release'],'monitor retains authoring freeze and manual release eligibility');
    aw_check(str_contains(oq_chat_bundle($qid,$student)['online_quiz_card_html'],'View lock countdown'),'server lock reflected without exposing questions');
    oq_query('UPDATE online_quiz_attempts SET locked_until=NULL WHERE id=?',[$aid]);
    oq_submit($aid,$student);
    aw_check(str_contains(oq_chat_bundle($qid,$student)['online_quiz_card_html'],'awaiting release'),'submission awaits manual release');
    aw_check(aw_count("SELECT COUNT(*) FROM reminder_delivery_jobs WHERE source_type='online_quiz' AND source_id=? AND occurrence_key LIKE 'result:%'",[$qid])===0,'unreleased result creates no notice');
    oq_query("DELETE FROM online_quiz_audit WHERE quiz_id=? AND action='result_notice_pending'",[$qid]);
    oq_query('UPDATE online_quizzes SET results_released_at=NOW() WHERE id=?',[$qid]);
    oq_result_notices($qid,null,true);
    aw_check(aw_count("SELECT COUNT(*) FROM reminder_delivery_jobs WHERE source_type='online_quiz' AND source_id=? AND occurrence_key LIKE 'result:%'",[$qid])===0,'worker does not replay historical results without an integration marker');
    oq_track_result_notice($qid,(int)$aid);oq_track_result_notice($qid,(int)$aid);
    aw_check(aw_count("SELECT COUNT(*) FROM online_quiz_audit WHERE quiz_id=? AND action='result_notice_pending'",[$qid])===1,'review/submission delivery marker is idempotent');
    oq_result_notices($qid,null,true);
    aw_check(aw_count("SELECT COUNT(*) FROM reminder_delivery_jobs WHERE source_type='online_quiz' AND source_id=? AND occurrence_key LIKE 'result:%'",[$qid])===2,'worker discovers tracked released results without browser dispatch');
    oq_manage($qid,$owner,'release_results');oq_manage($qid,$owner,'release_results');
    $monitor=oq_monitor($qid,$owner);
    aw_check($monitor['attempts'][0]['display_status']==='Submitted'&&$monitor['stats']['Finalized']===1,'Submitted presentation retains finalized calculation');
    aw_check($monitor['release']['results']['released']&&!$monitor['release']['results']['can_release'],'released monitor controls cannot offer a repeat release');
    aw_check(!preg_match('/^\d{4}-\d{2}-\d{2}/',$monitor['attempts'][0]['last_contact_label']),'monitor contact label is human-readable WAT');
    aw_check(str_contains(oq_chat_bundle($qid,$student)['online_quiz_card_html'],'View result'),'released score changes card action');
    aw_check(aw_count("SELECT COUNT(*) FROM reminder_delivery_jobs WHERE source_type='online_quiz' AND source_id=? AND occurrence_key LIKE 'result:%'",[$qid])===2,'result release notices deduplicate across repeated release');
    aw_check(!str_contains(oq_json(oq_chat_bundle($qid,$student)),'PRIVATE_ANSWER_EXPLANATION'),'score release does not expose unreleased answer explanation in chat');
    oq_query('UPDATE online_quizzes SET closes_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=?',[$qid]);
    aw_check(str_contains(oq_chat_bundle($qid,$student)['online_quiz_card_html'],'Closed'),'clock-based closure reflected without manual action');
    aw_check(!oq_monitor($qid,$owner)['can_close'],'monitor does not offer manual Close on an effectively closed quiz');
    oq_manage($qid,$owner,'close');reminder_sync_event($event);
    aw_check(aw_count("SELECT COUNT(*) FROM reminder_delivery_jobs WHERE source_type='calendar' AND source_id=? AND status IN ('pending','failed','processing')",[$event])===0,'manual closure cancels calendar reminders and resync cannot revive them');
    aw_check(aw_count('SELECT COUNT(*) FROM chat_messages WHERE online_quiz_id=?',[$qid])===1,'submission release and closure never append extra chat messages');
    aw_check(assessment_snapshot($student)['counts']['active']===1,'closed quiz leaves active count');
    echo "Completed $checks assessment integration checks.\n";
}finally{
    if(db()->inTransaction())db()->rollBack();
    if($qid){oq_query("DELETE n FROM web_notifications n JOIN reminder_delivery_jobs j ON j.id=n.reminder_delivery_job_id WHERE j.source_type='online_quiz' AND j.source_id=?",[$qid]);oq_query("DELETE FROM reminder_delivery_jobs WHERE source_type='online_quiz' AND source_id=?",[$qid]);oq_query('DELETE FROM chat_messages WHERE online_quiz_id=?',[$qid]);}
    if($cid){foreach(oq_query('SELECT id FROM calendar_events WHERE course_id=?',[$cid])->fetchAll(PDO::FETCH_COLUMN) as $eid){oq_query('DELETE FROM web_notifications WHERE event_id=?',[$eid]);oq_query("DELETE FROM reminder_delivery_jobs WHERE source_type='calendar' AND source_id=?",[$eid]);}oq_query('DELETE FROM courses WHERE id=?',[$cid]);}
    foreach($users as $u)oq_query('DELETE FROM users WHERE id=?',[$u['id']]);
}
