# REC-01 — Academic grade computation and validation

Date: 2026-11-19 (Asia/Manila)

**IMPLEMENTED — PENDING MANUAL VERIFICATION**

## 1. Executive summary

Implemented percentage-based four-period subject averages, exact 75% subject-result
classification, reported/computed reconciliation, provisional unit-weighted GWA,
subject summaries and versioned program-period academic requirements. Academic
Verification and Evaluations reuse these server calculations. Registrar standing,
reported grades, academic revisions, official semester rules, external approval,
award activation and financial history retain their separate authority.

No tests were created or executed. No manual/browser functional testing, record
creation, grade/decision rewrite, migration, seed, reset or email operation was
performed. Only source/schema inspection, syntax/format checking, static Blade
compilation without rendering, JavaScript syntax checking and asset compilation
were performed. The new migration must be reviewed and applied by the developer.

## 2. Existing grading implementation and inspected evidence

Reviewed AcademicVerificationController/Request, SaveAcademicVerification,
AcademicRecord/AcademicRecordGrade, AcademicValidationContext, ScholarshipProgram,
ProgramPeriodTerm, ScholarshipProgramController/Request, SchoolGradingPolicy,
EligibilityEvaluator, EvaluateApplication, EvaluationRun::isCurrent(), ActivateAward,
existing policies, routes, forms/tables, and relevant migrations.

Also reviewed `docs/sms/eligibility-rules.md` and existing scholar registration,
program, academic verification and validation workflow documentation.
`docs/18-scholarship-processes-validation-audit.md` was not present; no findings
are attributed to that missing report.

Read-only metadata inspection of the configured MySQL database confirmed:

- `academic_record_grades` already stores components, reported `average`, units
  and `equivalent` as nullable `varchar(30)` strings, with subject identity/remarks.
- `academic_records` already has revision/lock version, manual standing, enrollment,
  verification actor/date, source reference, certificates and a legacy decimal
  `grade_average`. Its scale is not evidence of an official percentage GWA.
- Verified grade rows and verified academic revisions have existing immutability
  guards; correction creates a new revision.
- `program_period_terms.criteria` is an already-confirmed, immutable official-policy
  snapshot. It cannot be repurposed for independently revised academic requirements
  because official catalog equality and activation rely on it.
- `evaluation_runs` stores source IDs, input/rule snapshots, outcomes and evaluator
  metadata. Existing source/context/currentness checks already reject superseded
  revisions and newer pending corrections rather than falling back.

Prior SchoolGradingPolicy always returned review. New code retains pending review
where policy remains unknown, while implementing the confirmed arithmetic.

## 3. Confirmed school grading rules

- Percentage grades range from 0 to 100, including decimals and legitimate zero.
- Subject average = `(Prelim + Midterm + Semi-Final + Final) / 4`.
- All four numeric components are needed; blanks and special marks are not zero.
- An unrounded complete average ≥75 is Passed; below 75 is Failed.
- Equivalent values never determine average, standing, GWA or eligibility.
- Individual failures do not automatically disqualify the scholar.
- GWA matters according to each program's approved academic requirements.
- Registrar verification remains required and standing remains manually certified.

## 4. Proposed GWA and outstanding formula approval

Provisional percentage GWA:

```text
sum(computed complete subject average × subject units) / sum(included units)
```

Completed numeric failed grades are included. INC, dropped, explicitly unverified,
missing/discrepant/unsupported grade rows and missing/nonpositive units are excluded,
with reasons. Numeric zero grades remain included when other requirements are met.
Total recorded units include recorded nonnegative units on excluded subjects; this
is distinct from the included weighted denominator.

Numerically complete draft rows can contribute to a clearly unverified provisional
preview. They are never accepted as verified evaluation evidence. Explicitly
unverified subject rows are excluded even within a verified revision.

Every computed GWA remains **provisional**, even when every subject is complete and
the record is verified. The school has not approved the official formula, subject
inclusion/exclusion rules or rounding policy. No checkbox in this change approves
that formula or silently turns it into an official GWA.

