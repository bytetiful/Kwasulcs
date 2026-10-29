<?php
/** Local-only integration QA; fixture-only writes, loopback SMTP, cleanup in finally. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('session.save_path',sys_get_temp_dir());
require_once dirname(__DIR__).'/includes/reminders.php';
require_once dirname(__DIR__).'/includes/assignment_test.php';
if(DB_HOST!=='127.0.0.1'||DB_NAME!=='kwasulcs_lcs')throw new RuntimeException('Local DB required.');
session_write_close();
$pdo=db(); $checks=0; $users=[]; $events=[]; $items=[]; $courseId=null; $pipes=[]; $sink=null; $http=null; $httpPipes=[]; $sessionDir=null;
$tag='rq'.bin2hex(random_bytes(4)); $port=random_int(22000,29000);
function rq_check(bool $ok,string $label): void {global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo "PASS: $label\n";}
function rq_sql(string $sql,array $params=[]): array {$q=db()->prepare($sql);$q->execute($params);return $q->fetchAll();}
function rq_count(string $sql,array $params=[]): int {return (int)array_values(rq_sql($sql,$params)[0])[0];}
function rq_event(array $overrides=[]): int {
    global $users,$courseId,$events,$tag;
    $e=array_merge(['title'=>$tag,'event_type'=>'lecture','scope'=>'personal','course_id'=>null,'created_by'=>$users[0],
        'start_datetime'=>reminder_sql_time(time()+60),'end_datetime'=>reminder_sql_time(time()+3600),
        'remind_minutes_before'=>2,'notify_web'=>1,'notify_email'=>0,'is_recurring'=>0,'recurrence_rule'=>null,
        'recurrence_end_date'=>null,'created_at'=>reminder_sql_time(time()-600)],$overrides);
    $q=db()->prepare('INSERT INTO calendar_events ('.implode(',',array_keys($e)).') VALUES ('.implode(',',array_fill(0,count($e),'?')).')');
    $q->execute(array_values($e));$id=(int)db()->lastInsertId();$events[]=$id;reminder_sync_event($id);return $id;
}
function rq_jobs(int $id): array {return rq_sql("SELECT * FROM reminder_delivery_jobs WHERE source_type='calendar' AND source_id=? ORDER BY id",[$id]);}
function rq_notices(int $id): int {return rq_count('SELECT COUNT(*) FROM web_notifications WHERE event_id=?',[$id]);}
function rq_worker(int $id, bool $async=false, ?int $assignmentId=null) {
    global $port;
    $cmd=[PHP_BINARY,dirname(__DIR__).'/notify_cron.php','--run','--local-only','--event-id='.$id,'--test-smtp-port='.$port];
    if($assignmentId!==null)$cmd[]='--assignment-id='.$assignmentId;
    $p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));fclose($pipes[0]);
    if($async)return [$p,$pipes];
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
    if(!in_array($exit,[0,2],true))throw new RuntimeException('Worker failed: '.$out.$err);
    return json_decode(trim($out),true,512,JSON_THROW_ON_ERROR);
}
try {
    $sink=proc_open(['python3',__DIR__.'/reminder_smtp_sink.py',(string)$port],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    rq_check(trim(fgets($pipes[1]))==='READY','local SMTP sink ready (no external relay)');
    stream_set_blocking($pipes[1],false);
    $stmt=$pdo->prepare("INSERT INTO users (full_name,matric_no,email,password,role) VALUES (?,?,?,'not-a-login-hash',?)");
    foreach(['lecturer','student','student'] as $i=>$role){$stmt->execute([$tag.' '.$i,$tag.'-'.$i,$tag.$i.'@example.invalid',$role]);$users[]=(int)$pdo->lastInsertId();}
    $pdo->prepare("INSERT INTO courses(title,code,semester,lecturer_id) VALUES (?,?,'rain',?)")->execute([$tag,$tag,$users[0]]);$courseId=(int)$pdo->lastInsertId();
    foreach(array_slice($users,1) as $uid)$pdo->prepare('INSERT INTO enrollments(student_id,course_id) VALUES (?,?)')->execute([$uid,$courseId]);
    rq_check(date_default_timezone_get()==='Africa/Lagos' && rq_sql('SELECT @@session.time_zone AS tz')[0]['tz']==='+01:00','CLI uses Lagos and DB +01:00');
    rq_check(abs(time()-strtotime(rq_sql('SELECT NOW() AS n')[0]['n']))<=1,'PHP date/strtotime and DB NOW agree');
    $id=rq_event(['created_at'=>reminder_sql_time(time()),'start_datetime'=>reminder_sql_time(time()+123)]);
    $job=rq_jobs($id)[0];
    rq_check(strtotime($job['scheduled_at'])===strtotime($job['occurrence_at'])-120,'two-minute offset matches web scheduling');
    rq_worker($id);rq_check(rq_notices($id)===0,'worker before schedule sends nothing');
    sleep(4); // Browser is never opened: independent CLI only.
    rq_worker($id);rq_check(rq_notices($id)===1,'independent CLI catches slightly late reminder without browser requests');
    rq_worker($id);rq_check(rq_notices($id)===1,'repeat worker creates no duplicate');
    $id=rq_event(['scope'=>'course','course_id'=>$courseId,'notify_email'=>1]);
    $result=rq_worker($id);
    rq_check($result['sent']===6 && rq_notices($id)===3,'multiple recipients, both channels');
    rq_worker($id);rq_check(rq_count("SELECT COUNT(*) FROM reminder_delivery_jobs WHERE source_id=? AND status='sent'",[$id])===6,'repeat worker does not send duplicate email');
    $emailOnly=rq_event(['notify_web'=>0,'notify_email'=>1]);rq_worker($emailOnly);
    rq_check(rq_notices($emailOnly)===0 && rq_jobs($emailOnly)[1]['status']==='sent','email-only delivery');
    $disabled=rq_event(['notify_web'=>0]);rq_worker($disabled);
    rq_check(rq_count("SELECT COUNT(*) FROM reminder_delivery_jobs WHERE source_id=? AND status='cancelled'",[$disabled])===2,'disabled channels cancelled, not endlessly pending');
    $pdo->prepare('UPDATE users SET pref_web_notifications=0,pref_email_notifications=0 WHERE id=?')->execute([$users[0]]);
    $pref=rq_event(['notify_email'=>1]);rq_worker($pref);rq_check(rq_notices($pref)===0 && rq_jobs($pref)[1]['status']==='cancelled','user preferences applied to both channels');
    $pdo->prepare('UPDATE users SET pref_web_notifications=1,pref_email_notifications=1 WHERE id=?')->execute([$users[0]]);
    foreach(['daily'=>86400,'weekly'=>604800,'biweekly'=>1209600,'monthly'=>null] as $rule=>$step){
        $rec=rq_event(['is_recurring'=>1,'recurrence_rule'=>$rule]);$jobs=rq_jobs($rec);
        rq_check(count(array_unique(array_column($jobs,'occurrence_key')))>=3,$rule.' schedules distinct occurrences');
        reminder_sync_event($rec);rq_check(count(rq_jobs($rec))===count($jobs),$rule.' scheduling is idempotent');
        rq_worker($rec);rq_check(rq_notices($rec)===1,$rule.' only due occurrence delivered');
        $pdo->prepare('UPDATE calendar_events SET remind_minutes_before=3 WHERE id=?')->execute([$rec]);reminder_sync_event($rec);rq_worker($rec);
        rq_check(rq_notices($rec)===1,$rule.' edit after delivery preserves sent occurrence');
        // Move next ordinal into due window, keeping sent ordinal zero unchanged.
        if($step){$newStart=time()+60-$step;$pdo->prepare('UPDATE calendar_events SET start_datetime=?,end_datetime=?,created_at=? WHERE id=?')->execute([reminder_sql_time($newStart),reminder_sql_time($newStart+3600),reminder_sql_time($newStart-600),$rec]);
            reminder_sync_event($rec);rq_worker($rec);rq_check(rq_notices($rec)===2,$rule.' second ordinal delivered exactly once');}
        else {
            $newStart=strtotime('-1 month',time()+60);
            $pdo->prepare('UPDATE calendar_events SET start_datetime=?,end_datetime=?,created_at=? WHERE id=?')->execute([reminder_sql_time($newStart),reminder_sql_time($newStart+3600),reminder_sql_time($newStart-600),$rec]);
            reminder_sync_event($rec);rq_worker($rec);rq_check(rq_notices($rec)===2,'monthly second ordinal delivered exactly once');
        }
    }
    $monthly=['start_datetime'=>'2026-01-31 10:00:00','end_datetime'=>'2026-01-31 11:00:00','is_recurring'=>1,'recurrence_rule'=>'monthly','recurrence_end_date'=>null];
    $occ=reminder_occurrences($monthly,strtotime('2026-01-01'),strtotime('2026-05-31 23:59:59'));
    rq_check(array_map(fn($o)=>date('Y-m-d',$o['start']),$occ)===['2026-01-31','2026-03-31','2026-05-31'],'monthly invalid days skipped without date drift');
    $edited=rq_event();$pdo->prepare('UPDATE calendar_events SET start_datetime=DATE_ADD(start_datetime,INTERVAL 1 DAY),end_datetime=DATE_ADD(end_datetime,INTERVAL 1 DAY) WHERE id=?')->execute([$edited]);reminder_sync_event($edited);rq_worker($edited);
    rq_check(rq_notices($edited)===0 && strtotime(rq_jobs($edited)[0]['scheduled_at'])>time(),'edit before delivery replaces old schedule');
    $deleted=rq_event();reminder_cancel_event($deleted);$pdo->prepare('DELETE FROM calendar_events WHERE id=?')->execute([$deleted]);rq_worker($deleted);
    rq_check(rq_notices($deleted)===0 && rq_jobs($deleted)[0]['status']==='cancelled','deleted event retains cancelled history without delivery');
    $stale=rq_event(['start_datetime'=>reminder_sql_time(time()-10800),'end_datetime'=>reminder_sql_time(time()-7200),'created_at'=>reminder_sql_time(time()-20000)]);rq_worker($stale);rq_check(rq_notices($stale)===0,'stale reminders do not send after downtime');
    $recover=rq_event();rq_check(reminder_worker_lock(),'test crash acquires worker lock');$claimed=reminder_claim((int)rq_jobs($recover)[0]['id']);reminder_worker_unlock();
    rq_check($claimed!==null,'job claimed before simulated crash');
    $pdo->prepare('UPDATE reminder_delivery_jobs SET lease_expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=?')->execute([$claimed['id']]);
    rq_worker($recover);rq_check(rq_notices($recover)===1 && rq_jobs($recover)[0]['attempts']==2,'expired claim recovers after worker crash');
    // The unique delivery reference protects replay even if job state is incorrectly reset.
    $pdo->prepare("UPDATE reminder_delivery_jobs SET status='pending' WHERE id=?")->execute([$claimed['id']]);rq_worker($recover);
    rq_check(rq_notices($recover)===1,'database-level web idempotency survives replay');
    $pdo->prepare('UPDATE users SET email=? WHERE id=?')->execute([$tag.'retry@example.invalid',$users[0]]);
    $retry=rq_event(['notify_email'=>1,'notify_web'=>0]);rq_worker($retry);$j=rq_jobs($retry)[1];
    rq_check($j['status']==='failed' && $j['sent_at']===null && $j['attempts']==1 && $j['last_error']!==null && $j['next_retry_at']>date('Y-m-d H:i:s'),'SMTP failure records retry/backoff without marking sent');
    rq_worker($retry);rq_check(rq_jobs($retry)[1]['attempts']==1,'backoff prevents immediate retry');
    $pdo->prepare('UPDATE reminder_delivery_jobs SET next_retry_at=NOW() WHERE id=?')->execute([$j['id']]);rq_worker($retry);rq_check(rq_jobs($retry)[1]['status']==='sent','SMTP retry succeeds through PHPMailer to local sink');
    $pdo->prepare('UPDATE users SET email=? WHERE id=?')->execute([$tag.'permanent@example.invalid',$users[0]]);
    $failure=rq_event(['scope'=>'course','course_id'=>$courseId,'notify_email'=>1,'notify_web'=>0]);$r=rq_worker($failure);
    rq_check($r['failed']===1 && $r['sent']===2,'permanent SMTP failure does not block later recipients');
    $pdo->prepare('UPDATE users SET email=? WHERE id=?')->execute([$tag.'slow@example.invalid',$users[0]]);
    $overlap=rq_event(['notify_email'=>1]);[$p,$ps]=rq_worker($overlap,true);
    for($i=0;$i<100;$i++){if(rq_count("SELECT COUNT(*) FROM reminder_delivery_jobs WHERE source_id=? AND channel='email' AND status='processing'",[$overlap]))break;usleep(20000);}
    $second=rq_worker($overlap);$out=stream_get_contents($ps[1]);$err=stream_get_contents($ps[2]);fclose($ps[1]);fclose($ps[2]);proc_close($p);
    rq_check($second['status']==='busy' && rq_notices($overlap)===1 && rq_jobs($overlap)[1]['attempts']==1,'overlapping workers: one SMTP attempt and one web notice');
    // Overlap also rejects mutation while SMTP is in flight, without a long row lock.
    $lockTest=rq_event(['notify_email'=>1,'notify_web'=>0]);[$p,$ps]=rq_worker($lockTest,true);
    for($i=0;$i<100;$i++){if(rq_count("SELECT COUNT(*) FROM reminder_delivery_jobs WHERE source_id=? AND status='processing'",[$lockTest]))break;usleep(20000);}
    $blocked=false;try{reminder_event_lock($lockTest);}catch(RuntimeException $e){$blocked=true;}
    rq_check($blocked,'event edit lock cannot supersede an in-flight email');
    stream_get_contents($ps[1]);stream_get_contents($ps[2]);fclose($ps[1]);fclose($ps[2]);proc_close($p);
    // Delivery-time audience reconciliation: joins, inactive members, lecturer changes.
    $aud=rq_event(['scope'=>'course','course_id'=>$courseId]);
    $pdo->prepare("UPDATE enrollments SET status='inactive' WHERE student_id=? AND course_id=?")->execute([$users[1],$courseId]);rq_worker($aud);
    rq_check(rq_notices($aud)===2,'inactive enrollment excluded at delivery');
    $pdo->prepare("UPDATE enrollments SET status='active' WHERE student_id=? AND course_id=?")->execute([$users[1],$courseId]);rq_worker($aud);
    rq_check(rq_notices($aud)===3,'newly active member receives still-timely occurrence');
    // Assignment/Test integration scoped to fixtures; existing lifecycle helpers unchanged.
    $q=$pdo->prepare("INSERT INTO assignment_tests(course_id,created_by,type,title,instructions,due_at,total_marks,status,allow_late_submissions)
        VALUES (?,?,'assignment',?,'QA',?,10,'published',?)");
    foreach([['future',3600,0],['close',-60,0],['late',-60,1]] as [$name,$offset,$late]){
        $q->execute([$courseId,$users[0],$tag.$name,reminder_sql_time(time()+$offset),$late]);$at=(int)$pdo->lastInsertId();$items[]=$at;
        $pdo->prepare("INSERT INTO assignment_test_notification_jobs(assignment_test_id,user_id,notification_type,scheduled_at) VALUES (?,?,'due_reminder',DATE_SUB(NOW(),INTERVAL 30 SECOND))")->execute([$at,$users[1]]);
        rq_worker($disabled,false,$at);
        if($name==='future'){
            rq_check(rq_count("SELECT COUNT(*) FROM web_notifications WHERE assignment_test_id=? AND notification_type='due_reminder'",[$at])===1,'Assignment/Test due reminder runs from authoritative worker');
            rq_worker($disabled,false,$at);rq_check(rq_count("SELECT COUNT(*) FROM web_notifications WHERE assignment_test_id=? AND notification_type='due_reminder'",[$at])===1,'Assignment/Test reminder remains idempotent');
        } elseif($name==='close')rq_check(rq_sql('SELECT status FROM assignment_tests WHERE id=?',[$at])[0]['status']==='closed','worker automatically closes overdue Assignment/Test');
        else rq_check(rq_sql('SELECT status FROM assignment_tests WHERE id=?',[$at])[0]['status']==='published','late-submission permission prevents automatic closure');
    }
    $at=$items[0];$item=assignment_test_find($at);$owner=['id'=>$users[0],'role'=>'lecturer'];
    $pdo->prepare("UPDATE assignment_test_notification_jobs SET sent_at=NULL WHERE assignment_test_id=?")->execute([$at]);
    // Exercise actual update API with required fields, not a redesigned deadline path.
    $updated=assignment_test_update_item($at,['title'=>$item['title'],'type'=>$item['type'],'course_id'=>$courseId,'instructions'=>'QA',
        'due_at'=>reminder_sql_time(time()+172800),'total_marks'=>10,'allowed_extensions'=>'pdf','visibility'=>'enrolled','allow_late_submissions'=>0],$owner);
    rq_check(rq_count('SELECT COUNT(*) FROM assignment_test_notification_jobs WHERE assignment_test_id=? AND cancelled_at IS NOT NULL',[$at])>=1,'deadline extension cancels old jobs');
    rq_worker($disabled,false,$at);rq_check(rq_sql('SELECT status FROM assignment_tests WHERE id=?',[$at])[0]['status']==='published','extended deadline stays open');
    require_once dirname(__DIR__).'/includes/calendar.php';
    $rendered=expand_recurring_events([$monthly],strtotime('2026-01-01'),strtotime('2026-05-31 23:59:59'));
    rq_check(array_column($rendered,'start_datetime')===array_map(fn($o)=>reminder_sql_time($o['start']),$occ),'calendar display and worker share monthly occurrence calculation');
    $legacyBefore=rq_count('SELECT COUNT(*) FROM reminder_delivery_jobs WHERE legacy_notification_id IS NOT NULL');
    rq_check(reminder_import_legacy()===0 && rq_count('SELECT COUNT(*) FROM reminder_delivery_jobs WHERE legacy_notification_id IS NOT NULL')===$legacyBefore,'legacy import repeats safely without replaying completed channels');
    // Real HTTP requests on a loopback PHP server with an isolated QA-user session.
    $httpPort=$port+10000;$sessionDir=sys_get_temp_dir().'/'.$tag.'-sessions';mkdir($sessionDir,0700);
    file_put_contents($sessionDir.'/sess_'.$tag,'user_id|i:'.$users[0].';role|s:8:"lecturer";');
    $http=proc_open([PHP_BINARY,'-d','session.save_path='.$sessionDir,'-S','127.0.0.1:'.$httpPort,'-t',dirname(__DIR__)],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$httpPipes);
    for($i=0;$i<100;$i++){$socket=@fsockopen('127.0.0.1',$httpPort,$errno,$error,0.1);if($socket){fclose($socket);break;}usleep(20000);}
    $httpEvent=rq_event();
    foreach(['notify_cron.php?run=1','notify_poll.php','dashboard.php','calendar.php'] as $route){
        $curl=curl_init('http://127.0.0.1:'.$httpPort.'/'.$route);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_COOKIE=>'PHPSESSID='.$tag]);
        $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        rq_check($status===($route==='notify_cron.php?run=1'?403:200),'HTTP '.$route.' returns expected status');
        rq_check(rq_notices($httpEvent)===0,'HTTP '.$route.' does not dispatch a due fixture');
        if($route==='notify_poll.php')rq_check(isset(json_decode($body,true)['notifications']),'authenticated poll still retrieves notifications');
    }
    $curl=curl_init('http://127.0.0.1:'.$httpPort.'/notify_cron.php?run=1');curl_setopt($curl,CURLOPT_RETURNTRANSFER,true);curl_exec($curl);
    rq_check(curl_getinfo($curl,CURLINFO_RESPONSE_CODE)===403 && rq_notices($httpEvent)===0,'unauthenticated HTTP run=1 is blocked without side effects');curl_close($curl);
    rq_worker($httpEvent);rq_check(rq_notices($httpEvent)===1,'only subsequent CLI execution delivers the HTTP-test reminder');
    // Source-level regression assertions: no request path can dispatch scheduled jobs.
    foreach(['dashboard.php','calendar.php','notify_poll.php'] as $file){$source=file_get_contents(dirname(__DIR__).'/'.$file);
        rq_check(!str_contains($source,'calendar_notifications')&&!str_contains($source,'assignment_test_fire_due_reminders(')&&!str_contains($source,'send_mail('),$file.' no longer dispatches reminders');}
    $cron=file_get_contents(dirname(__DIR__).'/notify_cron.php');rq_check(strpos($cron,"PHP_SAPI !== 'cli'")<strpos($cron,'require_once'),'public worker is rejected before bootstrap');
    $poll=file_get_contents(dirname(__DIR__).'/notify_poll.php');rq_check(str_contains($poll,'WHERE user_id=?')&&!preg_match('/INSERT|UPDATE|DELETE/',$poll),'poll is user-scoped and read-only');
    usleep(100000);$mailLog=stream_get_contents($pipes[1]);$accepted=array_filter(explode("\n",trim($mailLog)));
    rq_check(count($accepted)===9,'SMTP sink confirms exactly nine accepted messages across deterministic test cases');
    echo "Completed $checks reminder checks. Fixture records removed; no real email sent.\n";
} finally {
    if($pdo->inTransaction())$pdo->rollBack();
    reminder_worker_unlock();
    foreach($items as $id){$pdo->prepare('DELETE FROM web_notifications WHERE assignment_test_id=?')->execute([$id]);$pdo->prepare('DELETE FROM assignment_tests WHERE id=?')->execute([$id]);}
    foreach($events as $id){$pdo->prepare('DELETE FROM web_notifications WHERE event_id=?')->execute([$id]);$pdo->prepare("DELETE FROM reminder_delivery_jobs WHERE source_type='calendar' AND source_id=?")->execute([$id]);$pdo->prepare('DELETE FROM calendar_events WHERE id=?')->execute([$id]);}
    if($courseId)$pdo->prepare('DELETE FROM courses WHERE id=?')->execute([$courseId]);
    foreach($users as $id)$pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    if(is_resource($sink)){proc_terminate($sink);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($sink);}
    if(is_resource($http)){proc_terminate($http);foreach($httpPipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($http);}
    if($sessionDir && is_dir($sessionDir)){foreach(glob($sessionDir.'/sess_*') as $file)unlink($file);rmdir($sessionDir);}
}
