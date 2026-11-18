# Direct validation: confirmed scope and rule sources

**2026-11-19 grading update:** Percentage grades, the four-period subject mean and
the 75% passing threshold are now confirmed. See
[REC-01 grade computation and validation](../19-academic-grade-computation-and-validation.md)
for the implementation, provisional unit-weighted GWA, historical Equivalent
preservation, program-period academic policy versions and manual verification guide.
This update supersedes the earlier unconfirmed subject-formula/passing-scale notes
below; official semester rules and provider scope remain unchanged.

Updated 2026-11-11, Asia/Manila, after CFCI's clarification. The system validates existing scholars and Registrar academic records. Scholarship application processing, including EDUKAR/AKLAT intake, is outside scope.

## Confirmed CFCI requirements

| Area | Confirmed | Pending |
| --- | --- | --- |
| Reported grades | Percentage components 0–100; subject mean `(Prelim + Midterm + Semi-Final + Final) / 4`; subject passes at unrounded average ≥75. Equivalent is historical only and never used in calculations. | Official GWA formula/inclusion/rounding; approved program-specific thresholds and failed-subject policies. |
| Target period | Use the single active academic year/semester configured in the system. | No year/semester is inferred from examples, the current date, or an open-for-maintenance flag. |
| Grading source | Use the source semester specified by confirmed school policy for the active target period. | First semester is common, not an automatic source. The actual mapping requires a cited school policy. |
| External applications | Students apply directly through the relevant provider. | No application intake, application checklist, submission flow, or EDUKAR exception engine belongs in this system. |
| Outcomes | Apply only confirmed eligibility conditions; required unknown policies/data produce pending review. | No eligible/ineligible outcome inferred from an unconfirmed numeric scale. |

Administrators configure the active and source periods in **Academic periods**, citing and confirming the school source policy. A singleton context record holds these references and an audited revision. No period is preselected by migration. A source can differ from the benefit semester and need not be first semester. Configuration changes invalidate reliance on prior decisions without changing those historical rows. This initial school-wide context follows the supplied clarification; no unsupported per-program source rules are inferred.

## Recording grades

`academic_record_grades` is a child of versioned Registrar `academic_records`. Reported percentage components, reported subject average, units and remarks remain text so original precision such as `92.7500` is retained. A separate backend-derived four-period mean and provisional unit-weighted GWA are displayed; neither overwrites the reported average nor Registrar standing. INC/DRP/missing/reconciliation cases require review and are not zero. Existing Equivalent values are retained without new inputs, conversions or eligibility use. The 60-row limit is a technical form safeguard, not an academic course-load rule.

Draft rows can be maintained. Verification records the Registrar and server date, then database triggers prevent adding, editing, moving or deleting grade rows under the verified revision. Corrections create another academic revision with its own subject rows and evidence. Previously stored semester averages remain historical values and are not converted into invented subject rows. Certificates remain private and authorization-protected.

The evaluator fetches current-semester verified enrollment separately from the latest verified revision in the configured source grading period. A pending latest correction blocks fallback to an older verified source. Subject values, verification metadata, source policy/reference and context revision are pinned in each evaluation snapshot.

## Verified national sources

