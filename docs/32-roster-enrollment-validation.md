# Roster enrollment validation - Phase 3

2026-11-20 (Asia/Manila)
**IMPLEMENTED — PENDING MANUAL VERIFICATION**

## Implemented workflow and authorized accounts

**Billing and Validation > Billing batches > existing draft > Roster Validation.**

1. Registrar or the retained Administrator exception selects **Verify enrollment**.
   Review the displayed roster members and source/revision history. The toolbar
   starts unselected; a row action selects that named scholar. Identity and period
   come from the existing roster. No outcome defaults to Enrolled.
2. Select only scholars checked against actual Registrar records. Choose **Enrolled**,
   **Not enrolled** or **Unverified**, supply the actual reference and optional notes,
   and confirm. Each reviewed group uses one finding/reference; use separate groups
   for different findings/sources. Optional PDF/JPG/PNG evidence is limited to 2 MB.
   Corrections of verified/rejected records require a reason.
3. Save to reload the same billing draft with current enrollment, readiness results
   and whole-roster counts. Errors reopen the modal; attachments must be reselected.
   The modal follows the displayed roster page/search/filters, up to 200 members.
4. Follow **Evaluation details** for an existing assessment. A missing assessment
   now offers **Start evaluation** to Coordinator/Administrator users. This opens
   the existing form with program, semester and exact assignment preselected;
   **Open validation** uses the existing POST and assessment-reuse behavior.

Enrollment requires both existing billing view and academic-record write permission.
Registrar can view existing assessments but cannot start/evaluate them or activate
awards; those actions retain Coordinator/Administrator authorization. Finance,
Viewer and Student gain no enrollment/evaluation write access. Server-side gates,
CSRF and the existing authenticated route group remain; buttons are not permission
boundaries. The prefilled evaluation GET validates program/assignment and refuses
an academic period different from the active validation context instead of silently
substituting another semester. Existing POST-time checks remain unchanged.

## Reused functionality and preservation

Reuses `AcademicRecord` and its grade/document/revision relationships,
`AcademicRecordPolicy`, `SaveAcademicVerification::handle` and its enrollment group
method, protected certificate storage/download and audit logging; `BillingRoster`,
`BillingEligibility`, `ScholarshipEvaluationController`, `EvaluateApplication` and
`ActivateAward`; the shared modal, standardized tables and existing three tabs.
The enrollment operation is reused, not rebuilt in this follow-up. No UI redesign.

- Membership, source IDs and versions are rechecked under locks. Group saves are
  transactional; failure rolls back new records/audits and cleans up new files.
  Existing files are not deleted. Stale, duplicate, missing and nonmember selections
  fail. Student/period/grade/GWA/actor fields cannot be forged through this form.
- New enrollment sources fabricate no grades, standing or certified GWA. Unverified
  remains pending without a verification actor/time. Known findings use the existing
  verified actor/time/audit mechanism. Verified/rejected sources receive correction
  revisions: original records, references, grades and certificates remain intact.
- The shared verification flag covers the whole academic revision. A mixed pending
  source with unverified grades, standing, academic certification or non-enrollment
  evidence remains blocked from enrollment-only certification. Enrollment-only
  pending sources with enrollment attachments can later be verified normally.
  New enrollment evidence uses the existing document table/private download path;
  it does not automatically approve application-level evidence or external approvals.
- Not enrolled remains unavailable for ordinary semester billing. Reason text now
  explicitly identifies temporary exclusion and preserved assignment/roster history.
  Reservations retain the current validation explanation as well. No approved rule
  currently links this finding to permanent delisting, so enrollment notes are used;
  the registry remains in the existing authorized scholarship-closure workflow.
- Pending award reasons now identify the existing school evaluation, verified
  external approval and **Award activation** actions. Enrollment alone never activates
  an award. Drafts, rates, amounts, bulk billing, reservations, budgets, financial
  history and the Form 2/Form 3 final submission guard are unchanged.

## Remaining workflow blockers

Activation still requires an active scholar/program, a current qualified evaluation,
confirmed applicable official/program rules, intact evidence and verified exact-award/
semester external approval. Another active award blocks activation. Source corrections
can stale earlier assessments under `EvaluationRun::isCurrent`; reassessment remains
necessary. These existing safeguards were inspected, not modified.

