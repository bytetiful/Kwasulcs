<?php
require_once __DIR__.'/includes/assessments.php';
require_login();
header('Content-Type: application/json');
header('Cache-Control: private, no-store');
try { echo json_encode(assessment_snapshot(current_user()),JSON_THROW_ON_ERROR); }
catch(Throwable $e){error_log('Assessment polling: '.$e->getMessage());http_response_code(500);echo json_encode(['error'=>'Updates temporarily unavailable.']);}
