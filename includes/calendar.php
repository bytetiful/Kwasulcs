<?php
require_once __DIR__ . '/assignment_test.php';

/** Existing calendar course visibility, kept separate from linked coursework ACLs. */
function calendar_courses_for(array $user): array
{
    if ($user['role']==='admin') {
        $s=db()->prepare('SELECT id,code,title FROM courses WHERE department_id=? ORDER BY code');
        $s->execute([$user['department_id']??0]);
    } elseif ($user['role']==='lecturer') {
        $s=db()->prepare('SELECT id,code,title FROM courses WHERE lecturer_id=? ORDER BY code');
        $s->execute([$user['id']]);
    } else {
        $s=db()->prepare('SELECT c.id,c.code,c.title FROM courses c JOIN enrollments e ON e.course_id=c.id WHERE e.student_id=? ORDER BY c.code');
        $s->execute([$user['id']]);
    }
    return $s->fetchAll();
}

/** Shared server-side feed for initial render and refreshing all three views. */
function calendar_events_for_window(array $user, int $start, int $end, int $filterCourseId=0): array
{
    assignment_test_close_overdue();
    $courses=calendar_courses_for($user);
    $ids=array_map('intval',array_column($courses,'id'));
    $where=["(e.scope='global' AND cu.department_id=?)"];
    $params=[$user['department_id']??0];
    if ($ids) {
        if ($filterCourseId>0 && in_array($filterCourseId,$ids,true)) {
            $where[]="(e.scope='course' AND e.course_id=?)"; $params[]=$filterCourseId;
        } else {
            $where[]="(e.scope='course' AND e.course_id IN (".implode(',',array_fill(0,count($ids),'?'))."))";
            $params=array_merge($params,$ids);
        }
    }
    $where[]="(e.scope='personal' AND e.created_by=?)"; $params[]=$user['id'];
    $ordinary=implode(' OR ',$where);
    $linked=assignment_test_calendar_visibility_sql($user);
    $filter=$filterCourseId>0 ? ' AND e.course_id='.(int)$filterCourseId : '';
    $s=db()->prepare("SELECT e.*, c.code AS course_code,c.title AS course_title,cu.full_name AS organizer_name,
        ca.title AS assignment_title,ca.type AS assignment_type,ca.status AS assignment_status,
        ca.total_marks AS assignment_marks,ca.allow_late_submissions,ca.due_at AS assignment_due
        FROM calendar_events e LEFT JOIN courses c ON c.id=e.course_id
        LEFT JOIN users cu ON cu.id=e.created_by LEFT JOIN assignment_tests ca ON ca.id=e.assignment_test_id
        WHERE ((e.assignment_test_id IS NULL AND e.online_quiz_id IS NULL AND ($ordinary)) OR
            (e.assignment_test_id IS NOT NULL AND $linked $filter) OR
            (e.online_quiz_id IS NOT NULL AND EXISTS (SELECT 1 FROM online_quizzes oq JOIN courses qc ON qc.id=oq.course_id
             WHERE oq.id=e.online_quiz_id AND oq.status<>'draft' AND oq.visibility='enrolled'
             AND ((qc.lecturer_id=".(int)$user['id']." AND '".($user['role']==='lecturer'||$user['role']==='admin'?'staff':'learner')."'='staff')
             OR ('".($user['role']==='student'||$user['role']==='admin'?'learner':'staff')."'='learner' AND EXISTS
             (SELECT 1 FROM enrollments qe WHERE qe.course_id=oq.course_id AND qe.student_id=".(int)$user['id']." AND qe.status='active')))) $filter))
        AND ((e.is_recurring=0 AND e.start_datetime<=? AND e.end_datetime>=?)
            OR (e.is_recurring=1 AND e.start_datetime<=? AND (e.recurrence_end_date IS NULL OR e.recurrence_end_date>=?)))
        ORDER BY e.start_datetime,e.id");
    $from=date('Y-m-d H:i:s',$start); $until=date('Y-m-d H:i:s',$end);
    $s->execute(array_merge($params,[$until,$from,$until,date('Y-m-d',$start)]));
    $events=expand_recurring_events($s->fetchAll(),$start,$end);
    foreach ($events as &$ev) {
        $ev['_can_manage']=empty($ev['assignment_test_id']) && empty($ev['online_quiz_id']) &&
            ((int)$ev['created_by']===(int)$user['id'] || cal_user_can_manage_event($ev,$user));
        if (!empty($ev['assignment_test_id'])) {
            $ev['title']=$ev['assignment_title'];
            $ev['event_type']='assignment_test';
            $ev['assignment_status_label']=assignment_test_status_info([
                'status'=>$ev['assignment_status'],'due_at'=>$ev['assignment_due'],
                'allow_late_submissions'=>$ev['allow_late_submissions']])['label'];
            $ev['action_url']='assignment_test?id='.(int)$ev['assignment_test_id'];
        }
        if(!empty($ev['online_quiz_id']))$ev['action_url']='online_quiz?id='.(int)$ev['online_quiz_id'];
        $ev['_key']=$ev['id'].'@'.$ev['start_datetime'];
    }
    unset($ev);
    return $events;
}

/** Rendering and worker use the same recurrence arithmetic and monthly edge policy. */
function expand_recurring_events(array $events, int $monthStart, int $monthEnd): array
{
    require_once __DIR__.'/reminders.php';
    $expanded=[];
    foreach($events as $event) {
        if(empty($event['is_recurring']) || empty($event['recurrence_rule'])) { $expanded[]=$event; continue; }
        foreach(reminder_occurrences($event,$monthStart,$monthEnd) as $occ) {
            $item=$event;
            $item['_series_start']=$event['start_datetime']; $item['_series_end']=$event['end_datetime'];
            $item['start_datetime']=reminder_sql_time($occ['start']); $item['end_datetime']=reminder_sql_time($occ['end']);
            $item['_is_occurrence']=true; $expanded[]=$item;
        }
    }
    usort($expanded,fn($a,$b)=>strcmp($a['start_datetime'],$b['start_datetime']));
    return $expanded;
}
