# Server-driven reminder implementation — local completion report

Date: 7 September 2026. Project: `/Applications/XAMPP/xamppfiles/htdocs/LMS-portal`.
Only `127.0.0.1 / kwasulcs_lcs` was migrated/tested. No AlwaysData connection, deployment, push, WhatsApp integration, or production change.

## 1. Root architecture implemented

`Calendar event → stable occurrence ordinal → eligible recipient → channel delivery job → CLI worker → web / SMTP adapter`.

`includes/reminders.php` owns occurrence expansion, audience selection, scheduling/reconciliation, legacy import, claims, dispatch, retries and worker health. Event-write endpoints schedule but never dispatch scheduled reminders. `notify_cron.php` is the authoritative worker entry point. Assignment/Test keeps its existing domain helpers and job table, invoked by this worker.

## 2. Files changed

- Added `includes/reminders.php`, `reminder_worker_migration.sql`, `ops/local-reminder.crontab`, this report, `tests/reminder_worker.php`, and `tests/reminder_smtp_sink.py`.
- Changed `notify_cron.php`, `notify_poll.php`, `dashboard.php`, `calendar.php`, `calendar_save.php`, `calendar_delete.php`.
- Changed `includes/calendar.php` to share occurrence arithmetic with the worker.
- Changed `includes/assignment_test.php` only to guard scheduled dispatch as CLI-only and allow fixture-scoped execution.
- Changed `config.php` only to add an optional, explicitly local/CLI-only SMTP QA transport to the existing `send_mail()` function. Its normal PHPMailer configuration and application timezone remain unchanged. This file may be ignored by Git; preserve/merge this small change explicitly during a future release, without replacing host credentials.
- Installed the local user's one-minute crontab from `ops/local-reminder.crontab`, after verifying there were no existing user jobs. Runtime output goes to HTTP-denied `private_uploads/reminder-worker.log`.

Existing unrelated working-tree changes were preserved. No commits or pushes were made.

## 3. Schema changes

`reminder_delivery_jobs` adds per-channel lifecycle (`pending`, `processing`, `sent`, `failed`, `cancelled`, `superseded`), source/occurrence identity, occurrence start/end, scheduled/expiry times, claim token/time/lease, attempts, retry time, sent time, last error, schedule hash, and optional legacy reference.

The unique identity is `(source_type, source_id, occurrence_key, user_id, channel)`. A unique nullable `web_notifications.reminder_delivery_job_id` prevents duplicate in-app delivery even on replay. `reminder_worker_health` records migration completion, worker start/finish, status, counts, queue states and errors.

No event FK is attached to the new ledger, intentionally: event deletion leaves cancelled delivery history. Recipient deletion still cascades delivery jobs. No Assignment/Test schema or original upload data was changed.

## 4. Migration SQL

The exact additive, repeatable SQL is **`reminder_worker_migration.sql`**. It has been applied locally. The existing `calendar_notifications` table is retained untouched.

One-time data import is `php notify_cron.php --import-legacy`. Locally it imported **88 channel records from 44 legacy reminder rows**. Completed flags remain completed and are not replayed. Historical `sent_at` remains NULL where the old schema could not establish an actual send time; `last_error=legacy_sent_time_unknown` makes this limitation explicit.

Legacy recurring records do not contain an occurrence ID. Import conservatively maps them to the occurrence nearest `scheduled_at + reminder offset`; review unusually edited historical series before a future production import. The import marker prevents a later rerun from interpreting old flags again.

## 5. Worker execution method

Local scheduler (installed): once each minute, the exact command in `ops/local-reminder.crontab` runs with `--local-only`. It cannot connect using a different configured database host/name. The Mac must be awake, MariaDB available, and normal SMTP connectivity available for real email delivery.

Scheduler verification: independently recorded global runs were present in the private log. At **2026-09-07 02:04:00–02:04:01 WAT**, worker health was `ok`, with **0 overdue, 0 failures, 74 future pending channel jobs, and 88 preserved legacy sent channels**. This was a scheduler run, not a manually triggered dispatch or a browser request.

Manual local run:

```sh
/Applications/XAMPP/xamppfiles/bin/php /Applications/XAMPP/xamppfiles/htdocs/LMS-portal/notify_cron.php --run --local-only
```

Read-only operational check:

```sh
/Applications/XAMPP/xamppfiles/bin/php /Applications/XAMPP/xamppfiles/htdocs/LMS-portal/notify_cron.php --health --local-only
```

The worker reconciles a rolling 90-day occurrence horizon every run, including changes in eligible recipients. Up to 200 due channel jobs are selected by default (`--limit` is capped at 1000). A 50-second soft budget stops starting more normal-calendar deliveries; an already executing SMTP operation is allowed to finish. Assignment/Test processing follows even when the normal-calendar budget is exhausted.

