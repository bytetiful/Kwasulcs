<?php
require_once __DIR__.'/includes/online_quiz.php';require_login();
header('Location: assignment_tests?category=quiz',true,302);
exit;
$user=current_user();
