<?php
// CLI-only synthetic presentation fixture. Not an authentication bypass or web endpoint.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/includes/routes.php';
function h($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function brand_logo(){return '<span aria-hidden="true">◈</span>';}
$user=['id'=>0,'role'=>'lecturer'];$_SERVER['PHP_SELF']='calendar.php';
$flashMsg=$flashErr='';$monthLabel='September 2026';$isStaff=true;$filterCourseId=0;
$myCourses=[['id'=>8,'code'=>'CSC 402']];
$typeColors=['lecture'=>'#2563eb','tutorial'=>'#7c3aed','exam'=>'#dc2626','assignment_test'=>'#ea580c','personal'=>'#059669','general'=>'#64748b'];
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/vendor/bootstrap-icons/bootstrap-icons.css"><link rel="stylesheet" href="/assets/style.css"><link rel="stylesheet" href="/assets/calendar.css"><link rel="stylesheet" href="/assets/calendar-views.css"></head><body class="app-body calendar-page">
<?php include dirname(__DIR__).'/partials/nav.php';include dirname(__DIR__).'/partials/calendar_views.php';?>
<div id="formOverlay" hidden></div>
<script>
let ALL_EVENTS=window.fixtureEvents;
const TYPE_COLORS=<?php echo json_encode($typeColors);?>;
const FILTER_COURSE=0,TODAY_STR='2026-09-04';let selectedDate=TODAY_STR;
function ucFirst(str){return str.charAt(0).toUpperCase()+str.slice(1);}
function openNewEvent(){}function openEditEvent(){}
</script><script src="/assets/calendar-views.js" defer></script></body></html>
