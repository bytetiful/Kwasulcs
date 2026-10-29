# Stage 4 — local refinements and QA

Date: 4 September 2026. Stage 5 has not started.

Scope: `/Applications/XAMPP/xamppfiles/htdocs/LMS-portal`, local MariaDB `kwasulcs_lcs` on `127.0.0.1`. No AlwaysData access, deployment, push, external AI generation, or production resources. Existing environment configuration and unrelated worktree changes were preserved.

## Implemented

- Authenticated live list polling with one shared row renderer, immediate post-action refresh, keyed row updates, status announcements and Total/Open/Draft/Closed toggle filters.
- Existing shared chat card now includes each learner's own progress, late flag, released score and feedback link. Publication/edit/close/reopen update the existing card; no duplicate publish messages.
- Module-only admin policy now matches the latest user instruction: enrolled admins act as students unless assigned course lecturer. This supersedes the earlier Stage 3 administrative-visibility policy. Global admin privileges were not changed.
- Lecturer resolvers, roster, file history, receipt access and displayed audit history exclude student drafts and unfinalized replacements. Finalized receipt files/notes remain the review source.
- Fullscreen document preview uses the extracted Course media-modal styling and shared component structure, with keyboard close, focus restoration and background isolation. Receipt Open and marking Expand reuse it.
- Receipt header no longer displays the AT-* identifier. Previous Attempts opens older immutable receipts within the marking screen and hides marking controls until returning to the current attempt.
- Dark surfaces, controls, placeholders and labels use LCS tokens; dashboard notification rows and Quiz panels no longer stay white. True/False options support existing word/letter answer conventions without changing backend grading.
- Responsive receipt/file wrapping and narrow-screen roster rows; no main navigation redesign. The UI/UX skill guided focus, labels, contrast and responsive refinements within the supplied visual language.
- Asset-specific cache versions prevent browsers retaining pre-refinement page CSS/JS.

## Files changed in this refinement

Domain/integration: `includes/assignment_test.php`, `chat_group.php`, `chat_poll.php`.

Assignment pages/endpoints: `assignment_tests.php`, `assignment_test_poll.php` (new), `assignment_test_action.php`, `assignment_test.php`, `assignment_test_submission.php`, `assignment_test_file.php`, `assignment_test_submissions.php`, `assignment_test_mark.php`, `assignment_test_receipt.php`.

Shared presentation: `partials/head.php`, `partials/assignment_document_viewer.php` (new), `assets/assignment-test.css`, `assets/assignment-test.js` (new), `assets/media-modal.css` (new), `assets/course.css`, `assets/calendar.css`, `quiz.php`, `assets/quiz-refinements.css` (new).

Regression/report: `tests/assignment_test_stage4.php`, `tests/quiz_stage4.cjs`, this report. The temporary browser Quiz harness was removed after testing. Prior Stage 3 and unrelated dirty files were not reset or committed.

## Database changes

No schema changes or migrations. Existing nullable nonunique chat foreign key, stable submission row, attempt counter, file versions/checksums, receipts, audit and notification tables were reused. No plagiarism or other AI features were implemented.

## Browser QA

Normal lecturer/student sign-ins were performed by the user. Mutating checks used only approved `Stage 4 local QA` (item 13), later renamed `Stage 4 local QA — edited`.

