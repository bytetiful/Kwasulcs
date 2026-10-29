<?php
require_once __DIR__.'/includes/online_quiz.php';require_login();$user=current_user();
try{$result=oq_result((int)($_GET['id']??0),$user);}catch(Throwable $e){http_response_code(404);exit('Result not available.');}
require_once __DIR__.'/partials/online_quiz_shell.php';oq_page_begin('Quiz Result','Review your submission and released feedback.',$user);
?>
<section id="oq-result"></section>
<?php oq_bootstrap(['result'=>$result]);oq_page_end('online-quiz-result.js'); ?>
