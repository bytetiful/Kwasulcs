<?php
declare(strict_types=1);
// Deny HTTP before loading sessions, database configuration, or any processor.
if (PHP_SAPI !== 'cli') { http_response_code(403); header('Content-Type: text/plain'); exit("CLI execution only.\n"); }
ini_set('session.save_path',sys_get_temp_dir());
require_once __DIR__.'/includes/reminders.php';
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
$options=getopt('', ['run','health','import-legacy','local-only','event-id:','assignment-id:','limit:','test-smtp-port:']);
try {
    if(isset($options['local-only']) && (DB_HOST!=='127.0.0.1' || DB_NAME!=='kwasulcs_lcs'))throw new RuntimeException('Local database required.');
    if(isset($options['health'])) {
        echo json_encode(db()->query('SELECT * FROM reminder_worker_health WHERE id=1')->fetch(),JSON_PRETTY_PRINT)."\n"; exit;
    }
    if(isset($options['import-legacy'])) {
        if(!reminder_worker_lock())throw new RuntimeException('Worker busy; retry migration later.');
        try { echo json_encode(['imported_channels'=>reminder_import_legacy()])."\n"; }
        finally { reminder_worker_unlock(); }
        exit;
    }
    if(!isset($options['run'])) { echo "Usage: php notify_cron.php --run [--local-only] | --health | --import-legacy\n"; exit; }
    $eventId=isset($options['event-id'])?(int)$options['event-id']:null;
    $assignmentId=isset($options['assignment-id'])?(int)$options['assignment-id']:null;
    if(($eventId!==null && $eventId<1) || ($assignmentId!==null && $assignmentId<1))throw new RuntimeException('Positive fixture IDs required.');
    $adapter=null;
    if(isset($options['test-smtp-port'])) {
        if(!isset($options['local-only']) || $eventId===null)throw new RuntimeException('Test SMTP requires local-only and an explicit event ID.');
        $port=(int)$options['test-smtp-port'];
        if($port<1024 || $port>65535)throw new RuntimeException('Invalid local SMTP port.');
        $adapter=fn($to,$name,$subject,$body)=>send_mail($to,$name,$subject,$body,['host'=>'127.0.0.1','port'=>$port]);
    }
    // A scoped event run never processes unrelated Assignment/Test records.
    $result=reminder_run_worker((int)($options['limit']??200),$eventId,$adapter,$assignmentId);
    echo json_encode($result,JSON_UNESCAPED_SLASHES)."\n";
    exit(!empty($result['failed'])?2:0);
} catch(Throwable $e) {
    fwrite(STDERR,"Reminder worker: ".$e->getMessage()."\n"); exit(1);
}
