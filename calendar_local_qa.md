# Calendar redesign and Assignment/Test integration — local QA

Scope: `/Applications/XAMPP/xamppfiles/htdocs/LMS-portal`, local MariaDB `127.0.0.1 / kwasulcs_lcs`. No AlwaysData connection, deployment, push, production configuration change, or unrelated Stage 6 work.

## Implementation

Desktop Day uses a vertical time scale, all-day row and blocks positioned by actual start/end times. Week uses Monday–Sunday columns with the same time positioning and overlap lanes. Month uses two compact, ellipsized event labels per cell, then `+N more`; full titles live in the selected-day agenda and event-details panel. Deadlines use a small instant marker, not an invented hour-long duration.

At the existing mobile-navigation breakpoint (768px), Day uses a compact week strip and readable agenda, Week uses seven vertically grouped day sections with counts, and Month uses colored dots with the selected date's agenda below. No mobile mini-calendar duplication. Shared bottom navigation was not modified. Colors, typography and scoped dark-mode surfaces retain LCS styling. The UI/UX skill informed responsive card layouts, overflow handling and clear status/action feedback.

Today, period navigation, date selection, view switching, type filters, course filtering, event forms, recurrence options and reminder controls remain. View/date/course survive normal redirects. The new authenticated feed refreshes the active calendar every 15 seconds, on focus and navigation, without replacing an open edit form. Existing ordinary-event notification processing remains unchanged.

## Schema and exact linkage

Migration applied locally: [calendar_assignment_test_migration.sql](/Applications/XAMPP/xamppfiles/htdocs/LMS-portal/calendar_assignment_test_migration.sql).

- `calendar_events.assignment_test_id INT NULL`.
- Unique index `uniq_calendar_assignment_test`: one event per Assignment/Test, while ordinary events retain unrestricted NULL links.
- Foreign key `fk_calendar_assignment_test` references `assignment_tests(id) ON DELETE CASCADE`; deleting coursework cannot orphan its calendar event.
- Existing published and closed assignments were backfilled. Verified one event each for local Assignment/Test IDs 6, 9, 10, 11 and 26, with no orphan links.
- The legacy calendar enum is unchanged: Assignment projects to `general`, Test to `test`. The scoped calendar feed exposes both as the separate Assignment/Test display/filter category.
- Projection stores only fields required by existing calendar infrastructure: short title, type, course, creator and due timestamp. Start and end are both the exact due time. Full title, marks, type, late policy and status are read from the source Assignment/Test at render time.
- Lifecycle calls upsert the same event inside their existing transaction. The unique key also protects concurrent/repeated publication.
- Linked events have ordinary calendar email/web reminders disabled: Assignment/Test's existing notifications remain authoritative, avoiding duplicate notification streams.
- Linked calendar records cannot be edited/deleted independently through calendar endpoints. The details panel links to Assignment/Test instead.
- Linked-event visibility uses the module-specific owner/enrollment policy, including its restricted admin policy. This also guards the shared dashboard-upcoming query; ordinary calendar authorization stays separate.

The migration is a **run-once** schema change. Its backfill upsert is idempotent; do not rerun the ALTER against an already-migrated database. No runtime DDL was introduced.

## Automated verification

160 assertions passed:

| Suite | Checks | Coverage |
| --- | ---: | --- |
| `tests/calendar_integration.php` | 20 | Draft exclusion; publish/re-publish; same-ID title/due edits; owner/student/admin/unrelated access; full title; no independent calendar management; close/reopen/auto-close; reminder deduplication; delete cascade |
| `tests/calendar_layout.cjs` | 16 | Monday weeks, year/leap boundaries, timezone-independent wall time, duration, overlap lanes, overnight clipping, midnight boundaries, all-day and instant deadlines |
| `tests/calendar_recurrence.php` | 14 | Existing daily/weekly/biweekly/monthly expansion, original series dates when editing an occurrence, duration, end date, ordinary-event pass-through |
| `tests/calendar_browser.cjs` | 56 | Actual production partial/JS/CSS with synthetic events: 1440px desktop and 390px phone, all three views, both themes, no horizontal overflow, no duplicate mobile calendar, mobile grouped week, unclipped 23:59 deadline markers, details, full long titles, HTML escaping, linked action/status, no JS errors |
| Existing Stage 4/deadline suites | 54 | Permissions, private drafts, submissions, receipts, marking, auto-close and late-submission exception remain intact |

