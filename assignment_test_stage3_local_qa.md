# Assignment/Test — Stage 3 local QA

Date: 3 September 2026. Scope: local XAMPP project and MariaDB `kwasulcs_lcs` at `127.0.0.1` only. No AlwaysData connection or deployment.

## Implemented integration

- Chat landing and conversation sidebar entry immediately beneath AI Assistant; main desktop/mobile navigation unchanged.
- Atomic publication creates one initial course-group card and local in-app notifications. The nullable chat reference remains nonunique and indexed.
- One PHP card renderer serves initial rendering, new-message polling, and updates to existing cards after item changes/closure.
- Student detail, exact-byte private uploads, replacements, text drafts, final submissions, and resubmissions.
- Stable submission row with explicit attempt count, versioned files, and immutable per-attempt receipts containing file checksums and note snapshots.
- Lecturer roster, finalized-attempt preview/download, marking drafts, completed marking, feedback updates, and return for revision.
- Administrative visibility across items, submissions, receipts and audit history; course lecturers retain ownership of marking/management.
- Publication, due-reminder, receipt, marking and closure notifications; lifecycle audit events.

Business rules remain in `includes/assignment_test.php`; page/action files delegate authorization and state changes to it. AI/plagiarism features are not implemented.

## Browser results using normal user sign-in

The user approved publishing and later removing `Stage 3 browser QA — temporary` in local CSC 402.

| Check | Result |
| --- | --- |
| Lecturer creates draft and publishes | Passed |
| One initial group-chat card | Passed |
| Polled card equals initial-load card | Passed |
| Generated card has no ordinary edit/delete controls | Passed |
| Student opens published course item | Passed |
| Original text upload and saved note | Passed |
| Final submission, attempt 1 receipt | Passed |
| Replacement creates version 2 without overwriting version 1 | Passed |
| Resubmission retains same row, increments to attempt 2 | Passed |
| Receipt 1 still references original bytes and first note | Passed |
| Receipt 2 references replacement bytes and second note | Passed |
| Original download endpoint records audit | Passed |
| Lecturer roster: 4 students, 1 submitted, 3 pending | Passed |
| Marking draft transitions to under review | Passed |
| Mark complete saves 16/20 and sends local notification | Passed |
| Closure and existing-card update through polling without reload | Passed |
| Browser JavaScript error logs | No errors observed on checked pages |
| Inline text-file preview | Not verified: in-app browser blocks the protected URL with `net::ERR_BLOCKED_BY_CLIENT` |
| Fresh 390px responsive pass | Not verified: viewport override continued reporting the desktop width; earlier responsive checks were performed before this authenticated pass |

The inline preview requires a follow-up check in a normal local browser. No browser security controls were bypassed.

## Local service and schema checks

- Both stored files matched SHA-256 hashes of their original test fixtures exactly.
- Verified nullable, nonunique indexed `chat_messages.assignment_test_id` and all six module tables.
- Verified one submission row, two attempts, and separate original/replacement receipt manifests.
- Rollback-only marking regressions passed: invalid scores do not change lifecycle, draft/complete/return transitions work, admins retain visibility but cannot mutate lecturer grades.
- Earlier local service regressions covered enrollment/ownership/privacy denials, other-student file and receipt denial, deadline/late/resubmission rules, publication idempotency, and frozen finalized review snapshots.
- Four QA due reminders used the correct Assignment/Test ID and action link. Closure produced four notifications, publication four, receipts two, and marking one.
- Audit events verified: creation, publication, viewing, upload, replacement, draft save, submission/resubmission, receipt creation/viewing, downloading/preview requests, review start, marking draft, marked and closed.
- PHP syntax checks passed for all 20 affected PHP files. `git diff --check` passed.

## Fixes made during testing

- Preserve page item variables across shared navigation includes.
- Resolve the actual Assignment/Test when dispatching due-reminder jobs.
- Enforce owner-only grade updates, including already-marked work.
- Validate scores before state changes; transact marking, audit, and notifications together.
- Keep draft feedback private and use finalized receipt snapshots for review.
- Recheck permissions under locks during file replacement/removal, draft saves and finalization.
- Refresh existing chat cards after item updates.
- Improve dark-mode receipt-link contrast, upload labeling, draft-versus-final guidance, and receipt print navigation hiding.

## Operational notes and next review

- Due reminders are currently dispatched by the existing notification polling flow. No unattended scheduler has been installed; delivery depends on that flow being invoked.
- `config.php` is locally modified but ignored by Git. Its conversation-preview integration must be retained deliberately when preparing a future reviewed deployment; this report does not authorize deployment.
- The approved QA item and related database records were removed after testing. The real `CSC 402 first assignment` was preserved. Two orphaned QA text files remain under `private_uploads/assignment_tests/item_8/student_2/`: their XAMPP ownership prevents ordinary filesystem cleanup. Unrelated pre-existing code and asset changes were left intact.
- No next stage has been started. Bulk ZIP, AI assistance, similarity detection and further visual refinement are outside this completed integration pass.

Local review entry points:

- http://localhost/LMS-portal/chat.php
- http://localhost/LMS-portal/assignment_tests.php
- http://localhost/LMS-portal/assignment_test.php?id=6
- http://localhost/LMS-portal/assignment_test_submissions.php?id=6
- http://localhost/LMS-portal/assignment_test_audit.php?id=6

Use a normally authenticated lecturer/admin/student account as appropriate. The real item is for review only; do not submit or mark it as QA without approval.

The two remaining files are generated test fixtures, not student coursework. Cleanup needs an OS administrator because noninteractive `sudo` requires a password. Run locally if desired:

```sh
sudo rm /Applications/XAMPP/xamppfiles/htdocs/LMS-portal/private_uploads/assignment_tests/item_8/student_2/20260903194704_5565d2f8b9e7b066.txt /Applications/XAMPP/xamppfiles/htdocs/LMS-portal/private_uploads/assignment_tests/item_8/student_2/20260903195053_89100f69256e0877.txt
sudo rmdir /Applications/XAMPP/xamppfiles/htdocs/LMS-portal/private_uploads/assignment_tests/item_8/student_2 /Applications/XAMPP/xamppfiles/htdocs/LMS-portal/private_uploads/assignment_tests/item_8
```
