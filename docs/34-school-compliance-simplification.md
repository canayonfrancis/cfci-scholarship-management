# School compliance simplification

2026-11-20 (Asia/Manila)  
**IMPLEMENTED — PENDING MANUAL VERIFICATION**

## Findings and safely supported changes

The CoScho block is a **missing program basis**, not a new Semester 2 restriction.
`OfficialRuleCatalog::forProgram` supports only TDP/TES. Its period function accepts
2026-2027 Semester 2, but that date alone cannot supply CoScho rules. The previous
controller exposed all facts for every non-EDUKAR program, the request required a
beneficiary category, and the Alpine fallback treated a missing mapping as all
fields applicable. That displayed unrelated TDP/TES requirements without permitting
a lawful positive decision. No live database rows were inspected in this task.

The existing School compliance tab is now compact:

- Current enrollment, program/period compliance basis and saved school result.
- Existing supporting-document references and review status, with a link to the
  existing Supporting evidence tab. Accepted metadata is not presented as a usable
  file guarantee or an approved eligibility basis.
- Evaluator remarks/reference directly visible.
- Applicable TDP/TES rules and detailed findings in expandable sections. Program
  and beneficiary-category mapping still determines actual required checks.
- Unsupported programs/periods have remarks and **Record needs-review assessment**;
  they do not receive mandatory TDP/TES questions or a manual eligibility override.
  Missing applicability no longer means every condition is mandatory.

No new tab, module, policy builder, approval stage, database table or service.
The existing workspace styles, forms, evidence modal and notifications are reused.
The Alpine component retains a stable workspace element for tab/focus/group-status
queries rather than relying on the event-dependent element.

## Requirements classification

| Category | Treatment |
| --- | --- |
| Common operational verification | Current Registrar-verified enrollment, active validation period, authentic sources, evaluator remarks, permissions and revision/evidence checks remain. |
| Applicable TDP/TES school findings | Existing citizenship, HEI/program recognition, first degree, other aid, retention, attendance, discipline and continuing-award facts remain only where the existing program/category mapping requires them. Unknown findings stay Unverified. |
| TDP-specific | Same-semester SMART and TDP continuing reporting stay scoped to TDP; TES never receives the SMART control. |
| TES-specific | Legacy PNSL transfer/residency and TES-3A PWD conditions/evidence stay scoped to TES; TDP and CoScho do not receive them as mandatory fields. |
| Official external obligations | Actual HEI admission/retention and provider requirements are not waived. Existing source references, approved rules and required usable documents remain authoritative. |
| External beneficiary selection | Prioritization, national cross-matching, provider applications and funding remain separate. Legacy intake forms/income/annex metadata remain historical; this change does not rebuild external selection or introduce income thresholds. |
| Unnecessary under current scope | Grade/GWA requirements remain removed. Unmapped program checklists and the extra not-applicable panel are removed from this operational form. |

`requiredFacts` and `requiredDocuments` return no invented mapping for unsupported
programs. New evidence uploads use program-appropriate types: TDP supports its
common/LOA/transferee types; TES also supports its residency/PWD types. CoScho/other
unsupported non-EDUKAR programs can retain generic school/provider certification
(`eligibility_support`) and enrollment proof. Earlier files of every type remain
in the original evidence/history views. EDUKAR's existing upload restrictions remain.

## Decisions, certification and the unresolved CoScho gate

Existing TDP/TES evaluation still derives **qualified**, **not_qualified** or
**needs_information** from actual supported checks, evidence and recorded rule
confirmation. Incomplete findings retain the existing needs-review precedence.
There is no posted/manual decision override. Direct requests remain policy-gated;
the evaluator independently refuses an unsupported positive policy assertion.

The system supports storing and verifying a school/provider certificate through
existing private evidence. It does **not** define an approved rule saying that a
certificate replaces CoScho school-compliance checks. `RecordExternalApproval`
records a separate exact-award/semester authority decision and proof; neither that
decision nor an accepted `eligibility_support` file supplies the missing catalog
basis. Activation and billing independently still require a current qualified
assessment with a matching supported policy.

For CoScho, the institution/provider must confirm:

