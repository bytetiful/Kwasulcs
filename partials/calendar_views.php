<main class="page calendar-workspace" id="calendarWorkspace">
    <?php if ($flashMsg): ?><div class="alert success" role="status"><?php echo h($flashMsg); ?></div><?php endif; ?>
    <?php if ($flashErr): ?><div class="alert error" role="alert"><?php echo h($flashErr); ?></div><?php endif; ?>
    <div class="cv-mobile-brand"><a href="dashboard"><?php echo brand_logo(); ?><strong>KWASU LCS</strong></a><button type="button" data-calendar-today><i class="bi bi-calendar3" aria-hidden="true"></i> Today</button></div>
    <div class="cv-toolbar">
        <div class="cv-navigation">
            <button type="button" class="cv-icon" data-calendar-step="-1" aria-label="Previous period"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
            <button type="button" class="cv-icon" data-calendar-step="1" aria-label="Next period"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
            <button type="button" class="cv-control" id="calendarToday">Today</button>
            <label class="cv-date-label"><span id="calendarHeading"><?php echo h($monthLabel); ?></span><input type="date" id="calendarDate" aria-label="Go to date"></label>
        </div>
        <div class="cv-switch" role="group" aria-label="Calendar view">
            <?php foreach (['day','week','month'] as $view): ?><button type="button" data-calendar-view="<?php echo $view; ?>" aria-pressed="<?php echo $view==='month'?'true':'false'; ?>"><?php echo ucfirst($view); ?></button><?php endforeach; ?>
        </div>
        <button type="button" class="btn primary" id="btnNewEvent"><i class="bi bi-plus-lg" aria-hidden="true"></i> <?php echo $isStaff?'Add Event':'Personal Event'; ?></button>
    </div>
    <div class="cv-mobile-period" aria-label="Calendar period navigation">
        <button type="button" class="cv-icon" data-calendar-step="-1" aria-label="Previous period"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
        <strong id="calendarMobilePeriod"></strong>
        <button type="button" class="cv-icon" data-calendar-step="1" aria-label="Next period"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
    </div>
    <div class="cv-mobile-strip" id="calendarWeekStrip" aria-label="Choose day"></div>
    <div class="cv-context">
        <h2 class="cv-mobile-heading"><i class="bi bi-calendar3" aria-hidden="true"></i><span><strong id="calendarMobileHeading"></strong><small id="calendarMobileCount"></small></span></h2>
        <div class="cv-context-actions">
            <button type="button" class="btn primary cv-mobile-add" data-calendar-add><i class="bi bi-plus-lg" aria-hidden="true"></i> <?php echo $isStaff?'Add Event':'Personal Event'; ?></button>
            <details class="cv-filters" id="calendarFilters">
                <summary><span class="cv-legend" aria-hidden="true"><?php foreach ($typeColors as $type=>$color): ?><span><i style="background:<?php echo h($color); ?>"></i><?php echo $type==='assignment_test'?'Assignment/Test':ucfirst($type); ?></span><?php endforeach; ?></span><span><i class="bi bi-funnel" aria-hidden="true"></i> Filters</span></summary>
                <div class="cv-filter-options">
                    <?php foreach ($typeColors as $type=>$color): ?><label class="cv-type-filter" style="--filter-color:<?php echo h($color); ?>"><input type="checkbox" data-calendar-type="<?php echo h($type); ?>" checked><span><?php echo $type==='assignment_test'?'Assignment/Test':ucfirst($type); ?></span></label><?php endforeach; ?>
                    <label>Course <select id="calendarCourse"><option value="0">All my courses</option><?php foreach($myCourses as $course): ?><option value="<?php echo (int)$course['id']; ?>" <?php echo (int)$course['id']===$filterCourseId?'selected':''; ?>><?php echo h($course['code']); ?></option><?php endforeach; ?></select></label>
                </div>
            </details>
        </div>
    </div>
    <p class="cv-live" id="calendarLive" role="status" aria-live="polite"></p>
    <div class="cv-layout">
        <section class="cv-main" id="calendarCanvas" aria-label="Calendar"></section>
        <aside class="cv-sidebar">
            <section class="cv-panel cv-details" id="calendarDetails" hidden aria-label="Event details"></section>
            <section class="cv-panel" id="calendarAgenda" aria-label="Selected day agenda"></section>
            <section class="cv-panel cv-mini" id="calendarMini" aria-label="Mini calendar"></section>
            <section class="cv-panel cv-upcoming" id="calendarUpcoming" aria-label="Upcoming events"></section>
            <section class="cv-panel cv-tip"><img src="assets/images/calendar-reminder.png" alt="" class="cv-tip-art"><h3>Stay on track</h3><p>Create events, set reminders, and never miss a class.</p><button type="button" class="btn primary" id="calendarAddSelected">Add Event</button></section>
        </aside>
    </div>
</main>
