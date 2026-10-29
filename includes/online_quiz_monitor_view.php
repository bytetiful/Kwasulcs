<?php
/** Owner-only monitor presentation. No lifecycle or grading mutations. */
function oq_monitor_presentation(array $monitor): array
{
    $q=$monitor['quiz'];$state=oq_display_status($q);
    $monitor['display_status']=$state==='Active'?'Open':$state;
    $monitor['updated_at']=date('c');
    $monitor['can_close']=$q['status']!=='closed'&&$state!=='Closed';
    $monitor['can_edit']=count($monitor['attempts'])===0;
    foreach($monitor['attempts'] as &$a){
        $a['display_status']=$a['status']==='writing'?(!empty($a['locked_until'])&&strtotime($a['locked_until'])>time()?'Locked':($a['remaining_seconds']>0?'Writing':'Time ended')):'Submitted';
        $a['last_contact_label']=$a['last_seen_at']?date('j M, g:i A',strtotime($a['last_seen_at'])):'No contact yet';
        $a['score_percent']=$a['score']!==null&&!$a['needs_review']&&$a['status']!=='writing'?round(100*(float)$a['score']/max(1,(float)$a['total_marks']),1):null;
    }unset($a);
    foreach(['results'=>'result_release','answers'=>'answer_release'] as $channel=>$setting){
        $policy=$q['settings'][$setting];
        $released=oq_released($policy,$q,['status'=>'submitted'],$channel);
        $manual=$policy==='manual';
        $label=['manual'=>'Manual','immediate'=>'Immediate','after_submission'=>'After submission','after_close'=>'After closure'][$policy];
        $monitor['release'][$channel]=['policy'=>$label,'released'=>$released,'can_release'=>$manual&&!$released,
            'status'=>$released?($policy==='after_submission'?'Per submission':'Released'):'Not released',
            'copy'=>$channel==='results'?
                ($released?'Finalized, fully marked attempts can show scores.':($manual?'Students cannot see their scores until you release them.':'Scores become available after quiz closure, once marking is complete.')):
                ($released?'Correct answers are available on finalized attempts.':($manual?'Students cannot see correct answers until you release them.':'Correct answers become available after quiz closure.'))];
    }
    return $monitor;
}
