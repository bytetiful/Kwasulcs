# Online Quiz/Test — local implementation and QA

Updated: 7 September 2026. Local XAMPP/MariaDB only. No deployment, push,
AlwaysData connection, production schema change, or WhatsApp integration.

## 1. Architecture

Online Quiz/Test is an additional, manually authored assessment mode reached
from Assignment/Test. File-based assignments/tests and AI practice quizzes are
unchanged workflows. `includes/online_quiz.php` owns authorization, validation,
attempt state, grading, integrity, release and calendar integration. Thin PHP
pages render shared LCS presentation; a CSRF-protected JSON endpoint invokes
the domain layer. The existing sidebar is reused, with only its active-page
mapping extended. No new sidebar entry was added.

## 2. Files

New domain/schema: `includes/online_quiz.php`, `online_quiz_migration.sql`.

New pages: `online_quizzes.php`, `online_quiz_edit.php`, `online_quiz.php`,
`online_quiz_take.php`, `online_quiz_result.php`, `online_quiz_monitor.php`,
`online_quiz_api.php`, `partials/online_quiz_shell.php`.

New assets: `assets/online-quiz.css`, `assets/online-quiz-common.js`,
`assets/online-quiz-editor.js`, `assets/online-quiz-start.js`,
`assets/online-quiz-runner.js`, `assets/online-quiz-result.js`,
`assets/online-quiz-monitor.js`.

Integration changes: `assignment_tests.php`, `partials/nav.php`,
`includes/calendar.php`, `calendar_save.php`, `calendar_delete.php`,
`assets/calendar-views.js`, `includes/reminders.php`, `notifications.php`.

New tests: `tests/online_quiz.php`, `tests/online_quiz_client.cjs`,
`tests/online_quiz_http.php`, `tests/online_quiz_browser_fixture.php`.
Other pre-existing worktree changes belong to earlier work and are not a
deployment manifest for this feature.

## 3. Schema

The additive migration was applied to the local database. It adds:

- `online_quizzes`: ownership, availability, settings, release dates and revisions.
- `online_quiz_questions`: lecturer question definitions, options and keys.
- `online_quiz_attempts`: frozen settings, server deadline, lifecycle, lockout,
  warnings, human-review flag and private lecturer note.
- `online_quiz_attempt_questions`: immutable selected questions and option order.
- `online_quiz_answers`: revisioned answers, deterministic/manual marks and feedback.
- `online_quiz_integrity_events`: timestamped observations and confirmed offences.
- `online_quiz_audit`: lifecycle and marking events.

`calendar_events.online_quiz_id` is a nullable, uniquely indexed projection link.
The existing Assignment/Test tables are not replaced. Question snapshots and
grading policies provide extension points for future question selection/types
and optional marking assistance; no AI grading is implemented here.

## 4. Lecturer workflow

Four saved steps: Quiz Details → Settings → Questions → Review & Publish.
Previous/Next and Save Draft preserve server state; stale revisions are rejected.
Only managed courses can be selected. There is no global admin ownership bypass.
Questions support MCQ, True/False, accepted or manually marked short answer,
and essay. Editors collapse and support option editing, duplication, ordering
and deletion. Publication validates the completed definition. Authoring freezes
once attempts exist so student snapshots cannot be silently changed.

Monitoring shows eligible learners, started/writing/finalized/flagged attempts,
average graded percentage, progress, remaining time and last reported contact.
Owners can inspect integrity history, mark answers, edit feedback and retain
private review notes. Score release and answer release are independent actions.

## 5. Student workflow

An enrolled learner sees availability, duration, attempt limit, instructions,
release policies and secure-test rules before starting. Secure mode requires
acknowledgment. Starting creates or resumes the same current attempt. The server
freezes question/option ordering and bounds expiry by duration and quiz closure.
The runner includes navigation, answer controls, a server-based countdown,
save status and submission. No-backtracking is also enforced by the server.

## 6. Secure Test behavior

Lecturer-controlled fullscreen, selection/copy/cut/paste/drag/context-menu
restrictions, focus/fullscreen monitoring and a repeating per-attempt watermark.
Questions are removed from the workspace during lockout and omitted from the
locked API response. Quiz HTML and API responses use no-store caching.
The watermark is a deterrent, not screenshot prevention.

## 7. Network and recovery

Changed answers are locally buffered, saved with server revisions and retried
after reconnection. A 12-second request timeout prevents a hung request from
holding up the client queue indefinitely. Identical interrupted-save retries
are idempotent, including an already-advanced no-backtracking request. Conflicting
changed answers require explicit reconciliation rather than silent overwrites.
Network loss/restoration, reload and autosave failure are neutral events.
Expiry still prevents new answers; offline time never grants extra time.