## 5. Subject average computation

`AcademicGradeComputation` is the authoritative backend service. It uses the
already-installed Brick Math BigDecimal library, not floating-point arithmetic.
Input text retains its original decimal precision. The four-component sum divided
by four is exact at the bounded precision supported by existing 30-character fields.
The computation version is `cfci-percentage-four-period-75-v1`.

Computed subject averages are derived separately rather than written into the
reported `average` column. They are displayed to two decimals; unrounded values
appear in the read-only subject table. The provisional GWA keeps an exact weighted
numerator/denominator and an 18-decimal quotient in snapshots; its two-decimal display
is rounded directly from the exact ratio to avoid double-rounding.

The Alpine `academicGrades` editor uses scaled BigInt arithmetic for matching live
previews, updates on component/unit changes, and posts only reported inputs. It
does not submit computed averages/GWA as authoritative fields. The backend recalculates
from saved rows for detail displays and evaluation snapshots.

## 6. Passing grade validation and rounding

All numeric comparisons use unrounded values. For example, four components of
`74.9999` produce a Failed result although the two-decimal display is `75.00`.
The unrounded value and result remain visible; no school-approved rounding-up rule
has been invented. Display rounding uses half-up only as presentation, not as an
eligibility/standing rule. Registrar-certified GWA comparisons likewise use the
unrounded certified value against the approved minimum.

New component/reported-average inputs accept decimal percentage text or explicit
INC/INCOMPLETE/DRP/DROPPED marks. Scientific notation, percentage symbols, numeric
arrays, booleans and out-of-range numeric values are not accepted as grade inputs.
Units accept nonnegative decimal text; only strictly positive units enter GWA.
There is no newly invented unit-load or maximum-subject eligibility rule. Existing
60-row/30-character form limits remain technical safeguards.

## 7. Equivalent grades and historical values

No Equivalent input appears in the entry/correction editor, and nonempty submitted
Equivalent values are prohibited. Equivalent is omitted from new evaluation subject
snapshots and no longer establishes that a grading source has usable grade data.

The database column is retained. Existing verified parent revisions are untouched.
When editing a retained draft row or copying it into a correction, a scoped hidden
grade-row ID carries forward its existing Equivalent value without exposing an
editable Equivalent input. IDs cannot reference another academic record. Intentional
row removal on an editable draft retains the existing draft-edit semantics; verified
historical rows remain immutable.

Academic record details can show a clearly labeled historical Equivalent section;
new evaluation grade tables do not. Legacy record-level `grade_average`/unit/summary
metadata is retained when creating corrections and is never reinterpreted as a
certified percentage GWA. No percentage-to-equivalent conversion was added.

## 8. Failed subject handling

Completed averages below 75 are identified and counted. Their valid positive-unit
numeric grades contribute to provisional GWA. Any failed-subject case requires an
academic decision and remains review at this stage, not automatic disqualification.
An explicitly approved maximum failed count can be recorded but is a manual-review
reference; it does not activate a zero-failure default or an automatic rejection rule.

An independently approved minimum-GWA requirement may be unmet based on an actual
Registrar-certified percentage GWA. That is distinct from rejecting a scholar merely
because an individual subject failed. Review conditions retain precedence over
automatic failure, following the existing evaluator's aggregation rule.

## 9. INC handling

Reported subject status and explicit component/average INC or INCOMPLETE marks flag
academic review. Legacy remarks beginning with an explicit INC/INCOMPLETE mark are
also recognized conservatively. INC is never converted into zero or a numeric grade.
The subject is excluded from provisional GWA and retains its reported values.
Completion uses the existing Registrar correction/revision and verification workflow,
then requires a new Coordinator assessment using the latest verified source.

## 10. Dropped subjects

DRP/DROPPED marks and the separate reported status distinguish dropped subjects from
numeric failed grades. They require review and do not receive zero grades or automatic
scholarship disqualification. Their recorded units can remain in the total recorded
units while being excluded from the provisional denominator.

