<?php
/** Local-only, rollback deadline regression tests. No HTTP, files or external calls. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('session.save_path', sys_get_temp_dir());
require dirname(__DIR__) . '/includes/assignment_test.php';
if (DB_HOST !== '127.0.0.1' || DB_NAME !== 'kwasulcs_lcs') throw new RuntimeException('Local database required.');
$pdo = db();
$checks = 0;
function check_deadline(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $checks++; echo "PASS: $label\n";
}
$pdo->beginTransaction();
try {
    $course = $pdo->query("SELECT * FROM courses WHERE code='CSC 402' LIMIT 1")->fetch();
    if (!$course) throw new RuntimeException('CSC 402 required.');
    $owner = ['id'=>(int)$course['lecturer_id'], 'role'=>'lecturer'];
    $insert = $pdo->prepare("INSERT INTO assignment_tests (course_id,created_by,type,title,instructions,due_at,total_marks,status,allow_late_submissions)
        VALUES (?,?,'assignment','Deadline rollback QA','Test only',?,10,?,?)");
    $ids = [];
    foreach ([['published',0,-60],['published',0,3600],['draft',0,-60],['published',1,-60],['closed',0,-60]] as [$status,$late,$offset]) {
        $insert->execute([$course['id'],$owner['id'],date('Y-m-d H:i:s',time()+$offset),$status,$late]);
        $ids[]=(int)$pdo->lastInsertId();
    }
    $id = $ids[0];
    $studentIds=assignment_test_enrolled_student_ids((int)$course['id']);
    if (!$studentIds) throw new RuntimeException('Enrolled student required.');
    $pdo->prepare("INSERT INTO assignment_test_notification_jobs (assignment_test_id,user_id,notification_type,scheduled_at) VALUES (?,?,'due_reminder',NOW())")->execute([$id,$studentIds[0]]);
    check_deadline(assignment_test_close_overdue($id)===1,'overdue published assignment closes automatically');
    $item=assignment_test_find($id);
    check_deadline($item['status']==='closed','stored status is Closed');
    check_deadline(assignment_test_status_info($item)['label']==='Closed','shared badge is Closed');
    check_deadline(str_contains(assignment_test_chat_card_bundle($id,$owner)['assignment_test_card_html'],'Closed'),'shared chat card is Closed');
    $student=['id'=>$studentIds[0],'role'=>'student'];
    check_deadline(!assignment_test_submission_permissions($item,null,$student)['can_submit'],'closed deadline rejects submissions');
    $audit=$pdo->prepare("SELECT * FROM assignment_test_audit_logs WHERE assignment_test_id=? AND event_type='closed'");
    $audit->execute([$id]); $events=$audit->fetchAll();
    check_deadline(count($events)===1 && $events[0]['actor_id']===null && json_decode($events[0]['metadata_json'],true)['reason']==='deadline_elapsed','one system-attributed deadline audit event');
    $cancelled=$pdo->prepare('SELECT cancelled_at FROM assignment_test_notification_jobs WHERE assignment_test_id=?');
    $cancelled->execute([$id]);
    check_deadline((bool)$cancelled->fetchColumn(),'unsent due reminders cancelled');
    $notices=$pdo->prepare("SELECT COUNT(*) FROM web_notifications WHERE assignment_test_id=? AND notification_type='closed'");
    $notices->execute([$id]); $noticeCount=(int)$notices->fetchColumn();
    $eligible=$pdo->prepare("SELECT COUNT(*) FROM users WHERE pref_web_notifications=1 AND id IN (".implode(',',array_fill(0,count($studentIds),'?')).")");
    $eligible->execute($studentIds);
    check_deadline($noticeCount===(int)$eligible->fetchColumn(),'closure notifies eligible enrolled students');
    check_deadline(assignment_test_close_overdue($id)===0,'repeated reconciliation is idempotent');
    $notices->execute([$id]); $audit->execute([$id]);
    check_deadline((int)$notices->fetchColumn()===$noticeCount && count($audit->fetchAll())===1,'repeated polls do not duplicate notifications or audit');
    foreach (array_slice($ids,1) as $otherId) check_deadline(assignment_test_close_overdue($otherId)===0,'future/draft/late-enabled/already-closed item remains unchanged: '.$otherId);
    $late=assignment_test_find($ids[3]);
    check_deadline($late['status']==='published' && assignment_test_submission_permissions($late,null,$student)['can_submit'],'explicit late-submission permission preserved');
    check_deadline(assignment_test_status_info($late)['label']==='Open — late submissions allowed','overdue late-enabled item explains why it remains open');
    $pdo->prepare('UPDATE assignment_tests SET due_at=? WHERE id=?')->execute([date('Y-m-d H:i:s',time()-60),$ids[1]]);
    check_deadline(assignment_test_find($ids[1])['status']==='closed','ordinary detail/card read reconciles newly elapsed deadline');
    $snapshot=assignment_test_list_snapshot($owner);
    check_deadline(str_contains($snapshot['html'],'data-assignment-id="'.$id.'" data-status="closed"'),'list filtering uses persisted closed status');
    $pdo->prepare("UPDATE assignment_tests SET status='published',due_at=? WHERE id=?")->execute([date('Y-m-d H:i:s',time()+3600),$ids[1]]);
    check_deadline(assignment_test_close_overdue($ids[1])===0,'extended future deadline is not closed using stale expiry');
    check_deadline($pdo->inTransaction(),'reconciliation leaves caller transaction open');
    echo "Completed $checks deadline checks. All fixture writes rolled back.\n";
} finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