1. The actual program/provider identity and authoritative school-compliance basis,
   including its reference/version, applicable semesters and beneficiary categories.
2. Which findings the institution must verify and which external beneficiary checks
   are delegated to the provider. Confirm explicitly whether a named school/provider
   certification may substitute for individual checks, and exactly what it certifies.
3. The issuing authority/authorized signatory, accepted certification/reference,
   student/program/semester coverage, validity and responsible verifying role.
4. The approved mapping to existing eligible/ineligible/needs-review results while
   keeping enrollment, external approval, activation and financial restrictions.

No fictional circular, automatic certification acceptance or CoScho applicability
mapping was added. The approved basis must subsequently be reflected consistently
in the existing evaluation/activation/billing services; uploading a certificate alone
cannot accomplish that. **CoScho positive evaluation/activation remains blocked**
pending this confirmation. Unsupported periods/programs likewise remain review-only;
EDUKAR application rules and variable entitlement are not invented.

## Preserved workflow, history and files

Roster assignment → Registrar enrollment → applicable school compliance → verified
external approval → explicit qualified-award activation → eligible draft billing.
Enrollment alone never activates an award. Not enrolled remains unavailable for
ordinary billing. No award, roster membership, enrollment revision, saved assessment,
snapshot, supporting file, external decision or financial record was rewritten.
Rates, amounts, duplicate prevention, permissions, audit and the **Form 2/Form 3
final submission restriction** remain. Academic grade/GWA checks were not restored.

Modified:

- `app/Services/Eligibility/OfficialRuleCatalog.php`
- `app/Services/Eligibility/EligibilityEvaluator.php`
- `app/Http/Requests/EvaluateScholarshipRequest.php`
- `app/Http/Requests/ScholarshipEvidenceRequest.php`
- `app/Http/Controllers/ScholarshipEvaluationController.php`
- `app/View/EvaluationWorkspace.php`
- `resources/js/evaluation-workspace.js`
- `resources/views/evaluations/partials/school-compliance.blade.php`
- Added this report.

Developer: run **`npm run build`** or the existing **`npm run dev`** server for the
Alpine changes. Clear cached views with **`php artisan view:clear`** if needed.
No migration or new dependency is required. These commands were not executed here.

## Static checks and manual verification

Static source review only. `php -l` passed for the six changed PHP files;
`node --check resources/js/evaluation-workspace.js` and the scoped `git diff --check`
passed (line-ending notices only). No automated tests were created/executed, no
manual/browser test, Blade render, build, application bootstrap, database query/
write, migration, fixture, seed or reset was performed. No functional pass is claimed.

Developer manual steps (**all NOT TESTED**; use isolated development data):

1. Open CoScho 2026-2027 Semester 2 → School compliance. Check enrollment, remarks,
   document references and the exact missing-basis notice. No TDP/TES category,
   SMART, PNSL, PWD or detailed statutory checklist should be required.
2. Retain a controlled real-source reference/certification as permitted evidence,
   then record a needs-review assessment. Confirm history/audit are retained and
   no qualified result, automatic activation or billing entitlement is produced.
3. Attempt a direct unsupported positive decision/policy assertion, TDP/TES-specific
   fields or unauthorized upload type. It must not produce a positive assessment;
   the existing role gates must still reject unauthorized writers.
4. For applicable TDP and TES examples, check recorded semester confirmation and
   expand the detailed checks. Change new/continuing category and verify mapped
   facts, references, errors and indicators. SMART belongs only to TDP; PNSL/PWD
   controls belong only to TES. No automatic Yes/No values should be introduced.
5. Verify otherwise complete supported findings/evidence can yield the existing
   qualified result; explicit noncompliance or unknown/missing sources must yield
   the existing negative/review outcomes. Check stale revisions, missing or altered
   evidence, unknown semester mapping and unauthorized posts remain protected.
6. Review external approval, explicit activation and draft billing. Check Not enrolled,
   conflicting active awards, missing approval, rates and duplicate reservations;
   retain the final Form 2/Form 3 block. Compare earlier assessments, documents,
   financial history and the unchanged enrollment/roster workflow. Record actual results.
