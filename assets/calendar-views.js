'use strict';

// Schedule values are Lagos wall-clock timestamps, not the browser's timezone.
const CalendarLayout = (() => {
    const parse = value => new Date(value.replace(' ', 'T') + (value.length === 10 ? 'T00:00:00Z' : 'Z'));
    const key = date => date.toISOString().slice(0, 10);
    const add = (value, days) => { const d = parse(value); d.setUTCDate(d.getUTCDate() + days); return key(d); };
    const monday = value => add(value, -((parse(value).getUTCDay() + 6) % 7));
    const allDay = ev => !ev.assignment_test_id && ev.start_datetime.slice(11, 16) === '00:00' &&
        (parse(ev.end_datetime) - parse(ev.start_datetime) >= 86340000);
    const onDay = (ev, date) => {
        const start = parse(ev.start_datetime).getTime(), end = parse(ev.end_datetime).getTime();
        const day = parse(date).getTime();
        return start < day + 86400000 && (end > day || (end === start && start >= day));
    };
    // Connected overlap groups share width; each event takes the first free lane.
    function positioned(events, date) {
        const day = parse(date).getTime();
        const rows = events.filter(ev => onDay(ev, date) && !allDay(ev)).map(ev => ({ev,
            start: Math.max(0, (parse(ev.start_datetime) - day) / 60000),
            end: Math.min(1440, (parse(ev.end_datetime) - day) / 60000)
        })).sort((a, b) => a.start - b.start || b.end - a.end);
        let group = [], ends = [], groupEnd = -1;
        const finish = () => group.forEach(row => { row.lanes = ends.length; });
        rows.forEach(row => {
            if (row.start >= groupEnd) { finish(); group = []; ends = []; groupEnd = -1; }
            let lane = ends.findIndex(end => end <= row.start);
            if (lane < 0) lane = ends.length;
            const occupiedEnd = Math.max(row.end, row.start + 20);
            ends[lane] = occupiedEnd; row.lane = lane; group.push(row); groupEnd = Math.max(groupEnd, occupiedEnd);
        });
        finish(); return rows;
    }
    return {parse, key, add, monday, allDay, onDay, positioned};
})();
if (typeof module !== 'undefined') module.exports = CalendarLayout;

