# Unified Assignments & Tests — local implementation and QA

Completed local QA: 8 September 2026. Scope: `/Applications/XAMPP/xamppfiles/htdocs/LMS-portal` and local MariaDB only. No deployment, push, AlwaysData connection, production scheduler changes, or WhatsApp work.

## 1. Unified UI architecture

`assignment_tests.php` is the single discovery workspace. `includes/assessments.php` normalizes authorized Assignment/Test and Online Quiz records into a read/display model. Category, search (title and course), and status filters operate on the same page and retain state during polling. Counts come from authorized records, not sample data. Active Now uses existing server-side domain availability rules. The old `online_quizzes.php` listing redirects to the unified workspace with the quiz filter selected.

Domain storage and mutation routes remain separate. Type-prefixed row identities avoid collisions between equal numeric IDs in different modules.

## 2. Files changed

New files:

- `includes/assessments.php`, `includes/online_quiz_integration.php`
- `assessments_poll.php`, `partials/assessment_filters.php`, `assets/assessments.css`
- `assessment_workspace_migration.sql`, `tests/assessments.php`, this report

Updated for this integration:

- `assignment_tests.php`, `online_quizzes.php`, `assets/assignment-test.js`
- `includes/online_quiz.php`, `includes/reminders.php`
- `chat_group.php`, `chat_poll.php`, `chat_edit.php`, `chat_delete.php`
- `config.php` (generic quiz chat preview), `notifications.php` (local result action URL)
- `tests/online_quiz_http.php`, `tests/reminder_worker.php`

The worktree also contains pre-existing changes from earlier stages; those are not all changes from this integration. In particular, retain the local `config.php` change when preparing a future release even if that file is ignored by Git; never copy local credentials to another environment.

## 3. Schema changes

Applied locally: `assessment_workspace_migration.sql` adds nullable `chat_messages.online_quiz_id`, a non-unique index, and a foreign key to `online_quizzes` with `ON DELETE SET NULL`. Existing chat rows remain valid. No assessment tables were merged, and no new notification table was added. Existing delivery-ledger and quiz-audit storage are reused.

## 4. Assignment/Test behavior preserved

File/text submission, private drafts, finalized attempt snapshots, replacements, version history, immutable receipts, resubmission, marking, revision requests, and existing chat/calendar/reminder behavior remain in the Assignment/Test domain. Deadline tests verify automatic closure and the exception that late-submission-enabled items remain open until manually closed.

## 5. Online Quiz behavior preserved

The existing four-step authoring wizard, question types, frozen attempts/options, autosave, recovery, server timing, neutral reconnect/reload events, secure-test rules, 120-second lockouts, three-strike behavior, grading, monitoring, and release policies remain intact. Integration hooks add publication and result notices without changing anti-cheat semantics. The separate AI practice quiz routes were not redesigned or invoked for external AI generation during this QA.

## 6. Unified lecturer behavior

Owners see their authorized drafts and published work. Create New enters the existing Assignment/Test editor or Online Quiz wizard. Assignment actions retain existing CSRF-protected domain endpoints. Quiz actions lead to draft editing, monitoring/submissions, and permitted editing; lifecycle/release controls remain in the existing monitor. Unassigned administrators do not gain a global assessment-management bypass.

## 7. Unified student behavior

Active enrollment governs discovery. Students cannot see lecturer drafts or lecturer mutations. Assignment rows expose progress and receipts where available. Quiz rows reflect not-started, in-progress, locked, submitted, flagged, and released-result states. Finalized attempts also provide a Quiz details link to the existing preflight/attempt-selection flow. Result and answer permissions remain enforced by the quiz domain, not the unified UI.

## 8. Quiz chat-card implementation

`oq_chat_bundle()` is the dedicated renderer used by initial group-page HTML and polling. It renders a persistent quiz reference with title, course, question count, duration, availability, effective status, personal state, and the correct destination. It reuses the established Assignment/Test card styling. Unauthorized viewers receive a generic unavailable card without the quiz title. No question text, keys, explanations, integrity timeline, or lecturer monitoring data are returned in card payloads.

## 9. Chat-card lifecycle and idempotency

Publishing under the existing quiz row lock creates one group-chat card. Repeated publication and later timing edits reuse its identity. Polling re-renders existing quiz references even without a message edit timestamp, so clock transitions, starts, locks, submissions, and releases update the same card. Automated checks cover scheduled/open/closed transitions, timing edits, personal state, release, and a single message across lifecycle changes. Ordinary chat edit/delete endpoints reject assessment system cards.

## 10. Quiz notifications

Publication immediately creates an eligible learner's in-app notice and queues permitted email in the existing ledger. Result notices are emitted only when review/release policy permits. Existing result/submission pages provide submission confirmation; no redundant submission notification was added. Autosave, navigation, reconnect, and warning heartbeats do not generate notices.

