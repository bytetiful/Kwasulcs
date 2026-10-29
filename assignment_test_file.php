<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/assignment_test.php';
require_login();
$user = current_user();
$file = assignment_test_submission_file_for_user((int) ($_GET['id'] ?? 0), $user);
// The scoped resolver already checks ownership/enrollment and finalized-file access.
if (!$file) { http_response_code(403); exit('Forbidden'); }
$absolute = assignment_test_private_file_path($file);
if (!$absolute) { http_response_code(404); exit('File not found'); }
$view = !empty($_GET['view']);
assignment_test_record_audit((int) $file['assignment_test_id'], $view ? 'file_viewed' : 'file_downloaded', (int) $user['id'], (int) $file['submission_id'], ['file_id' => (int) $file['id'], 'version' => (int) $file['version_number']]);
$safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $file['original_filename']);
$inline = ['application/pdf', 'text/plain', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'];
header('Content-Type: ' . (in_array($file['mime_type'], $inline, true) ? $file['mime_type'] : 'application/octet-stream'));
header('Content-Length: ' . filesize($absolute));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Disposition: ' . ($view && in_array($file['mime_type'], $inline, true) ? 'inline' : 'attachment') . '; filename="' . $safeName . '"');
readfile($absolute);