## 11. Missing grades and verification status

Missing components never become zero and never produce a complete computed average.
A reported average alone cannot substitute for the four required components. A partial
row retains any reported average/remarks and requires review. Explicit INC/dropped
cases are counted in those categories rather than the ordinary missing-grade category.
Computed pass/fail counts may overlap reconciliation or verification flags; they are
not a partition of official academic outcomes or scholarship eligibility.

Unknown historical values, conflicting special marks and unsupported statuses require
reconciliation. Global record verification and per-subject unverified status are shown
separately from the mathematical pass/fail result. Draft data is preview information.

## 12. Reported/computed reconciliation

When a valid reported subject average differs at any precision from the computed
average, the row is flagged Requires reconciliation and excluded from provisional
GWA. Differences are not suppressed by two-decimal display rounding. Reported values
are not overwritten. Verification records the authenticity of what the Registrar
reported; it does not automatically dismiss a discrepancy.

Resolve through appropriate Registrar review and a corrected source/revision. There
is no new one-click discrepancy override or invented rounding tolerance. Historical
parent rows/certificates remain retained. Official GWA may legitimately differ from
the provisional proposal; no unapproved aggregate formula is used to overwrite the
Registrar-certified GWA.

## 13. Program-specific academic requirements

Configuration is on **Scholarship programs → View → Program-specific academic
requirements**, below existing assignment information. Select the applicable academic
period and choose **Load academic requirements**. No period or threshold is guessed.

The feature reuses `ProgramPeriodTerm` as the program/period scope, adding immutable
child `ProgramAcademicRequirement` versions. The existing official circular criteria,
version/source/confirmation and billing policy are not modified. A new version stores:

| Field | Meaning |
| --- | --- |
| Minimum percentage GWA required | Unconfigured, explicitly Yes, or explicitly No |
| Minimum GWA | Nullable percentage; never defaults to zero; required only when applicable |
| Verified standing required | Explicit program requirement; Registrar standing is never computed |
| Current enrollment required | Explicit requirement; cannot waive mandatory official enrollment checks |
| Completed academic grades required | Recorded program requirement; cannot override conservative missing/INC/dropped review safeguards |
| Maximum failed subjects | Nullable approved reference count; zero is allowed only when explicitly entered/approved, never inferred |
| INC/dropped handling | Unconfirmed or academic review; no invented rejection/exception modes |
| Source and version references | Approved policy source and program-period applicability |
| Confirmation | Draft or confirmed; responsible actor/date come from the server |

Only existing program writers, Coordinator and retained Test Administrator, can save
or confirm through the server-authorized endpoint. Confirmation requires explicit
boolean selections, source/version references and an authority acknowledgement.
A failed limit requires its own explicit approval acknowledgement. Drafts can keep
unknown values unset. No TDP/TES/EDUKAR/COSCHO threshold or real policy is seeded.

Every save appends a version; no update/delete route exists, and database triggers
protect all version rows. Program/actor locks and a previous-version counter reject
stale saves. The latest version is authoritative for review: a newer draft does not
silently fall back to an older confirmed policy. This conservatively requires
reassessment before current reliance, including after a draft revision is created.

Evaluation rule/input snapshots pin the exact version and criteria. Historical
evaluations/bills/assignments are not rewritten. Changed academic requirements or a
changed computation scope require a new assessment before activation/future reliance.

## 14. Academic Verification interface changes

- Retains scholar/period, course/year, enrollment, manual standing, source/reference,
  notes, certificates, draft/verify controls and correction history.
- Removes new Equivalent inputs and labels Average as **Reported average**.
- Adds reported-status choices, live computed average/result, counts and provisional
  GWA preview with missing/INC/dropped/discrepancy explanations.
- Read-only detail tables show backend-derived averages, unrounded values, result,
  grade verification/reconciliation status and an academic summary with exclusions.
- Adds optional **Registrar-certified percentage GWA** and its source reference.
  Verify requires a reference and explicit attestation that this is an actual
  certified percentage, not an equivalent or the provisional system calculation.

