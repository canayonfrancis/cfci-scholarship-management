# Phase 1 — Information Registry completion

2026-11-19 (Asia/Manila)  
**IMPLEMENTED — PENDING MANUAL VERIFICATION**

Phase 2 update: the newer requirement restores **Roster** as a separate membership
screen over the same Batch records. See `29-batch-roster-and-bulk-scholarship-assignment.md`;
this supersedes the single-menu navigation description below.

## Reuse and changes

- **Information Registry → Academic Year** now opens `/academic-years`: one grouped
  row per existing year, start/end years, semester/open counts, search and a link to
  manage that year's semesters. **Add academic year** uses the shared modal and
  existing academic-period endpoint to save start/end years **with the first semester**.
  The backend builds the year label; existing consecutive-year rules from 2007 onward
  remain. There is no duplicate year/period table or placeholder semester.
- **Information Registry → Semester** opens `/academic-periods`: filter by year,
  view existing semesters, add/edit code, dates and Open/Closed status in modals.
  Edit loads the selected record through an authorized GET and opens its modal on
  the listing; no AJAX dependency or separate edit service was added. Existing create/
  edit URLs lead to these modals. Validation returns entered values and field errors.
  Linked period identity stays locked. The existing active-validation/grading-source
  form is retained in a disclosure below the table with all historical periods.
- **D01/R07:** only the new-billing creation dropdown uses `creationPeriods` filtered
  by `is_open`. `ManageBillingBatch::create` checks the locked period before creating
  anything. Historical filters/reports/rate setup and grading sources retain their
  original choices. Existing draft operations and final submission guards are unchanged.
- **Batch:** the duplicate Roster child menu was removed from the shared desktop/mobile
  navigation. Batch opens the existing scholarship-batches page and stays highlighted
  throughout its index/detail routes, including old links with registry query values.
  Existing roster membership, routes, permissions and records are retained.
  The user enters a numeric batch label such as `1.0`; the readonly
  preview and locked backend generate `{Code}-{Number}-{Semester}-{Academic Year}`.
  The number remains text, including decimal notation. The existing `batch_number`
  column stores the generated full name; no new entity/column is needed. Scope
  uniqueness and the existing 60-character limit remain enforced. Historical names
  are untouched. No sequence/default number is introduced. Client preview values
  cannot override the server's name.
- **Information Registry → Reason for Delisting** replaces the unavailable link:
  search, status filter, add/edit modals and Active/Inactive status. Names are squished
  for whitespace and compared using a lowercase unique key, including inactive names.
  Transactions retain existing activity logging. No delete endpoint or official
  default reasons are added. No scholarship status/billing integration is performed.

The shared records page, record-field/modal components, table state/pagination,
colors, typography, horizontal navigation and hover/touch/keyboard menu behavior
are reused. New tables use existing onchange 10/20/50/100/200 sizes above the table,
independent scrolling/sticky headers and count/pagination below. No dependencies
or new service layer were added. Shared modal additions support PATCH, saving labels,
clean reset/preview synchronization and edit-dialog focus/URL recovery.

## Permissions and retained behavior

Academic Year/Semester reuse `AcademicPeriodPolicy`. Delisting reasons follow the
existing `RecordPolicy`: existing staff can read; Coordinator and retained Test
Administrator can create/update. Direct writes and edit-record selection are gated
server-side. Existing Registrar/Student/Finance/Viewer restrictions are retained.
All new routes are authenticated; CSRF and existing input validation remain.

Scholarship Grant / Program, Course / Program, Scholars, Bulk Upload Scholars,
Student Accounts, assignments, roster membership, evaluations, payments, liquidation,
reports, authentication and email have not been rebuilt. One numeric argument in
the existing guarded synthetic demo caller was adjusted for the new roster input
contract; no demo/seeder was executed. Existing tests with old free-text roster
creation payloads need developer review and were not edited or executed.

## Files

Modified:

- `app/Http/Controllers/AcademicPeriodController.php`
- `app/Http/Requests/AcademicPeriodRequest.php`
- `app/Http/Controllers/BillingBatchController.php`
- `app/Actions/Billing/ManageBillingBatch.php`
- `app/Http/Controllers/ScholarshipBatchController.php`
- `app/Actions/Records/ManageScholarshipRoster.php`
- `app/Support/TableColumns.php`
- `routes/web.php`
- `resources/views/layouts/navigation.blade.php`
- `resources/views/periods/index.blade.php`
- `resources/views/billing/index.blade.php`
- `resources/views/scholarship-batches/index.blade.php`
- `resources/views/components/record-form-dialog.blade.php`
- `resources/js/record-form-dialog.js`
- `database/seeders/SyntheticDemoSeeder.php` (one caller argument only)

Created:

- `app/Models/DelistingReason.php`
- `app/Policies/DelistingReasonPolicy.php`
- `app/Http/Controllers/DelistingReasonController.php`
- `resources/views/delisting-reasons/index.blade.php`
- `resources/views/delisting-reasons/fields.blade.php`
- `resources/views/periods/years.blade.php`
- `resources/views/periods/fields.blade.php`
- `database/migrations/2026_11_19_000002_create_delisting_reasons_table.php`
- This report.

## Database and developer commands

Only one migration is proposed: a new empty `delisting_reasons` table with name,
normalized unique name, active flag and timestamps. It does not touch existing
tables/data or seed reasons. Rollback refuses to drop a populated registry.
**It was not executed.** The registry shows a setup notice until its table exists.

