<?php
require_once __DIR__.'/includes/online_quiz.php';require_login();$user=current_user();
try{$a=oq_attempt((int)($_GET['id']??0),$user);$quiz=oq_require((int)$a['quiz_id'],$user);}catch(Throwable $e){http_response_code(404);exit('Attempt not available.');}
$entry=($_GET['entry']??'resume')==='start'?'start':'resume';
$secureMode=$a['status']==='writing'&&!empty($a['settings']['secure']);
$navigationBlocked=!empty($_SESSION['secure_quiz_navigation_blocked']);unset($_SESSION['secure_quiz_navigation_blocked']);
require_once __DIR__.'/partials/online_quiz_shell.php';oq_page_begin($quiz['title'],$quiz['course_code'].' · Online Quiz/Test',$user,$secureMode);
?>
<div id="oq-workspace"><section class="oq-card"><h2>Loading your saved attempt…</h2><p>Checking the server timer and access state.</p></section></div>
<noscript><p class="oq-error">JavaScript is required for timed online quizzes.</p></noscript>
<?php oq_bootstrap(['attempt_id'=>(int)$a['id'],'buffer_key'=>'lcs-quiz-'.(int)$user['id'].'-'.(int)$a['id'],'entry'=>$entry,'navigation_blocked'=>$navigationBlocked]);oq_page_end('online-quiz-runner.js'); ?>
