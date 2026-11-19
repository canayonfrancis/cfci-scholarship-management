# Academic grade requirements removal

2026-11-20 (Asia/Manila)  
**IMPLEMENTED — PENDING MANUAL VERIFICATION**

## Adviser requirement and implemented scope

The adviser removed internal academic-grade requirements from the current
scholarship evaluation, activation and billing workflow. This supersedes the
grade-entry/grade-policy blockers described in earlier implementation reports,
including document 32. Enrollment and other applicable scholarship requirements
remain independent requirements; enrolled does not mean payable.

- Removed grade-source selection/confirmation, subject grade/computation checks,
  percentage passing rules, provisional/certified GWA, minimum-GWA criteria,
  failed/INC/dropped-subject assessment, grade-derived standing and program grade
  policy prerequisites from **new assessments and current-decision validation**.
  Removed checks are absent, not recorded as fabricated passes.
- Evaluation now has exactly **Overview, School compliance, Supporting evidence,
  External approval, Award activation, History**. Current Registrar enrollment is
  prominent; progress starts with enrollment verification. Grade summaries, missing
  grade/policy warnings and the Academic records tab are removed. Obsolete links
  now lead to Overview. Old grade checks remain only in read-only History.
- Program academic policy configuration is retired, including its POST route.
  The former controller also rejects stale cached write routes with 404.
  The program page retains a collapsed **Historical academic policy versions**
  disclosure with read-only original versions. Program amounts, availability and
  assignments are unchanged.
- Semester validation configuration now selects only the **active validation
  period**, preserving authorization, optimistic revision and audit. Legacy stored
  source-period/reference/confirmation fields are left untouched for history and
  are not required by new assessments. Open/Closed availability remains separate;
  new ordinary billing still requires an open period.

## Backend workflow and historical preservation

`SaveAcademicVerification::enrollment` → `EvaluateApplication` →
`EvaluationRun::isCurrent` → existing `ActivateAward`/`BillingEligibility::basis`.

1. Registrar/retained Administrator records an explicit Enrolled, Not enrolled or
   Unverified finding with the existing source reference, optional private proof,
   bulk review, membership checks, version checks, transaction and audit.
2. Enrollment saves append an **enrollment-only revision**, even when the previous
   source contains grades. Existing records, grade rows, standing, GWA, certificates
   and verification metadata are not rewritten or promoted. New revisions contain
   enrollment and existing identity metadata; no subject rows or academic results
   are manufactured. Corrections of verified/rejected sources still require a
   reason. Unverified remains pending without a verification actor/time.
3. Authorized evaluators use the existing school-compliance form. New snapshots
   include enrollment, the active period/revision, applicable official rules, facts
   and evidence, with scope **`enrollment_school_compliance_v2`**. They do not pin a
   grade record, grading-source context, grading-policy result or academic policy.
4. `isCurrent` requires this scope in both input/rule snapshots, matching current
   active context, current assessment/submission revision, unchanged award conflict,
   usable evidence and the latest verified enrollment source. All legacy-scope runs
   require explicit reassessment; their decisions/snapshots remain unchanged.
5. Activation still needs a pending award, active scholar/program, free active-award
   slot, current qualified evaluation and verified exact-award/semester external
   approval. It explicitly checks Registrar-verified enrolled status. Billing still
   checks active assignment/coverage, membership, enrollment, current decision,
   external approval, approved pinned rate, entitlement limits and duplicates.

Existing admission/retention, citizenship, HEI recognition, assistance/SMART checks,
duration/exception review, required evidence, external authority/date checks,
permissions and audit remain. No account gains automatic activation or payability.
Not enrolled stays unavailable for ordinary semester billing without terminating
an assignment or deleting membership. Financial amounts, budgets, duplicate
constraints, receipt/payment/liquidation/report logic and email are unchanged.

History retains original academic source revisions, reported subjects/certificates,
old policy versions and evaluation snapshots. Original values are displayed read-only
without recalculation. Legacy calculation/record helpers remain for retained tooling;
the operational evaluator/presenter no longer invokes them. No historical decisions
are rewritten, no rows deleted and no migration introduced or executed.

## Official-rule conflict and remaining blockers