`--event-id=N` limits ordinary QA to one event and skips unrelated Assignment/Test work. `--assignment-id=N` adds one explicitly scoped Assignment/Test. `--test-smtp-port=N` requires both `--local-only` and an explicit event ID and can send only to a loopback SMTP sink. It does not relay externally.

## 6. Security changes to notify_cron.php

All HTTP invocation returns **403 before config/session/database loading**, including `?run=1`, with or without a valid logged-in session. The processing helper also rejects non-CLI invocation. No web execution token or secret endpoint was introduced.

## 7. Browser-dispatch paths removed

Dashboard and Calendar no longer claim ordinary reminder rows or create scheduled reminder notifications. `notify_poll.php` now only reads the authenticated user's notifications/unread count. Dashboard's 60-second fetch updates the notification badge; it cannot dispatch reminders. Normal notification retrieval, read/clear actions and existing page rendering remain available.

Immediate event-update notices remain the existing write-triggered behavior, separate from scheduled reminders. Existing Assignment/Test read-time closure reconciliation remains intact for status correctness; scheduled due-reminder jobs themselves are CLI-only.

## 8. Ordinary Calendar reminder results

The integration suite creates isolated fixture users/course/events, invokes independent CLI processes, and cleans up only those fixture records. A real event scheduled for `T`, with a `T − 2 minutes` reminder, produced no notification before its schedule and exactly one after a short delay, without opening a browser. SMTP testing used the real PHPMailer function against a loopback-only sink.

Passed: one-time reminders, multiple recipients, web-only, email-only, both channels, disabled event channels, disabled user preferences, edits before delivery, deletion/cancellation, overdue catch-up, stale cancellation, and current active-enrollment checks. Re-enabled eligible members receive a still-timely occurrence without replaying already-sent recipients.

## 9. Recurring-event results

Daily, weekly, biweekly and monthly scheduling creates distinct ordinal jobs and repeated synchronization creates no duplicates. Each type delivered both its first and second tested due ordinal exactly once. Editing the offset after the first delivery did not resend it.

Ordinal zero is the original occurrence; later ordinals represent intervals from that series origin. Changing a time/offset does not change the identity of an already delivered ordinal. New pending schedules replace unsent state; removed audience/occurrences become superseded. To intentionally send a wholly new series independently of delivered ordinals, create a new event.

Monthly rule: retain the original day of month and skip months without that day (e.g. January 31 → March 31 → May 31). The calendar renderer now uses this same helper, avoiding the previous rollover drift and disagreement between displayed dates and reminders. Existing recurrence counts/duration/end-date regressions pass.

## 10. Assignment/Test reminder results

The authoritative worker invokes `assignment_test_close_overdue()` and `assignment_test_fire_due_reminders()`. Existing transaction-based duplicate protection, active-enrollment checks, notification preferences and audit writes remain in the Assignment/Test module. Calendar projections remain excluded from ordinary delivery jobs, preventing double scheduling.

Passed: due reminders from independent worker execution, repeated-run idempotency, automatic closure, deadline extension cancellation/replacement, and late-enabled items remaining open. This stage preserves Assignment/Test's current in-app-only due-reminder channel and its current expiry/closure behavior, not a new email policy.

## 11. Email retry behavior

Normal production SMTP still uses the existing PHPMailer setup. `sent` is written only after SMTP success. A failure records `failed`, attempts and last error; retries back off at 1, 2, 4, 8, 16, 32 and then 60 minutes, subject to the reminder's expiry time. A failing recipient does not prevent later recipients from being attempted. Disabled preferences/channels become cancelled instead of occupying retry slots forever.

The local sink deliberately failed a first attempt and permanently rejected another recipient. The first retried successfully; the permanent failure did not block the other recipients. The full integration run observed exactly nine accepted SMTP messages, all captured locally, including concurrency scenarios. No real inbox was used for QA; external provider acceptance/inbox placement is not proven by a loopback test.

## 12. Duplicate/concurrency tests

A database advisory lock admits one worker per application database. It remains held during SMTP **without a row/table transaction being held around SMTP**; accidentally overlapping workers return `busy` without claiming jobs. Conditional token claims and a 15-minute lease support recovery after a worker exits/crashes. An expired abandoned claim becomes retryable.

In-app insertion and the job's sent-state update commit together. The unique delivery-job key prevents an extra in-app row even if a sent job is deliberately reset for a replay test. Event edit/delete paths use a fail-fast per-event advisory lock so they cannot supersede an in-flight email; the UI receives a retry-shortly error rather than waiting through SMTP.

Passed: repeat worker runs, two overlapping real CLI workers, one accepted email under overlap, expired claim recovery, forced web replay, and edit/send contention.

## 13. Missed-reminder policy