PHP lint passed for all 11 checked PHP files, JavaScript syntax passed, and `git diff --check` passed. Browser fixtures intercept all requests and never access account sessions or external services.

## Signed-in browser QA

- Actual local lecturer session rendered linked deadlines with compact month cells and selected-day details.
- Day/Week/Month controls and date selection were exercised locally; responsive/light/dark coverage was also automated at exact desktop/phone widths.
- Created personal recurring event 59 with a deliberately long title, a 09:00–10:30 interval, weekly recurrence, and email disabled.
- Later occurrences appeared; selecting September 22 displayed that occurrence's correct date. Editing it retained the original September 15 start and 90-minute interval, rather than shifting the series.
- Edited the title successfully using the existing calendar endpoint.
- Made only the QA in-app reminder due. It produced exactly one local notification and marked its reminder row sent.
- Deleted the QA event through the UI; its recurring occurrences disappeared. Removed its exact remaining QA notification afterward.

## Assignment/Test lifecycle results

Publish, repeat publication, title update, due-date move, manual close, reopen and automatic close all retained a single calendar event ID. The source status was displayed consistently. Database deletion cascaded to the linked event. There is no new Assignment/Test delete UI; the cascade protects existing database deletion/cleanup paths. Published course/type immutability remains enforced by the existing module, rather than weakened for calendar editing.

## Remaining limitations

1. **Pre-existing recurring-reminder schema defect:** local `calendar_notifications` has `UNIQUE(event_id,user_id)`, while the existing scheduler attempts to insert multiple occurrences. As a result only one scheduled row persists per series/user. Recurring calendar display works and the first in-app reminder was tested, but reminders for every later occurrence are **not certified**. The unrelated reminder key/scheduler was deliberately not redesigned in this UI/linkage stage.
2. Email/push delivery was not exercised. QA email was explicitly disabled; only local in-app delivery was tested. No OS scheduler was installed.
3. Fresh student/admin browser logins were not used; their effective access policies were verified through the real server helper/query layer. A synthetic non-existent outsider principal exercised cross-department denial because the local fixture had no suitable unrelated student.
4. Phone/browser rendering was tested with Chromium at 390px, not on physical iOS/Android devices. The fixture uses production view code and shared navigation but synthetic data; normal lecturer CRUD was separately tested in the real local browser.
5. The existing schema has no dedicated all-day flag. Ordinary midnight-to-end-of-day/full-day intervals are displayed in the all-day row; Assignment/Test deadlines remain timed instants.
6. Existing recurrence expansion semantics were preserved, including any existing month-end recurrence edge behavior. Tests cover normal monthly dates, not a redesigned recurrence engine.

## Cleanup

Temporary Assignment/Test integration records 27 and 28, their chat cards, calendar projections and local notifications were removed by guarded test cleanup. Rollback suites left no fixture rows. Browser QA event 59 and notification 323 were removed. Verified zero QA items/events/notices and zero orphan linked calendar events. Disposable QA records were not backed up; real coursework and ordinary calendar records were preserved.

No uploads were created this stage. Synthetic screenshots remain in `/private/tmp/calendar-{1440|390}-{light|dark}-{day|week|month}.png` for inspection. The two XAMPP-owned PDFs already noted in the earlier Stage 4 report remain outside this stage's cleanup.

## Files changed in this stage

- `calendar.php`: presentation replaced; existing forms and web-notification handling retained.
- `calendar_feed.php`: authenticated scoped refresh endpoint.
- `includes/calendar.php`: shared event feed, preserved recurrence expansion and occurrence/source dates.
- `partials/calendar_views.php`: toolbar, filters and view/detail containers.
- `assets/calendar-views.js`, `assets/calendar-views.css`: responsive calendar rendering and interactions.
- `calendar_save.php`, `calendar_delete.php`: prevent independent changes to linked deadlines.
- `includes/assignment_test.php`: idempotent calendar synchronization and module-specific calendar visibility predicate.
- Local `config.php`: only calendar permission/upcoming helper integration; DB/production connection settings unchanged. This existing local file is Git-ignored.
- `calendar_assignment_test_migration.sql`.
- `tests/calendar_integration.php`, `tests/calendar_layout.cjs`, `tests/calendar_recurrence.php`, `tests/calendar_view_fixture.php`, `tests/calendar_browser.cjs`.
- This report.

Existing unrelated working-tree changes were preserved. Work stops at this calendar stage.
