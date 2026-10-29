<?php
require_once __DIR__ . '/includes/calendar.php';
require_login();
header('Content-Type: application/json');
header('Cache-Control: private, no-store');
try {
    $date=(string)($_GET['date']??date('Y-m-d'));
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if (!$parsed || $parsed->format('Y-m-d')!==$date) throw new InvalidArgumentException('Invalid date.');
    $start=$parsed->modify('first day of this month')->modify('-7 days')->getTimestamp();
    $end=$parsed->modify('last day of this month')->modify('+15 days')->getTimestamp()-1;
    echo json_encode(['events'=>calendar_events_for_window(current_user(),$start,$end,(int)($_GET['course']??0)),
        'from'=>date('Y-m-d',$start),'until'=>date('Y-m-d',$end)],JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code($e instanceof InvalidArgumentException?400:500);
    error_log('Calendar feed: '.$e->getMessage());
    echo json_encode(['error'=>'Calendar updates are temporarily unavailable.']);
}