`OfficialRuleCatalog::FACTS['retention']` still requires **HEI-certified admission/
retention compliance**, with a real source. The embedded TDP/TES catalog contains
no numerical grade/GWA threshold to retain as a separate system calculation.
However, an institutional/provider retention rule may impose academic conditions;
the adviser change cannot silently waive a mandatory external rule. The compliance
screen and historical policy disclosure flag this for confirmation. Do not certify
that fact from the adviser instruction alone; keep it Unverified until the actual
applicable requirement/source is confirmed. No external circular or live stored
policy was independently reviewed in this static-only task, so no external waiver
or blanket absence of academic obligations is claimed.

CoScho/other unsupported official rule sets still require approved definitions;
EDUKAR variable entitlement remains unsupported. Missing enrollment, other required
facts/evidence, current evaluation, active assignment or external approval can still
block billing. **Form 2/Form 3 final submission remains blocked** pending approved
templates/classification. Grade removal does not remove these restrictions.

## Files and developer commands

Modified:

- `app/Actions/Eligibility/{EvaluateApplication,ActivateAward}.php`
- `app/Actions/Records/SaveAcademicVerification.php`
- `app/Models/EvaluationRun.php`
- `app/Services/Eligibility/EligibilityEvaluator.php`
- `app/Services/Billing/BillingRoster.php` — no grade-count dependency.
- `app/View/EvaluationWorkspace.php`
- `app/Http/Controllers/{ScholarshipEvaluationController,BillingBatchController,AcademicPeriodController,ScholarshipProgramController}.php`
- `app/Http/Controllers/AcademicRequirementController.php` — retired write guard.
- `app/Http/Requests/AcademicContextRequest.php`, `routes/web.php`
- `resources/js/billing-workspace.js`
- `resources/views/evaluations/show.blade.php` and its
  `partials/{overview,school-compliance,supporting-evidence,history}.blade.php`
- `resources/views/components/{academic-context-form,program-academic-requirements}.blade.php`
- `resources/views/periods/index.blade.php`
- `resources/views/billing/partials/{enrollment-dialog,roster-validation}.blade.php`

Removed the unused `resources/views/evaluations/partials/academic-records.blade.php`
template. Added this report. No new tables, services, dependencies or approval stages.

Developer: **`npm run build`** or the existing **`npm run dev`** server loads the
updated enrollment script. **`php artisan optimize:clear`** clears stale compiled
routes/views if cached. No migration is required. Commands were not run here.
Existing grade-focused automated expectations will need review when testing is
authorized; no test files were changed or tests created/executed in this task.

Static review traced the source-to-decision-to-activation/billing path. `php -l`
passed for the 14 changed PHP action/controller/model/service/request/route files;
`node --check resources/js/billing-workspace.js` and scoped `git diff --check` passed
(line-ending notices only). No app bootstrap, build, Blade render, automated/manual/
browser test, database query/write, fixture, seed, reset or migration was performed.

## Manual verification — all NOT TESTED

Use isolated development data and existing authorized accounts.

1. In Semester, save an active validation period without a grading source/reference.
   Confirm existing dates, academic years, Open/Closed behavior and new-billing
   closed-period rejection remain. Previously stored source context is preserved.
2. As Registrar, record individual/bulk enrollment findings in Roster Validation.
   Try a member whose prior pending/verified source contains grades: a new enrollment
   revision must preserve the original grades, source flags and files without copying
   them into invented current academic results. Check stale/invalid selections and
   unauthorized requests still fail, with no partial saves.
3. As Coordinator, open an evaluation with no subject grades, GWA, grade policy or
   historical grading source. Confirm the six tabs and enrollment-based progress.
   Grade-related checks must be absent, while missing enrollment/facts/evidence or
   official rules must still prevent a qualified result.
4. Reassess a legacy run using the existing evaluation action. Confirm old snapshots
   and decisions are unchanged in History and the new scope is recorded separately.
   Its result must depend on actual remaining requirements, not an automatic pass.
5. With otherwise complete applicable TDP/TES prerequisites, review explicit award
   activation and draft billing. No missing-grade prerequisite should remain. Then
   vary enrollment, external approval, active-award conflict and evidence to confirm
   the existing restrictions. Do not assume CoScho/EDUKAR rules are supported.
6. Inspect program amount/assignment management and read-only policy history. The
   retired academic-policy POST and controls must be unavailable. Check historical
   Registrar sources, reported grades, certificates and previous evaluations remain
   readable in History, without recalculating or rewriting them.
7. Compare pinned rates, totals, reservations, existing financial history and the
   Form 2/Form 3 final-submission block. Verify enrolled alone cannot activate or
   bill, and Not enrolled cannot be ordinarily billed. Record actual results before
   acceptance; no functional pass is claimed here.
