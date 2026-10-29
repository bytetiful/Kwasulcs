<?php
require_once dirname(__DIR__) . '/includes/routes.php';
// Shared green header controls. Inside .app-hero these share the page banner's
// background; elsewhere this is a compact standalone header. Calendar omits it.
// Theme preference applies everywhere, but can only be changed on Home.
$__headerNotifCount = notif_unread_count((int) $user['id']);
$__headerAvatar      = avatar_inner_html($user['full_name'], $user['google_picture'] ?? null);
$__headerShowTheme = lcs_current_page() === 'dashboard';
?>
<header class="app-header">
    <div class="app-header-actions">
        <a class="app-icon-btn" href="notifications" title="Notifications">
            <i class="bi bi-bell-fill"></i>
            <?php if ($__headerNotifCount > 0): ?>
                <span class="notif-badge app-icon-badge"><?php echo $__headerNotifCount; ?></span>
            <?php endif; ?>
        </a>
        <a class="app-icon-btn" href="chat" title="Messages">
            <i class="bi bi-chat-dots-fill"></i>
        </a>
        <?php if ($__headerShowTheme): ?><button class="theme-toggle app-header-theme-toggle" role="switch" onclick="toggleTheme()" title="Dark mode"></button><?php endif; ?>

        <div class="app-profile" id="appProfile">
            <button type="button" class="app-profile-btn" id="appProfileBtn" aria-haspopup="true" aria-expanded="false">
                <span class="app-avatar"><?php echo $__headerAvatar; ?></span>
                <span class="app-profile-name"><?php echo h($user['full_name']); ?></span>
                <i class="bi bi-chevron-down"></i>
            </button>
            <div class="app-profile-menu" id="appProfileMenu" hidden>
                <a href="profile"><i class="bi bi-person-circle"></i> Profile</a>
                <a href="settings"><i class="bi bi-gear-fill"></i> Settings</a>
                <?php if ($user['role'] !== 'student'): ?>
                    <a href="admin.php"><i class="bi bi-sliders"></i> Content Management</a>
                <?php endif; ?>
                <?php if ($__headerShowTheme): ?><button type="button" class="app-profile-menu-theme" onclick="toggleTheme()">
                    <span class="app-profile-menu-theme-label"><i class="bi bi-moon-stars"></i> Dark mode</span>
                    <span class="theme-toggle app-profile-menu-toggle" role="switch"></span>
                </button><?php endif; ?>
                <div class="app-profile-menu-divider"></div>
                <a href="logout.php" class="app-profile-menu-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        </div>
    </div>
</header>

<script>
    (function () {
        // ── Profile dropdown ────────────────────────────────────────────────
        var btn = document.getElementById('appProfileBtn');
        var menu = document.getElementById('appProfileMenu');
        if (btn && menu) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var wasOpen = !menu.hidden;
                menu.hidden = wasOpen;
                btn.setAttribute('aria-expanded', wasOpen ? 'false' : 'true');
            });
            document.addEventListener('click', function (e) {
                if (!menu.hidden && !menu.contains(e.target) && e.target !== btn) {
                    menu.hidden = true;
                    btn.setAttribute('aria-expanded', 'false');
                }
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    menu.hidden = true;
                    btn.setAttribute('aria-expanded', 'false');
                }
            });
        }

    })();
</script>
