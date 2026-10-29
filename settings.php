<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/google_oauth.php';
require_once __DIR__ . '/includes/telegram/settings.php';
require_login();

$user = current_user();

$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['telegram_action'])) {
    if (!telegram_settings_verify_csrf(is_string($_POST['telegram_csrf'] ?? null) ? $_POST['telegram_csrf'] : null)) {
        http_response_code(403);
        exit('Invalid request token.');
    }
    try {
        $result = telegram_settings_handle_action(
            (string)($_SERVER['REQUEST_METHOD'] ?? 'POST'),
            $user,
            is_string($_POST['telegram_csrf'] ?? null) ? $_POST['telegram_csrf'] : null,
            (string)$_POST['telegram_action'],
            $_POST
        );
        $telegramType = (string)$result['type'];
        $telegramMessage = (string)$result['message'];
    } catch (DomainException|InvalidArgumentException $e) {
        $telegramType = 'error';
        $telegramMessage = $e->getMessage();
    } catch (Throwable $e) {
        $telegramType = 'error';
        $telegramMessage = 'We could not update Telegram settings right now. Please try again.';
        error_log('[LCS Telegram Settings] request failed for user ' . (int)$user['id'] . ' (' . get_class($e) . ')');
    }
    $_SESSION['telegram_settings_flash'] = ['type' => $telegramType, 'text' => $telegramMessage];
    header('Location: settings#telegram-notifications');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $emailPref = isset($_POST['pref_email_notifications']) ? 1 : 0;
    $webPref   = isset($_POST['pref_web_notifications']) ? 1 : 0;
    $chatPref  = isset($_POST['pref_chat_notifications']) ? 1 : 0;

    db()->prepare('UPDATE users SET pref_email_notifications = ?, pref_web_notifications = ?, pref_chat_notifications = ? WHERE id = ?')
        ->execute([$emailPref, $webPref, $chatPref, $user['id']]);
    $success = 'Settings saved.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disconnect_google'])) {
    google_oauth_disconnect((int) $user['id']);
    header('Location: settings?google=disconnected');
    exit();
}

// current_user() only selects a fixed column list and caches it, so the
// pref_* and google_* columns are read here directly instead — and re-read
// after a save rather than trusting that cache, so the page reflects the
// update immediately. (Schema already established in production — see the
// note in current_user() in config.php for why the migration call that
// used to run here was removed.)
$prefStmt = db()->prepare('SELECT pref_email_notifications, pref_web_notifications, pref_chat_notifications, email, google_id, google_picture, google_connected_at FROM users WHERE id = ?');
$prefStmt->execute([$user['id']]);
$prefs = $prefStmt->fetch();
$telegramState = telegram_settings_state((int)$user['id']);
$telegramFlash = $_SESSION['telegram_settings_flash'] ?? null;
unset($_SESSION['telegram_settings_flash']);
$telegramCsrf = telegram_settings_csrf_token();
$telegramCategories = telegram_settings_categories();

