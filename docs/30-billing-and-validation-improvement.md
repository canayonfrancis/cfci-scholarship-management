# Phase 3 — Billing and Validation

2026-11-19 (Asia/Manila)  
**IMPLEMENTED — PENDING MANUAL VERIFICATION**

## Implemented workflow and reuse

Reuses `BillingBatchController`, `ManageBillingBatch`, `BillingRoster`,
`BillingEligibility`, existing roster/application/academic relationships, approved
rate revisions, item snapshots, policies, audit logging and shared modal/table UI.
No new service, dependency, table, migration or database status was introduced.

- **Billing and Validation → Billing batches** (`/billing-batches`) now has a
  top-right **Create billing** action. Search by billing batch, roster name or
  reference supplements the existing scope/status/Batch Number filters.
  Records follow the toolbar. Budget overview and authorized rate/budget forms
  are collapsed disclosures; existing financial configuration/history remains.
- The creation modal selects **one existing roster in an open period**. It shows
  its stored name, original numeric label when the generated name can be matched
  exactly, program, academic year, semester, member count and approved semester
  rate. Historical names are not guessed or renamed. No repeated scope selection.
- `POST /billing-batches` derives program/period from the roster, checks any
  legacy supplied scope for compatibility, and rechecks the locked open period,
  roster and approved positive rate. It creates only the existing financial draft
  header, not another scholarship roster, student or assignment. Validation
  returns the modal with a named error bag and entered values.
- The draft opens its **Roster validation workspace** automatically. Every member
  is retrieved, including members without evaluations and those already billed or
  reserved. Columns show ID, name, course, year, scholarship status, latest verified
  enrollment result, eligibility, availability, reason and read-only draft amount.
  Evaluation details and scholar links respect their existing view permissions.
- Whole-roster counts show total, eligible/available, pending review, excluded and
  reserved. Reservation takes precedence in these mutually exclusive counts;
  current eligibility remains separately visible. These are presentation labels,
  not stored statuses or an official payable classification. Counts precede search
  and table filters. Existing item history retains its recorded amounts separately.
- Tables reuse onchange **10/20/50/100/200** rows per page above the table,
  independent scrolling, sticky sortable headers, and pagination below.

## Bulk selection, amounts and integrity

On an editable draft, select individual eligible rows or **Select all displayed
eligible scholars**, then **Review selected scholars**. Review lists their IDs,
names, amounts, roster/scope and total additions. Confirm the review before saving.
Selection is deliberately page-local, capped at 200; navigation/filter/sort changes
clear unsaved selections. Save each reviewed group before navigating. The stored
draft total is separate from the unsaved selection preview.

`POST /billing-batches/{batch}/items/bulk` (`billing-batches.items.bulk`) authorizes
the existing update policy, validates distinct application IDs, review confirmation
and lock version, and calls `ManageBillingBatch::change(..., 'add_many', ...)`.
Single-item and bulk additions share the same creation loop and eligibility basis.
One transaction rechecks all selected members/assignments, current evaluations,
verified enrollment, semester external approvals and evidence before success.
It retains existing student/context/program/period/batch/assignment/membership
locks, obtains selected application locks in student order and checks the version
after locking the header. A failing member rolls back the entire group's new
items, version change and audit entries; previously saved items remain unchanged.

The existing cross-program **unique student/academic-period billing constraint**
and reservation check remain. Stale forms, repeat submissions, conflicting bills,
nonmembers and wrong assignments cannot create a second ordinary semester item.
No rates or totals are accepted from the browser. Amounts use the batch's pinned
approved exact-semester rate and existing entitlement ceilings/snapshots; preview
addition uses integer centavos. Existing independent approval, budget checks and
authorized adjustments remain intact.

TES/TDP/CoScho can display configured approved fixed rates; this does **not** waive
program eligibility rules. `OfficialRuleCatalog::forProgram` still lacks approved
CoScho rules, so that program can remain pending review. EDUKAR is explicitly
blocked from fixed-rate draft/item preparation: its individual variable entitlement
formula/authority is unavailable. No official amount or variable formula is guessed.

Existing billing permissions remain: Registrar, Coordinator, Administrator and
retained Finance compatibility can prepare editable drafts; existing read roles
retain access. Only Coordinator/Administrator can configure rates and budgets.
Existing independent approval restrictions and Student access denial remain.

## Fixed-rate reference confirmation correction

Static inspection found that `ScholarshipProgram.school_stated_semester_amount`
(the school-stated reference) and `ProgramPeriodTerm.rates` (approved exact-period
revisions) are separate sources. Configuring the program amount does not approve
any semester rate. The former modal omitted the reference amount and displayed one
unhelpful unavailable message for missing rates, inactive programs and variable
programs. The lookup already matched `program_id` **and** `academic_period_id`;
no incorrect scope join was found. `BillingBatchOptions` filters existing billing
headers and does not supply or approve amounts; it needs no correction.

