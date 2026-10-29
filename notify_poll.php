<?php
/** Read-only notification retrieval. Never dispatches scheduled jobs. */
require_once __DIR__ . '/config.php';
require_login();
$user=current_user();
header('Content-Type: application/json');
header('Cache-Control: no-store');
$q=db()->prepare('SELECT id,message,is_read,created_at,action_url FROM web_notifications WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 20');
$q->execute([$user['id']]);
echo json_encode(['ok'=>true,'unread'=>notif_unread_count((int)$user['id']),'notifications'=>$q->fetchAll()]);
