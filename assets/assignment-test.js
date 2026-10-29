(() => {
    'use strict';
    const page = document.querySelector('[data-assignment-live]');
    if (page) {
        const list = document.getElementById('assignment-live-list');
        const status = document.getElementById('assignment-live-status');
        const filters = [...page.querySelectorAll('[data-assignment-filter]')];
        const unified = page.hasAttribute('data-unified-assessments');
        const params = new URLSearchParams(location.search);
        const search = document.getElementById('assessment-search');
        const statusFilter = document.getElementById('assessment-status');
        const courseFilter = document.getElementById('assessment-course');
        let currentPage = Math.max(1, Number(params.get('page')) || 1);
        const pageSize = 15;
        let selected = unified && ['all','quiz','assignment'].includes(params.get('category')) ? params.get('category') : 'all', busy = false, timer, lastHTML = null, refreshAgain = false;
        if(search) search.value = params.get('search') || '';
        if(statusFilter && [...statusFilter.options].some(o=>o.value===params.get('status'))) statusFilter.value=params.get('status');
        if(courseFilter && [...courseFilter.options].some(o=>o.value===params.get('course'))) courseFilter.value=params.get('course');
        function filterRows(announce = false) {
            let visible = 0;
            for (const row of list.children) {
                row.hidden = unified ? (selected !== 'all' && row.dataset.category !== selected) || (statusFilter.value !== 'all' && row.dataset.status !== statusFilter.value) || (courseFilter.value !== 'all' && row.dataset.course !== courseFilter.value) || !row.dataset.search.includes(search.value.trim().toLowerCase()) : selected !== 'all' && row.dataset.status !== selected;
                if (!row.hidden) visible++;
            }
            filters.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.assignmentFilter === selected)));
            document.getElementById('assignment-filter-empty').hidden = visible > 0;
            if(unified) {
                const matched=[...list.children].filter(row=>!row.hidden);
                currentPage=Math.min(currentPage,Math.max(1,Math.ceil(visible/pageSize)));
                matched.forEach((row,i)=>row.hidden=i<(currentPage-1)*pageSize||i>=currentPage*pageSize);
                document.getElementById('assessment-showing').textContent=`Showing ${visible?(currentPage-1)*pageSize+1:0}–${Math.min(currentPage*pageSize,visible)} of ${visible}`;
                document.getElementById('assessment-page-number').textContent=currentPage;
                page.querySelector('[data-assessment-page="previous"]').disabled=currentPage===1;
                page.querySelector('[data-assessment-page="next"]').disabled=currentPage*pageSize>=visible;
            }
            if (announce) status.textContent = `${visible} ${unified?'assessment':'Assignment/Test item'}${visible === 1 ? '' : 's'} shown.`;
            if(unified) {const url=new URL(location.href);url.searchParams.set('category',selected);for(const [key,value] of [['search',search.value],['status',statusFilter.value],['course',courseFilter.value],['page',currentPage>1?currentPage:'']]) value && value!=='all'?url.searchParams.set(key,value):url.searchParams.delete(key);history.replaceState(null,'',url);}
        }
        filters.forEach(button => button.addEventListener('click', () => {
            const value = button.dataset.assignmentFilter;
            selected = selected === value ? 'all' : value;
            currentPage=1;
            filterRows(true);
        }));
        search?.addEventListener('input',()=>{currentPage=1;filterRows(true);});
        statusFilter?.addEventListener('change',()=>{currentPage=1;filterRows(true);});
        courseFilter?.addEventListener('change',()=>{currentPage=1;filterRows(true);});
        page.querySelectorAll('[data-assessment-page]').forEach(button=>button.addEventListener('click',()=>{currentPage+=button.dataset.assessmentPage==='next'?1:-1;filterRows(true);}));
        page.addEventListener('keydown',event=>{if(event.key==='Escape'){const menu=event.target.closest('details');if(menu){menu.open=false;menu.querySelector('summary').focus();}}});
        document.addEventListener('click',event=>page.querySelectorAll('.assessment-overflow[open],.assessment-create[open]').forEach(menu=>{if(!menu.contains(event.target))menu.open=false;}));
        async function refresh() {
            if (document.hidden) return;
            if (busy) { refreshAgain = true; return; }
            busy = true;
            try {
                const response = await fetch(unified?'assessments_poll.php':'assignment_test_poll.php', {cache:'no-store', headers:{Accept:'application/json'}});
                if (!response.ok) throw new Error('Live updates paused. Retrying shortly.');
                const snapshot = await response.json();
                if (typeof snapshot.html !== 'string' || !snapshot.counts) throw new Error('Live updates paused. Retrying shortly.');
                if(unified&&snapshot.courses){
                    const value=courseFilter.value;
                    const options=[new Option('All courses','all'),...Object.entries(snapshot.courses).map(([id,label])=>new Option(label,id))];
                    if(JSON.stringify([...courseFilter.options].map(o=>[o.value,o.text]))!==JSON.stringify(options.map(o=>[o.value,o.text]))) {
                        courseFilter.replaceChildren(...options);
                        courseFilter.value=options.some(o=>o.value===value)?value:'all';
                    }
                }
                if (snapshot.html !== lastHTML) {
                    // Diff keyed rows so unchanged controls retain focus during polling.
                    const template = document.createElement('template');
                    template.innerHTML = snapshot.html;
                    const wanted = new Set();
                    let index = 0;
                    for (const next of [...template.content.children]) {
                        const id = next.dataset.assignmentId;
                        wanted.add(id);
                        const old = [...list.children].find(row => row.dataset.assignmentId === id);
                        if (!old) list.append(next);
                        else if (old.innerHTML !== next.innerHTML) {
                            const focused = old.contains(document.activeElement);
                            const text = focused ? document.activeElement.textContent : null;
                            if(old.querySelector('.assessment-overflow[open]')){const menu=next.querySelector('.assessment-overflow');if(menu)menu.open=true;}
                            old.replaceWith(next);
                            if (focused) ([...next.querySelectorAll('a,button,summary')].find(el => el.textContent === text) || filters[0]).focus({preventScroll:true});
                        }
                        const row = [...list.children].find(row => row.dataset.assignmentId === id);
                        for(const [key,value] of Object.entries(next.dataset)) row.dataset[key]=value;
                        if (list.children[index] !== row) list.insertBefore(row, list.children[index] || null);
                        index++;
                    }
                    for (const old of [...list.children]) if (!wanted.has(old.dataset.assignmentId)) old.remove();
                    const changed = lastHTML !== null;
                    lastHTML = snapshot.html;
                    filterRows(changed);
                }
                for (const node of page.querySelectorAll('[data-count]')) node.textContent = snapshot.counts[node.dataset.count] ?? 0;
            } catch (error) {
                status.textContent = error.message || 'Live updates paused. Retrying shortly.';
            } finally {
                busy = false;
                if (refreshAgain) { refreshAgain = false; await refresh(); }
            }
        }
        page.addEventListener('submit', async event => {
            const form = event.target.closest('[data-assignment-action]');
            if (!form) return;
            event.preventDefault();
            status.classList.remove('assessment-sr');
            if (form.dataset.saving) return;
            const data = new FormData(form);
            if (event.submitter?.name) data.set(event.submitter.name, event.submitter.value);
            if (data.get('action') === 'close' && !confirm('Close this Assignment/Test?')) return;
            const buttons = [...form.querySelectorAll('button')];
            form.dataset.saving = '1';
            form.setAttribute('aria-busy', 'true');
            buttons.forEach(button => button.disabled = true);
            try {
                // A named input called "action" shadows HTMLFormElement.action.
                const response = await fetch(form.getAttribute('action'), {method:'POST', body:data, headers:{Accept:'application/json'}});
                if (!response.headers.get('Content-Type')?.includes('application/json')) throw new Error('Unable to save. Your session may have expired; sign in again and retry.');
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.message || 'Unable to save. Check the form and try again.');
                // Queue another fetch if an earlier poll is still in flight.
                await refresh();
                status.textContent = result.message;
                if (data.get('action') === 'create') form.reset();
            } catch (error) {
                status.textContent = error.message || 'Unable to save. Please try again.';
                status.tabIndex = -1;
                status.focus();
            } finally {
                delete form.dataset.saving;
                form.removeAttribute('aria-busy');
                buttons.forEach(button => button.disabled = false);
            }
        });
        const schedule = () => { clearTimeout(timer); timer = setTimeout(async () => { await refresh(); schedule(); }, 4000); };
        document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
        window.addEventListener('focus', refresh);
        window.addEventListener('pageshow', refresh);
        filterRows();
        refresh();
        schedule();
    }

    const modal = document.getElementById('assignmentDocumentModal');
    if (modal) {
        const frame = modal.querySelector('iframe');
        const close = modal.querySelector('[data-document-close]');
        let trigger, scrollY, inertElements = [];
        function dismiss() {
            if (modal.hidden) return;
            frame.removeAttribute('src');
            modal.hidden = true;
            document.body.classList.remove('drawer-open');
            inertElements.forEach(element => element.inert = false);
            inertElements = [];
            trigger?.focus({preventScroll:true});
            window.scrollTo({top:scrollY});
        }
        document.addEventListener('click', event => {
            const link = event.target.closest('[data-document-preview]');
            if (!link) return;
            const url = new URL(link.href, location.href);
            if (url.origin !== location.origin) return;
            event.preventDefault();
            trigger = link;
            scrollY = window.scrollY;
            frame.src = url.href;
            const title = link.dataset.documentTitle || 'Document preview';
            document.getElementById('assignmentDocumentTitle').textContent = title;
            frame.title = title;
            modal.hidden = false;
            document.body.classList.add('drawer-open');
            inertElements = [...document.body.children].filter(el => el !== modal && !el.inert && !['SCRIPT','STYLE','LINK'].includes(el.tagName));
            inertElements.forEach(element => element.inert = true);
            close.focus();
        });
        close.addEventListener('click', dismiss);
        modal.addEventListener('click', event => { if (event.target === modal) dismiss(); });
        document.addEventListener('keydown', event => {
            if (modal.hidden) return;
            if (event.key === 'Escape') { event.preventDefault(); dismiss(); }
            if (event.key === 'Tab' && event.shiftKey && document.activeElement === close) {
                event.preventDefault(); frame.focus();
            }
        });
        document.addEventListener('focusin', event => {
            if (!modal.hidden && !modal.contains(event.target)) close.focus();
        });
    }
    const attempts = document.querySelector('[data-previous-attempt]');
    if (attempts) attempts.addEventListener('change', () => {
        const previous = document.querySelector('[data-previous-receipt]');
        const frame = previous.querySelector('iframe');
        const active = Boolean(attempts.value);
        previous.hidden = !active;
        document.querySelector('[data-current-attempt]').hidden = active;
        const form = document.querySelector('[data-current-marking]');
        if (form) { form.hidden = active; form.inert = active; }
        document.querySelector('[data-previous-marking-note]').hidden = !active;
        if (active) frame.src = attempts.value;
        else frame.removeAttribute('src');
    });
})();
