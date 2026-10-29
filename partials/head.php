<?php
require_once dirname(__DIR__) . '/includes/routes.php';
// Shared <head> block. Set $pageTitle (and optionally $extraCss = ['assets/x.css', ...])
// before including this file.
$pageTitle = $pageTitle ?? 'KWASU LCS';
$extraCss  = $extraCss ?? [];
$__privacyAcknowledgementAssets = isset($user['id'])
    && isset($_SESSION['user_id'])
    && (int) $user['id'] === (int) $_SESSION['user_id'];
$__pwaAuthenticated = $__privacyAcknowledgementAssets;
require_once dirname(__DIR__) . '/includes/pwa.php';
$__pwaNeedsCsrf = lcs_current_page() === 'settings';
$__pwaConfig = [
    'authenticated' => $__pwaAuthenticated,
    'userKey' => $__pwaAuthenticated ? pwa_user_cache_key((int)$user['id']) : '',
    // The only PWA mutation control currently lives on Settings. Keeping the
    // token off ordinary read pages also keeps it out of offline snapshots.
    'csrf' => $__pwaAuthenticated && $__pwaNeedsCsrf ? pwa_csrf_token() : '',
    'vapidPublicKey' => pwa_vapid_public_key(),
    'unreadCount' => $__pwaAuthenticated ? notif_unread_count((int)$user['id']) : 0,
];
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#078a2e" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#063f22" media="(prefers-color-scheme: dark)">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="KWASU LCS">
<title><?php echo h($pageTitle); ?> — KWASU LCS</title>
<script>(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme:dark)').matches))document.documentElement.setAttribute('data-theme','dark')})();</script>
<link rel="manifest" href="assets/favicon/site.webmanifest">
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon/favicon-32x32.png">
<link rel="apple-touch-icon" sizes="180x180" href="assets/favicon/apple-touch-icon.png">
<link rel="stylesheet" href="assets/vendor/bootstrap-icons/bootstrap-icons.css">
<link rel="stylesheet" href="assets/style.css?v=<?php echo asset_version(); ?>">
<link rel="stylesheet" href="assets/pwa.css?v=<?php echo (int) @filemtime(dirname(__DIR__) . '/assets/pwa.css'); ?>">
<?php foreach ($extraCss as $__css): ?>
<link rel="stylesheet" href="<?php echo h($__css); ?>?v=<?php echo (int) @filemtime(dirname(__DIR__) . '/' . $__css) ?: asset_version(); ?>">
<?php endforeach; ?>
<link rel="stylesheet" href="assets/app-header.css?v=<?php echo (int) @filemtime(dirname(__DIR__) . '/assets/app-header.css'); ?>">
<?php if ($__privacyAcknowledgementAssets): ?>
<link rel="stylesheet" href="assets/privacy-acknowledgement.css?v=<?php echo (int) @filemtime(dirname(__DIR__) . '/assets/privacy-acknowledgement.css'); ?>">
<script src="assets/privacy-acknowledgement.js?v=<?php echo (int) @filemtime(dirname(__DIR__) . '/assets/privacy-acknowledgement.js'); ?>" defer></script>
<?php endif; ?>
<script src="assets/theme.js?v=<?php echo asset_version(); ?>" defer></script>
<script src="assets/status-loader.js?v=<?php echo asset_version(); ?>" defer></script>
<script>window.LCSPWA=<?php echo json_encode($__pwaConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
<script src="assets/pwa.js?v=<?php echo (int) @filemtime(dirname(__DIR__) . '/assets/pwa.js'); ?>" defer></script>
