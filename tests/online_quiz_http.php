<?php
/** Local HTTP contract tests with isolated fixture sessions; never uses a real login session. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('session.save_path',sys_get_temp_dir());
require_once dirname(__DIR__).'/includes/online_quiz.php';
if(DB_HOST!=='127.0.0.1'||DB_NAME!=='kwasulcs_lcs')throw new RuntimeException('Local database required.');
session_write_close();$uids=[];$qid=0;$cid=0;$server=null;$pipes=[];$checks=0;
$tag='oqhttp'.bin2hex(random_bytes(5));$dir=sys_get_temp_dir().'/'.$tag;$port=random_int(33000,39000);$token=bin2hex(random_bytes(32));
function oh_check(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo "PASS: $label\n";}
function oh_request(string $route,?int $user=null,?array $data=null,bool $csrf=true): array {
    global $port,$tag,$token;
    $c=curl_init('http://127.0.0.1:'.$port.'/'.$route);$headers=['Content-Type: application/json'];if($csrf)$headers[]='X-CSRF-Token: '.$token;
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>$headers]);
    if($user!==null)curl_setopt($c,CURLOPT_COOKIE,'PHPSESSID='.$tag.$user);
    if($data!==null){curl_setopt($c,CURLOPT_POST,true);curl_setopt($c,CURLOPT_POSTFIELDS,oq_json($data));}
    $body=curl_exec($c);if($body===false)throw new RuntimeException(curl_error($c));$status=curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
    return [$status,$body,json_decode($body,true)];
}
try{
    mkdir($dir,0700);
    foreach(['lecturer','student','admin'] as $i=>$role){oq_query("INSERT INTO users(full_name,matric_no,email,password,role,pref_web_notifications,pref_email_notifications) VALUES (?,?,?,'not-a-login-hash',?,0,0)",[$tag.$i,$tag.$i,$tag.$i.'@example.invalid',$role]);$uids[]=(int)db()->lastInsertId();
        file_put_contents($dir.'/sess_'.$tag.$uids[$i],'user_id|i:'.$uids[$i].';assignment_test_csrf|s:64:"'.$token.'";');}
    $owner=oq_query('SELECT * FROM users WHERE id=?',[$uids[0]])->fetch();
    oq_query("INSERT INTO courses(title,code,semester,lecturer_id) VALUES (?,?,'rain',?)",[$tag,$tag,$uids[0]]);$cid=(int)db()->lastInsertId();
    oq_query('INSERT INTO enrollments(student_id,course_id) VALUES (?,?)',[$uids[1],$cid]);
    $q=oq_save(0,['course_id'=>$cid,'title'=>$tag,'instructions'=>'HTTP fixture only','opens_at'=>date('Y-m-d\TH:i',time()-60),'closes_at'=>date('Y-m-d\TH:i',time()+3600),'duration_minutes'=>30,'settings'=>['shuffle_questions'=>false,'secure'=>true],'questions'=>[
        ['question_type'=>'short','question_text'=>'HTTP fixture response','marks'=>1,'key'=>['HTTP_SECRET_KEY'],'explanation'=>'HTTP_PRIVATE_EXPLANATION']
    ]],$owner);$qid=(int)$q['id'];
    $server=proc_open([PHP_BINARY,'-d','session.save_path='.$dir,'-S','127.0.0.1:'.$port,'-t',dirname(__DIR__)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    for($i=0;$i<100;$i++){$s=@fsockopen('127.0.0.1',$port,$errno,$error,.1);if($s){fclose($s);break;}usleep(20000);}
    oh_check(oh_request('online_quiz_api.php',null,['action'=>'state','id'=>0])[0]===401,'unauthenticated API denied');
    oh_check(oh_request('online_quiz_api.php',$uids[0],['action'=>'publish','id'=>$qid],false)[0]===403,'missing CSRF token denied');
    oh_check(oh_request('online_quiz_api.php',$uids[0])[0]===403,'GET cannot invoke API writes');
    oh_check(oq_find($qid)['status']==='draft','rejected requests leave draft unchanged');
    oh_check(oh_request('online_quiz.php?id='.$qid,$uids[1])[0]===404,'draft page hidden from learner');
    oh_check(oh_request('online_quiz_api.php',$uids[2],['action'=>'publish','id'=>$qid])[0]===422,'foreign admin publish denied');
    oh_check(oh_request('online_quiz_api.php',$uids[0],['action'=>'publish','id'=>$qid])[0]===200,'owner publishes through HTTP API');
    require_once dirname(__DIR__).'/includes/assessments.php';
    $student=oq_query('SELECT * FROM users WHERE id=?',[$uids[1]])->fetch();
    [$status,$html]=oh_request('online_quiz.php?id='.$qid,$uids[1]);
    oh_check($status===200&&str_contains($html,'Before you start')&&str_contains($html,'Start secure quiz')&&!str_contains($html,'Return to your secure quiz'),'first quiz entry uses concise start language rather than resume language');
    oh_check(str_contains($html,'oq-secure-checklist')&&str_contains($html,'Network')&&str_contains($html,'Secure test')&&str_contains($html,'Integrity'),'pre-start secure guidance renders as three compact groups');
    [$status,$html]=oh_request('assignment_tests.php',$uids[1]);
    oh_check($status===200&&str_contains($html,'Assignments &amp; Tests')&&str_contains($html,'data-category="quiz"'),'unified learner workspace renders authorized quiz');
    oh_check(str_contains($html,'assessment-context-label')&&!str_contains($html,'aria-label="Breadcrumb"'),'assessment workspace uses a compact context affordance rather than a redundant breadcrumb');
    oh_check(str_contains($html,'id="assessment-course"')&&str_contains($html,'id="assessment-showing"')&&str_contains($html,'data-assessment-page="next"'),'unified course filter and pagination contract present');
    [$status,,$snapshot]=oh_request('assessments_poll.php',$uids[1]);
    oh_check($status===200&&$snapshot['counts']['quiz']===1&&!str_contains($snapshot['html'],'HTTP_SECRET_KEY'),'unified poll uses sanitized authorized presentation');
    [$status,$html]=oh_request('chat_group.php?course_id='.$cid,$uids[1]);
    oh_check($status===200&&str_contains($html,oq_chat_bundle($qid,$student)['online_quiz_card_html']),'initial group page uses shared quiz renderer');
    $messageId=(int)oq_query('SELECT id FROM chat_messages WHERE online_quiz_id=?',[$qid])->fetchColumn();
    [$status,,$chat]=oh_request('chat_poll.php?type=group&id='.$cid.'&since_id=0',$uids[1]);
    oh_check($status===200&&$chat['messages'][0]['online_quiz_card_html']===oq_chat_bundle($qid,$student)['online_quiz_card_html'],'new-message polling uses the identical quiz renderer');
    oh_check(oh_request('chat_poll.php?type=group&id='.$cid.'&since_id=0',$uids[2])[0]===200&&!str_contains(oh_request('chat_poll.php?type=group&id='.$cid.'&since_id=0',$uids[2])[1],'HTTP_PRIVATE_EXPLANATION'),'staff chat access never grants answer access');
    oh_check(oh_request('online_quiz_monitor.php?id='.$qid,$uids[1])[0]===404,'learner cannot read monitoring page before an attempt');
    oh_check(oh_request('online_quiz_api.php',$uids[1],['action'=>'start','id'=>$qid,'ack'=>false])[0]===422,'HTTP secure acknowledgment enforced');
    [$status,$body,$json]=oh_request('online_quiz_api.php',$uids[1],['action'=>'start','id'=>$qid,'ack'=>true]);$a=$json['data'];$aid=$a['id'];
    oh_check($status===200&&count($a['questions'])===1,'HTTP attempt starts with learner projection');
    oh_check(!str_contains($body,'HTTP_SECRET_KEY')&&!str_contains($body,'HTTP_PRIVATE_EXPLANATION'),'HTTP writing payload has no answer keys or explanations');
    oh_check(oh_request('online_quiz_api.php',$uids[2],['action'=>'state','id'=>$aid])[0]===422,'HTTP foreign attempt state denied');
    oh_check(oh_request('online_quiz_monitor.php?id='.$qid,$uids[1])[0]===303,'active secure attempt guard takes precedence over unrelated internal pages');
    oh_check(oh_request('online_quiz_monitor.php?id='.$qid,$uids[0])[0]===200,'owner monitoring page loads');
    $monitorHtml=oh_request('online_quiz_monitor.php?id='.$qid,$uids[0])[1];
    oh_check(str_contains($monitorHtml,'oq-monitor-heading')&&!str_contains($monitorHtml,'app-hero oq-hero'),'monitor uses compact heading within unchanged standard shell');
    oh_check(str_contains($monitorHtml,'oq-attempt-search')&&str_contains($monitorHtml,'oq-attempt-filter')&&str_contains($monitorHtml,'Results &amp; answers'),'monitor filter and release-panel render contracts present');
    $monitorData=oq_monitor($qid,$owner);
    oh_check(!$monitorData['release']['results']['can_release']&&!$monitorData['release']['answers']['can_release'],'automatic policies do not expose active manual release controls');
    [$status,$html]=oh_request('online_quiz_take.php?id='.$aid.'&entry=start',$uids[1]);
    oh_check($status===200&&!str_contains($html,'HTTP_SECRET_KEY')&&!str_contains($html,'HTTP fixture response'),'runner HTML does not embed protected question payload');
    oh_check(str_contains($html,'"entry":"start"'),'runner receives explicit first-entry presentation state');
    oh_check(str_contains($html,'oq-secure-body')&&str_contains($html,'Secure assessment')&&!str_contains($html,'class="sidebar"')&&!str_contains($html,'class="bottom-nav"')&&!str_contains($html,'feedback-fab'),'active secure attempt removes global navigation and floating feedback exits');
    $resumeHtml=oh_request('online_quiz_take.php?id='.$aid,$uids[1])[1];
    oh_check(str_contains($resumeHtml,'"entry":"resume"'),'runner defaults existing links to resume presentation state');
    oh_check(oh_request('online_quiz_take.php?id=0',$uids[1])[0]===303,'a different runner URL cannot replace the active secure attempt');
    $guardedRoutes=['dashboard.php','courses.php','course.php?id='.$cid,'calendar.php','chat.php','assignment_tests.php','notifications.php','settings.php'];
    $guarded=true;foreach($guardedRoutes as $route)$guarded=$guarded&&oh_request($route,$uids[1])[0]===303;
    oh_check($guarded,'Home, Courses, Calendar, Assessment/Chat, header and settings routes return to the active secure attempt');
    oh_check(oh_request('logout.php',$uids[1])[0]===303,'logout cannot bypass an active secure attempt');
    $blocked=(int)oq_query("SELECT COUNT(*) FROM online_quiz_integrity_events WHERE attempt_id=? AND metadata_json LIKE '%internal_navigation_blocked%'",[$aid])->fetchColumn();
    oh_check($blocked>=count($guardedRoutes)+2,'blocked internal routes are retained as non-penalty integrity context');
    oh_check(oh_request('online_quiz_api.php',$uids[1],['action'=>'state','id'=>$aid])[0]===200,'secure quiz API remains available while page routes are guarded');
    $answer=['id'=>$a['questions'][0]['id'],'value'=>'HTTP_SECRET_KEY','revision'=>0];
    oh_check(oh_request('online_quiz_api.php',$uids[1],['action'=>'answers','id'=>$aid,'answers'=>[$answer]])[0]===200,'HTTP autosave accepted');
    [$status,,$json]=oh_request('online_quiz_api.php',$uids[1],['action'=>'submit','id'=>$aid]);
    oh_check($status===200&&$json['data']['status']==='submitted','HTTP submission finalizes once');
    $courseHtml=oh_request('course.php?id='.$cid,$uids[1])[1];
    oh_check((str_contains($courseHtml,'href="courses"')||str_contains($courseHtml,'href="courses.php"'))&&str_contains($courseHtml,'Back to Courses'),'normal course navigation returns after secure attempt finalization');
    [$status,,$chat]=oh_request('chat_poll.php?type=group&id='.$cid.'&since_id='.$messageId.'&since_time='.urlencode(date('Y-m-d H:i:s',time()+60)),$uids[1]);
    oh_check($status===200&&count($chat['messages'])===0&&str_contains(oq_json($chat['updates']),'View result'),'own submission refreshes existing card without message edit or new message');
    [$status,$html]=oh_request('online_quiz_result.php?id='.$aid,$uids[1]);
    oh_check($status===200&&!str_contains($html,'HTTP_PRIVATE_EXPLANATION')&&str_contains($html,'"key":null'),'result HTML keeps unreleased key and explanation hidden');
    oh_check(oh_request('online_quiz_result.php?id='.$aid,$uids[2])[0]===404,'foreign result page denied');
    echo "Completed $checks HTTP checks.\n";
}finally{
    if(is_resource($server)){proc_terminate($server);foreach($pipes as $p)if(is_resource($p))fclose($p);proc_close($server);}
    if(db()->inTransaction())db()->rollBack();
    if($qid){foreach(oq_query('SELECT id FROM calendar_events WHERE online_quiz_id=?',[$qid])->fetchAll(PDO::FETCH_COLUMN) as $eid){oq_query("DELETE FROM reminder_delivery_jobs WHERE source_type='calendar' AND source_id=?",[$eid]);oq_query('DELETE FROM web_notifications WHERE event_id=?',[$eid]);oq_query('DELETE FROM calendar_events WHERE id=?',[$eid]);}oq_query('DELETE FROM online_quizzes WHERE id=?',[$qid]);}
    if($cid){oq_query('DELETE FROM enrollments WHERE course_id=?',[$cid]);oq_query('DELETE FROM courses WHERE id=?',[$cid]);}
    foreach($uids as $uid)oq_query('DELETE FROM users WHERE id=?',[$uid]);
    if(is_dir($dir)){foreach(scandir($dir) as $f)if(str_starts_with($f,'sess_'))unlink($dir.'/'.$f);rmdir($dir);}
}