The [official UniFAST issuance index](https://unifast.gov.ph/guidelines.html) links the signed circulars below. Their relevant scanned pages were rendered/OCR-extracted and visually reviewed during Task 5; the downloaded files were rechecked after the clarification and their hashes remain unchanged.

| Program | Source | Catalog version | SHA-256 |
| --- | --- | --- | --- |
| TDP | [UniFAST MC 02, series of 2026](https://unifast.gov.ph/assets/pdf/guidelines/revised-tdp-pr.pdf) | `UNIFAST-TDP-MC02-2026-v1` | `ef4e55b035bf83b2aaca73ca998c67c27564d43ba5e0d9db8ad56166522753ec` |
| TES | [UniFAST MC 01, series of 2026](https://unifast.gov.ph/assets/pdf/guidelines/revised-tes-pr-v2.pdf) | `UNIFAST-TES-MC01-2026-v1` | `56876b55509f9ef38a18ecf207512c9f91c2eba5e163bf00f7381a250675e61c` |

Their effectivity begins in the first semester of AY 2026-2027. That date identifies the official policy version; it does not select the system's active period or grading source. The catalog supports numeric semester codes 1/2 for academic years from that effectivity onward. Older/custom/summer labels still need reviewed policy mapping rather than silently borrowing a version. A Coordinator explicitly confirms applicable national rules for the configured context.

TDP uses the circular's qualification, HEI retention, attendance, disciplinary, reporting and national-aid conditions, including its same-semester TDP/SMART restriction and permitted aid exceptions. TES uses its recognition, nationality, first-degree and national-aid conditions, with the applicable continuing-grantee retention/attendance/discipline checks. National slot allocation, prioritization, cross-matching, funding and external approval remain separate. No older brochure's numeric income cap or school planning-year cutoff is imported into these profiles.

Institutional/non-academic facts are checked by the Coordinator against private, authenticated evidence and explicit source references. The direct-validation screen supports school compliance/enrollment proof and applicable LOA/transfer/residency/PWD evidence. It does not collect application forms, intake-only income submissions, institutional billing annexes or an application submission. Historical source catalog metadata and existing uploaded documents are retained for audit; the snapshot's `required_documents` lists only the evidence applied by that particular validation run.

## Pending academic policy and outcomes

The subject percentage formula and 75% pass threshold are confirmed. `SchoolGradingPolicy` now reviews the latest applicable confirmed program-period academic requirement version and Registrar-verified source. Provisional unit-weighted GWA never supplies an official minimum-GWA decision. Where an approved minimum exists, only separately recorded Registrar-certified percentage GWA can satisfy it. Unconfigured requirements, missing/unverified/discrepant grades, INC/dropped subjects and failed-subject cases retain academic review safeguards. No values are configured automatically for actual scholarship programs.

Missing/unconfirmed active context, source policy, verified enrollment, reported source grades, mandatory evidence or school academic rules produce `needs_review` (pending review). This takes precedence over a separate national-rule failure while required facts/policies remain unresolved. Production validation remains pending until approved program academic criteria and any required certified GWA are recorded through the authorized workflow. Older synthetic automation fixtures were left intact and may need developer updates for the new scope/schema; no functional tests were executed for REC-01.

The pure `EligibilityEvaluator` aggregates confirmed checks; `EvaluateApplication` assembles trusted inputs, calls the policy checks and appends an immutable evaluation. It does not submit a scholarship application. Existing `scholarship_applications` and `submission_revision` identifiers are retained solely as legacy internal semester-validation storage, preserving FKs, existing rows and history. No new application lifecycle is added; old submitted states are retained as historical data.

New percentage-validation runs use `cfci-percentage-validation-v2` and retain their exact source/context, calculation version, academic requirement version, checked facts, applied rule/evidence keys, evidence checksums/reviewers, reasons, evaluator and date. Prior runs are not overwritten. Earlier-scope runs require reassessment before current reliance. Current-decision checks reject changed academic policy/source/context revisions, unavailable/corrupted evidence, newer runs and active-award conflicts. Historical billing and assignment records are not rewritten.

## EDUKAR/AKLAT and external status

EDUKAR/AKLAT application requirements, application workflows and exceptions are excluded. Its screen can review existing Registrar data and record an externally issued award decision; it exposes no beneficiary-category/intake checklist, national application evidence form or exception workflow. It does not invent EDUKAR eligibility criteria or issue an approval. Academic review remains pending where required school policy is unconfirmed. Excluded application features are not a future implementation backlog.

Verified external decisions for existing assigned scholars remain separate from school validation. Staff record the actual authority reference/date, exact semester/assignment context, validity if stated and private proof. Corrections, rejections and revocations append predecessor-linked records. An earlier approval cannot bypass a later decision or be silently carried to another semester.

Activation remains a separate guarded action requiring current eligible school validation, verified latest valid external approval and an available active-scholarship slot. While required school grading policy is pending, it cannot authorize a new award. Existing active/historical assignments are preserved; no automatic termination or financial transaction is created. Future financial posting must independently check current eligibility, approval and financial requirements.

## Remaining decisions

- Confirm official GWA formula, included/excluded subject treatment, rounding and actual program-period academic requirements. Equivalent conversion is excluded; no such conversion should be implemented.
- Configure actual current and source periods with a school reference; migration and tests do not choose live school periods.
- Review any special national categories, school-approved exceptions or custom-semester mappings before automatic decisions.
- Confirmed national `program_period_terms` rows retain their existing immutability. No unknown amount is authorized here; later rate revisions need reviewed financial handling.
- Student information-system integration, external portal integration, billing, financial posting and reporting are outside this change.
