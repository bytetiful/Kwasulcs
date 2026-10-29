<div class="assessment-toolbar">
    <div class="assessment-tabs" aria-label="Assessment category"><?php foreach(['all'=>'All','assignment'=>'Assignments','quiz'=>'Online quizzes'] as $key=>$label): ?><button type="button" data-assignment-filter="<?= $key ?>" aria-pressed="<?= $key==='all'?'true':'false' ?>"><?= $label ?> (<span data-count="<?= $key ?>"><?= $snapshot['counts'][$key] ?></span>)</button><?php endforeach; ?></div>
    <div class="assessment-filter-row">
    <label class="assessment-search"><span class="assessment-sr">Search assessments</span><input type="search" id="assessment-search" placeholder="Search assessments by title or course…"></label>
    <label>Course<select id="assessment-course"><option value="all">All courses</option><?php foreach($snapshot['courses'] as $cid=>$label): ?><option value="<?= (int)$cid ?>"><?= h($label) ?></option><?php endforeach; ?></select></label>
    <label>Status<select id="assessment-status"><option value="all">All statuses</option><option value="open">Open</option><option value="scheduled">Scheduled</option><option value="draft">Draft</option><option value="closed">Closed</option></select></label>
    </div>
</div>