After developer review/approval, apply this migration to the intended database:

```powershell
php artisan migrate --path=database/migrations/2026_11_19_000002_create_delisting_reasons_table.php
```

Rebuild the existing frontend bundle for the modal JavaScript changes:

```powershell
npm run build
```

Neither command above was executed in this task. No installation is needed.

## Static review and limitations

- Reviewed targeted source against report 27, including routes/policies, modal
  recovery, period locks, creation-only choices, generated-name persistence,
  normalized-name uniqueness, audit transactions and preserved table/nav code.
- `php -l` completed successfully for all **13 affected PHP source files** (including
  the new migration, without executing it). `node --check` passed for the modified
  modal script. `git diff --check` found no whitespace errors; only CRLF/LF notices.
  These are static checks, not functional verification.
- No automated tests were created/executed. No manual/browser testing, application
  boot, Blade rendering, build, database query, migration, sample-record creation,
  seeding, reset, email or production-record change was performed.
- **Standalone empty years and a year-wide availability flag are not supported by
  the current schema.** Creation therefore requires the first actual semester;
  availability is managed per semester. If independent years are subsequently
  required, the smallest proposed schema extension is one academic-years registry
  with unique year identity/availability linked to existing periods through a
  reviewed additive migration. That extension is not implemented or executed here.
- Existing year validation requires consecutive years. The request's illustrative
  `2025-2027` span is not used to relax that rule. Name generation uses the exact
  selected period: a normal configured `2025-2026` with code `MD`, number `1.0`
  and semester `1` yields `MD-1.0-1-2025-2026`. Retained historical labels are not
  rewritten. Semester codes remain institution-entered; no summer/mid-year defaults
  or eligibility policies are invented.
- Roster batch numbers are entered textual numeric labels, not billing numbers.
  Different textual notation is not silently converted into the same number.
  Names exceeding the existing column length are rejected rather than truncated.
- Delisting reasons are a registry only. Assignment linkage and historical reason
  snapshots belong to the explicitly deferred later integration phase.
- Previous audit D02/stale test contracts and academic/classification business
  blockers are outside Phase 1. The existing billing submission block remains.

## Shared modal close-handler correction

The reported `this.$el.close is not a function` error came from the shared dialog
script using Alpine's event-context `$el` as the dialog. In a button click handler
that element is the button. The installed Alpine `$el` magic/evaluator source
confirms this context behavior. `record-form-dialog.js` now captures the native
dialog during initialization and uses that stable reference for open/close, open
state, trigger lookup and focus trapping. Existing cancellation confirmation,
reset, scroll restoration and submission behavior remain.

JavaScript syntax and whitespace checks were performed; no functional test was run.
When using Vite development mode, refresh the page to load the updated module. For
built assets, run the existing `npm run build` command before refreshing.

Developer checks still pending: open Create scholarship batch and use Cancel,
the close icon and Escape; repeat after changing a field, confirming or declining
discard; check reopening, restored background scrolling/focus and Tab/Shift+Tab
through the nested roster fields. Repeat for the shared registry dialogs.

## Manual verification — developer to perform; all NOT TESTED

Use isolated fictional data and existing authorized development accounts only.

1. After reviewing/applying the new migration and rebuilding assets, sign in as an
   existing Coordinator or Test Administrator. Open **Information Registry →
   Academic Year**. Check distinct grouped years/search/counts. Add a fictional year
   with consecutive start/end years and its first institution-approved semester code.
   Verify one period is created; duplicate year/semester and invalid ranges fail.
2. Open **Semester**, select the year and add a different approved semester. Edit
   dates and Open/Closed status. Check date validation, entered-value recovery,
   Cancel/Escape, dirty-form confirmation, focus restoration and responsive scrolling.
   Existing in-use year/code must remain protected.
3. Expand the retained validation/grading-source disclosure. Confirm historical/closed
   periods remain selectable where previously allowed. Inspect existing period
   detail/history and reports; do not change actual academic decisions to test layout.
4. Open **Billing and Validation → Billing batches**. New-draft choices should list
   only open periods, while historical filters still include closed ones. In isolated
   data, attempt a direct authorized new-draft POST for a closed period: expect a
   validation error and no draft/audit insert. Existing drafts and final classification
   safeguards must retain their prior behavior.
5. Confirm Information Registry contains **Batch** and no separate **Roster** item
   on desktop/mobile. Open **Batch → Create batch**. Select a program,
   academic period and number `1.0`. Verify the readonly preview, then the saved name
   and duplicate rejection. Try invalid number text, an overlong generated name and
   a forged preview value. Expect rejection or server-derived name; no historical
   roster renaming or automatic number assignment. Cancel/reopen should reset preview.
6. Open **Reason for Delisting**. Add only a clearly fictional development reason,
   search it, edit it and make it inactive. Check duplicate names using case/spacing
   variations and an inactive duplicate. Expect field errors and no duplicate record.
   Confirm there is no permanent delete or automatic scholar/billing status change.
7. As Registrar/read-only legacy staff, confirm permitted registry reading and denied
   writes under existing policies. As Student, try direct registry/write URLs and
   expect denial. Do not assign roles or create privileged accounts for this check.
8. Across the new/retained lists, verify header sorting, onchange page sizes, search
   filters, count/pagination, independent scrolling, sticky headers and horizontal
   hover/touch navigation. Review the existing scholar/account/roster and historical
   financial links for regressions. Record actual results; none are claimed here.

**IMPLEMENTED — PENDING MANUAL VERIFICATION**