Registrar/Test Administrator remain the only academic record writers. Verification
and correction retain existing actor/timestamp, revision, lock version, certificate
privacy and immutable-history protections. A canceled parent/subject/verification
save raises an exception so its transaction cannot silently commit partial grades.

## 15. Evaluations integration

- Current enrollment is fetched from the latest revision for the active evaluation
  semester. Subject standing/grades/GWA come from the configured source grading
  semester, not from an unrelated period or silent current-semester substitution.
- The latest revision is selected before checking verification; a newer pending
  correction makes older verified evidence stale. There is no fallback.
- New snapshots contain raw percentage source data, computed summary/version,
  program academic requirement version and the existing official/evidence context.
- Academic requirements are applied only when the latest version is confirmed and
  scoped through this application's exact program-period term. Required unknown
  policies/grades produce review, not an invented eligibility default.
- When minimum GWA is required, only verified `certified_percentage_gwa` with source
  reference can be compared. An available/high provisional GWA is insufficient.
- The academic summary displays active/source periods, enrollment, manual standing,
  GWA and its status, minimum if configured, subject results and outstanding review.
- The old generic unconfirmed-conversion/threshold warning is replaced by confirmed
  subject arithmetic and condition-specific GWA/policy/review messages.
- Currentness uses `cfci-percentage-validation-v2`, validates the pinned academic
  policy version and retains prior context/source/evidence/conflict checks. A resolved
  negative academic decision can remain current, while activation still requires an
  overall passed/qualified decision. Review cannot authorize an award.

Official semester applicability/confirmation, all other school compliance evidence,
external authority approval, active-program/Student state, one active scholarship,
duplicate assignment protection and the separate explicit activation action remain.
Activation now also holds the program lock while checking currentness, preventing a
concurrent policy save through the new entry point from changing the requirement
version between the currentness check and award activation.

## 16. Database changes — migration created, NOT EXECUTED

`database/migrations/2026_11_19_000001_add_academic_percentage_evaluation.php`:

- Adds nullable `academic_record_grades.reported_status` (`varchar(20)`).
- Adds nullable `academic_records.certified_percentage_gwa` (`varchar(30)`) and
  `certified_gwa_reference` (text).
- Creates `program_academic_requirements`: existing term FK, revision/criteria,
  source/version references, draft/confirmed state, creator/confirmer/time and
  timestamps. A unique term/revision index and restrictive FKs retain history.
- Adds update/delete immutability triggers for academic requirement versions.

No existing grade/Equivalent/decision column is removed or retyped. No existing row
is updated, backfilled, normalized, seeded or recertified by the migration. Subject
averages/GWA are derived and captured in new evaluation snapshots rather than
rewriting official reported values.

Readiness checks show a pending-upgrade notice and reject new academic/policy/evaluation
writes before this migration is available, avoiding unknown-column/table errors.
Existing histories remain readable. No migration or trigger behavior was tested.

## 17. Files created and modified

Created:

- `app/Services/Eligibility/AcademicGradeComputation.php`
- `app/Models/ProgramAcademicRequirement.php`
- `app/Http/Requests/AcademicRequirementRequest.php`
- `app/Http/Controllers/AcademicRequirementController.php`
- `database/migrations/2026_11_19_000001_add_academic_percentage_evaluation.php`
- `resources/js/academic-grades.js`
- `resources/views/components/academic-grade-summary.blade.php`
- `resources/views/components/academic-evaluation-summary.blade.php`
- `resources/views/components/program-academic-requirements.blade.php`
- `docs/19-academic-grade-computation-and-validation.md`

Modified:

- `app/Models/AcademicRecord.php`, `AcademicRecordGrade.php`, `ProgramPeriodTerm.php`,
  `EvaluationRun.php`.
- `app/Http/Requests/AcademicVerificationRequest.php`.
- `app/Http/Controllers/ScholarshipProgramController.php`,
  `ScholarshipEvaluationController.php`.
