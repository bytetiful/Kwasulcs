<?php
require_once __DIR__.'/includes/online_quiz.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
if(!current_user()){http_response_code(401);echo oq_json(['error'=>'Please sign in again.']);exit;}
$user=current_user();
try {
    if($_SERVER['REQUEST_METHOD']!=='POST'||!assignment_test_verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN']??null)){
        http_response_code(403);echo oq_json(['error'=>'Invalid request token.']);exit;
    }
    if((int)($_SERVER['CONTENT_LENGTH']??0)>2000000)throw new RuntimeException('Request is too large.');
    $data=json_decode(file_get_contents('php://input'),true,64,JSON_THROW_ON_ERROR);
    $action=$data['action']??'';$id=(int)($data['id']??0);
    $result=match($action){
        'save'=>oq_save($id,$data['quiz']??[],$user),
        'publish'=>oq_publish($id,$user),
        'start'=>oq_start($id,$user,(bool)($data['ack']??false)),
        'state'=>oq_state($id,$user),
        'answers'=>oq_save_answers($id,$user,$data['answers']??[],isset($data['next'])?(int)$data['next']:null),
        'submit'=>oq_submit($id,$user),
        'integrity'=>oq_integrity($id,$user,(string)($data['type']??''),(string)($data['request_key']??'')),
        'monitor'=>oq_monitor($id,$user),
        'close','release_results','release_answers'=>oq_manage($id,$user,$action),
        'review'=>oq_review($id,$user,$data['marks']??[],(string)($data['note']??'')),
        default=>throw new RuntimeException('Unknown quiz action.')
    };
    echo oq_json(['ok'=>true,'data'=>$result]);
}catch(RuntimeException $e){http_response_code(422);echo oq_json(['error'=>$e->getMessage()]);}
catch(Throwable $e){error_log('Online quiz request: '.$e->getMessage());http_response_code(500);echo oq_json(['error'=>'Unable to complete this request. Please retry.']);}