The existing unique delivery identity deduplicates by quiz, publication/result-attempt key, recipient, and channel. Newly submitted/reviewed attempts receive an idempotent audit marker so the worker can discover clock-released results without replaying all historical results. Explicit lecturer result release remains an intentional release action.

## 11. Email and reminder-worker integration

Email is queued, never sent by the publication browser request. The existing CLI-only worker, delivery claims, retries, consent rechecks, and PHPMailer adapter remain authoritative. Newly created linked quiz calendar events enable permitted email reminders; existing event settings are retained. HTTP tests confirm dashboard, calendar, notification polling, and public worker URLs do not dispatch scheduled reminders.

Tests use isolated fixtures, a fake email adapter for quiz publication, and a loopback SMTP sink for the existing worker suite. No global real-data worker dispatch was initiated as a regression test, and no scheduler configuration was changed.

## 12. Calendar behavior

Publication and pre-attempt timing edits retain one linked calendar event. Scheduled reminders use the existing calendar delivery jobs and recurrence infrastructure. Manual quiz closure cancels appropriate unsent reminders, and resynchronization cannot revive them. No replacement calendar implementation was introduced.

## 13. Light/dark/mobile QA

Browser checks covered desktop at 1440 px and mobile at 375 × 812 px in both themes. The sidebar, green patterned header, and navigation remain unchanged. Tables become stacked rows on mobile; measured document width was 375 px with no horizontal overflow. Tabs, filters, statistics, badges, dropdown, actions, and rows were inspected. Search/status/category combinations and the no-match empty state were exercised. Both creation entry points opened their existing editors without saving real records.

The UI/UX skill guided responsive sizing, readable theme surfaces, and focus-safe live refresh. QA caught an unsuitable background token and replaced it with the existing theme-aware surface token. Original light mode and the normal viewport were restored afterward. Tests were browser viewport emulation, not physical-device testing.

## 14. Automated regression results

All 293 checks passed after the integration:

| Suite | Checks |
| --- | ---: |
| Online Quiz domain | 78 |
| Online Quiz HTTP, including unified list and initial/poll card consistency | 24 |
| Online Quiz client | 12 |
| Assignment/Test Stage 4 | 34 |
| Assignment/Test deadlines | 20 |
| Calendar recurrence | 14 |
| Independent reminder worker | 73 |
| New assessment integration | 38 |
| **Total** | **293** |

Focused checks cover mixed authorized data/counts, filter metadata, ownership and enrollment, unchanged Assignment publication, quiz-card identity, live state, private payloads, publication eligibility/preferences, queued email, result-release deduplication, historical-result non-replay, linked calendar jobs, and closure cancellation. Updated PHP files passed syntax checks; the modified JavaScript passed `node --check`. Isolated fixture records were cleaned up by the test harnesses; unrelated user work was preserved.

## 15. Remaining limitations and observations

- During earlier local QA, the already-running local worker generated three notices for previously released real results. These real-user notices were left intact. The final marker-based implementation and regression checks prevent automatic historical-result replay merely from enabling this integration.
- Existing quizzes are not bulk republished/backfilled into chat or publication notifications. The integration applies on publication and supported subsequent saves; no real historical quiz was republished for QA.
- Quiz-card refresh and normalized rows currently use per-record queries, and tracked result audits are revisited by the worker. Large histories may need batching/pagination and completed-marker optimization later.
- Email retains the existing SMTP crash-window limitation: a provider may accept mail just before the process fails to record success. The ledger prevents ordinary repeat sends, but SMTP cannot guarantee exactly-once delivery in that failure window.
- Desktop/mobile theme checks and creation navigation were exercised manually. Publication/learner lifecycle and exact initial-versus-poll rendering were verified with isolated domain/HTTP fixtures, not by modifying a real lecturer's quiz in the browser.
- No physical-device anti-cheat certification, external AI generation, external SMTP-provider test, or production-environment validation was performed.

## 16. Future production migration requirements — documentation only

After separate production approval: review the release diff and existing domain/reminder migration prerequisites; back up the target database; apply the additive `assessment_workspace_migration.sql` with the matching application release; retain environment-specific configuration and secrets. Include the generic chat-preview change from `config.php` without copying local credentials.

Use the already-established centralized `notify_cron.php` CLI worker and its existing deployment/health procedures. This integration does not require a second scheduler or a browser-driven dispatch path. Verify nullable quiz references, ownership/enrollment, publication idempotency, notification consent, release restrictions, linked calendar jobs, and HTTP worker rejection in the approved environment. Do not replay historical publications/results automatically. No production command or scheduler change was executed for this task.