$googleFlash  = $_GET['google'] ?? '';
$googleReason = $_GET['reason'] ?? '';
$googleMessages = [
    'connected'      => ['type' => 'success', 'text' => 'Google account connected.'],
    'disconnected'   => ['type' => 'success', 'text' => 'Google account disconnected.'],
    'not_configured' => ['type' => 'error', 'text' => 'Google sign-in is not set up yet — contact an administrator.'],
    'error'          => ['type' => 'error', 'text' => "We couldn't connect your Google account. Please try again." . ($googleReason ? ' (' . $googleReason . ')' : '')],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/favicon/apple-touch-icon.png">
    <?php $pageTitle = 'Settings';
    $extraCss = ['assets/telegram-settings.css'];
    include __DIR__ . '/partials/head.php'; ?>
</head>

<body class="app-body settings-page">
    <?php include __DIR__ . '/partials/nav.php'; ?>
    <section class="app-hero">
    <?php include __DIR__ . '/partials/appheader.php'; ?>

    <header class="topbar">
        <div>
            <div class="eyebrow"><?php echo brand_logo(); ?> KWASU LCS</div>
            <h1><i class="bi bi-gear-fill icon"></i> Settings</h1>
            <p class="muted">Notification preferences.</p>
        </div>
        <div class="topbar-actions">
            <a class="btn glass btn-go-dashboard" href="dashboard"><i class="bi bi-grid-1x2-fill"></i> Go to Dashboard</a>
        </div>
    </header>
    </section>

    <main class="page">

        <section class="panel telegram-settings-panel" id="telegram-notifications"
            aria-labelledby="telegram-settings-title"
            data-telegram-state="<?php echo h($telegramState['state']); ?>"
            data-connect-endpoint="telegram_connect.php"
            data-status-endpoint="telegram_connection_status.php"
            data-csrf="<?php echo h($telegramCsrf); ?>">
            <div class="panel-head telegram-settings-heading">
                <div class="telegram-settings-title-row">
                    <span class="telegram-settings-icon" aria-hidden="true"><i class="bi bi-send-fill"></i></span>
                    <div>
                        <h2 id="telegram-settings-title">Telegram notifications</h2>
                        <p class="muted">Receive important KWASU LCS notifications directly on Telegram.</p>
                    </div>
                </div>
                <?php if (in_array($telegramState['state'], ['connected', 'disabled'], true)): ?>
                    <span class="telegram-status-pill <?php echo $telegramState['enabled'] ? 'is-on' : 'is-paused'; ?>">
                        <i class="bi <?php echo $telegramState['enabled'] ? 'bi-check-circle-fill' : 'bi-pause-circle-fill'; ?>" aria-hidden="true"></i>
                        <?php echo $telegramState['enabled'] ? 'Connected' : 'Paused'; ?>
                    </span>
                <?php elseif ($telegramState['state'] === 'blocked'): ?>
                    <span class="telegram-status-pill is-unavailable"><i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i> Unavailable</span>
                <?php endif; ?>
            </div>

            <div class="telegram-live-region" role="status" aria-live="polite" aria-atomic="true" data-telegram-live></div>
            <?php if (is_array($telegramFlash)): ?>
                <div class="alert <?php echo $telegramFlash['type'] === 'success' ? 'success' : 'error'; ?>" role="status"><?php echo h((string)$telegramFlash['text']); ?></div>
            <?php endif; ?>

            <?php if (!$telegramState['service_enabled']): ?>
                <div class="telegram-service-note" role="status"><i class="bi bi-info-circle" aria-hidden="true"></i><span>Telegram connection is not active on this LCS environment yet. Existing web and email notifications are unaffected.</span></div>
            <?php endif; ?>

            <div class="telegram-waiting" data-telegram-waiting hidden>
                <span class="telegram-waiting-icon" aria-hidden="true"><i class="bi bi-hourglass-split"></i></span>
                <div>
                    <strong>Waiting for Telegram connection</strong>
                    <p>Complete the connection in Telegram, then return here. Opening Telegram does not connect your account by itself.</p>
                    <button type="button" class="btn secondary" data-telegram-check><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Check connection</button>
                </div>
            </div>

            <?php if ($telegramState['state'] === 'not_connected'): ?>
                <div class="telegram-empty-state">
                    <div>
                        <strong>Telegram is not connected</strong>
                        <p class="muted">Link securely from this page. LCS never asks for your Telegram password, and you can pause notifications later.</p>
                    </div>
                    <button type="button" class="btn primary" data-telegram-connect <?php echo !$telegramState['service_enabled'] ? 'disabled' : ''; ?>>
                        <i class="bi bi-send" aria-hidden="true"></i> Connect Telegram
                    </button>
                </div>
            <?php elseif ($telegramState['state'] === 'blocked'): ?>
                <div class="telegram-state-card is-unavailable">
                    <span class="telegram-state-icon" aria-hidden="true"><i class="bi bi-exclamation-circle"></i></span>
                    <div class="telegram-state-copy">
                        <strong>Telegram connection unavailable</strong>
                        <p>The bot can no longer reach this account. Reconnect from Telegram to restore access.</p>
                    </div>
                    <div class="telegram-state-actions">
                        <button type="button" class="btn primary" data-telegram-connect <?php echo !$telegramState['service_enabled'] ? 'disabled' : ''; ?>><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Reconnect Telegram</button>
                        <button type="button" class="btn secondary telegram-disconnect-trigger" data-telegram-disconnect-open>Disconnect</button>
                    </div>
                </div>
            <?php elseif ($telegramState['state'] === 'disconnected'): ?>
                <div class="telegram-state-card">
                    <span class="telegram-state-icon" aria-hidden="true"><i class="bi bi-link-45deg"></i></span>
                    <div class="telegram-state-copy">
                        <strong>Telegram was disconnected</strong>
                        <p>Your historical delivery records are preserved. Reconnecting creates a new one-time link.</p>
                    </div>
                    <button type="button" class="btn primary" data-telegram-connect <?php echo !$telegramState['service_enabled'] ? 'disabled' : ''; ?>><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Reconnect Telegram</button>
                </div>
            <?php else: ?>
                <div class="telegram-connected-bar">
                    <div class="telegram-identity">
                        <span class="telegram-avatar" aria-hidden="true"><i class="bi bi-person-fill"></i></span>
                        <div>
                            <span class="muted">Connected Telegram account</span>
                            <strong><?php echo h((string)($telegramState['display_name'] ?: 'Telegram user')); ?></strong>
                            <?php if ($telegramState['username']): ?><span class="telegram-username">@<?php echo h(ltrim((string)$telegramState['username'], '@')); ?></span><?php endif; ?>
                        </div>
                    </div>
                    <form method="post">
                        <input type="hidden" name="telegram_csrf" value="<?php echo h($telegramCsrf); ?>">
                        <input type="hidden" name="telegram_action" value="set_global">
                        <input type="hidden" name="enabled" value="<?php echo $telegramState['enabled'] ? '0' : '1'; ?>">
                        <button class="telegram-switch-button" type="submit" role="switch" aria-checked="<?php echo $telegramState['enabled'] ? 'true' : 'false'; ?>">
                            <span class="telegram-switch-track" aria-hidden="true"><span></span></span>
                            <span><?php echo $telegramState['enabled'] ? 'Notifications on' : 'Notifications off'; ?></span>
                        </button>
                    </form>
                </div>

                <?php if (!$telegramState['enabled']): ?>
                    <div class="telegram-paused-note" role="status"><i class="bi bi-pause-circle" aria-hidden="true"></i><span>Telegram delivery is paused globally. Your connection and category choices are preserved.</span></div>
                <?php endif; ?>

                <fieldset class="telegram-category-list">
                    <legend>Notification categories</legend>
                    <?php foreach ($telegramCategories as $category => $definition):
                        $categoryOn = (bool)($telegramState['preferences'][$category] ?? true); ?>
                        <div class="telegram-category-row">
                            <span class="telegram-category-icon" aria-hidden="true"><i class="bi <?php echo h($definition['icon']); ?>"></i></span>
                            <div class="telegram-category-copy">
                                <strong><?php echo h($definition['label']); ?></strong>
                                <span class="muted"><?php echo h($definition['description']); ?></span>
                            </div>
                            <form method="post">
                                <input type="hidden" name="telegram_csrf" value="<?php echo h($telegramCsrf); ?>">
                                <input type="hidden" name="telegram_action" value="set_category">
                                <input type="hidden" name="category" value="<?php echo h($category); ?>">
                                <input type="hidden" name="enabled" value="<?php echo $categoryOn ? '0' : '1'; ?>">
                                <button class="telegram-mini-switch" type="submit" role="switch" aria-checked="<?php echo $categoryOn ? 'true' : 'false'; ?>" aria-label="<?php echo h(($categoryOn ? 'Pause ' : 'Enable ') . $definition['label']); ?>"><span aria-hidden="true"></span></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </fieldset>

                <div class="telegram-management-actions">
                    <?php if ($telegramState['open_url']): ?>
                        <a class="btn primary" href="<?php echo h($telegramState['open_url']); ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Open Telegram</a>
                    <?php endif; ?>
                    <button type="button" class="btn secondary telegram-disconnect-trigger" data-telegram-disconnect-open><i class="bi bi-x-circle" aria-hidden="true"></i> Disconnect</button>
                </div>
            <?php endif; ?>

            <p class="telegram-privacy-note"><i class="bi bi-shield-check" aria-hidden="true"></i><span>Telegram is optional. LCS shares only the information needed for selected notifications. Web and email behavior remains unchanged.</span></p>
        </section>

        <?php if (in_array($telegramState['state'], ['connected', 'disabled', 'blocked'], true)): ?>
            <dialog class="telegram-disconnect-dialog" id="telegramDisconnectDialog" aria-labelledby="telegramDisconnectTitle" aria-describedby="telegramDisconnectDescription">
                <form method="post">
                    <input type="hidden" name="telegram_csrf" value="<?php echo h($telegramCsrf); ?>">
                    <input type="hidden" name="telegram_action" value="disconnect">
                    <input type="hidden" name="disconnect_confirm" value="1">
                    <span class="telegram-dialog-icon" aria-hidden="true"><i class="bi bi-exclamation-triangle"></i></span>
                    <h2 id="telegramDisconnectTitle">Disconnect Telegram?</h2>
                    <p id="telegramDisconnectDescription">This revokes the connection and cancels pending Telegram deliveries. Historical delivery records remain available, and web or email notifications are not affected.</p>
                    <div class="telegram-dialog-actions">
                        <button type="button" class="btn secondary" data-telegram-disconnect-close>Keep connected</button>
                        <button type="submit" class="btn danger">Disconnect Telegram</button>
                    </div>
                </form>
            </dialog>
        <?php endif; ?>
        <section class="panel pwa-settings-panel" id="app-on-this-device" aria-labelledby="pwa-settings-title">
            <div class="panel-head">
                <div>
                    <h2 id="pwa-settings-title">LCS on this device</h2>
                    <p class="muted">Optional device features. Your normal browser access remains unchanged.</p>
                </div>
            </div>
            <div class="pwa-capability-grid">
                <article class="pwa-capability-card">
                    <h3><i class="bi bi-phone" aria-hidden="true"></i> Install LCS</h3>
                    <p data-pwa-install-support>Checking installation support…</p>
                    <button type="button" class="btn primary" data-pwa-install hidden><i class="bi bi-download" aria-hidden="true"></i> Install app</button>
                </article>
                <article class="pwa-capability-card">
                    <h3><i class="bi bi-bell" aria-hidden="true"></i> Browser notifications</h3>
                    <p>Permission is requested only when you choose to enable it. Web, email and Telegram preferences are unaffected.</p>
                    <button type="button" class="btn secondary" data-pwa-push><i class="bi bi-bell" aria-hidden="true"></i> Enable on this device</button>
                </article>
            </div>
        </section>
        <div class="panel">
            <div class="panel-head">
                <h2>Notifications</h2>
            </div>

            <?php if ($success): ?>
                <div class="alert success"><?php echo h($success); ?></div>
            <?php endif; ?>

            <form method="post" class="form-stack">
                <label class="checkbox-label">
                    <input type="checkbox" name="pref_email_notifications" value="1" <?php echo $prefs['pref_email_notifications'] ? 'checked' : ''; ?>>
                    Email reminders for upcoming events
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="pref_web_notifications" value="1" <?php echo $prefs['pref_web_notifications'] ? 'checked' : ''; ?>>
                    Web notifications
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="pref_chat_notifications" value="1" <?php echo $prefs['pref_chat_notifications'] ? 'checked' : ''; ?>>
                    Chat message notifications
                </label>

                <button type="submit" class="btn primary" name="save_settings" value="1" style="margin-top:8px;">Save Settings</button>
            </form>
        </div>

        <?php if (google_oauth_configured()): ?>
            <div class="panel connected-accounts-panel">
                <div class="panel-head">
                    <h2>Connected Accounts</h2>
                </div>

                <?php if ($googleFlash && isset($googleMessages[$googleFlash])): ?>
                    <div class="alert <?php echo h($googleMessages[$googleFlash]['type']); ?>"><?php echo h($googleMessages[$googleFlash]['text']); ?></div>
                <?php endif; ?>

                <?php if (!empty($prefs['google_id'])): ?>
                    <div class="google-connected-row">
                        <?php if (!empty($prefs['google_picture'])): ?>
                            <img src="<?php echo h($prefs['google_picture']); ?>" alt="" class="google-connected-avatar">
                        <?php else: ?>
                            <span class="google-connected-avatar google-connected-avatar--fallback"><i class="bi bi-google"></i></span>
                        <?php endif; ?>
                        <div class="google-connected-info">
                            <strong>Google account connected</strong>
                            <span class="muted"><?php echo h($prefs['email'] ?: 'No email on file'); ?></span>
                            <?php if (!empty($prefs['google_connected_at'])): ?>
                                <span class="muted google-connected-since">Connected <?php echo h(date('M j, Y', strtotime($prefs['google_connected_at']))); ?></span>
                            <?php endif; ?>
                        </div>
                        <form method="post" onsubmit="return confirm('Disconnect your Google account?');">
                            <button type="submit" class="btn secondary" name="disconnect_google" value="1">Disconnect</button>
                        </form>
                    </div>
                <?php else: ?>
                    <p class="muted">Connect your Google account to verify your email address and pull your name and profile photo automatically. This does not change how you sign in — your matric number and password still work as usual.</p>
                    <a class="btn primary" href="google_oauth_start.php"><i class="bi bi-google"></i> Connect Google Account</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <section class="panel" id="legal" aria-labelledby="settings-legal-title">
            <div class="panel-head"><div>
                <h2 id="settings-legal-title">Privacy</h2>
                <p class="muted">Read how KWASU LCS handles your information.</p>
            </div></div>
            <a class="btn secondary" href="privacy-policy"><i class="bi bi-shield-check" aria-hidden="true"></i> Privacy Policy</a>
        </section>
    </main>
    <script src="assets/telegram-settings.js" defer></script>
</body>

</html>