**Missing source-entry operation:** new subject grades, Registrar academic standing
and certified percentage GWA still lack an operational entry/correction form. The
smallest remaining operation is a Registrar-authorized source modal for the
**configured grading-source semester**, reusing `SaveAcademicVerification::handle`,
existing subject rows, actual certificates, references, certified-GWA reference,
optimistic versions and correction history. It must not use provisional calculated
GWA as official certification. This operation is documented only, as requested;
the removed standalone module is not restored. Mixed pending academic sources
also need that source-review operation.

The existing active/source-period context, confirmed program-period requirements
and actual sources must be ready. `OfficialRuleCatalog` provides TDP/TES rules;
CoScho rules and EDUKAR variable entitlement remain unresolved. **Final submission
remains blocked** by absent approved Form 2/Form 3 templates and current payable
classification. Assessment creation and enrollment do not bypass these blockers.

## Files, commands and static review

Enrollment implementation files retained in this phase:

- `app/Actions/Records/SaveAcademicVerification.php`
- `app/Http/Controllers/BillingBatchController.php`
- `app/Http/Controllers/AcademicCertificateController.php`
- `app/Services/Billing/BillingRoster.php` and `routes/web.php`
- `resources/js/billing-workspace.js`, `resources/views/billing/show.blade.php`
- `resources/views/billing/partials/{roster-validation,enrollment-dialog}.blade.php`
- `resources/views/components/{record-form-dialog,retained-academic-source}.blade.php`
- Scoped enrollment styles in `resources/css/app.css`.

This follow-up changes only roster reason/link presentation,
`app/Http/Controllers/ScholarshipEvaluationController.php`,
`resources/views/evaluations/create.blade.php` and this report. No new service,
validation engine, dependency, table, migration, tab or approval stage was added.

Developer: run **`npm run build`** or the existing **`npm run dev`** server if earlier
enrollment assets are not current; use **`php artisan view:clear`** for cached views
if necessary. Existing academic storage migrations must already be applied. No
new migration is needed or executed. These developer commands were not run here.

Verification is static only. No automated tests were created/executed, no manual/
browser testing, build or application bootstrap occurred, and no database query,
sample, seed, reset or record change was performed. Runtime results remain pending.

Actual follow-up checks: `php -l` passed for
`app/Http/Controllers/ScholarshipEvaluationController.php` and
`app/Services/Billing/BillingRoster.php`; scoped `git diff --check` passed with a
line-ending normalization notice only. Blade links/fields and the retained
enrollment, evaluation and activation code were reviewed statically, not executed.

## Manual verification - all NOT TESTED

1. In isolated data, log in as Registrar, open Roster Validation and record Enrolled
   for a reviewed group with an actual reference. Confirm updated findings/counts
   and the same billing draft. Pending assignments must remain pending.
2. Record Not enrolled for another group: ordinary billing must remain unavailable,
   with a clear reason and unchanged awards, roster membership and financial history.
   Save Unverified with an enrollment attachment, then review and verify that
   enrollment-only pending source later without losing its evidence.
3. Correct a verified finding with a reason. Check original/new revisions, references,
   certificates and academic data. Mixed unverified academic facts must not be
   certified by this shortcut. Try stale/duplicate/nonmember IDs and invalid uploads;
   expect no partial group saves and correct modal error recovery/file reselection.
4. As Coordinator/Administrator, follow Evaluation details for an existing record.
   For a missing assessment, use Start evaluation: check preselected program,
   semester and assignment, then Open validation. Repeat to check assessment reuse;
   a mismatched active period must fail clearly without substituting another period.
5. Review Academic records, School compliance, Supporting evidence, External approval
   and Award activation in the existing workspace. Missing grades/GWA/policy/evidence
   must remain review blockers. Only existing activation safeguards may activate.
6. Try unauthorized enrollment/evaluation POSTs as other roles; verify permissions,
   references and confirmation. Recheck billing totals, pinned rates, duplicate
   reservations, history and the unchanged Form 2/Form 3 submission protection.
   Record actual results separately; no functional pass is claimed here.
