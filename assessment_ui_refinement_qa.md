# Assessment UI refinement — local progress and QA

8 September 2026. Implementation, automated checks, and lecturer browser QA are complete. Local XAMPP/MariaDB only. No schema changes, deployment, push, AlwaysData access, or production changes.

## 1. Files changed

- Listing: `assignment_tests.php`, `partials/assessment_filters.php`, `includes/assessments.php`, `assets/assessments.css`, `assets/assignment-test.js`.
- Monitor: `online_quiz_monitor.php`, `assets/online-quiz-monitor.js`, new `assets/online-quiz-monitor.css`, new `includes/online_quiz_monitor_view.php`.
- `includes/online_quiz.php`: adds owner-only monitor presentation fields and the lock timestamp to its existing monitor projection; existing calculations and mutations remain unchanged.
- Tests: `tests/assessments.php`, `tests/online_quiz_http.php`.

## 2. Assignments & Tests UI

One divided statistics strip replaces standalone cards. Underline category tabs, search, authorized-course dropdown, status filter, rows, and a real-count footer share one container. Type badges sit beside titles. Contextual text actions lead to the existing domain pages; secondary actions use labeled native disclosure menus. Create assessment retains both existing creation routes. The inline Assignment/Test editor is hidden until opened through that entry point or an Edit link.

## 3. Monitor UI

Compact breadcrumb, back link, title/status, configuration link, and close control replace the large hero; standard LCS navigation/header remains unchanged. Statistics keep the original calculations, with Started/Submitted presentation labels and a percentage suffix. Student attempts and Results & answers form the main columns. Search/filter use owner-authorized attempt data. Expandable details include warning/flag state, neutral activity explanation, and View attempt. Existing marking form and integrity timeline remain accessible below the monitor via that link.

The presentation helper derives effective status, readable WAT contact labels, and release-policy copy on the server. Manual release buttons are disabled for non-manual policies and after release. Once attempts exist, the configuration link says View configuration rather than implying editing is available. A quiz past its effective close time shows Quiz closed instead of an active Close button. No lifecycle semantics were changed.

## 4. Mobile behavior

Listing rows become stacked items, statistics form a two-column strip, filters stack, and actions retain usable touch targets. The monitor uses title/actions, statistics, release panel, then attempt cards on narrow screens. Disclosure controls expose expanded state and labeled destinations. Both layouts were inspected at 375 × 812 pixels in light and dark themes, including an expanded attempt; measured document width remained 375 pixels with no horizontal overflow.

## 5. Dark-mode behavior

Panels, borders, text, fields, menus, and expanded areas use existing LCS tokens. Green text actions use token-derived theme-aware mixes after browser QA identified insufficient contrast with the original green. Listing and monitor were visually checked in light and dark themes at desktop and 375-pixel mobile widths. The original light theme was restored after QA.

## 6. Polling and live refresh

The listing retains four-second authorized polling, stable type-prefixed row identities, and URL-backed filter state. Course options refresh from authorized snapshots. Pagination uses 15 filtered rows per page, clamps safely as records change, and preserves page/filter state across polling. Open overflow state and focused controls are retained during keyed replacements. Filter announcements are visually hidden to avoid shifting the page; form feedback remains visible.

The monitor retains 15-second refresh and adds keyed attempt updates, an expanded-ID set, unchanged search/filter controls, and focus-aware replacement. Successful mutations request a fresh monitor snapshot. Lecturer browser QA confirmed that a selected Submitted filter, the search value `496`, and the expanded attempt remained intact through a refresh interval, with no duplicate attempt identity or lost expansion.

## 7. Existing functionality preserved

Authorization, CSRF, separate Assignment/Test and Online Quiz mutation routes, file/text submission, receipts, grading, quiz anti-cheat rules, lockouts, release policies, chat cards, notifications, queued email, calendar sync, and CLI reminders remain in their existing domains. No new schema or reminder dispatcher was introduced. No real marks or release settings were changed for browser QA.

## 8. Automated tests

307 checks passed: Online Quiz domain 78; HTTP 28; client 12; Assignment/Test Stage 4 34; deadlines 20; recurrence 14; reminder worker 73; assessment integration 48. This includes 14 new presentation/HTTP checks for course privacy, badge placement, overflow destinations, monitor state/count consistency, WAT labels, compact markup, and release controls. Existing tests were not weakened. PHP/JavaScript syntax checks passed. Tests use isolated local fixtures and loopback/fake email delivery.

## 9. Browser QA performed

Listing was inspected in desktop light/dark and mobile light/dark at 375 × 812 pixels. No horizontal overflow appeared. Combined quiz category + CSC 402 course + Closed status produced the expected single row; search produced the correct empty state and zero-count footer. Filters remained selected across live updates. The lecturer creation dropdown exposed the existing Assignment/Test and Online Quiz/Test routes. A closed assignment's overflow menu exposed Open details, Edit, and Reopen; Escape closed it and returned focus to the labeled trigger.

The CSC 199 `Test` monitor was inspected through a normal lecturer login in desktop and mobile light/dark. Its effective Closed state, six statistics, 100% average, submitted attempt, WAT contact label, and automatic release-policy states matched the server data. Search and Submitted filtering worked. The attempt stayed expanded through polling and showed one warning, Not flagged, neutral integrity copy, and the existing View attempt destination. View attempt reached the unchanged marking/review form and integrity timeline; View configuration reached the populated existing quiz wizard. Mobile placed Results & answers before Student attempts and retained readable stacked attempt cells. No marks, release settings, or lifecycle controls were changed. The original light theme and normal viewport were restored, and the temporary QA tab was closed.

## 10. Remaining work/limitations

- Destructive/lifecycle browser actions were intentionally not clicked against real student results. Domain/HTTP tests exercise release, closure, and authorization with isolated fixtures.
- Pagination is client-side over the existing authorized snapshot; it limits displayed rows, not database fetch size. Large datasets may eventually need server-side pagination without changing domain rules.
- Browser QA used responsive emulation rather than a physical phone. A dedicated screen-reader session was not performed; accessible names, native controls, expanded state, focus return, and keyboard Escape behavior were inspected through the browser accessibility tree.