- `app/Actions/Records/SaveAcademicVerification.php`.
- `app/Actions/Eligibility/EvaluateApplication.php`, `ActivateAward.php`.
- `app/Services/Eligibility/SchoolGradingPolicy.php`, `EligibilityEvaluator.php`.
- `routes/web.php`: authenticated POST
  `/scholarship-programs/{program}/academic-requirements`, named
  `scholarship-programs.academic-requirements.store`; existing program update
  authorization in the FormRequest plus a fresh actor/role check in the transaction.
- `resources/js/app.js`: registers the new Alpine editor.
- `resources/views/components/reported-grades-editor.blade.php`,
  `reported-grades-table.blade.php`.
- `resources/views/academic-verifications/form.blade.php`, `show.blade.php`.
- `resources/views/evaluations/show.blade.php`, `programs/show.blade.php`.
- `docs/sms/eligibility-rules.md`: current rule/scope clarification.

No dependency, email/configuration, authentication, Student self-service, bulk upload,
roster, billing/reporting implementation or existing test file was modified.

## 18. Developer setup commands

Review and explicitly approve the migration before applying it in the intended
environment. The agent did **not** run this command:

```powershell
php artisan migrate --path=database/migrations/2026_11_19_000001_add_academic_percentage_evaluation.php
```

Rebuild assets on deployment after obtaining the changed JavaScript:

```powershell
npm run build
```

The local asset build was executed as compilation only. No packages were added;
existing Composer dependencies already contain Brick Math. Existing route/view cache
deployments should refresh their caches through their normal procedure. Enter real
program requirements only after the school has approved their actual sources.

## 19. Static source review and actual command results

Performed read-only schema metadata inspection, source/reference/authorization review,
formula/zero/null/precision review, live-preview/backend correspondence review,
revision/context/policy applicability review, equivalent-preservation review and
transaction/immutability/audit/activation review.

Actual source checks:

- PHP syntax checking of **18** changed/new PHP source/route/migration files: no
  syntax errors.
- Laravel Pint source formatting completed for those PHP files.
- JavaScript syntax checking with `node --check resources/js/academic-grades.js`.
- Static Blade compilation and PHP syntax checks of **9** changed/new templates:
  no views rendered, controllers invoked or functional scenarios executed.
- Vite production compilation: **62 modules** built.
- Whitespace/reference checks; existing test files left untouched.

Review corrections included: preserving grade IDs/Equivalent across corrections;
allowing null hidden IDs on new rows; applying exact threshold comparisons before
display rounding; avoiding GWA double-rounding; guarding malformed grade types;
using installed BigDecimal APIs; keeping draft policy/currentness behavior explicit;
and guarding writes/read summaries while the migration is pending.

These are static/compilation results, not functional evidence. No PHPUnit, Pest,
Artisan test, frontend suite, browser scenario, migration, import, evaluation, grade
save, activation or sample record creation was executed. Existing automated
expectations for the formerly unconfirmed grading behavior may require developer
review before future test runs; they were not changed or run here.

## 20. Manual testing instructions — NOT EXECUTED

Use a controlled development database, existing authorized accounts and new fictional
Student/subject data. Do not change real academic records for testing. The developer
must first review/apply the pending migration and load rebuilt assets.

### Registrar: entry, averages and verification

1. Sign in as **Registrar Staff** (or retained Test Administrator).
2. Open **Academic verification → Add academic record**. Select an existing fictional
   scholar and the actual configured source grading period. Use the school-approved
   context; do not assume semester 1 is always the source.
3. Enter course/year, enrollment and standing as stated by your fictional Registrar
   source. Standing must not be chosen automatically from calculated averages.
4. Under **Subject percentage grades**, choose **Add subject grade** and fill the
   sample rows below. Enter plain percentage decimals, not `%` symbols/equivalents.
5. Observe the live computed averages/results and provisional GWA/counts. Leave
   reported averages blank when unavailable, or copy them exactly from the source.
