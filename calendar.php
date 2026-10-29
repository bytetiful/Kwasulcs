<?php
require_once __DIR__ . '/includes/calendar.php';
require_login();
$user=current_user();
$role=$user['role'];
$isStaff=in_array($role,['admin','lecturer'],true);
$year=max(1970,min(2100,(int)($_GET['y']??date('Y'))));
$month=max(1,min(12,(int)($_GET['m']??date('n'))));
$monthStart=mktime(0,0,0,$month,1,$year);
$monthEnd=mktime(23,59,59,$month,(int)date('t',$monthStart),$year);
$monthLabel=date('F Y',$monthStart);
$realTodayYear=(int)date('Y'); $realTodayMonth=(int)date('n'); $todayFull=date('Y-m-d');
$filterCourseId=(int)($_GET['course']??0);
$myCourses=calendar_courses_for($user);
$allEvents=calendar_events_for_window($user,strtotime('-7 days',$monthStart),strtotime('+14 days',$monthEnd),$filterCourseId);

// Scheduled reminders are dispatched only by the CLI worker.

// ── Prev/Next month links ─────────────────────────────────────────────────────
$prevM = $month === 1  ? 12 : $month - 1;
$prevY = $month === 1  ? $year - 1 : $year;
$nextM = $month === 12 ? 1  : $month + 1;
$nextY = $month === 12 ? $year + 1 : $year;
$courseParam = $filterCourseId ? '&course=' . $filterCourseId : '';

$typeColors = [
    'lecture'  => '#2563eb',
    'tutorial' => '#7c3aed',
    'exam'     => '#dc2626',
    'assignment_test' => '#ea580c',
    'personal' => '#059669',
    'general'  => '#64748b',
];

// ── Flash messages from redirects ─────────────────────────────────────────────
$flashMsg = $_SESSION['cal_flash'] ?? '';
$flashErr = $_SESSION['cal_error'] ?? '';
unset($_SESSION['cal_flash'], $_SESSION['cal_error']);

// ── Upcoming events for the sidebar card ──────────────────────────────────────
// Upcoming agenda uses the same scoped occurrence feed as the main calendar.
require_once __DIR__ . '/includes/pwa.php';
pwa_mark_cacheable_page((int)$user['id']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <link rel="icon" type="image/png" sizes="32x32" href="assets/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="assets/favicon/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="assets/favicon/apple-touch-icon.png">
    <?php $pageTitle = 'Calendar';
    $extraCss = ['assets/calendar.css', 'assets/calendar-views.css'];
    include __DIR__ . '/partials/head.php'; ?>
    <style>
        /* ── Time input ──────────────────────────────────────────────────── */
        .time-input-wrap {
            display: block;
            width: 100%;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 10px 14px;
            font: inherit;
            font-weight: 700;
            color: var(--text);
            transition: border-color .15s, box-shadow .15s;
        }

        .time-input-wrap:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(7, 167, 1, .15);
        }
    </style>
</head>

