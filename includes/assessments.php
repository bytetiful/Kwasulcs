<?php
declare(strict_types=1);
require_once __DIR__.'/online_quiz.php';

/** Presentation only: domain authorization and mutations remain in their modules. */
function assessment_quiz_context(array $q, array $user): array
{
    $id=(int)$q['id'];
    if (oq_owner($q,$user)) return ['label'=>$q['status']==='draft'?'Continue draft':'View submissions','url'=>($q['status']==='draft'?'online_quiz_edit':'online_quiz_monitor').'?id='.$id,'note'=>''];
    $a=oq_query('SELECT id,status,expires_at,locked_until,flagged,needs_review,settings_snapshot_json,submitted_at FROM online_quiz_attempts WHERE quiz_id=? AND student_id=? ORDER BY id DESC LIMIT 1',[$id,$user['id']])->fetch();
    if ($a) {
        if ($a['status']==='writing') {
            $locked=!empty($a['locked_until']) && strtotime($a['locked_until'])>time();
            return ['label'=>strtotime($a['expires_at'])<=time()?'Finalize attempt':($locked?'View lock countdown':'Resume quiz'), 'url'=>'online_quiz_take.php?id='.(int)$a['id'], 'note'=>$locked?'Temporarily locked':($a['flagged']?'Flagged for review':'In progress')];
        }
        $settings=json_decode($a['settings_snapshot_json'],true);
        $released=!$a['needs_review']&&oq_released($settings['result_release'],$q,$a,'results');
        return ['label'=>$released?'View result':'View submission','url'=>'online_quiz_result?id='.(int)$a['id'],'note'=>($a['flagged']?'Flagged for review · ':'').($released?'Result available':'Submitted · awaiting release')];
    }
    return ['label'=>oq_display_status($q)==='Active'?'Start quiz':'View details','url'=>'online_quiz?id='.$id,'note'=>oq_display_status($q)==='Scheduled'?'Not started · not yet open':'Not started'];
}

function assessment_rows(array $user): array
{
    $rows=[];
    foreach (assignment_test_list_for_user($user) as $item) {
        $owner=assignment_test_can_manage($item,$user);
        $progress=$owner?null:assignment_test_learner_progress($item,$item);
        $status=assignment_test_status_info($item);
        $rows[]=['key'=>'assignment:'.$item['id'],'category'=>'assignment','title'=>$item['title'], 'type'=>assignment_test_type_label($item['type']),
            'course_id'=>$item['course_id'],'course_code'=>$item['course_code'],'course_title'=>$item['course_title'],
            'due'=>$item['due_at'],'marks'=>$item['total_marks'],'status'=>$item['status']==='published'?'open':$item['status'],'status_label'=>$status['label'],
            'created'=>$item['created_at'],'owner'=>$owner,'item'=>$item,'progress'=>$progress,
            'context'=>['url'=>'assignment_test?id='.(int)$item['id'],'label'=>!empty($progress['feedback_available'])?'View feedback':(!empty($item['submission_id'])&&$item['submission_status']==='draft'?'Continue':'Open'),'note'=>'']];
    }
    foreach (oq_list($user) as $q) {
        $meta=oq_query('SELECT c.title,COALESCE(SUM(qq.marks),0) AS marks FROM courses c LEFT JOIN online_quiz_questions qq ON qq.quiz_id=? WHERE c.id=? GROUP BY c.id,c.title',[$q['id'],$q['course_id']])->fetch();
        $status=oq_display_status($q);$owner=oq_owner($q,$user);
        $rows[]=['key'=>'quiz:'.$q['id'],'category'=>'quiz','title'=>$q['title'],'type'=>'Online Quiz','course_id'=>$q['course_id'],'course_code'=>$q['course_code'],'course_title'=>$meta['title'],
            'due'=>$q['closes_at'],'marks'=>$meta['marks'],'status'=>$status==='Active'?'open':strtolower($status),'status_label'=>$status==='Active'?'Open':$status,
            'created'=>$q['created_at'],'owner'=>$owner,'item'=>$q,'progress'=>null,'context'=>assessment_quiz_context($q,$user)];
    }
    usort($rows,fn($a,$b)=>strcmp($b['created'],$a['created'])?:strcmp($b['key'],$a['key']));
    return $rows;
}

