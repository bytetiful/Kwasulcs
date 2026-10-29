<?php
// Explicit, local-only browser fixture. Does not change existing coursework or credentials.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('session.save_path',sys_get_temp_dir());
require_once dirname(__DIR__).'/includes/online_quiz.php';
if(DB_HOST!=='127.0.0.1'||DB_NAME!=='kwasulcs_lcs')throw new RuntimeException('Local database required.');
session_write_close();
$tag='OQ-BROWSER-QA-20260907';$email='oq-browser-qa-20260907@example.invalid';
$action=$argv[1]??'';
$owner=oq_query('SELECT * FROM users WHERE email=?',[$email])->fetch();
if($action==='cleanup'){
    if(!$owner){echo "No fixture exists.\n";exit;}
    $pdo=db();$pdo->beginTransaction();
    try{
        $courses=oq_query('SELECT id FROM courses WHERE code=? AND lecturer_id=?',[$tag,$owner['id']])->fetchAll(PDO::FETCH_COLUMN);
        foreach($courses as $course){
            $quizzes=oq_query('SELECT id FROM online_quizzes WHERE course_id=? AND created_by=?',[$course,$owner['id']])->fetchAll(PDO::FETCH_COLUMN);
            foreach($quizzes as $quiz){
                $events=oq_query('SELECT id FROM calendar_events WHERE online_quiz_id=?',[$quiz])->fetchAll(PDO::FETCH_COLUMN);
                foreach($events as $event){oq_query("DELETE FROM reminder_delivery_jobs WHERE source_type='calendar' AND source_id=?",[$event]);oq_query('DELETE FROM web_notifications WHERE event_id=?',[$event]);oq_query('DELETE FROM calendar_events WHERE id=?',[$event]);}
                oq_query('DELETE FROM online_quizzes WHERE id=?',[$quiz]);
            }
            oq_query('DELETE FROM enrollments WHERE course_id=?',[$course]);oq_query('DELETE FROM courses WHERE id=?',[$course]);
        }
        oq_query('DELETE FROM users WHERE id=? AND email=?',[$owner['id'],$email]);$pdo->commit();echo "Removed isolated quiz, course, fixture lecturer and their QA history.\n";
    }catch(Throwable $e){$pdo->rollBack();throw $e;}exit;
}
if($action!=='prepare'||empty($argv[2]))throw new RuntimeException('Usage: prepare <existing enrolled-learner account id> | cleanup');
if($owner)throw new RuntimeException('Fixture already exists; inspect or clean it before preparing another.');
$learner=oq_query("SELECT id FROM users WHERE id=? AND role IN ('student','admin')",[(int)$argv[2]])->fetchColumn();
if(!$learner)throw new RuntimeException('Learner account not available.');
oq_query("INSERT INTO users(full_name,matric_no,email,password,role,pref_web_notifications,pref_email_notifications) VALUES (?,?,?,'not-a-login-hash','lecturer',0,0)",[$tag,$tag,$email]);
$owner=oq_query('SELECT * FROM users WHERE email=?',[$email])->fetch();
oq_query("INSERT INTO courses(title,code,semester,lecturer_id) VALUES (?,?,'rain',?)",['Temporary Online Quiz browser QA',$tag,$owner['id']]);$course=(int)db()->lastInsertId();
$q=oq_save(0,['course_id'=>$course,'title'=>'Temporary Online Quiz browser QA','instructions'=>'Isolated local QA. These questions have no academic effect. Network interruptions and reloads are neutral.','opens_at'=>date('Y-m-d\TH:i',time()-60),'closes_at'=>date('Y-m-d\TH:i',time()+10800),'duration_minutes'=>90,'attempts_allowed'=>2,'settings'=>['secure'=>true,'shuffle_questions'=>false,'shuffle_options'=>true,'answer_release'=>'after_close'],'questions'=>[
    ['question_type'=>'truefalse','question_text'=>'This is temporary local QA content.','marks'=>1,'key'=>['true']],
    ['question_type'=>'short','question_text'=>'Type local test to check answer saving.','marks'=>1,'key'=>['local test']]
]],$owner);
oq_publish((int)$q['id'],$owner);
// Disable both channels before enrolling a real account in this isolated course.
$event=(int)oq_query('SELECT id FROM calendar_events WHERE online_quiz_id=?',[$q['id']])->fetchColumn();
oq_query('UPDATE calendar_events SET notify_web=0,notify_email=0 WHERE id=?',[$event]);reminder_cancel_event($event);
oq_query('INSERT INTO enrollments(student_id,course_id) VALUES (?,?)',[$learner,$course]);
echo 'http://localhost/LMS-portal/online_quiz.php?id='.(int)$q['id'].PHP_EOL;
