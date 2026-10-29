<?php
/** Local-only rollback regression suite. No HTTP, uploads, AI or production calls. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('session.save_path', sys_get_temp_dir());
require dirname(__DIR__) . '/includes/assignment_test.php';
if (DB_HOST !== '127.0.0.1' || DB_NAME !== 'kwasulcs_lcs') throw new RuntimeException('Local database required.');
$pdo = db();
$checks = 0;
function check4(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $checks++; echo "PASS: $label\n";
}
$pdo->beginTransaction();
try {
    $course = $pdo->query("SELECT * FROM courses WHERE code='CSC 402' LIMIT 1")->fetch();
    if (!$course) throw new RuntimeException('CSC 402 fixture prerequisite missing.');
    $owner = ['id'=>(int)$course['lecturer_id'], 'role'=>'lecturer'];
    $s = $pdo->prepare("SELECT u.id FROM users u JOIN enrollments e ON e.student_id=u.id WHERE e.course_id=? AND e.status='active' AND u.role='student' LIMIT 1");
    $s->execute([$course['id']]);
    $student = ['id'=>(int)$s->fetchColumn(), 'role'=>'student'];
    $admin = ['id'=>(int)$pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn(), 'role'=>'admin'];
    if (!$student['id'] || !$admin['id']) throw new RuntimeException('Student/admin prerequisite missing.');
    $pdo->prepare("INSERT IGNORE INTO enrollments (course_id,student_id,status) VALUES (?,?,'active')")->execute([$course['id'],$admin['id']]);
    $pdo->prepare("INSERT INTO assignment_tests (course_id,created_by,type,title,instructions,due_at,total_marks,status,allow_resubmission) VALUES (?,?,'assignment','Stage 4 rollback fixture','Test only',DATE_ADD(NOW(),INTERVAL 2 DAY),10,'published',1)")->execute([$course['id'],$owner['id']]);
    $item = assignment_test_find((int)$pdo->lastInsertId());
    check4(assignment_test_can_manage($item,$owner), 'assigned lecturer manages');
    check4(!assignment_test_can_manage($item,$admin) && !assignment_test_can_mark($item,$admin) && !assignment_test_can_view_audit($item,$admin), 'ordinary admin cannot manage, mark or audit');
    check4(assignment_test_can_view($item,$student) && assignment_test_can_view($item,$admin), 'enrolled student and admin can view');
    check4(assignment_test_can_manage($item,['id'=>$owner['id'],'role'=>'admin']), 'assigned admin lecturer remains course owner');
    check4(!assignment_test_can_manage($item,$student), 'student cannot manage');
    $pdo->prepare("INSERT INTO assignment_test_submissions (assignment_test_id,student_id,note_text) VALUES (?,?,'unsent secret')")->execute([$item['id'],$student['id']]);
    $sid = (int)$pdo->lastInsertId();
    check4(assignment_test_submission_find($sid,false,$owner) === null, 'draft hidden from lecturer resolver');
    check4(assignment_test_submission_find($sid,false,$admin) === null, 'admin cannot resolve another learner draft');
    check4(assignment_test_submission_find($sid,false,$student) !== null, 'student resolves own draft');
    check4(assignment_test_submission_roster($item,$owner) === [], 'draft absent from roster');
    assignment_test_record_audit($item['id'],'submission_draft_saved',$student['id'],$sid);
    check4(assignment_test_audit_entries($item,$owner) === [], 'draft audit hidden');
    $fileInsert = $pdo->prepare("INSERT INTO assignment_test_submission_files (submission_id,file_path,original_filename,stored_filename,file_extension,mime_type,file_size,checksum_sha256,version_number,is_current) VALUES (?,'assignment_tests/rollback-only.txt',?,'rollback-only.txt','txt','text/plain',4,?, ?,?)");
    $fileInsert->execute([$sid,'final.txt',hash('sha256','test'),1,1]); $fid=(int)$pdo->lastInsertId();
    check4(assignment_test_submission_file_for_user($fid,$owner) === null, 'draft file cannot be downloaded by lecturer');
    $pdo->prepare("UPDATE assignment_test_submissions SET status='submitted',submission_attempt=1,submitted_at=NOW() WHERE id=?")->execute([$sid]);
    $manifest = [['id'=>$fid,'original_filename'=>'final.txt','mime_type'=>'text/plain','file_size'=>4,'checksum_sha256'=>hash('sha256','test'),'version_number'=>1]];
    $pdo->prepare("INSERT INTO assignment_test_submission_receipts (submission_id,submission_attempt,receipt_code,submitted_at,note_text_snapshot,file_manifest_json) VALUES (?,1,?,NOW(),'final note',?)")->execute([$sid,'rollback-'.bin2hex(random_bytes(8)),json_encode($manifest)]);
    $rid=(int)$pdo->lastInsertId();
    $receiptBefore=assignment_test_submission_receipt_find($rid,$student);
    $fileInsert->execute([$sid,'unsent-replacement.txt',hash('sha256','test'),2,1]); $replacement=(int)$pdo->lastInsertId();
    check4(assignment_test_submission_file_for_user($fid,$owner) !== null, 'finalized file is accessible to lecturer');
    check4(assignment_test_submission_file_for_user($replacement,$owner) === null, 'unsent replacement hidden after final submission');
    check4(assignment_test_submission_file_for_user($replacement,$student) !== null, 'student sees own replacement');
    $sub=assignment_test_submission_find($sid,false,$owner);
    check4($sub['note_text']==='final note', 'lecturer sees receipt note, not unsent note');
    check4(count(assignment_test_submission_files_for_user($sub,$item,$owner))===1, 'lecturer file history excludes unfinalized versions');
    check4(assignment_test_submission_receipt_find($rid,$admin)===null, 'admin cannot access another learner receipt');
    check4(count(assignment_test_submission_roster($item,$owner))===1, 'finalized work appears on roster');
    check4(assignment_test_submission_roster($item,$owner,['status'=>'draft'])===[], 'draft filter cannot reveal drafts');
    foreach ([$student,$admin] as $unauthorized) {
        $denied=false;
        try { assignment_test_complete_marking($item,$sid,$unauthorized,10,'Not allowed'); } catch (RuntimeException $e) { $denied=true; }
        check4($denied, $unauthorized['role'].' cannot mark another/current submission');
    }
    $badScore=false;
    try { assignment_test_complete_marking($item,$sid,$owner,11,'Too high'); } catch (InvalidArgumentException $e) { $badScore=true; }
    check4($badScore && assignment_test_submission_find($sid,false,$owner)['status']==='submitted', 'invalid score leaves lifecycle unchanged');
    $marked=assignment_test_complete_marking($item,$sid,$owner,8,'Good work');
    check4($marked['status']==='marked', 'submitted to under review to marked lifecycle');
    $card=assignment_test_chat_card_bundle($item,$student);
    check4(str_contains($card['assignment_test_card_html'],'8 / 10') && str_contains($card['assignment_test_card_html'],'View Feedback'), 'shared chat card shows own score and feedback');
    $adminCard=assignment_test_chat_card_bundle($item,$admin);
    check4(!str_contains($adminCard['assignment_test_card_html'],'8 / 10') && str_contains($adminCard['assignment_test_card_html'],'Pending Submission'), 'personalized card does not leak other learner score');
    $list=assignment_test_list_snapshot($student);
    check4(str_contains($list['html'],'8 / 10'), 'live list uses personal progress');
    assignment_test_return_submission($item,$sid,$owner,'Please revise');
    $resub=assignment_test_transition_submission($sid,'resubmitted',$student);
    check4((int)$resub['submission_attempt']===2 && (int)$resub['id']===$sid, 'return and resubmit increments attempt on same row');
    check4(assignment_test_submission_receipt_find($rid,$student)===$receiptBefore, 'previous receipt remains immutable');
    $pdo->prepare("INSERT INTO assignment_test_submissions (assignment_test_id,student_id,note_text) VALUES (?,?,'admin draft')")->execute([$item['id'],$admin['id']]);
    $adminSid=(int)$pdo->lastInsertId();
    $adminSub=assignment_test_transition_submission($adminSid,'submitted',$admin);
    check4($adminSub['status']==='submitted', 'enrolled admin can finalize own work');
    check4(assignment_test_submission_find($adminSid,false,$student)===null, 'student cannot inspect admin work');
    check4(in_array($admin['id'],assignment_test_enrolled_student_ids($course['id']),true), 'enrolled admin included in module notifications');
    $draftItem=$item; $draftItem['status']='draft';
    check4(!assignment_test_can_view($draftItem,$student) && !assignment_test_can_view($draftItem,$admin), 'unpublished work hidden from learners including admins');
    $closedItem=$item; $closedItem['status']='closed';
    check4(!assignment_test_submission_permissions($closedItem,$resub,$student)['can_upload'], 'closed work rejects uploads');
    $events=array_column(assignment_test_audit_entries($item,$owner),'event_type');
    check4(!in_array('submission_draft_saved',$events,true) && in_array('marked',$events,true), 'audit keeps lifecycle but hides earlier draft actions');
    echo "Completed $checks checks. All fixture writes rolled back.\n";
} finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