if (typeof document !== 'undefined') {
const CV = {
    view: ['day', 'week', 'month'].includes(new URLSearchParams(location.search).get('view')) ? new URLSearchParams(location.search).get('view') : 'month',
    course: FILTER_COURSE, types: new Set(Object.keys(TYPE_COLORS)), detail: null, request: 0
};
const cvEscape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const cvEl = id => document.getElementById(id);
const cvLabel = date => CalendarLayout.parse(date).toLocaleDateString('en-GB', {weekday:'long',day:'numeric',month:'long',year:'numeric',timeZone:'UTC'});
const cvTime = stamp => CalendarLayout.parse(stamp).toLocaleTimeString('en-US', {hour:'numeric',minute:'2-digit',timeZone:'UTC'});
const cvWeekLabel = start => {
    const end=CalendarLayout.add(start,6),a=CalendarLayout.parse(start),b=CalendarLayout.parse(end);
    if(start.slice(0,7)===end.slice(0,7))return `${Number(start.slice(8))} – ${Number(end.slice(8))} ${a.toLocaleDateString('en-GB',{month:'long',year:'numeric',timeZone:'UTC'})}`;
    return `${a.toLocaleDateString('en-GB',{day:'numeric',month:'short',timeZone:'UTC'})} – ${b.toLocaleDateString('en-GB',{day:'numeric',month:'short',year:'numeric',timeZone:'UTC'})}`;
};
const cvCategory = ev => ev.assignment_test_id || ev.event_type === 'test' ? 'assignment_test' : ev.event_type;
const cvType = ev => cvCategory(ev) === 'assignment_test' ? 'Assignment/Test' : ucFirst(ev.event_type);
const cvEvents = date => ALL_EVENTS.filter(ev => CV.types.has(cvCategory(ev)) && (!date || CalendarLayout.onDay(ev,date)))
    .sort((a,b)=>Number(CalendarLayout.allDay(b))-Number(CalendarLayout.allDay(a)) || a.start_datetime.localeCompare(b.start_datetime));
const cvColor = ev => TYPE_COLORS[cvCategory(ev)] || TYPE_COLORS.general;
function cvButton(ev, compact=false, style='',extraClass='') {
    const time = CalendarLayout.allDay(ev) ? 'All day' : (ev.assignment_test_id ? 'Due ' : '') + cvTime(ev.start_datetime);
    return `<button type="button" class="cv-event ${compact?'cv-event-compact':''} ${ev.start_datetime===ev.end_datetime?'cv-event-instant':''} ${extraClass}" data-event-key="${cvEscape(ev._key)}" data-category="${cvEscape(cvCategory(ev))}" style="--event-color:${cvColor(ev)};${style}" title="${cvEscape(ev.title+' · '+time)}"><span class="cv-event-time">${cvEscape(time)}${!compact&&!CalendarLayout.allDay(ev)&&!ev.assignment_test_id?' – '+cvEscape(cvTime(ev.end_datetime)):''}</span><strong>${cvEscape(ev.title)}</strong>${compact?'':`<span class="cv-event-meta">${cvEscape([ev.course_code,ev.location].filter(Boolean).join(' · '))}</span><span class="cv-event-type">${cvEscape(cvType(ev))}</span>`}</button>`;
}
function cvAgenda(date) {
    const events = cvEvents(date);
    return `<h2>${cvEscape(cvLabel(date))}</h2><p class="muted">${events.length} event${events.length===1?'':'s'}</p><div class="cv-agenda-list">${events.map(ev=>cvButton(ev)).join('') || '<p class="cv-empty">No events scheduled.</p>'}</div>`;
}
// Mobile day/week both use a duration-faithful time grid. Connected overlaps
// are staggered across the available width so every title and time stays
// visible instead of being reduced to a thin, unreadable strip.
function cvMobileTimeline(date) {
    const events=cvEvents(date),allDay=events.filter(CalendarLayout.allDay),deadlines=events.filter(ev=>!CalendarLayout.allDay(ev)&&ev.start_datetime===ev.end_datetime),timed=events.filter(ev=>!deadlines.includes(ev));
    const rows=CalendarLayout.positioned(timed,date);
    const starts=rows.map(row=>Math.floor(row.start/60)),ends=rows.map(row=>Math.ceil(Math.max(row.end,row.start+20)/60));
    let first=8,last=17;
    if(rows.length){const earliest=Math.min(...starts),latest=Math.max(...ends);first=earliest>17?Math.max(0,earliest-1):Math.max(0,Math.min(8,earliest));last=Math.min(24,Math.max(first+4,latest+1));}
    const hourHeight=72,height=Math.max(hourHeight*4,(last-first)*hourHeight);
    let html=allDay.length?`<div class="cv-mobile-all-day"><span>All day</span><div>${allDay.map(ev=>cvButton(ev)).join('')}</div></div>`:'';
    if(deadlines.length)html+=`<div class="cv-mobile-all-day cv-mobile-deadlines"><span>Deadlines</span><div>${deadlines.map(ev=>cvButton(ev)).join('')}</div></div>`;
    if(!events.length)html+='<p class="cv-empty cv-mobile-empty">No events scheduled. Your day is clear.</p>';
    html+=`<div class="cv-mobile-timeline" style="--cv-mobile-height:${height}px;--cv-hour-height:${hourHeight}px"><div class="cv-mobile-scale">`;
    for(let hour=first;hour<=last;hour++)html+=`<span style="top:${(hour-first)*hourHeight}px">${cvEscape(cvTime(date+' '+String(hour).padStart(2,'0')+':00:00'))}</span>`;
    html+='</div><div class="cv-mobile-track">';
    rows.forEach(row=>{
        const duration=Math.max(20,row.end-row.start),blockHeight=Math.max(58,duration/60*hourHeight);
        const top=Math.max(0,Math.min((row.start-first*60)/60*hourHeight,height-blockHeight));
        const width=row.lanes===1?100:row.lanes===2?58:Math.max(42,54-(row.lanes-3)*4);
        const left=row.lanes===1?0:row.lane*(100-width)/(row.lanes-1);
        const condensed=blockHeight<88?'cv-event--condensed':'';
        html+=cvButton(row.ev,false,`top:${top}px;height:${blockHeight}px;left:${left}%;width:${width}%;z-index:${row.lane+1}`,`cv-event--timeline ${condensed}`);
    });
    return html+'</div></div>';
}
function cvMonth(mini=false) {
    const base = selectedDate.slice(0,7)+'-01', start=CalendarLayout.monday(base);
    let html=`<div class="${mini?'cv-mini-grid':'cv-month-grid'}">`;
    ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'].forEach(d=>{html+=`<div class="cv-dow">${mini?d[0]:d}</div>`;});
    for(let n=0;n<42;n++) {
        const date=CalendarLayout.add(start,n), events=cvEvents(date), day=Number(date.slice(8));
        const state=`${date===selectedDate?' is-selected':''}${date===TODAY_STR?' is-today':''}${date.slice(0,7)!==base.slice(0,7)?' is-outside':''}`;
        html+=`<div class="cv-month-day${state}"><button type="button" class="cv-date-select" data-calendar-date="${date}" aria-label="${cvEscape(cvLabel(date)+', '+events.length+' events')}" ${date===TODAY_STR?'aria-current="date"':''}>${day}</button>`;
        if(!mini) html+=`<div class="cv-month-labels">${events.slice(0,2).map(ev=>cvButton(ev,true)).join('')}${events.length>2?`<button class="cv-more" data-calendar-date="${date}">+${events.length-2} more</button>`:''}</div><div class="cv-month-dots" aria-hidden="true">${events.slice(0,4).map(ev=>`<i style="background:${cvColor(ev)}"></i>`).join('')}${events.length>4?'<small>+</small>':''}</div>`;
        html+='</div>';
    }
    return html+'</div>';
}
function cvTimeline(dates) {
    const columns=dates.map(date=>CalendarLayout.positioned(cvEvents(date),date));
    const rows=columns.flat();
    let first=8,last=20;
    rows.forEach(row=>{first=Math.min(first,Math.floor(row.start/60));last=Math.min(24,Math.max(last,Math.ceil(Math.max(row.end,row.start+20)/60)));});
    const hours=last-first,height=hours*64;
    let html=`<div class="cv-time-grid" style="--days:${dates.length};--grid-height:${height}px"><div class="cv-time-head"><span>GMT+1</span>${dates.map(d=>`<button data-calendar-date="${d}" class="${d===TODAY_STR?'is-today':''}">${CalendarLayout.parse(d).toLocaleDateString('en-GB',{weekday:'short',day:'numeric',month:'short',timeZone:'UTC'})}</button>`).join('')}</div>`;
    html+=`<div class="cv-all-day"><span>All-day</span>${dates.map(d=>`<div>${cvEvents(d).filter(CalendarLayout.allDay).map(ev=>cvButton(ev,true)).join('')}</div>`).join('')}</div><div class="cv-timed-body"><div class="cv-time-scale">`;
    for(let hour=first;hour<last;hour++) html+=`<span style="top:${(hour-first)*64}px">${hour===0?'12 AM':hour<12?hour+' AM':hour===12?'12 PM':(hour-12)+' PM'}</span>`;
    html+='</div>';
    columns.forEach(column=>{
        html+='<div class="cv-time-column">';
        column.forEach(row=>{
            const blockHeight=Math.max(20,(row.end-row.start)/60*64);
            // Keep instant/minimum-height markers at 23:59 inside the last
            // time row; their labels retain the exact deadline timestamp.
            const top=Math.max(0,Math.min((row.start-first*60)/60*64,height-blockHeight));
            html+=cvButton(row.ev,false,`position:absolute;top:${top}px;height:${blockHeight}px;left:calc(${row.lane/row.lanes*100}% + 3px);width:calc(${100/row.lanes}% - 6px)`);
        });
        html+='</div>';
    });
    return html+'</div></div>';
}
function cvRender() {
    cvEl('calendarWorkspace').dataset.view=CV.view;
    const monthName=CalendarLayout.parse(selectedDate).toLocaleDateString('en-GB',{month:'long',year:'numeric',timeZone:'UTC'});
    cvEl('calendarHeading').textContent=CV.view==='day'?cvLabel(selectedDate):monthName;
    cvEl('calendarDate').value=selectedDate;
    document.querySelectorAll('[data-calendar-view]').forEach(btn=>btn.setAttribute('aria-pressed',String(btn.dataset.calendarView===CV.view)));
    const start=CalendarLayout.monday(selectedDate), dates=Array.from({length:7},(_,i)=>CalendarLayout.add(start,i));
    cvEl('calendarMobilePeriod').textContent=CV.view==='week'?cvWeekLabel(start):cvLabel(selectedDate);
    cvEl('calendarMobileHeading').textContent=cvLabel(selectedDate);
    const selectedCount=cvEvents(selectedDate).length;cvEl('calendarMobileCount').textContent=`${selectedCount} event${selectedCount===1?'':'s'}`;
    cvEl('calendarWeekStrip').innerHTML=dates.map(d=>{const events=cvEvents(d);return `<button data-calendar-date="${d}" class="${d===selectedDate?'is-selected':''}" aria-label="${cvEscape(cvLabel(d)+', '+events.length+' events')}"><span>${CalendarLayout.parse(d).toLocaleDateString('en-GB',{weekday:'short',timeZone:'UTC'})}</span><strong>${Number(d.slice(8))}</strong><i class="cv-strip-dots" aria-hidden="true">${events.slice(0,3).map(ev=>`<b style="background:${cvColor(ev)}"></b>`).join('')}</i></button>`;}).join('');
    const canvas=cvEl('calendarCanvas');
    if(CV.view==='month') canvas.innerHTML=cvMonth();
    if(CV.view==='day') canvas.innerHTML=`<div class="cv-desktop-timeline">${cvTimeline([selectedDate])}</div><div class="cv-mobile-agenda">${cvMobileTimeline(selectedDate)}</div>`;
    if(CV.view==='week') canvas.innerHTML=`<div class="cv-desktop-timeline">${cvTimeline(dates)}</div><div class="cv-mobile-agenda">${cvMobileTimeline(selectedDate)}</div>`;
    cvEl('calendarAgenda').innerHTML=cvAgenda(selectedDate);
    cvEl('calendarMini').innerHTML=`<h3>${cvEscape(monthName)}</h3>${cvMonth(true)}`;
    const upcoming=cvEvents().filter(ev=>ev.start_datetime.slice(0,10)>=selectedDate && ev.start_datetime.slice(0,10)<CalendarLayout.add(selectedDate,7)).slice(0,6);
    cvEl('calendarUpcoming').innerHTML=`<h3>Upcoming <small>Next 7 days</small></h3>${upcoming.map(ev=>`<p class="cv-upcoming-date">${cvEscape(ev.start_datetime.slice(0,10))}</p>${cvButton(ev,true)}`).join('')||'<p class="cv-empty">No upcoming events.</p>'}`;
    if(CV.detail) cvRenderDetail();
    cvEl('calendarLive').textContent=`${cvEvents().length} events in the loaded calendar period.`;
    const url=new URL(location.href); url.searchParams.set('y',selectedDate.slice(0,4));url.searchParams.set('m',Number(selectedDate.slice(5,7)));url.searchParams.set('date',selectedDate);url.searchParams.set('view',CV.view);
    if(CV.course)url.searchParams.set('course',CV.course);else url.searchParams.delete('course');
    history.replaceState(null,'',url);
    document.querySelectorAll('#eventForm input[name="redirect"]').forEach(el=>el.value=url.pathname.split('/').pop()+url.search);
}
function cvRenderDetail() {
    const ev=ALL_EVENTS.find(ev=>ev._key===CV.detail), panel=cvEl('calendarDetails');
    if(!ev){panel.hidden=true;CV.detail=null;return;}
    panel.hidden=false;
    panel.innerHTML=`<div class="cv-detail-heading"><span>Event details</span><button class="cv-icon" data-close-event aria-label="Close event details">×</button></div><h2 tabindex="-1" id="calendarEventTitle">${cvEscape(ev.title)}</h2><p class="cv-category" style="--event-color:${cvColor(ev)}">${cvType(ev)}</p><p>${cvEscape(cvLabel(ev.start_datetime.slice(0,10)))}</p><p>${ev.assignment_test_id?'Due ':''}${cvTime(ev.start_datetime)}${ev.assignment_test_id?'':' – '+cvEscape(cvLabel(ev.end_datetime.slice(0,10)))+' '+cvTime(ev.end_datetime)}</p>${ev.location?`<p><i class="bi bi-geo-alt" aria-hidden="true"></i> ${cvEscape(ev.location)}</p>`:''}${ev.course_code?`<p class="cv-course">${cvEscape(ev.course_code+' — '+(ev.course_title||''))}</p>`:''}${ev.description?`<p class="cv-description">${cvEscape(ev.description)}</p>`:''}<p class="muted">Organizer: ${cvEscape(ev.organizer_name)}</p>${Number(ev.is_recurring)?`<p>Repeats ${cvEscape(ev.recurrence_rule)}${ev.recurrence_end_date?' until '+cvEscape(ev.recurrence_end_date):''}</p>`:''}`;
    if(ev.assignment_test_id)panel.innerHTML+=`<p>${cvEscape(ucFirst(ev.assignment_type))} · ${cvEscape(ev.assignment_marks)} marks</p><p class="cv-source-status">${cvEscape(ev.assignment_status_label)}</p><a class="btn primary" href="${cvEscape(ev.action_url)}">Open Assignment/Test</a>`;
    if(ev.online_quiz_id)panel.innerHTML+=`<a class="btn primary" href="online_quiz?id=${Number(ev.online_quiz_id)}">Open Online Quiz/Test</a>`;
    else if(ev._can_manage)panel.innerHTML+=`<div class="cv-detail-actions"><button class="btn secondary" data-edit-event="${cvEscape(ev._key)}">Edit</button><form action="calendar_delete.php" method="post" data-delete-calendar><input type="hidden" name="event_id" value="${Number(ev.id)}"><input type="hidden" name="redirect" value="${cvEscape('calendar'+location.search)}"><button class="btn danger">Delete</button></form></div>`;
}
function openEventDetail(key) {
    const ev=ALL_EVENTS.find(ev=>ev._key===String(key)||Number(ev.id)===key);
    if(!ev)return;
    CV.detail=ev._key;cvRenderDetail();cvEl('calendarEventTitle').focus({preventScroll:true});
    cvEl('calendarDetails').scrollIntoView({block:'nearest',behavior:'auto'});
}
async function cvRefresh(force=false) {
    const request=++CV.request,date=selectedDate,course=CV.course;
    try {
        const response=await fetch(`calendar_feed.php?date=${date}&course=${course}`,{cache:'no-store',headers:{Accept:'application/json'}});
        if(!response.ok)throw new Error('Failed');
        const data=await response.json();
        if(request!==CV.request)return;
        if(force || JSON.stringify(ALL_EVENTS)!==JSON.stringify(data.events)) {
            const focused=document.activeElement?.dataset.eventKey;
            ALL_EVENTS=data.events;cvRender();
            if(focused)Array.from(document.querySelectorAll('[data-event-key]')).find(el=>el.dataset.eventKey===focused)?.focus({preventScroll:true});
        }
    } catch(error){if(request===CV.request)cvEl('calendarLive').textContent='Calendar updates unavailable. Existing events are still shown; retrying shortly.';}
}
function selectDay(date) {selectedDate=date;CV.detail=null;cvEl('calendarDetails').hidden=true;cvRender();cvRefresh();}
function switchView(view) {CV.view=view;cvRender();cvRefresh();}
document.addEventListener('DOMContentLoaded',()=>{
    // On phones, dock the feedback/chat affordance in the brand row instead
    // of floating it over timed events. Restore its original DOM position if
    // the viewport later crosses back to desktop width.
    const feedback=document.querySelector('.feedback-fab'),brand=document.querySelector('.cv-mobile-brand'),todayButton=brand?.querySelector('[data-calendar-today]');
    if(feedback&&brand&&todayButton){const home=document.createComment('calendar-feedback-home');feedback.before(home);const media=matchMedia('(max-width: 768px)');const place=()=>{if(media.matches)brand.insertBefore(feedback,todayButton);else home.after(feedback);};place();media.addEventListener?.('change',place);}
    const fromUrl=new URLSearchParams(location.search).get('date');
    if(/^\d{4}-\d{2}-\d{2}$/.test(fromUrl||'') && !isNaN(CalendarLayout.parse(fromUrl)))selectedDate=fromUrl;
    cvEl('calendarWorkspace').addEventListener('click',event=>{
        const target=event.target.closest('button');if(!target)return;
        if(target.dataset.calendarView)switchView(target.dataset.calendarView);
        else if(target.dataset.calendarDate)selectDay(target.dataset.calendarDate);
        else if(target.dataset.eventKey)openEventDetail(target.dataset.eventKey);
        else if(target.dataset.editEvent)openEditEvent(target.dataset.editEvent);
        else if(target.hasAttribute('data-close-event')){cvEl('calendarDetails').hidden=true;const key=CV.detail;CV.detail=null;Array.from(document.querySelectorAll('[data-event-key]')).find(el=>el.dataset.eventKey===key)?.focus();}
        else if(target.dataset.calendarWeek)selectDay(CalendarLayout.add(selectedDate,Number(target.dataset.calendarWeek)*7));
        else if(target.dataset.calendarStep){
            const step=Number(target.dataset.calendarStep);
            if(CV.view==='month'){const date=CalendarLayout.parse(selectedDate.slice(0,7)+'-01');date.setUTCMonth(date.getUTCMonth()+step);selectDay(CalendarLayout.key(date));}
            else selectDay(CalendarLayout.add(selectedDate,step*(CV.view==='week'?7:1)));
        }
    });
    cvEl('calendarWorkspace').addEventListener('submit',event=>{if(event.target.matches('[data-delete-calendar]')&&!confirm('Delete this event? Recurring events delete the entire series.'))event.preventDefault();});
    cvEl('calendarToday').addEventListener('click',()=>selectDay(TODAY_STR));
    document.querySelector('[data-calendar-today]').addEventListener('click',()=>selectDay(TODAY_STR));
    document.querySelectorAll('[data-calendar-add]').forEach(button=>button.addEventListener('click',()=>openNewEvent(selectedDate)));
    cvEl('calendarDate').addEventListener('change',event=>{if(event.target.value)selectDay(event.target.value);});
    cvEl('calendarAddSelected').addEventListener('click',()=>openNewEvent(selectedDate));
    cvEl('calendarCourse').addEventListener('change',event=>{CV.course=Number(event.target.value);cvRefresh(true);});
    document.querySelectorAll('[data-calendar-type]').forEach(input=>input.addEventListener('change',()=>{if(input.checked)CV.types.add(input.dataset.calendarType);else CV.types.delete(input.dataset.calendarType);cvRender();}));
    cvRender();cvRefresh();
    setInterval(()=>{if(!document.hidden && cvEl('formOverlay').hidden)cvRefresh();},15000);
    window.addEventListener('focus',()=>{if(cvEl('formOverlay').hidden)cvRefresh();});
});
}
