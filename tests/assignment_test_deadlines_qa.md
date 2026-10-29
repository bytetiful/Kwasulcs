# Automatic deadline closure — local follow-up

Verified September 4, 2026 against local XAMPP and `127.0.0.1 / kwasulcs_lcs` only.

- Published items without late-submission permission now become persistently Closed after their due time. Drafts and future deadlines are unchanged.
- Reconciliation runs during list/detail/card reads, group-chat polling, notification polling, and the existing notification cron worker. Open lists refresh through the existing four-second poll. With no requests and no scheduled worker, persistence/notifications catch up on the next request; this change does not install an OS scheduler.
- Closure is transactionally row-locked and rechecked. It records one system `closed` audit event with `reason: deadline_elapsed`, cancels unsent reminders and sends local in-app closure notices. It updates the original chat card, not a new message.
- Explicit Allow late submissions remains supported; overdue items with this setting display “Open — late submissions allowed.” Existing server-side submission permissions are preserved.
- Closed items can be edited to extend their deadline, but remain closed until explicitly reopened. Reopening an expired item without late-submission permission is rejected with a future-deadline instruction. Published course/type remain immutable.
- The UI/UX skill informed the explanatory status/form copy and existing success/error feedback; no layout redesign.

## Verification

- `tests/assignment_test_deadlines.php`: 20 checks passed, all fixture writes rolled back. Covers stored/rendered status, chat card, submission rejection, audit, notification eligibility/idempotence, cancelled reminders, future/draft/late-enabled/closed exclusions, read-time reconciliation and extended deadlines.
- `tests/assignment_test_stage4.php`: all 34 existing regression checks passed.
- PHP lint passed for all six changed/new PHP files; `git diff --check` passed.
- Browser: “Second assignment” automatically changed to Closed without page reload; counts changed to 2 Open / 2 Closed; Closed filter showed both closed items. “CSC 402 assignment test1” correctly exposed its existing late-submission exception. “test 3” has a future deadline. Dark-mode screenshot visually inspected.
- No schema change, production access, deployment, scheduler installation, or Stage 5 work. No new permanent QA records or uploaded files.

## Files

`includes/assignment_test.php`, `assignment_tests.php`, `assignment_test_action.php`, `chat_poll.php`, `notify_cron.php`, `tests/assignment_test_deadlines.php`, and this report.
