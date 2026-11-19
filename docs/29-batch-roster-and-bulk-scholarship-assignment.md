# Phase 2 — Batch, roster and bulk scholarship assignment

2026-11-19 (Asia/Manila)  
**IMPLEMENTED — PENDING MANUAL VERIFICATION**

## Reused functionality and scope

Reuses `ScholarshipBatch`, `ScholarshipBatchMembership`, `Student`, existing
assignments and their period relationships, `SaveScholarshipAssignment::save`,
`ManageScholarshipRoster::join`/coverage checks, policies, audit logging and the
shared records page/modal/table components. No new service, entity, dependency,
cohort, database column or migration was added.

Phase 1 manual verification is developer-reported and was not repeated here.
The newest Phase 2 requirement supersedes the earlier removal of the Roster menu:

- **Information Registry → Batch** (`/scholarship-batches`): existing batch creation,
  generated naming, filtered listing and batch details. Details link to membership
  management. Historical headers remain immutable, with no renaming operation added.
- **Information Registry → Roster** (`/rosters`): lists those **same batch records**
  without another creation form. Opening one (`/rosters/{batch}`) manages membership.
  The existing `scholarship-batches.show` route remains available for compatibility.
- Both screens use the same controller/listing view and existing permissions, with
  separate presentation responsibilities. No duplicate batch/roster data is created.

Automatic name generation is unchanged. Detail pages show the stored name, program,
academic year, semester and total members. The entered decimal batch number is
displayed only when it can be extracted from the exact existing generated format;
unstructured historical names show “Not recorded separately” rather than a guess.

## Membership and bulk assignment flow

The member table now includes Student ID, full name, course/program, year level,
scholarship status and an authorized View action. Existing enrollment-source,
Registrar finding, billing/reservation information and roster filters remain.

1. Authorized users open a roster and select **Add scholars** above its member table.
2. In the shared modal, search existing profiles by Student ID/name or filter by
   course. Select individual checkboxes or **Select all displayed eligible scholars**.
   Already-member/conflicting rows explain why they cannot be selected.
3. Selected IDs and the new-assignment date survive search, sorting, ordinary
   pagination and page-size navigation. A separate list includes off-page selections
   and allows removal of unavailable records. Select-all covers the displayed page
   only. A technical cap of 200 selections matches the existing maximum page size.
4. Enter **Scholarship starting date for new pending assignments** when needed.
   This date applies only to selected profiles needing new assignments. Their
   program and starting academic period come from the roster; users do not reselect
   them. If actual starting dates differ, submit those groups separately.
5. Choose **Review selected scholars**. Review names, existing-assignment reuse,
   pending-assignment creation and the entered date. Back returns to selection;
   Confirm submits to `POST /rosters/{batch}/scholars` (`rosters.scholars.store`).
6. The backend resolves the current assignments again and saves the entire selection
   transactionally. Success returns to the roster with its existing notification.
   Validation returns the modal, selected IDs, entered date and error summary. No
   success is reported for a failed transaction.

The GET search form is outside the modal's POST form; search controls use native
form association. This avoids invalid nested forms. Only IDs/date are posted for
assignment; names, course, program, period and assignment choices are retrieved
from existing records. Displayed previews cannot authorize a write.

## Continuing scholars, validation and integrity

- One applicable pending/active assignment is reused using existing period/date
  coverage rules. Only the membership is inserted. Original assignment program,
  starting period/date, status and activation history are not updated.
- With no current applicable assignment, the existing save action creates a pending
  assignment, and the existing join action creates membership. A new start date is
  mandatory for this bulk path. Existing individual assignment behavior is unchanged.
- Multiple applicable assignments or a current same-program assignment outside the
  roster's coverage are explained and left for review through the scholar profile;
  the bulk path does not guess which original assignment to replace.
- Existing rules permit a pending award while another program has an active award.
  The modal explains this condition; it never closes the active award or activates
  the new one. Archived profiles are identified and are not restored by selection.
- `addSelected` validates selection/date again and locks selected students in ID
  order, reads their assignments/membership with locks, and locks the selected
  program/period/batch. It rechecks selection before invoking existing save/join
  actions. Their nested transactions/audits are inside the outer transaction.
- A missing student, duplicate member, unavailable assignment, missing/invalid date
  or any action failure rolls back all new assignments, memberships and audit entries
  in that submission. Existing composite FKs and unique batch/student membership
  remain the final protection against duplicates. Repeat submissions fail safely
  when the members already exist; they do not create additional awards or membership.
- Coordinator and retained Test Administrator can write through existing policies.
  Registrar retains read access; other roles retain their original restrictions.
  Both the controller and shared action authorize the actor. CSRF remains enabled.
- Roster membership does not certify enrollment, qualification, external approval,
  activation or payability. No billing/evaluation/financial/report/email rules were
  changed. Individual profile assignment and membership actions remain available.

## Files changed in Phase 2

- `app/Actions/Records/ManageScholarshipRoster.php`: read-only selection explanation
  and atomic bulk orchestration; original naming, individual join and coverage retained.
- `app/Http/Controllers/ScholarshipBatchController.php`: roster presentation,
  metadata, bounded candidate/selected queries and authorized submission/recovery.