## 8. Three-strike rules

Focus/fullscreen departures receive a 3-second grace; suspected reduced desktop
viewport receives 8 seconds. Resizing alone does not immediately count. Each
confirmed offence sets a server-controlled 120-second lock. The second warning
explains that another offence flags the attempt. Three offences flag human
review, without assigning zero or immediately submitting. Reload does not reset
a confirmed lock, warning count, question ordering, answers or original expiry.
Expiry is reconciled by both server requests and the existing CLI worker.

## 9. Grading and results

MCQ/True-False grade deterministically. Accepted short answers normalize case
and whitespace; manual short answers and essays need lecturer marking. Empty
answers score zero. Client-supplied marks are never used. Results distinguish
points/percentage grading and only expose pass status when configured.
Correct keys, explanations, correctness and per-question marks are withheld
until their independent release policies permit them.

## 10. Calendar and reminders

Publication synchronizes one linked calendar event. Existing server-driven
reminder jobs remain authoritative; browser reads never dispatch reminders.
Visibility and enrollment are rechecked at delivery. Manual quiz closure cancels
unsent jobs, and worker resync cannot revive them. Linked quiz events cannot be
edited/deleted independently through ordinary calendar endpoints.

## 11. Verification

All **249 automated checks passed** in the local environment:

| Suite | Checks |
| --- | ---: |
| Online quiz domain (`tests/online_quiz.php`) | 78 |
| Online quiz HTTP contracts (`tests/online_quiz_http.php`) | 18 |
| Quiz client units (`tests/online_quiz_client.cjs`) | 12 |
| Assignment/Test deadlines | 20 |
| Assignment/Test lifecycle/permissions | 34 |
| Calendar recurrence | 14 |
| Independent reminder worker | 73 |

PHP syntax checks and JavaScript syntax checks passed for the module. HTTP tests
used a loopback-only PHP server and isolated test-user sessions, not a real
browser login. Reminder email tests used a loopback SMTP sink, not real mail.
Domain lockout tests age only fixture timestamps to test boundaries; they are
not a substitute for physical browser/device signal testing.

Browser observations on isolated QA content:

- Saved Quiz Details, navigated Settings/Questions and saved a manual MCQ.
- Fresh page load restored Review & Publish, its question and configured dates.
- Secure preflight and the student runner loaded through normal authenticated UI.
- A real fullscreen-exit observation was recorded, followed three seconds later
  by one confirmed warning; the same attempt subsequently continued and submitted.
- Two temporary attempts reached results (2/2 and 1/2); answer release remained
  withheld and warnings remained visible. Some interactions occurred while the
  user was also operating the browser; these are observed outcomes, not claims
  that every click was performed by the agent.
- Result and wizard layouts were inspected at 375×812 and the normal desktop
  viewport. Both inspected mobile pages had scroll width equal to viewport width.
- The temporary viewport override was reset afterward.
- Temporary browser QA quizzes, attempts, test-course enrollment, fixture lecturer,
  calendar/reminder records and the unpublished QA draft were removed. Existing
  assessments and their submissions were not deleted or changed. Disposable QA
  content can be recreated using the CLI-only fixture helper.

## 12. Remaining limitations / validation gaps

The implementation is local, **not certified production-ready**. Full end-to-end
physical-device testing remains for offline/reconnect while typing, a real tab/app
switch independent of fullscreen exit, all three lockouts and refresh during an
active lockout, brief versus sustained split-screen resizing, mobile keyboard
transitions, and live manual-marking/feedback editing in a lecturer browser.
Server/domain behavior for these applicable rules is covered above, but those
checks must not be described as completed real-device browser tests.

Browser focus/fullscreen/viewport signals are best-effort and can be bypassed by
a hostile client. They are not proof of cheating. The reduced-viewport heuristic
intentionally skips coarse-pointer/small-screen devices to avoid keyboard and
orientation false positives. Fullscreen varies by device/browser. Answers still
only on a device at expiry cannot safely be accepted afterward. Private-device
storage can be cleared or unavailable. No native proctoring, screenshot blocking,
AI grading, AI generation or question-pool authoring is included.

## 13. Future production requirements — documentation only

Do not deploy this work without separate approval and completion of the pending
acceptance checks. Before any future rollout: back up and inspect the target
schema; confirm existing Assignment/Test and reminder migrations; review/test
`online_quiz_migration.sql` against the actual MariaDB version; apply the additive
schema before code that references it; deliver only reviewed module/integration
changes; verify Lagos/+01:00 timing, CSRF/session behavior, enrollment constraints,
and CLI-worker configuration in a staging environment. Do not copy local `.env`,
QA users, fixture sessions, test coursework or private uploads. Preserve frozen
attempts and audit data in any rollback. No production action was performed.