6. Choose **Save draft**. Expect reported text retained, backend-derived results on
   the detail page and an Unverified/provisional notice. No scholarship is activated.
7. Open **Edit / verify draft**, provide the Registrar source/reference and, if
   applicable, certificates. Use **Verify record**. Expect existing verification
   actor/date/immutability behavior; unresolved grade issues remain review flags.
8. If a real certified percentage GWA is available for your fixture, enter it in
   the separate field, identify its source and check the certification acknowledgement
   before Verify. Never copy the provisional value as official merely to pass a rule.
9. Use **Create correction** for verified data. Confirm the prior revision and
   certificates remain accessible, legacy Equivalent is retained, and a pending
   correction prevents using its older verified predecessor as current evidence.

### Coordinator: program-specific academic policy

1. Sign in as **Coordinator** (or retained Test Administrator).
2. Open **Scholarship programs → View** for a controlled demonstration program.
   Scroll to **Program-specific academic requirements**.
3. Select the intended evaluation period and choose **Load academic requirements**.
   Expect Unconfigured when no version exists; there are no assumed thresholds.
4. Use **Save academic policy draft** for unresolved requirements. Expect a retained
   unconfirmed version and continued review safeguards.
5. Only using an actual approved source (or clearly fictional development fixture),
   complete the required Yes/No choices, minimum if required, INC/dropped review
   handling and source/version references. A maximum failed count needs explicit
   approval acknowledgement; leaving it blank means unknown, not zero.
6. Check the policy-authority acknowledgement and select **Confirm new academic
   policy version**. Expect a new immutable version with the actor/server date.
7. Save a subsequent version and inspect **Retained academic policy versions**.
   Expect older rows/snapshots unchanged and prior reliance requiring reassessment.

### Coordinator: Evaluations and preserved workflow

1. Configure active/source grading periods through the already-approved **Academic
   periods** workflow, with the proper school policy reference.
2. Maintain a separate verified current-enrollment record for the active period if
   the source grading semester differs. Do not substitute its grades for the source.
3. Open the existing **Evaluations** entry for the fictional assignment and review
   **Program academic evaluation**, source links, subject results, GWA status and
   outstanding reasons. Draft/unknown/missing/discrepant sources must remain review.
4. Choose **Evaluate and record school decision** using the existing form. Expect a
   new immutable snapshot of calculation/context/academic-policy versions alongside
   the unchanged official policy/evidence checks.
5. Confirm a high provisional GWA alone cannot satisfy a required minimum. Add a
   genuinely Registrar-certified percentage GWA through the proper record workflow
   and verify it before assessing the approved minimum.
6. Confirm an individual failed subject yields review, not automatic whole-scholar
   disqualification. Other independently approved academic/official checks retain
   their own authority and unresolved evidence keeps the overall result pending.
7. Create a newer pending source correction or academic policy version. Expect prior
   evaluations to require reassessment. Existing decisions/bills/awards remain stored.
8. Confirm **Activate award** still requires a current overall qualified evaluation,
   latest verified external approval, active Student/program and a free active-award
   slot. No calculation or verification action activates it automatically.

## 21. Sample data — guide only, NOT inserted or executed

Use fictional subject names and new record fixtures. Below are arithmetic examples,
not actual scholarship-program requirements or real Student records:

| Subject fixture | Units | Prelim | Midterm | Semi-Final | Final | Reported average | Expected calculation |
| --- | --- | --- | --- | --- | --- | --- | --- |
| DEMO-A — Fictional Analytics | 3 | 80 | 80 | 80 | 80 | 80 | Computed 80.00, Passed |
| DEMO-B — Fictional Writing | 3 | 70 | 70 | 70 | 70 | 70 | Computed 70.00, Failed; academic review |
| ZERO — Zero is a real value | 1 | 0 | 0 | 0 | 0 | blank | Computed 0.00, Failed, not missing |
| EDGE — Below boundary | 3 | 74.9999 | 74.9999 | 74.9999 | 74.9999 | blank | Display 75.00 but unrounded Failed |
| INC-DEMO | 3 | 80 | INC | blank | blank | INC | Incomplete, no computed complete average, excluded |
| DRP-DEMO (choose Dropped status) | 3 | blank | blank | blank | blank | blank | Dropped, excluded, not numeric failed |
| MISSING-DEMO | 3 | 80 | 80 | blank | 80 | 80 | Missing; retain reported 80, exclude incomplete calculation |
| RECON-DEMO | 3 | 80 | 80 | 80 | 80 | 81 | Computed 80.00 and reported 81 remain separate; reconciliation |