- `app/Support/TableColumns.php`: align expanded member and candidate sort columns.
- `routes/web.php`: three authenticated private roster index/detail/bulk routes.
- `resources/views/layouts/navigation.blade.php`: distinct Batch/Roster entries.
- `resources/views/scholarship-batches/index.blade.php`: shared listing modes.
- `resources/views/scholarship-batches/show.blade.php`: metadata/member table/action.
- `resources/views/scholarship-batches/add-scholars.blade.php`: new selection/review modal.
- `resources/js/roster-scholar-selection.js`: new selection/review and navigation state.
- `resources/js/app.js`: register the page-specific Alpine component.
- `resources/js/record-form-dialog.js` and
  `resources/views/components/record-form-dialog.blade.php`: optional custom footer
  and intentional state-preserving navigation; keep existing close/focus behavior.
- `resources/js/data-tables.js`: skip sorting on the explicitly marked checkbox header.
- `resources/css/app.css`: scoped roster heading/modal sizing/scrolling styles.
- `docs/28-information-registry-completion.md`: superseding navigation note.
- This report. Prior uncommitted Phase 1 work was retained.

## Commands and actual verification

Static source review covered authorization, current assignment resolution, original
start preservation, transaction scope, duplicate handling, validation recovery,
modal form ownership, table state and navigation. Actual permitted checks:

- `php -l` on the roster action/controller, `TableColumns.php` and `routes/web.php`:
  all four reported no syntax errors.
- `node --check` on `roster-scholar-selection.js`, `record-form-dialog.js`,
  `data-tables.js` and `app.js`: completed without syntax errors.
- `git diff --check`: no whitespace errors, only existing CRLF/LF notices.

Required developer asset command:

```powershell
npm run build
```

The build was **not run** in this task. No migration is required for Phase 2.
No automated tests were created/executed; no application boot, Blade rendering,
manual/browser testing, sample records, database queries/writes, seed, reset or
email operation was performed. Static checks do not prove functional correctness.

## Beginner manual verification — all NOT TESTED

Use existing authorized development accounts and isolated fictional profiles only.

1. Rebuild assets. Open **Information Registry → Batch**, verify the working name
   preview/creation, existing filters, details and historical names. Open **Roster**
   and confirm it lists the same batch IDs without a duplicate creation form.
2. Open an existing fictional roster. Check name/program/decimal batch number where
   available, period, total member count, course/year/status and authorized View links.
3. Click **Add scholars**. Search by Student ID and full name; filter by course.
   Select some profiles, navigate candidate pages/change size/sort, and search again.
   Verify selected IDs/count/date remain, including off-page selections. Select-all
   must affect displayed eligible rows only, with unavailable/already-member rows disabled.
4. Include one new-assignment profile and one continuing profile. Omit the new start
   date and expect an error. Enter its actual fictional date, review, go Back, and
   confirm. Expect one new pending assignment plus the necessary memberships; the
   continuing award's original starting period/date/status must remain unchanged.
5. Try an already-member selection, missing ID, ambiguous/out-of-coverage assignment,
   duplicate IDs and a direct invalid submission in isolated data. Expect a clear
   error, reopened modal/values and no partial assignments/memberships/audit inserts.
   Repeat a completed submission and confirm no duplicates.
6. Have another authorized development session change eligibility/membership between
   review and confirmation. Confirm the server rejects invalid stale selections
   transactionally. No real student data should be used for this scenario.
7. Exercise Cancel, close icon, Escape, discard confirmation, focus/Tab navigation
   and reopening. Check mobile layout, table scrollbars/sticky headers, page controls
   and hover/touch navigation. Confirm selection cancellation submits nothing.
8. As Registrar, verify read access and absent Add scholars action. As Student or
   unauthorized role, verify direct roster/bulk requests are denied. Confirm the
   existing individual profile assignment flow and relevant history still work.

## Limitations

### Modal reopening correction

Static review of the reported close/reopen loop found that shared modal cancellation
dispatched synthetic `change` events to every select, including Rows per page.
Its existing onchange handler navigated while `add=1` was still in the URL, causing
the server to reopen the modal. Cancellation now notifies only Alpine-model fields
and preserves table page-size values without emitting their navigation events.
The clean-form baseline is refreshed after selection cleanup so a fresh reopening
does not inherit a stale unsaved-change warning.
Roster saving, validation recovery and deliberate search/paging navigation are unchanged.
JavaScript syntax/whitespace checks were performed; no functional tests were run.
Developer verification remains: close with Cancel, × and Escape after opening from
Add scholars, after filtering/paging, and after a validation error. Repeat with page
size changed; expect no reload/reopen on close, cleared selection and normal reopening
only through Add scholars. Confirm successful saving returns to the closed roster page.

Selections are bounded to 200 per atomic request; starting date is shared only by
new assignments in that request. Search/filter navigation reloads the server-rendered
modal with its selection state rather than adding a new API/library. Native dialogs
and the existing JavaScript bundle are required. Historical roster corrections,
bulk award activation and automatic carry-forward are not introduced. Database
concurrency, UI rendering and all manual scenarios remain unexecuted by the agent.

**IMPLEMENTED — PENDING MANUAL VERIFICATION**
