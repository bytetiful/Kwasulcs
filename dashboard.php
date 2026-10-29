<?php
require_once __DIR__ . '/config.php';
require_login();

$user = current_user();
$role = $user['role'];

// Scheduled reminders are dispatched only by the CLI worker.

$allCourses = courses_for_user($user);

$enrolled = [];
if ($role === 'student') {
    $enrolled = enrolled_course_ids((int) $user['id']);
}

$enrolledCount = count($enrolled);

// Homepage course slider: students see only their registered courses (quick
// access, takes less space); lecturer/admin already see just their relevant
// set from courses_for_user(), so no further filtering needed.
$courses = ($role === 'student')
    ? array_values(array_filter($allCourses, fn($c) => isset($enrolled[(int) $c['id']])))
    : $allCourses;

// Computed against the FULL list (before the student filter above) so a
// course keeps the same pattern/color here and on courses.php regardless of
// which subset actually renders — nth-of-type alone can't guarantee that
// once filtering skips courses ahead of it in the canonical order.
$coursePatterns = course_pattern_map($allCourses);

// ── Unread notification count (bell badge) ────────────────────────────────────
$unreadCount = 0;
try {
    $ns = db()->prepare('SELECT COUNT(*) FROM web_notifications WHERE user_id = ? AND is_read = 0');
    $ns->execute([$user['id']]);
    $unreadCount = (int) $ns->fetchColumn();
} catch (\Throwable $e) { /* table may not exist yet */
}

// ── Upcoming events for this user (next 5) ────────────────────────────────────
$upcomingEvents = upcoming_events_for($user, 5);