With DEMO-A and DEMO-B only, included units are 6 and provisional GWA is 75.00.
The numeric failing subject is included, and its failure still requires academic
review rather than automatic scholar rejection. For min-GWA checks, any sample
threshold (such as 85) is **fictional demonstration policy only** on a separate
development program; no value is assigned to TDP, TES, EDUKAR or COSCHO by this work.

## 22. Expected manual results — all NOT TESTED

| Scenario | Expected result |
| --- | --- |
| All four valid numeric components | Backend mean and two-decimal display; raw source precision retained |
| Zero component versus blank | Zero participates; blank prevents complete average |
| Negative, >100, array/Boolean or malformed grade | Validation error; no silent conversion or partial save |
| Below-75 average displayed as 75.00 | Failed remains Failed using unrounded comparison |
| INC/DRP/missing/conflicting marks | Correct category/review/exclusion; no zero substitution |
| Numeric failed subject | Counted and included in provisional GWA; no automatic overall disqualification |
| Reported average differs | Both retained; reconciliation required; no silent overwrite |
| All units missing/zero | No division by zero; provisional GWA unavailable with reasons |
| New Equivalent/client-computed submission | Prohibited or ignored as an authoritative source; calculations use only backend inputs |
| Unverified subject/record | Clearly unverified; draft not accepted as verified evaluation evidence |
| Policy minimum/max left blank | Unconfigured, not zero; no invented default |
| Confirmed minimum with provisional GWA only | Review; official certified percentage still required |
| Approved minimum with valid certified percentage | Exact comparison; other academic/official/evidence guards remain required |
| Newer draft academic correction | No fallback to older verified source; reassessment |
| Newer academic policy revision | Historical snapshots retained; reassessment before reliance |
| Unauthorized policy POST / grade-row ID from another record | Denied by existing role/ownership-scope validation |
| Missing migration | Upgrade notice and rejected writes; no unknown-column save attempt |
| Rollback/trigger/concurrent saves | Expected atomicity/immutability/stale-save protection; runtime behavior remains for developer verification |

## 23. Known limitations and outstanding school decisions

- Official GWA formula, inclusion/exclusion treatment and rounding remain unapproved.
  The proposal is never used as official minimum-GWA evidence.
- Actual program-period numeric requirements and approved sources remain for school
  confirmation/configuration. No production thresholds or policy versions were created.
- Failed-subject maximums are recorded references; automated failed-subject
  disqualification and automatic INC/dropped exceptions remain deliberately unavailable.
- Subject discrepancies require corrected source/revision or future approved policy;
  this change does not invent tolerances or bypasses.
- Existing legacy record-level average scale is not inferred. A Registrar must provide
  an actual certified percentage source before that new field is usable.
- New policy revisions, including drafts, require reassessment; this conservative
  behavior is explicit in the interface. Older rows are never rewritten.
- New formulas/scope mark prior evaluation reliance stale; this does not delete or
  change historical academic decisions, bills or active scholarship records.
- The migration, SQL triggers, runtime UI, record saves, concurrency, certificate
  handling and complete workflow remain untested as requested. Source compilation
  cannot substitute for developer functional verification.
- The live-preview editor requires a modern browser supporting BigInt and JavaScript;
  backend computation remains authoritative. Existing 60-row technical form limits
  and private-file safeguards remain in effect.

**Final status: IMPLEMENTED — PENDING MANUAL VERIFICATION.**