The reported CoScho PHP 45,000.00 is user-provided context, not a hardcoded amount
or independently inspected database value. Per the static-only restriction, no
live rate rows or migration state were queried. An actually absent approved rate
cannot be certified from the old message alone. The revised UI distinguishes these
conditions using current records when the developer opens the page.

- The modal and draft creation select the **latest positive approved rate** for
  the exact program/semester, using the existing rates relationship. Existing
  approved rates are displayed read-only and need no repeated confirmation.
- TDP, TES and CoScho are identified by `ScholarshipProgram::usesFixedSemesterRate`.
  Their configured reference amount is separately displayed read-only, including
  when no semester rate exists. EDUKAR cannot use this confirmation path; unknown
  programs retain existing explicit rate setup without receiving a guessed formula.
- An authorized Coordinator/Administrator sees a compact **School rate approval
  reference** and confirmation checkbox in the same creation modal when needed.
  **Confirm semester rate** posts to the existing `billing-batches.terms` route
  and `ManageBillingBatch::configure` action, not a new module or approval stage.
  Registrar/Finance users receive a clear instruction to obtain that confirmation.
- The action derives scope from the roster, locks the program/period/roster and
  existing terms, requires an open period, active fixed program, positive configured
  amount, actual approval reference and explicit confirmation. It reads the amount
  from the locked program record. A submitted preview amount is only a stale-value
  check; altered billing amounts are not used. A changed reference amount, stale
  rate revision or concurrent/repeated confirmation fails without a partial approval.
- Existing approved rates cannot be replaced through this shortcut. Intentional
  rate revisions still use the existing authorized configuration disclosure.
  Success reopens the same roster with its approved rate and **Create draft and
  review roster** enabled. Rate confirmation itself does not create a billing draft.
- Existing item creation/bulk totals continue using `BillingEligibility::basis`,
  eligible selection and backend-generated pinned rate snapshots. Unverified,
  excluded and reserved members cannot contribute new draft items. Reference changes
  never rewrite confirmed rates, item history or prior draft rates. Budget checks,
  academic/external approvals and the Form 2/Form 3 submission block remain intact.

This correction affects only `ScholarshipProgram.php`, `ManageBillingBatch.php`,
`BillingBatchController.php`, `resources/views/billing/index.blade.php` and this
report. It creates no database changes, routes, services, dependencies or JS modules.

Developer manual checks for this correction (**NOT TESTED**):

1. As Coordinator, open **Billing and Validation → Billing batches → Create billing**.
   Choose an open-period CoScho roster with a configured reference and no approved
   rate. Compare the read-only reference against Scholarship Grant / Program.
2. Enter the real approval reference, check confirmation and click **Confirm semester
   rate**. Expect the same modal/roster with an approved rate and the creation action;
   confirm that no draft exists until **Create draft and review roster** is submitted.
3. Reopen a roster already having an approved rate. Expect automatic retrieval and
   no confirmation request. As Registrar, a missing rate must explain the required
   Coordinator/Administrator action and deny a direct confirmation request.
4. In isolated data, verify missing references, unchecked confirmation, zero/missing
   program amounts, closed periods, EDUKAR, altered preview values, concurrent/repeat
   confirmation and a reference changed in another tab all fail safely where applicable.
5. Create a draft and review eligible selections/total. Verify unavailable members,
   duplicate reservations, approval/budget checks and the final submission block.
   Change the program reference separately and confirm existing rates/drafts/items
   retain their original amount. CoScho eligibility rules remain a separate blocker.

No new developer command or migration is required for this PHP/Blade correction.
If views are cached, the developer can run `php artisan view:clear`. The initial
Phase 3 asset build requirement below still applies if those assets are not built.

Follow-up static review, 2026-11-20: rechecked the modal's confirmation/creation
targets, named validation errors, same-roster reopening, authorization, reference
amount comparison, duplicate approval prevention and pinned-rate creation path.
No further application change was identified as necessary. Functional verification
remains NOT TESTED; the existing CoScho eligibility and final-classification blockers
are unchanged. No database operation, migration, build or functional test was run.

## Reasons and unresolved completion gates

- Existing **Scholars → scholar → Update scholarship assignment** offers active
  Reason for Delisting entries as optional text suggestions when choosing an ended
  or relinquished status. The wording is copied into the existing reason/reference
  field and saved by the unchanged authorized status-change action and audit.
  There is no new reason FK, mandatory reason taxonomy or automatic transition.
  Temporary semester exclusion never closes an assignment. Approved linkage of
  temporary exclusion to permanent delisting remains undefined and is not invented.
- **Academic source blocker:** operational routes have only retained academic
  certificate download and context configuration; they provide no current entry,
  draft update, verification or correction form for enrollment/subject grades and
  supporting certificates. `SaveAcademicVerification::handle` and
  `AcademicRecordPolicy` remain available, but no removed module is restored here.
  The smallest proposed follow-up, subject to approval, is one roster-scoped
  **Record/verify academic source** modal in Billing and Validation for the existing
  Registrar/Administrator policy. Reuse that action, grade rows, private documents,
  verified actor/time, lock version and immutable correction revisions, deriving
  student/period from the roster and using the configured grading-source period.
  Until approved and implemented, missing records remain unverified; assessments
  and approvals alone cannot manufacture enrollment, grades or certified GWA.
