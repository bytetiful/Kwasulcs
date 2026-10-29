<?php
require_once __DIR__ . '/config.php';
require_login();

$user = current_user();
$role = $user['role'];

$courses  = courses_for_user($user);
// Registration is now checked for students AND admins — a department
// admin can SEE every course in their department (courses_for_user()
// above), but that visibility must not imply registration; only an
// actual active enrollment does. Lecturers are left as-is (always shown
// registered): course.lecturer_id ownership is a different, pre-existing
// relationship, not a registration formality, and out of scope here.
$enrolled = in_array($role, ['student', 'admin'], true) ? enrolled_course_ids((int) $user['id']) : [];

// Lightweight — id/code/title/level only, no content/stats. See
// past_courses_for_user() in config.php. Admin is included — an admin
// account IS a student academically (department + level + current/past
// courses), with administrative authority layered on top; that authority
// lives entirely in admin.php's own department-wide, all-levels query and
// is untouched by this.
$pastCourses = in_array($role, ['student', 'admin'], true) ? past_courses_for_user($user) : [];

$courseIds = array_map(fn($c) => (int) $c['id'], $courses);
$patterns  = course_pattern_map($courses);
$stats     = course_content_stats($courseIds);
$quizAvgs  = course_quiz_averages($courseIds, $user);