**Normal Calendar:** send overdue reminders for at most two hours after their scheduled time, and never after their occurrence end. Expiry is `min(scheduled_at + 2 hours, occurrence_end_at)`. A reminder due at 13:30 is still eligible at 13:42 if its event has not ended. Expired jobs become cancelled with `stale` recorded. A late-created upcoming event clamps its schedule to creation time once; each worker run does not move that schedule forward.

**Assignment/Test:** preserve existing module rules. Non-late-enabled overdue work closes and cancels its pending due reminders. Late-enabled work stays open according to the existing policy. This was not redesigned to adopt Calendar's two-hour rule.

## 14. Timezone validation

No application timezone change. Both CLI and web bootstrap through `config.php`: PHP `Africa/Lagos`, database session `+01:00`. Tests confirm PHP and database current time agree and the same two-minute arithmetic determines the stored schedule. Shared calendar/worker recurrence expansion prevents a separate rendering calculation.

## 15. Regression results

- New reminder suite: **73 passed** (`tests/reminder_worker.php`). Includes actual local HTTP calls proving authenticated dashboard/calendar/poll do not dispatch, and authenticated/unauthenticated worker HTTP calls both fail before processing.
- Existing deadline suite: **20 passed**, rolled back.
- Existing submission/marking suite: **34 passed**, rolled back; includes permissions, draft isolation, immutable receipts, marking, returning/resubmission and attempt increment.
- Existing calendar recurrence suite: **14 passed**, no database writes.
- **141 checks total** across these suites. Syntax checks cover the changed PHP files.

Temporary QA users, courses, events, jobs, notifications, sessions and test servers were removed/stopped. Existing legacy history was preserved. Operational health/log data and the approved new schema remain.

## 16. Remaining risks

- SMTP does not support an atomic transaction with MariaDB. If a provider accepts email immediately before a process dies, its recovered job may retry and duplicate that message. Normal deterministic overlap is protected; absolute exactly-once email cannot honestly be promised with the existing SMTP infrastructure.
- Legacy sent flags lack reliable delivery times and old recurring records lack occurrence identity; see import caveat above.
- A local scheduler cannot run when this Mac is asleep/off. An always-on host is required for delivery while all user devices are offline.
- Large installations may need reconciliation pagination, longer-term queue retention and monitoring; current reconciliation scans ordinary events with a 90-day horizon. A long-running SMTP operation can exceed the soft run budget, though another worker cannot overlap it.
- Enrollment/department changes during the tiny interval after the final eligibility check and external acceptance cannot revoke an email already in flight.
- This stage does not alter existing immediate event-update emails, Assignment/Test permissions, or other notification types.
- Future WhatsApp needs an adapter plus consent/address policy in the shared service. The generic source/channel ledger needs no Calendar page/schema redesign; no Meta integration was implemented.

## 17. Future production migration/scheduler steps — DOCUMENTATION ONLY

Do not execute these as part of this local stage. Production paths, PHP binary, DB credentials, cron availability and backups must first be verified by an authorized operator; they were deliberately not contacted here.

1. Back up the production database and application; record legacy row counts and sent flags. Pause any existing reminder scheduler and temporarily prevent event edits/old browser dispatch during the cutover.
2. Apply the **exact checked-in `reminder_worker_migration.sql`** to the intended production database. No local QA records or local cron configuration are part of that SQL.
3. Release the listed application changes together, carefully merging `config.php` without changing production secrets. Do not roll back just the PHP files after enabling this worker: old flag-based dispatch could resend reminders because the new ledger deliberately does not update old flags.
4. In the release directory, using the verified host PHP binary and existing environment, run `php notify_cron.php --import-legacy`. Review imported-channel counts and ambiguous edited recurring series. Repeating this command is safe after the successful marker has been stored.
5. Verify `php notify_cron.php --health`, and verify HTTP `/notify_cron.php?run=1` returns 403. Confirm PHP Lagos / DB +01:00 and SMTP connectivity to the intended provider.
6. Run `php notify_cron.php --run` once under authorized observation, then schedule every minute. Do **not** use `--local-only` in production and do not use the test SMTP flags.
7. The scheduler command template is below. Replace the explicit placeholders with verified absolute paths; those production paths were not guessed or probed.

```cron
* * * * * /VERIFIED/PHP/BINARY /VERIFIED/APP/PATH/notify_cron.php --run >> /VERIFIED/PRIVATE/LOG/PATH/reminder-worker.log 2>&1
```

8. Restore normal event editing. Observe at least two scheduled heartbeats, queue lateness and provider delivery. Alert on a missing heartbeat, `error`/`degraded` status, or growing overdue count. Rotate the private log.
9. To disable locally, remove only the reminder line from the user's crontab, preserving any other jobs. To roll back a deployed dispatcher, first stop scheduling, then plan an explicit ledger reconciliation—never reactivate the old browser senders blindly.