- **Final submission remains BLOCKED.** `BillingRoster::submissionClassification`
  is unchanged and still rejects submission because approved versioned Form 2/Form 3
  templates, payable/excluded mappings and current-source classification are absent.
  The UI explains this and disables final submission. Direct requests retain the
  backend guard and transaction rollback. Draft creation/addition is not completed
  billing, Coordinator approval, receipt or payment.

## Files and static verification

Changed:

- `app/Models/ScholarshipProgram.php`
- `app/Actions/Billing/ManageBillingBatch.php`
- `app/Http/Controllers/BillingBatchController.php`
- `app/Http/Controllers/ScholarshipAssignmentController.php`
- `app/Services/Billing/{BillingBatchOptions,BillingEligibility,BillingRoster}.php`
- `app/Support/TableColumns.php`
- `routes/web.php`
- `resources/views/billing/{index,show}.blade.php`
- `resources/views/assignments/form.blade.php`
- `resources/js/{app,billing-batch-filter}.js`
- New `resources/js/billing-roster-selection.js` and this report.

Pre-existing modal-close corrections in `resources/js/record-form-dialog.js` and
`docs/29-batch-roster-and-bulk-scholarship-assignment.md` were preserved.

Executed **static checks only**, with no application bootstrap or DB connection:

- Initial Phase 3 `php -l <file>`: all eight initial PHP source/route files passed.
  The amount correction additionally checks the three changed PHP files with
  `php -l` (model, controller and billing action); all passed.
- `node --check <file>`: `app.js`, `billing-batch-filter.js` and
  `billing-roster-selection.js` passed.
- `git diff --check`: passed; Git reported line-ending normalization notices.

No automated tests were created/executed; no browser/manual testing, Blade runtime
render, production build, migration, seed, sample record, import, email or live
database operation was performed. No functional pass is claimed. Readiness is
calculated from the whole roster on each view using existing eligibility/evidence
checks; large-roster runtime performance has not been measured.

Developer command: run **`npm run build`** (or use the existing `npm run dev`
development server) to load the changed Alpine components. No Phase 3 migration
is required. Apply any previously pending migrations only through the existing
approved deployment process; no migration was executed here.

## Developer manual verification — all NOT TESTED here

Use an isolated development database and existing authorized test accounts/data.
Do not use real financial records for negative tests.

1. Log in as Registrar. Open **Billing and Validation → Billing batches**.
   Check search, filters, headers, scroll, size changes and pagination; verify that
   budget/configuration disclosures preserve existing history and role visibility.
2. Open **Create billing**; try Cancel, Escape, reopening and an invalid submission.
   Choose an existing open-period roster. Check its name/number/program/year/semester,
   member count and approved rate without reselecting scope. Create the draft.
3. Confirm the header links the original roster and no memberships/assignments are
   added or replaced. Check all workspace columns/counts against isolated records,
   including missing evaluations, latest unverified enrollment, verified not-enrolled,
   inactive assignments and existing cross-program semester reservations.
4. Follow **Evaluation details** as an authorized reader. Missing source operations
   must not appear as completed verification. CoScho without approved rules and
   EDUKAR without variable entitlements must not become eligible automatically.
5. Select eligible displayed rows. Review IDs, amounts, count and total; go back,
   change the selection and review again. Confirm and save. Compare the saved item
   snapshots and prepared total to the pinned approved rate. Change the current rate
   through authorized setup and confirm existing draft/history rates remain pinned.
6. In isolated data, submit a selection containing a nonmember, duplicate ID,
   wrong assignment, already-reserved or newly ineligible student; also retry the
   old lock version from another tab. Expect rejection with no partial new items
   or successful audit entries. Try simultaneous overlapping reservations across
   batches/programs and check that only one student/semester reservation survives.
7. Try creating through an authorized direct POST with a closed period's roster,
   mismatched supplied scope, missing/unapproved rate or EDUKAR fixed rate. Expect
   no new draft. Check closed-period history and existing rate/budget history remain.
8. As Student/unauthorized staff, try direct billing creation and bulk POSTs with
   CSRF protection respected. Expect authorization denial. Verify retained read-only
   roles cannot add items and Registrar cannot configure rates/budgets.
9. Confirm **Final submission unavailable** explains Form 2/Form 3 requirements.
   A direct authorized submission with otherwise complete prerequisites must still
   fail classification and retain the draft/items without partial finalization.
10. As an authorized scholarship writer, open a scholar's editable assignment,
    select an existing permitted closure status and an active reason suggestion;
    add a supporting reference and save only in isolated data. Confirm existing
    required reason/closing-period/history guards. Not-enrolled or failed academic
    results must never automatically perform this closure.
11. Check developer-verified Phase 1/2 naming, continuing assignments, roster bulk
    selection/modal close, horizontal hover/touch navigation, existing billing
    approval/adjustment history and historical financial links for regressions.
    Record actual outcomes separately before accepting Phase 3.