$typeColors = [
    'lecture'  => '#2563eb',
    'tutorial' => '#7c3aed',
    'exam'     => '#dc2626',
    'test'     => '#ea580c',
    'personal' => '#059669',
    'general'  => '#0891b2',
];
require_once __DIR__ . '/includes/pwa.php';
pwa_mark_cacheable_page((int)$user['id']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/favicon/apple-touch-icon.png">
    <?php $pageTitle = 'Dashboard';
    $extraCss = ['assets/calendar.css', 'assets/dashboard-header.css'];
    include __DIR__ . '/partials/head.php'; ?>
</head>

<body class="app-body dashboard-page">
    <?php include __DIR__ . '/partials/nav.php'; ?>
    <section class="dashboard-hero" aria-label="Welcome">
        <?php include __DIR__ . '/partials/appheader.php'; ?>
        <div class="dashboard-welcome">
        <div class="dashboard-welcome-copy">
            <h1>Welcome, <?php echo h($user['full_name']); ?></h1>
            <p class="dashboard-role"><?php echo h(ucfirst($role)); ?> portal</p>
            <p class="dashboard-description"><?php echo $role === 'lecturer' ? 'Manage your courses, engage with students, and stay updated all in one place.' : ($role === 'admin' ? 'Manage your learning community and stay updated all in one place.' : 'Explore your courses, connect with your lecturers, and stay on top of your learning.'); ?></p>
        </div>
        </div>
    </section>

    <main class="page">
        <!-- ── Stats row ─────────────────────────────────────────────────────────── 
        <div class="dash-stats">
            <?php if ($role === 'student'): ?>
                <div class="stat-card">
                    <div class="stat-num"><?php echo $enrolledCount; ?></div>
                    <div class="stat-label">Courses Enrolled</div>
                </div>
                <div class="stat-card">
                    <div class="stat-num"><?php echo count($upcomingEvents); ?></div>
                    <div class="stat-label">Upcoming Events</div>
                </div>
                <div class="stat-card <?php echo $unreadCount > 0 ? 'stat-card--alert' : ''; ?>">
                    <div class="stat-num"><?php echo $unreadCount; ?></div>
                    <div class="stat-label">Unread Notifications</div>
                </div>
            <?php elseif ($role === 'lecturer'): ?>
                <div class="stat-card">
                    <div class="stat-num"><?php echo count($courses); ?></div>
                    <div class="stat-label">Your Courses</div>
                </div>
                <div class="stat-card">
                    <div class="stat-num"><?php echo count($upcomingEvents); ?></div>
                    <div class="stat-label">Upcoming Events</div>
                </div>
                <div class="stat-card <?php echo $unreadCount > 0 ? 'stat-card--alert' : ''; ?>">
                    <div class="stat-num"><?php echo $unreadCount; ?></div>
                    <div class="stat-label">Unread Notifications</div>
                </div>
            <?php else: ?>
                <div class="stat-card">
                    <div class="stat-num"><?php echo count($courses); ?></div>
                    <div class="stat-label">Total Courses</div>
                </div>
            <?php endif; ?>
        </div>
            -->

        <div class="dash-layout">

            <!-- ── Left: Courses ──────────────────────────────────────────────────── -->
            <div class="dash-main">
                <div class="panel">
                    <div class="panel-head">
                        <h2>Courses</h2>
                        <p class="muted">
                            <?php if ($role === 'student'): ?>
                                Your running subjects.
                            <?php elseif ($role === 'lecturer'): ?>
                                Courses assigned to you.
                            <?php else: ?>
                                All courses in the system.
                            <?php endif; ?>
                        </p>
                        <a class="btn tiny secondary" href="courses">View all</a>
                    </div>
                    <div class="course-slider-wrap">
                        <?php if (count($courses) > 2): ?>
                            <button type="button" class="slider-arrow slider-arrow--left" onclick="document.getElementById('courseSlider').scrollBy({left:-260,behavior:'smooth'})" aria-label="Scroll left">‹</button>
                        <?php endif; ?>
                        <section class="course-slider" id="courseSlider">
                            <?php foreach ($courses as $course): ?>
                                <a class="course-card" href="course?id=<?php echo (int)$course['id']; ?>" data-pattern="<?php echo $coursePatterns[(int) $course['id']] ?? 1; ?>" style="--course-color:<?php echo h($course['color'] ?: '#2563eb'); ?>;">
                                    <span class="course-code"><?php echo h($course['code']); ?></span>
                                    <h3><?php echo h($course['title']); ?></h3>
                                </a>
                            <?php endforeach; ?>
                            <?php if (!$courses): ?>
                                <p class="muted" style="padding:16px 0;">
                                    <?php echo $role === 'student' ? 'No registered courses yet — ' : 'No courses yet.'; ?>
                                    <?php if ($role === 'student'): ?><a href="courses" style="color:var(--primary-dark);">browse courses →</a><?php endif; ?>
                                </p>
                            <?php endif; ?>
                        </section>
                        <?php if (count($courses) > 2): ?>
                            <button type="button" class="slider-arrow slider-arrow--right" onclick="document.getElementById('courseSlider').scrollBy({left:260,behavior:'smooth'})" aria-label="Scroll right">›</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ── Right: Upcoming events + Notifications ─────────────────────────── -->
            <div class="dash-side">

                <!-- Upcoming events -->
                <div class="panel">
                    <div class="panel-head panel-head--dash">
                        <div class="panel-head-icon">
                            <span class="panel-icon"><i class="bi bi-calendar3"></i></span>
                            <div>
                                <h2>Upcoming Events</h2>
                                <p class="muted panel-subtitle">Don't miss what's coming up.</p>
                            </div>
                        </div>
                        <a class="btn tiny secondary pill-cta" href="calendar">View calendar <i class="bi bi-chevron-right"></i></a>
                    </div>
                    <?php if (!$upcomingEvents): ?>
                        <p class="muted" style="padding:12px 0;font-size:14px;">
                            No upcoming events.
                            <a href="calendar" style="color:var(--primary-dark);">Add one →</a>
                        </p>
                    <?php endif; ?>
                    <?php foreach ($upcomingEvents as $ev):
                        $color = $typeColors[$ev['event_type']] ?? '#64748b';
                    ?>
                        <a class="dash-event-row" href="calendar" style="border-left-color:<?php echo $color; ?>;">
                            <div class="dash-event-date" style="background:<?php echo $color; ?>18;">
                                <span class="udate-day" style="color:<?php echo $color; ?>;"><?php echo date('d', strtotime($ev['start_datetime'])); ?></span>
                                <span class="udate-mon" style="color:<?php echo $color; ?>;"><?php echo date('M', strtotime($ev['start_datetime'])); ?></span>
                            </div>
                            <div class="dash-event-info">
                                <strong><?php echo h($ev['title']); ?></strong>
                                <div class="muted">
                                    <?php echo date('g:i A', strtotime($ev['start_datetime'])); ?>
                                    <?php if ($ev['course_code']): ?>
                                        · <?php echo h($ev['course_code']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="dash-event-type" style="background:<?php echo $color; ?>22;color:<?php echo $color; ?>;">
                                <?php echo ucfirst($ev['event_type']); ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Recent notifications -->
                <?php
                $recentNotifs = [];
                try {
                    $rn = db()->prepare('
                    SELECT wn.*, ce.event_type
                    FROM web_notifications wn
                    LEFT JOIN calendar_events ce ON ce.id = wn.event_id
                    WHERE wn.user_id = ?
                    ORDER BY wn.created_at DESC LIMIT 4
                ');
                    $rn->execute([$user['id']]);
                    $recentNotifs = $rn->fetchAll();
                } catch (\Throwable $e) {
                }
                ?>
                <div class="panel">
                    <div class="panel-head panel-head--dash">
                        <div class="panel-head-icon">
                            <span class="panel-icon"><i class="bi bi-bell-fill"></i></span>
                            <div>
                                <h2>
                                    Notifications
                                    <?php if ($unreadCount > 0): ?>
                                        <span class="notif-badge" style="margin-left:6px;"><?php echo $unreadCount; ?></span>
                                    <?php endif; ?>
                                </h2>
                                <p class="muted panel-subtitle">Stay updated with important activities.</p>
                            </div>
                        </div>
                        <a class="btn tiny secondary pill-cta" href="notifications">View all <i class="bi bi-chevron-right"></i></a>
                    </div>
                    <?php if (!$recentNotifs): ?>
                        <p class="muted" style="padding:12px 0;font-size:14px;">No notifications yet.</p>
                    <?php endif; ?>
                    <?php foreach ($recentNotifs as $n):
                        $color = $typeColors[$n['event_type'] ?? 'general'] ?? '#64748b';
                        $isNew = !$n['is_read'];
                    ?>
                        <div class="dash-notif-row <?php echo $isNew ? 'dash-notif-row--new' : ''; ?>"
                            style="border-left-color:<?php echo $color; ?>;">
                            <span class="dash-notif-icon" style="background:<?php echo $color; ?>18;color:<?php echo $color; ?>;">
                                <i class="bi <?php echo $isNew ? 'bi-bell-fill' : 'bi-bell-slash'; ?>"></i>
                            </span>
                            <div class="dash-event-info">
                                <div style="font-size:13px;<?php echo $isNew ? 'font-weight:600;' : ''; ?>">
                                    <?php echo h($n['message']); ?>
                                </div>
                                <div class="muted" style="font-size:11px;">
                                    <?php
                                    $diff = time() - strtotime($n['created_at']);
                                    if ($diff < 60)        echo 'Just now';
                                    elseif ($diff < 3600)  echo (int)($diff / 60) . ' min ago';
                                    elseif ($diff < 86400) echo (int)($diff / 3600) . ' hr ago';
                                    else                   echo (int)($diff / 86400) . ' day(s) ago';
                                    ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            </div>
        </div>

        <?php if ($role === 'admin'): ?>
            <section class="note">
                Admin can create, edit, delete, and upload everything from the management page.
            </section>
        <?php endif; ?>

    </main>
    <script>
        // Retrieval only: the independent CLI worker owns all scheduled dispatch.
        async function refreshNotificationCount() {
            try {
                const response = await fetch('notify_poll.php', {cache:'no-store'});
                if (!response.ok) return;
                const data = await response.json();
                if (!data.ok) return;
                const link = document.querySelector('.app-header a[href="notifications"]');
                if (link) {
                    let badge = link.querySelector('.notif-badge');
                    if (!badge) { badge = document.createElement('span'); badge.className='notif-badge app-icon-badge'; link.append(badge); }
                    badge.textContent = String(data.unread); badge.hidden = data.unread === 0;
                }
                document.dispatchEvent(new CustomEvent('lcs:notifications', {detail:data}));
            } catch (_) { /* Next retrieval retries; it cannot send anything. */ }
        }
        setInterval(refreshNotificationCount, 60000);
    </script>
</body>

</html>