// Category = the course code's letter prefix (e.g. "CSC 402" -> "CSC") —
// there's no dedicated category/department column, so this is the one real,
// derived grouping available rather than a fabricated field.
foreach ($courses as &$c) {
    $c['category'] = preg_match('/^([A-Za-z]+)/', $c['code'], $m) ? strtoupper($m[1]) : 'General';
}
unset($c);
$categoryCounts = array_count_values(array_column($courses, 'category'));
ksort($categoryCounts, SORT_NATURAL | SORT_FLAG_CASE);
require_once __DIR__ . '/includes/pwa.php';
pwa_mark_cacheable_page((int)$user['id']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/favicon/apple-touch-icon.png">
    <?php $pageTitle = 'Courses';
    include __DIR__ . '/partials/head.php'; ?>
</head>

<body class="app-body">
    <?php include __DIR__ . '/partials/nav.php'; ?>
    <section class="app-hero">
        <?php include __DIR__ . '/partials/appheader.php'; ?>

        <header class="topbar">
            <div>
                <div class="eyebrow"><?php echo brand_logo(); ?> KWASU LCS</div>
                <h1><i class="bi bi-journal-bookmark-fill icon"></i> Courses</h1>
                <p class="muted">
                    <?php if (in_array($role, ['student', 'admin'], true)): ?>
                        All courses — click one to register and access its content.
                    <?php else: ?>
                        Courses assigned to you.
                    <?php endif; ?>
                </p>
            </div>
            <div class="topbar-actions">
                <a class="btn glass btn-go-dashboard" href="dashboard"><i class="bi bi-grid-1x2-fill"></i> Go to Dashboard</a>
            </div>
        </header>
    </section>

    <main class="page">
        <div class="course-toolbar">
            <div class="course-search-input">
                <i class="bi bi-search"></i>
                <input type="search" id="courseSearchInput" placeholder="Search courses…" aria-label="Search courses">
            </div>
            <details class="course-filter-disclosure" id="courseFilters">
                <summary><span><i class="bi bi-funnel" aria-hidden="true"></i> Categories &amp; Filters</span><span class="course-filter-state" id="courseFilterState">All</span><i class="bi bi-chevron-down course-filter-chevron" aria-hidden="true"></i></summary>
                <div class="course-filter-options">
                    <div class="course-filter-field">
                        <label for="courseCategoryFilter">Category</label>
                        <div class="course-select-wrap">
                            <select id="courseCategoryFilter" class="course-select">
                                <option value="">All Categories</option>
                                <?php foreach ($categoryCounts as $cat => $count): ?>
                                    <option value="<?php echo h($cat); ?>"><?php echo h($cat); ?> (<?php echo (int)$count; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <i class="bi bi-chevron-down" aria-hidden="true"></i>
                        </div>
                    </div>
                    <div class="course-filter-field">
                        <label for="courseSemesterFilter">Semester</label>
                        <div class="course-select-wrap">
                            <select id="courseSemesterFilter" class="course-select">
                                <option value="">All Semesters</option>
                                <option value="rain">Rain Semester</option>
                                <option value="harmattan">Harmattan Semester</option>
                            </select>
                            <i class="bi bi-chevron-down" aria-hidden="true"></i>
                        </div>
                    </div>
                    <div class="course-filter-field">
                        <label for="courseStatusFilter">Status</label>
                        <div class="course-select-wrap">
                            <select id="courseStatusFilter" class="course-select">
                                <option value="">All Status</option>
                                <option value="registered">Registered</option>
                                <option value="open">Open</option>
                            </select>
                            <i class="bi bi-chevron-down" aria-hidden="true"></i>
                        </div>
                    </div>
                    <div class="course-filter-field">
                        <span class="course-filter-label">Sort by</span>
                        <input type="hidden" id="courseSortSelect" value="recent">
                        <details class="course-sort-menu" id="courseSortMenu">
                            <summary><span id="courseSortLabel">Recently Added</span><i class="bi bi-chevron-down" aria-hidden="true"></i></summary>
                            <div class="course-sort-options">
                                <button type="button" data-sort="recent" aria-current="true">Recently Added</button>
                                <button type="button" data-sort="code">Course Code</button>
                                <button type="button" data-sort="title">Course Title</button>
                            </div>
                        </details>
                    </div>
                </div>
            </details>
        </div>

        <?php if (in_array($role, ['student', 'admin'], true) && $pastCourses): ?>
            <!-- ── Past Courses ────────────────────────────────────────────────────────
                 Lightweight: id/code/title/level only (past_courses_for_user() in
                 config.php) — no documents/videos/topics/quiz stats loaded here.
                 Clicking a course opens its normal course page on demand; nothing
                 past-course-related is fetched until then. -->
            <div class="panel" style="margin-bottom:20px;padding:16px 20px;">
                <button type="button" id="pastCoursesToggle" style="display:flex;align-items:center;justify-content:space-between;width:100%;background:none;border:none;cursor:pointer;padding:0;font:inherit;color:inherit;" aria-expanded="false" aria-controls="pastCoursesPanel">
                    <span style="display:flex;align-items:center;gap:8px;font-weight:600;">
                        <i class="bi bi-clock-history"></i> Past Courses
                        <span class="muted" style="font-weight:400;font-size:13px;">(<?php echo array_sum(array_map('count', $pastCourses)); ?>)</span>
                    </span>
                    <i class="bi bi-chevron-down" id="pastCoursesChevron"></i>
                </button>
                <div id="pastCoursesPanel" hidden style="margin-top:16px;">
                    <?php foreach ($pastCourses as $level => $levelCourses): ?>
                        <div style="margin-bottom:14px;">
                            <div class="muted" style="font-size:13px;font-weight:600;margin-bottom:8px;"><?php echo (int) $level; ?> Level</div>
                            <div style="display:flex;flex-wrap:wrap;gap:8px;">
                                <?php foreach ($levelCourses as $pc): ?>
                                    <a class="btn tiny secondary" href="course?id=<?php echo (int) $pc['id']; ?>" title="<?php echo h($pc['title']); ?>">
                                        <?php echo h($pc['code']); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <script>
                (function() {
                    var btn = document.getElementById('pastCoursesToggle');
                    var panel = document.getElementById('pastCoursesPanel');
                    var chevron = document.getElementById('pastCoursesChevron');
                    btn.addEventListener('click', function() {
                        var isHidden = panel.hidden;
                        panel.hidden = !isHidden;
                        btn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
                        chevron.style.transform = isHidden ? 'rotate(180deg)' : '';
                    });
                })();
            </script>
        <?php endif; ?>

        <div class="course-rows" id="courseRows">
            <?php if (!$courses): ?>
                <p class="muted" style="padding:16px 0;">No courses yet.</p>
            <?php endif; ?>
            <?php foreach ($courses as $course):
                $courseId    = (int) $course['id'];
                $isRegistered = in_array($role, ['student', 'admin'], true) ? course_status($course, $enrolled) === 'Registered' : true;
                $lecturerColor = user_color((int) $course['lecturer_id']);
                $lecturerAvatar = avatar_inner_html($course['lecturer_name'], $course['lecturer_picture'] ?? null);
                $s = $stats[$courseId];
                $quizAvg = $quizAvgs[$courseId] ?? null;
                $createdTs = strtotime($course['created_at'] ?? 'now');
            ?>
                <div class="course-row"
                    data-href="course?id=<?php echo $courseId; ?>"
                    data-pattern="<?php echo $patterns[$courseId] ?? 1; ?>"
                    data-search="<?php echo h(strtolower($course['code'] . ' ' . $course['title'] . ' ' . $course['lecturer_name'])); ?>"
                    data-category="<?php echo h($course['category']); ?>"
                    data-semester="<?php echo h($course['semester']); ?>"
                    data-status="<?php echo $isRegistered ? 'registered' : 'open'; ?>"
                    data-code="<?php echo h($course['code']); ?>"
                    data-title="<?php echo h($course['title']); ?>"
                    data-created="<?php echo (int) $createdTs; ?>"
                    style="--course-color:<?php echo h($course['color'] ?: '#2563eb'); ?>;">
                    <div class="course-row-accent" aria-hidden="true"></div>
                    <div class="course-row-body">
                        <div class="course-row-top">
                            <span class="course-row-code"><?php echo h($course['code']); ?></span>
                            <div class="course-row-top-actions">
                                <?php if ($isRegistered): ?>
                                    <span class="course-status-pill ok"><i class="bi bi-check-circle-fill"></i> Registered</span>
                                <?php else: ?>
                                    <span class="course-status-pill"><i class="bi bi-unlock-fill"></i> Open</span>
                                <?php endif; ?>
                                <div class="course-row-menu-wrap">
                                    <button type="button" class="course-row-menu-btn" aria-label="More options" aria-haspopup="true" aria-expanded="false">
                                        <i class="bi bi-three-dots-vertical"></i>
                                    </button>
                                    <div class="course-row-menu" hidden>
                                        <a href="course?id=<?php echo $courseId; ?>"><i class="bi bi-box-arrow-in-right"></i> Open Course</a>
                                        <a href="calendar?course=<?php echo $courseId; ?>"><i class="bi bi-calendar3"></i> View Calendar</a>
                                        <a href="chat_group?course_id=<?php echo $courseId; ?>"><i class="bi bi-chat-dots-fill"></i> Message Group</a>
                                        <?php if ($role !== 'student'): ?>
                                            <a href="admin.php?manage=<?php echo $courseId; ?>"><i class="bi bi-sliders"></i> Manage Content</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <h3 class="course-row-title"><?php echo h($course['title']); ?></h3>
                        <div class="course-row-meta">
                            <span class="course-row-lecturer">
                                <span class="course-row-avatar" style="background:<?php echo $lecturerColor; ?>;"><?php echo $lecturerAvatar; ?></span>
                                <?php echo h($course['lecturer_name']); ?>
                            </span>
                            <span class="course-row-meta-item"><i class="bi bi-calendar3"></i> <?php echo h(ucfirst($course['semester'])); ?> Semester</span>
                        </div>
                        <div class="course-row-stats">
                            <span><i class="bi bi-file-earmark-text"></i> <?php echo (int) $s['documents']; ?> Documents</span>
                            <span><i class="bi bi-camera-video-fill"></i> <?php echo (int) $s['videos']; ?> Videos</span>
                            <?php if ($quizAvg !== null): ?>
                                <span><i class="bi bi-bullseye"></i> <?php echo $quizAvg; ?>% Average Quiz Performance</span>
                            <?php else: ?>
                                <span class="muted-stat"><i class="bi bi-bullseye"></i> No quiz attempts yet</span>
                            <?php endif; ?>
                        </div>
                        <div class="course-row-footer">
                            <span class="course-row-updated">
                                <?php if ($s['updated_at']): ?>
                                    <i class="bi bi-clock"></i> Updated <?php echo h(content_time_ago($s['updated_at'])); ?>
                                <?php else: ?>
                                    <i class="bi bi-clock"></i> No content yet
                                <?php endif; ?>
                            </span>
                            <a class="course-open-btn" href="course?id=<?php echo $courseId; ?>">Open Course <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <p class="muted course-no-results" id="courseNoResults" hidden>No courses match your filters.</p>
        </div>
    </main>

    <script>
        (function() {
            const rowsWrap = document.getElementById('courseRows');
            const rows = Array.from(rowsWrap.querySelectorAll('.course-row'));
            const searchInput = document.getElementById('courseSearchInput');
            const categoryFilter = document.getElementById('courseCategoryFilter');
            const semesterFilter = document.getElementById('courseSemesterFilter');
            const statusFilter = document.getElementById('courseStatusFilter');
            const sortSelect = document.getElementById('courseSortSelect');
            const sortMenu = document.getElementById('courseSortMenu');
            const sortLabel = document.getElementById('courseSortLabel');
            const filterState = document.getElementById('courseFilterState');
            const noResults = document.getElementById('courseNoResults');

            function updateFilterState() {
                const count = [categoryFilter.value, semesterFilter.value, statusFilter.value].filter(Boolean).length
                    + (sortSelect.value === 'recent' ? 0 : 1);
                filterState.textContent = count ? count + ' active' : 'All';
            }

            function applyFilters() {
                const q = searchInput.value.trim().toLowerCase();
                const cat = categoryFilter.value;
                const sem = semesterFilter.value;
                const status = statusFilter.value;
                let anyVisible = false;
                rows.forEach(function(row) {
                    const match = (!q || row.dataset.search.includes(q)) &&
                        (!cat || row.dataset.category === cat) &&
                        (!sem || row.dataset.semester === sem) &&
                        (!status || row.dataset.status === status);
                    row.hidden = !match;
                    if (match) anyVisible = true;
                });
                noResults.hidden = anyVisible;
                updateFilterState();
            }

            function applySort() {
                const mode = sortSelect.value;
                const sorted = rows.slice().sort(function(a, b) {
                    if (mode === 'code') return a.dataset.code.localeCompare(b.dataset.code);
                    if (mode === 'title') return a.dataset.title.localeCompare(b.dataset.title);
                    return +b.dataset.created - +a.dataset.created;
                });
                sorted.forEach(row => rowsWrap.appendChild(row));
                updateFilterState();
            }

            [searchInput, categoryFilter, semesterFilter, statusFilter].forEach(function(el) {
                el.addEventListener('input', applyFilters);
                el.addEventListener('change', applyFilters);
            });
            sortMenu.querySelectorAll('[data-sort]').forEach(function(button) {
                button.addEventListener('click', function() {
                    sortSelect.value = button.dataset.sort;
                    sortLabel.textContent = button.textContent.trim();
                    sortMenu.querySelectorAll('[data-sort]').forEach(function(option) {
                        if (option === button) option.setAttribute('aria-current', 'true');
                        else option.removeAttribute('aria-current');
                    });
                    sortMenu.open = false;
                    applySort();
                    sortMenu.querySelector('summary').focus();
                });
            });
            updateFilterState();

            // Whole-row click opens the course, except for clicks that
            // originate inside the overflow menu (button or its dropdown).
            rows.forEach(function(row) {
                row.addEventListener('click', function(e) {
                    if (e.target.closest('.course-row-menu-wrap')) return;
                    window.location.href = row.dataset.href;
                });
            });

            // Overflow menu open/close
            document.querySelectorAll('.course-row-menu-btn').forEach(function(btn) {
                const menu = btn.nextElementSibling;
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const wasOpen = !menu.hidden;
                    document.querySelectorAll('.course-row-menu').forEach(m => m.hidden = true);
                    document.querySelectorAll('.course-row-menu-btn').forEach(b => b.setAttribute('aria-expanded', 'false'));
                    menu.hidden = wasOpen;
                    btn.setAttribute('aria-expanded', wasOpen ? 'false' : 'true');
                });
            });
            document.addEventListener('click', function() {
                document.querySelectorAll('.course-row-menu').forEach(m => m.hidden = true);
                document.querySelectorAll('.course-row-menu-btn').forEach(b => b.setAttribute('aria-expanded', 'false'));
            });
        })();
    </script>
</body>

</html>