function assessment_snapshot(array $user): array
{
    $rows=assessment_rows($user);$counts=['all'=>count($rows),'assignment'=>0,'quiz'=>0,'active'=>0];
    foreach($rows as $r){$counts[$r['category']]++;if($r['status']==='open')$counts['active']++;}
    $courses=[];foreach($rows as $r)$courses[(int)$r['course_id']]=$r['course_code'].' — '.$r['course_title'];asort($courses);
    ob_start();foreach($rows as $r):$item=$r['item'];$id=(int)$item['id'];
        $primary=$r['context'];
        $receiptId=(!$r['owner']&&$r['category']==='assignment'&&!empty($item['submission_id']))?oq_query('SELECT id FROM assignment_test_submission_receipts WHERE submission_id=? ORDER BY submission_attempt DESC LIMIT 1',[$item['submission_id']])->fetchColumn():null;
        if($r['owner']&&$r['category']==='assignment'&&$item['status']!=='draft')$primary=['url'=>'assignment_test_submissions?id='.$id,'label'=>'View submissions'];
        if($r['owner']&&$r['category']==='quiz'&&$item['status']!=='draft')$primary['label']=$r['status']==='closed'?'View results':'Monitor';
        $secondary=($r['owner']&&$r['category']==='assignment')||($r['owner']&&$r['category']==='quiz'&&$item['status']!=='draft'); ?>
    <article class="assessment-row assessment-row--<?= $r['owner']?'owner':'student' ?><?= $secondary?' assessment-row--has-overflow':'' ?>" data-assignment-id="<?= h($r['key']) ?>" data-category="<?= h($r['category']) ?>" data-status="<?= h($r['status']) ?>" data-course="<?= (int)$r['course_id'] ?>" data-search="<?= h(mb_strtolower($r['title'].' '.$r['course_code'].' '.$r['course_title'])) ?>">
        <div class="assessment-title"><span class="assignment-stat-icon <?= $r['category']==='quiz'?'is-purple':'is-green' ?>" aria-hidden="true"><i class="bi <?= $r['category']==='quiz'?'bi-check2-circle':'bi-file-earmark-text' ?>"></i></span><div class="assessment-title-copy"><div class="assessment-title-line"><h2><a href="<?= h($primary['url']) ?>"><?= h($r['title']) ?></a></h2><span class="assignment-type-badge is-<?= $r['category']==='quiz'?'test':'assignment' ?>"><?= h($r['type']) ?></span></div></div></div>
        <div class="assessment-course" data-label="Course"><strong><?= h($r['course_code']) ?></strong><span class="assessment-course-separator" aria-hidden="true">·</span><small class="muted"><?= h($r['course_title']) ?></small></div>
        <div class="assessment-due" data-label="Due date"><i class="bi bi-calendar3 assessment-meta-icon" aria-hidden="true"></i><span><small class="assessment-meta-label">Due date</small><time><?= $r['due']?h(date('M j, Y · g:i A',strtotime($r['due']))):'Not scheduled' ?></time></span></div>
        <div class="assessment-marks" data-label="Marks"><i class="bi bi-file-earmark-text assessment-meta-icon" aria-hidden="true"></i><span><small class="assessment-meta-label">Marks</small><?= h(assignment_test_format_marks($r['marks'])) ?></span></div>
        <div class="assessment-status" data-label="Status"><span class="assignment-test-status assignment-test-status--<?= ['open'=>'success','closed'=>'danger','draft'=>'muted','scheduled'=>'muted'][$r['status']]??'muted' ?>"><?= h($r['status_label']) ?></span></div>
        <div class="assessment-actions"><a class="assessment-primary" href="<?= h($primary['url']) ?>"><?= h($primary['label']) ?></a>
        <?php if($secondary): ?><details class="assessment-overflow"><summary aria-label="More actions for <?= h($r['title']) ?>"><i class="bi bi-three-dots-vertical" aria-hidden="true"></i></summary><div>
        <?php if($r['owner']&&$r['category']==='assignment'): ?><a href="assignment_test?id=<?= $id ?>">Open details</a><?php endif; ?>
        <?php if($r['owner']&&$r['category']==='assignment'): $action=['draft'=>'publish','published'=>'close','closed'=>'reopen'][$item['status']]; ?>
            <a class="btn tiny secondary" href="assignment_tests?edit=<?= $id ?>#assignment-editor">Edit</a>
            <form method="post" action="assignment_test_action.php" data-assignment-action><input type="hidden" name="csrf" value="<?= h(assignment_test_csrf_token()) ?>"><input type="hidden" name="action" value="<?= $action ?>"><input type="hidden" name="assignment_test_id" value="<?= $id ?>"><button class="btn tiny secondary"><?= ucfirst($action) ?></button></form>
        <?php elseif($r['owner']&&$r['category']==='quiz'&&$item['status']!=='draft'): ?>
            <a class="btn tiny secondary" href="online_quiz_edit?id=<?= $id ?>"><?= oq_query('SELECT id FROM online_quiz_attempts WHERE quiz_id=? LIMIT 1',[$id])->fetchColumn()?'View configuration':'Edit' ?></a>
        <?php endif; ?>
        </div></details><?php endif; ?></div>
        <?php if($r['progress']||$r['context']['note']||$receiptId): ?><div class="assessment-progress"><?php if($r['progress'])echo assignment_test_render_progress($r['progress']); ?><?php if($r['context']['note']): ?><small class="muted assessment-context-note"><?= h($r['context']['note']) ?></small><?php endif; ?><?php if($receiptId): ?><a class="assessment-receipt-link" href="assignment_test_receipt?id=<?= (int)$receiptId ?>">View receipt</a><?php endif; ?></div><?php endif; ?>
    </article>
    <?php endforeach;
    return ['html'=>trim((string)ob_get_clean()),'counts'=>$counts,'courses'=>$courses];
}
