<?php
require_once __DIR__.'/includes/online_quiz.php';require_login();$user=current_user();
try{$courses=assignment_test_courses_owned_by($user);if(!$courses)throw new RuntimeException('No managed courses.');$quiz=empty($_GET['id'])?null:oq_require((int)$_GET['id'],$user,true);}
catch(Throwable $e){http_response_code(404);exit('Quiz not available.');}
require_once __DIR__.'/partials/online_quiz_shell.php';
oq_page_begin('Create Quiz','Create a new quiz for your course in a few simple steps.',$user);
?>
<nav class="oq-steps" aria-label="Creation progress" id="oq-steps"></nav>
<div class="oq-columns"><section class="oq-card"><form id="oq-wizard" novalidate><div id="oq-step"></div></form></section><aside><section class="oq-card" id="oq-summary"></section><section class="oq-card" id="oq-checklist"></section><div class="oq-tip"><i class="bi bi-lightbulb"></i><div><strong>Tip</strong><p>Save a draft at any time. Saved steps are restored when you return.</p></div></div></aside></div>
<footer class="oq-card oq-toolbar"><button class="btn secondary" id="oq-previous">← Previous</button><div class="oq-actions"><button class="btn secondary" id="oq-save">Save Draft</button><button class="btn primary" id="oq-next">Next →</button></div></footer>
<?php oq_bootstrap(['quiz'=>$quiz,'questions'=>$quiz?oq_questions((int)$quiz['id']):[],'courses'=>$courses,'defaults'=>oq_defaults()]);oq_page_end('online-quiz-editor.js'); ?>