<body class="app-body calendar-page">
    <?php include __DIR__ . '/partials/nav.php'; ?>

    <?php include __DIR__ . '/partials/calendar_views.php'; ?>

    <!-- ══ CREATE/EDIT MODAL ═════════════════════════════════════════════════════ -->
    <div class="modal-overlay" id="formOverlay" hidden>
        <div class="modal-box modal-box--wide">
            <div class="modal-header">
                <h2 id="formTitle">New Event</h2>
                <button class="modal-close" id="closeForm">✕</button>
            </div>
            <div class="modal-body">
                <form id="eventForm" method="post" action="calendar_save.php" class="form-stack">
                    <input type="hidden" name="event_id" id="fEventId" value="0">
                    <input type="hidden" name="redirect"
                        value="calendar?y=<?php echo $year; ?>&amp;m=<?php echo $month; ?><?php echo $filterCourseId ? '&amp;course=' . $filterCourseId : ''; ?>">

                    <div class="form-grid-cal">
                        <label>Title <span class="req">*</span>
                            <input type="text" name="title" id="fTitle" required placeholder="e.g. CSC401 Lecture">
                        </label>
                        <label>Event Type <span class="req">*</span>
                            <select name="event_type" id="fType" required>
                                <option value="lecture">Lecture</option>
                                <option value="tutorial">Tutorial</option>
                                <option value="exam">Exam</option>
                                <option value="test">Test</option>
                                <option value="general">General</option>
                                <option value="personal">Personal</option>
                            </select>
                        </label>
                        <label>Start date <span class="req">*</span>
                            <input type="date" name="start_date" id="fStartDate" required>
                        </label>
                        <label>Start time <span class="req">*</span>
                            <input type="time" id="fStartTime" class="time-input-wrap" aria-label="Start time" required>
                        </label>
                        <label>End date <span class="req">*</span>
                            <input type="date" name="end_date" id="fEndDate" required>
                        </label>
                        <label>End time <span class="req">*</span>
                            <input type="time" id="fEndTime" class="time-input-wrap" aria-label="End time" required>
                        </label>
                        <!-- Hidden combined fields sent to calendar_save.php -->
                        <input type="hidden" name="start_datetime" id="fStart">
                        <input type="hidden" name="end_datetime" id="fEnd">
                    </div>

                    <label>Description
                        <textarea name="description" id="fDesc" rows="2" placeholder="Optional…"></textarea>
                    </label>
                    <label>Location
                        <input type="text" name="location" id="fLocation" placeholder="e.g. Room 204, Senate Building">
                    </label>

                    <?php if ($isStaff): ?>
                        <div class="form-grid-cal">
                            <label>Visibility <span class="req">*</span>
                                <select name="scope" id="fScope" required>
                                    <option value="personal">Personal only</option>
                                    <option value="course">Course (enrolled students see this)</option>
                                    <?php if ($role === 'admin'): ?>
                                        <option value="global">Global (all users)</option>
                                    <?php endif; ?>
                                </select>
                            </label>
                            <label id="fCourseWrap" style="display:none;">Course <span class="req">*</span>
                                <select name="course_id" id="fCourse">
                                    <option value="">Select course…</option>
                                    <?php foreach ($myCourses as $mc): ?>
                                        <option value="<?php echo (int)$mc['id']; ?>"
                                            <?php echo $filterCourseId === (int)$mc['id'] ? 'selected' : ''; ?>>
                                            <?php echo h($mc['code'] . ' — ' . $mc['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="scope" value="personal">
                    <?php endif; ?>

                    <div class="recurrence-row">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_recurring" id="fRecurring" value="1">
                            <i class="bi bi-arrow-repeat icon"></i> Repeat this event
                        </label>
                    </div>
                    <div id="recurrenceFields" class="form-grid-cal" style="display:none;">
                        <label>Repeat every
                            <select name="recurrence_rule" id="fRule">
                                <option value="daily">Day</option>
                                <option value="weekly" selected>Week</option>
                                <option value="biweekly">2 Weeks</option>
                                <option value="monthly">Month</option>
                            </select>
                        </label>
                        <label>Until (leave blank = no end)
                            <input type="date" name="recurrence_end_date" id="fRecEnd">
                        </label>
                    </div>

                    <div class="notif-row">
                        <span class="notif-label">Remind via</span>
                        <label class="checkbox-label">
                            <input type="checkbox" name="notify_email" id="fEmail" value="1" checked> Email
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="notify_web" id="fWeb" value="1" checked> Notification
                        </label>
                        <select name="remind_minutes_before" id="fRemind">
                            <option value="15">15 min before</option>
                            <option value="30" selected>30 min before</option>
                            <option value="60">1 hour before</option>
                            <option value="120">2 hours before</option>
                            <option value="1440">1 day before</option>
                        </select>
                    </div>

                    <div class="modal-footer" style="padding:0;border:0;margin-top:4px;">
                        <button type="submit" class="btn primary" id="fSubmitBtn">Create Event</button>
                        <button type="button" class="btn secondary" id="cancelForm">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ══ DATA + JS ═════════════════════════════════════════════════════════════ -->
    <script>
        'use strict';

        // ── Data injected from PHP ──────────────────────────────────────────────────
        let ALL_EVENTS = <?php echo json_encode(array_values($allEvents), JSON_HEX_TAG | JSON_HEX_APOS); ?>;
        const TYPE_COLORS = <?php echo json_encode($typeColors); ?>;
        const IS_STAFF = <?php echo $isStaff ? 'true' : 'false'; ?>;
        const USER_ID = <?php echo (int)$user['id']; ?>;
        const FILTER_COURSE = <?php echo $filterCourseId; ?>;
        const TODAY_STR = "<?php echo $todayFull; ?>";
        const VIEW_YEAR = <?php echo $year; ?>;
        const VIEW_MONTH = <?php echo $month; ?>;
        const REAL_TODAY_YEAR = <?php echo $realTodayYear; ?>;
        const REAL_TODAY_MONTH = <?php echo $realTodayMonth; ?>;

        // Reference date for the Week/Day tab views — defaults to today when
        // viewing the current month, otherwise the 1st of the viewed month,
        // and updates whenever a day is clicked (main grid or mini calendar).
        let selectedDate = (VIEW_YEAR === REAL_TODAY_YEAR && VIEW_MONTH === REAL_TODAY_MONTH) ?
            TODAY_STR :
            `${VIEW_YEAR}-${String(VIEW_MONTH).padStart(2, '0')}-01`;

        // ── Time input helpers — global scope so all modal functions can access them ──
        // A native <input type="time"> already gives/takes a zero-padded
        // 24-hour "HH:MM" string directly, so these just read/write it —
        // kept as named helpers (rather than inlined) so every call site
        // that used the old 3-part hour/minute/AM-PM widget didn't need to
        // change too.
        function setTimeInputs(prefix, hhmm) {
            const el = document.getElementById(prefix + 'Time');
            if (el && hhmm) el.value = hhmm;
        }

        function getTimeString(prefix) {
            const el = document.getElementById(prefix + 'Time');
            return (el && el.value) ? el.value : '00:00';
        }

        // ── Modal helpers ──────────────────────────────────────────────────────────
        function closeModals() {
            document.querySelectorAll('.modal-overlay').forEach(el => el.hidden = true);
            // Always clear the event form's hidden event_id when any modal closes.
            // Without this, closing an Edit form via X/outside-click/Escape (instead
            // of Cancel or Submit) left fEventId stuck on the edited event's id —
            // so the NEXT "New Event" click could silently resubmit as an edit of
            // that old event instead of creating a new one.
            resetForm();
        }

        // ── Wire close buttons (safe — runs after DOM is ready) ──────────────────
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('closeForm').addEventListener('click', closeModals);
            document.getElementById('cancelForm').addEventListener('click', closeModals);
            document.getElementById('formOverlay').addEventListener('click', e => { if(e.target.id==='formOverlay') closeModals(); });
            document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModals();});
            document.getElementById('btnNewEvent').addEventListener('click',()=>openNewEvent(selectedDate));
            const scopeEl = document.getElementById('fScope');
            if (scopeEl) {
                scopeEl.addEventListener('change', () => toggleScopeFields(scopeEl.value));
            }

            // ── Recurrence checkbox ───────────────────────────────────────────────
            const recurChk = document.getElementById('fRecurring');
            if (recurChk) {
                recurChk.addEventListener('change', () => toggleRecurrence(recurChk.checked));
            }

            // Default times when form opens fresh
            setTimeInputs('fStart', '08:00');
            setTimeInputs('fEnd', '09:00');

            // ── Sync hidden datetime fields before form submit ─────────────────
            document.getElementById('eventForm').addEventListener('submit', function(e) {
                const sd = document.getElementById('fStartDate').value;
                const ed = document.getElementById('fEndDate').value;
                const st = getTimeString('fStart');
                const et = getTimeString('fEnd');

                if (!sd || !ed) {
                    e.preventDefault();
                    alert('Please fill in start and end dates.');
                    return;
                }

                // Safety net: if the visible form title says "New Event" but
                // fEventId is somehow still set to an old id (stale state from
                // a previously-edited event), force it back to 0 right before
                // submit so we never silently overwrite the wrong event.
                const formTitleText = document.getElementById('formTitle').textContent.trim();
                const fEventIdEl = document.getElementById('fEventId');
                if (formTitleText === 'New Event' && fEventIdEl.value !== '0') {
                    fEventIdEl.value = '0';
                }

                document.getElementById('fStart').value = sd + ' ' + st + ':00';
                document.getElementById('fEnd').value = ed + ' ' + et + ':00';
            });

            // ── Auto-advance end 1h after start changes ────────────────────────
            function syncEndFromStart() {
                const sd = document.getElementById('fStartDate').value;
                if (!sd) return;
                document.getElementById('fEndDate').value = sd;
                const [sh, sm] = (document.getElementById('fStartTime').value || '08:00').split(':').map(Number);
                const endH = (sh + 1) % 24;
                document.getElementById('fEndTime').value = String(endH).padStart(2, '0') + ':' + String(sm).padStart(2, '0');
            }
            document.getElementById('fStartDate').addEventListener('change', syncEndFromStart);
            document.getElementById('fStartTime').addEventListener('change', syncEndFromStart);

        });

        // ── Open new-event form ────────────────────────────────────────────────────
        function openNewEvent(dateStr) {
            resetForm();
            document.getElementById('formTitle').textContent = 'New Event';
            document.getElementById('fSubmitBtn').textContent = 'Create Event';

            if (dateStr) {
                document.getElementById('fStartDate').value = dateStr;
                document.getElementById('fEndDate').value = dateStr;
            }
            // Set default times (always reset to 08:00/09:00 for new events)
            setTimeInputs('fStart', '08:00');
            setTimeInputs('fEnd', '09:00');

            if (FILTER_COURSE > 0) {
                const scopeEl = document.getElementById('fScope');
                if (scopeEl) {
                    scopeEl.value = 'course';
                    toggleScopeFields('course');
                }
                const fc = document.getElementById('fCourse');
                if (fc) fc.value = FILTER_COURSE;
            }

            document.getElementById('formOverlay').hidden = false;
            document.getElementById('fTitle').focus();
        }

        // ── Open edit form ────────────────────────────────────────────────────────
        function openEditEvent(id) {
            const ev = ALL_EVENTS.find(e => e._key === String(id) || Number(e.id) === id);
            if (!ev || !ev._can_manage || ev.assignment_test_id) return;
            closeModals();

            document.getElementById('formTitle').textContent = 'Edit Event';
            document.getElementById('fSubmitBtn').textContent = 'Save Changes';
            document.getElementById('fEventId').value = ev.id;
            document.getElementById('fTitle').value = ev.title;
            document.getElementById('fType').value = ev.event_type;
            document.getElementById('fDesc').value = ev.description || '';
            document.getElementById('fLocation').value = ev.location || '';
            // Populate date pickers
            const startParts = (ev._series_start || ev.start_datetime).slice(0, 16).split(/[T ]/);
            const endParts = (ev._series_end || ev.end_datetime).slice(0, 16).split(/[T ]/);
            document.getElementById('fStartDate').value = startParts[0];
            document.getElementById('fEndDate').value = endParts[0];
            // Set time inputs to the exact saved time (no rounding)
            setTimeInputs('fStart', startParts[1] || '08:00');
            setTimeInputs('fEnd', endParts[1] || '09:00');
            // Pre-populate hidden fields in case user submits without touching time
            document.getElementById('fStart').value = ev.start_datetime.slice(0, 19).replace('T', ' ');
            document.getElementById('fEnd').value = ev.end_datetime.slice(0, 19).replace('T', ' ');
            document.getElementById('fEmail').checked = Number(ev.notify_email) === 1;
            document.getElementById('fWeb').checked = Number(ev.notify_web) === 1;
            document.getElementById('fRemind').value = ev.remind_minutes_before || 30;

            const scopeEl = document.getElementById('fScope');
            if (scopeEl) {
                scopeEl.value = ev.scope;
                toggleScopeFields(ev.scope);
            }

            const fc = document.getElementById('fCourse');
            if (fc && ev.course_id) fc.value = ev.course_id;

            const recurChk = document.getElementById('fRecurring');
            if (recurChk) {
                recurChk.checked = Number(ev.is_recurring) === 1;
                toggleRecurrence(recurChk.checked);
            }
            const ruleEl = document.getElementById('fRule');
            if (ruleEl && ev.recurrence_rule) ruleEl.value = ev.recurrence_rule;
            const recEndEl = document.getElementById('fRecEnd');
            if (recEndEl && ev.recurrence_end_date) recEndEl.value = ev.recurrence_end_date;

            document.getElementById('formOverlay').hidden = false;
            document.getElementById('fTitle').focus();
        }

        // ── Toggle helpers ─────────────────────────────────────────────────────────
        function toggleScopeFields(scope) {
            const wrap = document.getElementById('fCourseWrap');
            if (wrap) wrap.style.display = scope === 'course' ? '' : 'none';
        }

        function toggleRecurrence(show) {
            document.getElementById('recurrenceFields').style.display = show ? '' : 'none';
        }

        function resetForm() {
            document.getElementById('eventForm').reset();
            document.getElementById('fEventId').value = '0';
            document.getElementById('recurrenceFields').style.display = 'none';
            const wrap = document.getElementById('fCourseWrap');
            if (wrap) wrap.style.display = 'none';
            // Reset time inputs to defaults
            setTimeInputs('fStart', '08:00');
            setTimeInputs('fEnd', '09:00');
            document.getElementById('fStart').value = '';
            document.getElementById('fEnd').value = '';
            const sd = document.getElementById('fStartDate');
            const ed = document.getElementById('fEndDate');
            if (sd) sd.value = '';
            if (ed) ed.value = '';
        }

        // ── Utilities ──────────────────────────────────────────────────────────────
        function ucFirst(str) {
            return str ? str.charAt(0).toUpperCase() + str.slice(1) : '';
        }

        function row(icon, text) {
            return `<div class="detail-row"><span class="detail-icon">${icon}</span><span>${text}</span></div>`;
        }
    </script>
    <script src="assets/calendar-views.js?v=<?php echo (int)filemtime(__DIR__.'/assets/calendar-views.js'); ?>" defer></script>
</body>

</html>
