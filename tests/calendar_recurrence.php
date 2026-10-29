<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('session.save_path',sys_get_temp_dir());
require dirname(__DIR__).'/includes/calendar.php';
$checks=0;
function recurcheck($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS: $label\n";}
$event=['id'=>1,'title'=>'Test','is_recurring'=>1,'start_datetime'=>'2026-08-03 09:00:00','end_datetime'=>'2026-08-03 10:30:00','recurrence_end_date'=>'2026-10-30'];
$start=strtotime('2026-09-01 00:00:00');$end=strtotime('2026-09-30 23:59:59');
foreach(['daily'=>30,'weekly'=>4,'biweekly'=>2,'monthly'=>1]as$rule=>$expected){
 $event['recurrence_rule']=$rule;$rows=expand_recurring_events([$event],$start,$end);
 recurcheck(count($rows)===$expected,"$rule occurrence count unchanged");
 recurcheck($rows[0]['_series_start']===$event['start_datetime'],"$rule edits retain original series start");
 recurcheck(strtotime($rows[0]['end_datetime'])-strtotime($rows[0]['start_datetime'])===5400,"$rule retains 90-minute duration");
}
$event['recurrence_rule']='weekly';$event['recurrence_end_date']='2026-09-07';
recurcheck(count(expand_recurring_events([$event],$start,$end))===1,'recurrence end respected');
$event['is_recurring']=0;
recurcheck(expand_recurring_events([$event],$start,$end)[0]===$event,'ordinary events passed through unchanged');
echo "$checks recurrence checks passed. No database writes.\n";
