<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/notifications/signed_links.php';

$reference = trim((string)($_GET['ref'] ?? ''));
$payload = lcs_signed_link_resolve_reference($reference);
if ($payload === null) {
    http_response_code(404);
    exit('This link is invalid or has expired.');
}

if (!current_user()) {
    $_SESSION['post_login_destination'] = 'go.php?ref=' . rawurlencode($reference);
    header('Location: index.php', true, 303);
    exit;
}

require_login();
$user = current_user();
$destination = $user ? lcs_signed_link_authorized_destination($payload, $user) : null;
if ($destination === null) {
    http_response_code(404);
    exit('This link is not available for your account.');
}

header('Location: ' . $destination, true, 303);
exit;