| Check | Result |
| --- | --- |
| Create draft; filter Draft/Closed and return to All | Passed, no page reload |
| Publish, edit, close and reopen | Passed; list and already-open group card updated |
| Single publish card after all lifecycle changes | Passed: one chat message |
| Student-specific list and chat progress | Passed: Pending Submission → Submitted through polling |
| Exact PDF upload and replacement | Passed; original SHA-256 retained, versions 1 and 2 |
| Draft privacy before final submit | Passed: lecturer roster empty |
| Unsubmitted replacement privacy | Passed: lecturer snapshot still used version 1 and first note |
| Final submit/resubmit | Passed: same submission 15, attempts 1 and 2, separate receipts 14 and 15 |
| Clean receipt header; Open; Download | Passed |
| Fullscreen PDF preview and keyboard close | Passed; PDF visibly rendered at full viewport; focus returned to trigger |
| Lecturer Previous Attempts | Passed; embedded first receipt read-only, current marking controls hidden |
| Marking draft and Mark Complete | Passed: Under Review → Marked, 8/10, student notification |
| Publication, closure, reopen, due reminders | Passed: five local notifications each |
| Receipts/marking notifications | Passed: two receipt notifications and one marking notification |
| Dark Quiz setup, receipt, marking and dashboard notifications | Visually checked; readable dark surfaces |
| True/False answer selection and locked result | Passed using production renderer in temporary localhost fixture; no AI request |
| Light marking and Expand | Visually checked |
| QA item removal via live list polling | Passed: count returned from 5 to 4 without reload |

The two receipt snapshots retained different notes and file versions. SHA-256 of both uploaded copies matched the 661-byte local fixture: `2abd2afc627e85d44e940b8cc695d214f7006d12fbeb20e58841539a98fbe33f`.

## Automated regressions

- 34 local database/helper assertions passed with fixture writes rolled back. Includes learner/admin/lecturer restrictions, assigned-admin owner behavior, draft/receipt/file isolation, invalid-score atomicity, marking/return/resubmit lifecycle, stable submission identity, immutable receipt, notification eligibility and personalized rendering.
- 26 JavaScript production-renderer checks passed: word/letter True/False formats, selection, locked correct result, MCQ and short-answer preservation. Backend exact-match grading was not changed.
- PHP syntax checks passed for 18 relevant PHP files. Assignment/Test JavaScript syntax and `git diff --check` passed.
- Browser QA caught and fixed a named form `action` field shadowing the form URL, stale CSS caching, and duplicate word labels on True/False buttons.

Commands:

```sh
/Applications/XAMPP/xamppfiles/bin/php tests/assignment_test_stage4.php
node tests/quiz_stage4.cjs
node --check assets/assignment-test.js
git diff --check
```

## Remaining limitations / review items

1. Full phone/tablet viewport QA is not certified. The browser override requested 390px but still reported 1087px; it was reset. Narrow embedded receipts exercised responsive wrapping, but this does not replace real phone/tablet checks.
2. Three unattributed `MutationObserver.observe` errors were logged during PDF/embedded-receipt testing. The project PHP/JS contains no MutationObserver calls; their source remains unconfirmed. PDF display, modal controls, receipts and marking worked. A clean list reload produced no new errors. Review in a normal browser before declaring all browser regressions clean.
3. Admin restrictions were tested at the shared helper/database level, not through a fresh normal admin browser sign-in. Live marked-score rendering uses the tested shared helper; a second post-mark student browser sign-in was not performed.
4. Due reminders still depend on the existing notification-poll flow; no unattended scheduler was installed. AI generation was intentionally not exercised.
5. Two daemon-owned QA PDF copies need OS-admin cleanup (paths below). No permission protections were bypassed. Earlier Stage 3 orphan files were left untouched.

## Cleanup

Removed the approved temporary item, one chat message and 23 local notifications; cascades removed its submission, two file metadata rows, receipts, reminder jobs and audit records. These disposable database test records were not backed up. Existing items 6, 9, 10 and 11 remain. The temporary renderer-test page was removed.

Only these two Stage 4 upload copies remain, inaccessible through the module after their metadata was removed:

```text
/Applications/XAMPP/xamppfiles/htdocs/LMS-portal/private_uploads/assignment_tests/item_13/student_2/20260904015019_e4d1853a1a838b96.pdf
/Applications/XAMPP/xamppfiles/htdocs/LMS-portal/private_uploads/assignment_tests/item_13/student_2/20260904015400_2f84c420df96d28b.pdf
```

No production deployment is approved by this report. Stage 4 implementation is in place, with the verification/cleanup limitations above explicitly outstanding.
