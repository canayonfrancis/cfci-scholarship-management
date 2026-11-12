# 08 - Implementation gap analysis

Task 30 HI-01 amendment (2026-11-13): [billing roster integration](../sms/billing-roster-integration.md) implements existing roster selection/persistence and exact membership enforcement for new financial preparation, including submit-time rechecks, multi-submission support and accurate unlinked labels. No migration or historical association/backfill is introduced. CR-01 grading and CR-02 final classification remain unresolved; final submission fails closed on unavailable classification. See the current progress entry for fresh database tests. The audit and baseline descriptions below retain their historical findings.

Review date: 2026-11-13 (Asia/Manila). Scope: analysis and documentation only.

Task 28 QA amendment (2026-11-13): [BPM coverage/testing report](../sms/bpm-coverage-and-testing-report.md) verifies available workflow components and identifies remaining production acceptance blockers. Fixed a reproduced inconsistency where liquidation certified reversed/oversubscribed receipts already rejected by funding monitoring; it now reuses the existing scoped source checks without changing historical decisions or actual payments. Expanded real HTTP roster/payroll/signature/report and negative enrollment/security coverage. Approved grading/Form 2/Form 3 classification and final roster-to-billing integration remain blocked; successful synthetic financial fixtures do not resolve them. See [progress](../sms/progress.md) for current SQLite/MariaDB results and preserved-data evidence.

Task 27 implementation amendment (2026-11-13): **all six internal operational reports are now delivered** under Scholarship reports, with appropriate program/roster/financial-submission/year/semester filters, distinct counts, exact whole-result totals, CSV/complete print and evidence/history exceptions. Reporting starts from independent membership where required, treats billed as submitted, reuses actual funding and typed signature/liquidation checks, and creates no migration or source-data backfill. Counting/output definitions are declared in [six-reports.md](../sms/six-reports.md); final verification is in [progress](../sms/progress.md). G12 below is an original baseline gap, superseded for this internal-report scope. Approved Form 2/Form 3 outputs, official provider formats and historical as-of reconstruction are still separate requirements.

Task 26 implementation amendment (2026-11-13): **C06/C07 are resolved for the confirmed basic workflow.** A Coordinator verifies signed acknowledgment showing Student ID/name, actual payment date, amount received and payment reference, retaining acknowledgment reference/page-line. Every future liquidation submission/acceptance requires that coverage for every payout and accepted intact liquidation support, including older payouts; past accepted decisions are retained with missing checklist provenance shown as an exception. Implemented append-only `payment_acknowledgments` and a nullable immutable checklist snapshot on existing liquidations. Generic `payment_evidence` remains distinct. See [verification procedure](../sms/signed-payroll-and-liquidation.md) and Task 26 in [progress](../sms/progress.md). The original baseline findings below are historical, not a claim that these delivered checks are still missing.

Task 25 implementation amendment (2026-11-13): C05 is resolved for a **basic internal school register prepared only after the entire approved billing batch is funded**. The user requested preservation of existing direct-payment recording; direct payroll preparation remains deferred pending provider-funding evidence. Added only `payrolls`/`payroll_items`, linked to existing financial submissions/items, with immutable preparation/funding/eligibility snapshots and no automatic payouts, deductions or changed financial totals. One full-outstanding register per submission is retained. See [payroll preparation](../sms/payroll-preparation.md) and Task 25 in [progress](../sms/progress.md). Typed signed acknowledgments, direct preparation and final form-classification gating remain outstanding; preparation does not resolve them.

Implementation amendment (2026-11-13): the user has now confirmed **one semester roster can support multiple separate billing submissions** (C02), and **billed means submitted; drafts are not billed** (C04). The original M01 candidate that reused one financial header as the roster is superseded by that answer. Current implementation and test status are recorded under Task 24 in [progress](../sms/progress.md). The baseline findings below describe the earlier analysis; they must not be used to claim that newly delivered roster/queue/monitoring work is still missing. Approved Form 2/Form 3 templates and final classification-to-billing integration remain unresolved.

## Authority, scope and verification limits

The teacher-approved eleven-step workflow in this request is the primary sequence. It adds independent batch membership before validation, Form 2/Form 3 classification, payroll preparation/signed evidence and six explicit reports. Earlier delivery milestones do not establish those newly requested capabilities.

Read [implementation plan](../sms/implementation-plan.md) and [progress](../sms/progress.md), including later amendments. Inspected current routes, controllers/requests/policies, Eloquent models/relations, all scholarship migration stages through `000008`, relevant Blade screens, action/services and existing feature/database/integration tests. No applicable `AGENTS.md` was found. No external scholarship rule or official template was researched/adopted: this review evaluates the supplied workflow against repository behavior, not provider-policy validity.

**“Already implemented and working” below means the current code contains the behavior and existing tests cover it, supported by the earlier verification log.** Runtime/browser tests, live database contents, accounts and evidence were not revalidated in this documentation task. Task 18 records the latest prior full SQLite result (362 passed, four MySQL-only skips, 4,400 assertions) and an isolated MariaDB subset (108 passed, 1,273 assertions). Those are historical results, not new runs or proof that school policy is complete.

## Workflow comparison at original analysis (see implementation amendments above)

| Teacher step | Current implementation | Assessment / smallest alignment |
| --- | --- | --- |
| 1. Create/select Scholarship Program | Catalog CRUD, stable code, availability, nullable references and audits | **Already implemented and working.** Reuse it; no catalog rebuild. |
| 2. Create program/year/semester Batch | `billing_batches` links program/period/terms; creation requires approved rate | **Implemented but inconsistent.** Financial header exists, but roster setup is rate gated. Add independent memberships and defer rate binding to financial preparation. |
| 3. Select student, assign program and batch | Student CRUD and pending program assignment work before validation; no batch field/relation | **Partly implemented; batch membership missing.** Save membership without a billing item. |
| 4. Validate enrollment | Registrar latest-revision verification/corrections and evidence exist | **Already implemented core, incomplete integration.** Add batch queue and explicit known-current-enrollment checks; “verified” alone is insufficient. |
| 5. Form 2 enrolled / Form 3 other applicable statuses | No classification field, template mapping, form generator or classification history | **Missing.** Map only confirmed program templates to latest verified enrollment; unknowns stay pending. |
| 6. Bill eligible enrolled grantees | Guarded rate-pinned items, duplicate prevention, immutable submission and independent approval | **Already implemented core, inconsistent entry point.** Uses validation contexts rather than batch members and has no form gate. Preserve authorization and add member/enrollment/classification checks. |
| 7. Track billings awaiting funds | Receipt/allocation/payment records and summaries exist; billing status tracks draft/submission/approval | **Missing explicit queue/report.** Derive funding readiness; awaiting approval is different from awaiting cash. |
| 8. Receive funds and prepare payroll | Actual evidenced receipt posting and scoped batch allocations work | **Receipt flow implemented; payroll missing.** No prepared payroll register or planned payment lines. |
| 9. Actual payment + signed payroll | Payout drafts, actual details, independent evidence review/posting work | **Implemented but inconsistent with signature requirement.** Accepted generic payment proof is not explicit signed payroll coverage. |
| 10. Verify acknowledgments/documents and liquidate | Full-batch financial reconciliation, independent accept/return, immutable coverage and printing work | **Implemented but incomplete against new requirement.** No typed signed-payroll check; `liquidation_support` uploads are optional in the completeness logic. |
| 11. Generate six reports | Live dashboard, allocation-history screen/CSV, batch/payment lists and liquidation printout | **Partial.** Complete roster and signature facts are missing; the six specified output modes are not delivered. |

## Already implemented and working

| Capability to preserve | Current repository evidence |
| --- | --- |
| Laravel authentication/profile and Cronasia dashboard | `routes/auth.php`, auth controllers/requests, `ProfileController`, `DashboardController`, dashboard/layout Blade views; auth/profile/dashboard tests |
| Two operational roles | `User::OPERATIONAL_ROLES = ['coordinator', 'registrar']`; policies and provider gates; `OperationalRoleSecurityTest` and lifecycle tests |
| Single student identity and protected profile details | `Student`, `ScholarController`, `ScholarRequest`, `ScholarProfile`; record/profile tests; nullable enhancement migration `000007` |
| Pending program assignment before validation | `SaveScholarshipAssignment`, nested scoped assignment routes, assignment request/form; no enrollment/evaluation requirement for pending save |
| Registrar verification and retained corrections | `SaveAcademicVerification`, `AcademicVerificationRequest`, `AcademicRecord`, academic revision/subject guards; `AcademicVerificationTest` |
| Separate evaluation/external approval/activation | `EvaluateApplication`, `EvaluationRun::isCurrent()`, `RecordExternalApproval`, `ActivateAward`; evaluation/scope/integration tests |
| Billing integrity | `ManageBillingBatch`, `BillingEligibility`, `ManageScholarshipFunds`, `BillingBatchPolicy`; billing/fund/database tests; submitted history, exact scope and duplicate constraints |
| Actual funding distinct from budgets/bills | `RecordFinancialActivity::receipt/postReceipt/allocate`, `FundingReceipt`, `FundingAllocation`; evidence/cap/idempotency/concurrency tests |
| Actual school/direct payments and authorized deductions | `RecordFinancialActivity::payout/postPayout/deduct/settle`, `Payout`, `Deduction`; actual ID/date/reference, gross/net equation, posting limits and private proof tests |
| Separately reviewed liquidation | `ManageLiquidation`, `LiquidationSummary`, `Liquidation`/`LiquidationItem`; incomplete/unpaid, returned/new sequence, missing proof, duplicate coverage and immutable-line tests |
| Useful financial reporting | `ScholarshipFundsController::report`, `AllocationSummary`, `billing/report.blade.php`, `DashboardAnalytics`, financial printing; report/analytics tests |

Normal new eligibility is **not currently fully operational**: `SchoolGradingPolicy::check()` unconditionally returns review because approved school interpretation is missing. CoScho/custom eligibility profiles are also not supplied by a catalog entry. Downstream test success uses labeled synthetic prerequisites. Do not enable guessed grades/policies to demonstrate the new workflow.

## Implemented but inconsistent

### G01 - “Batch” currently means a financial container

`ScholarshipAssignment` has program/start/end periods but no batch relation. Student forms save identity/profile only. `BillingBatch::items()` represents actual financial items; `ManageBillingBatch::change('add')` checks eligibility and reserves the unique student/semester slot. Consequently students can be assigned to a **program before validation**, but cannot be assigned to a **batch before validation**.

The program-form Batch Number selection is contextual only: `ScholarshipProgramController::store/update` excludes `batch_program_id`, `academic_period_id`, `billing_batch_id` and `batch_number`. It does not persist the selection. Existing context/options behavior must not be mistaken for program/batch assignment.

### G02 - Financial prerequisites occur too early for roster preparation

Current batch creation needs an approved semester rate even though the teacher puts roster setup before enrollment validation/billing. An approved rate may be configured independently of eligibility, but should not be required merely to retain members. Defer financial rate pinning until billing preparation; retain all current gates when amounts are actually authorized.

### G03 - Verification, evaluation and classification are different

Academic verification can verify standing while enrollment remains `unverified`. `EligibilityEvaluator` uses the exact-period verified source, and ordinary not-enrolled cases produce fail/review rather than passed qualification. `BillingEligibility` relies on the completed qualified evaluation chain; it has no explicit Form 2/member/enrolled condition of its own.

There is no Form 2/Form 3 output. Do not equate school `failed/needs_review` with Form 3 or assume `passed` selects Form 2. Classification should depend on current known Registrar enrollment and a confirmed applicable template, while payability retains its separate guards. Neither `academic_records.verification_status` nor `students.record_status` alone is a classification basis.

### G04 - Payment proof is broader than signed payroll evidence

`FinancialEvidence::TYPES['payout']` contains only `payment_evidence`; posting permits any accepted intact proof under that type. There is no signature-confirmed metadata, payroll reference, signer/beneficiary coverage or page/line link. A human can review a signed document today, but existing records cannot reliably distinguish it from other accepted proof. Do not relabel every posted payout as signed-payroll-supported.

### G05 - Liquidation proves financial reconciliation, not a complete provider checklist

`LiquidationSummary` checks payment proof, actual paid/settled amounts and cash reconciliation. `liquidation_support` is allowed for uploads but never required by that summary. The new workflow requires explicit signed acknowledgments and applicable documents before completion. Extend the completion gate after the real checklist is supplied; retain existing acceptance history and expose legacy completeness as unconfirmed where necessary.

### G06 - Existing reporting is useful but not the six requested reports

General `/reports` pages were previously removed. `/billing-batches/report` and its CSV **still exist** and report allocation history including drafts and other stages. The current dashboard intentionally uses `DashboardAnalytics::build([])` for all programs/periods and ignores obsolete filter URL parameters. Financial and record modules keep their own filters. Reintroducing operational lists must not restore removed dashboard controls or redesign authentication/navigation without a separate need.

## Missing functionality

| ID | Missing fact or behavior | Why it matters |
| --- | --- | --- |
| G07 | Independent retained batch membership and batch enrollment queue | Required for steps 2-4 and complete grants/not-billed denominators, including never-evaluated and non-enrolled members |
| G08 | Versioned program-specific Form 2/Form 3 mapping/output | Required to prove the source enrollment revision and applicable template; retain unknown/other status exceptions |
| G09 | Explicit submitted/approved awaiting-funds and funded-unpaid queues | Budgets, program receipts and approval status cannot identify batch cash readiness alone |
| G10 | Traceable payroll preparation independent of actual payments | No saved planned payroll/reference/lines exist; payout drafts require an already actual payment date |
| G11 | Typed signed acknowledgment coverage and required liquidation-document checks | Needed for signed-payroll paid reporting and the new liquidation completion requirement |
| G12 | Six named operational report modes with scoped totals/exports | Existing dashboard/CSV/detail/print views are not the required complete report set |

Non-enrolled students are already retained as academic/identity records. What is missing is their independent **batch membership and classification**, not permission to delete or recreate their identities. Current ordinary financial gates do not authorize their billing. No Form 3 payable behavior is proposed.

## Requirements requiring clarification

These questions are recorded for later implementation. They do not prevent completion of this analysis and no answer is inferred.

| ID | School/provider decision needed | Proposed conservative treatment until resolved |
| --- | --- | --- |
| C01 | Exact Form 2/Form 3 samples, program applicability, version/effective period, columns and special-status mapping | No universal form engine or guessed provider template. Unknown/unverified enrollment remains pending, not automatic Form 3. |
| C02 | **Resolved by user, 2026-11-13:** one semester roster supports multiple separate billing submissions. Transfers/reassignments were not authorized. | Use independent `scholarship_batches` roster headers and `scholarship_batch_memberships`, with a nullable same-scope roster reference on existing `billing_batches`. No guessed legacy backfill or transfer workflow. |
| C03 | Which additional evaluation/external approval/rate/budget requirements apply to each program, and are any current pre-billing gates intentionally changing? | Preserve current safeguards. The eleven-step ordering does not itself waive financial prerequisites or supply school grading rules. |
| C04 | **Resolved by user, 2026-11-13:** submitted billing is billed; draft billing is not. | Keep draft, submitted, approved and funding-readiness states separate. Draft reservations still prevent duplicate allocation and must not be mistaken for permission to bill again. |
| C05 | **Partly resolved by user, 2026-11-13:** basic internal register is sufficient; wait until the entire approved billing batch is funded; preserve existing direct recording. Direct-provider availability evidence, replacement registers and any future installment/reservation rules remain undefined. | Task 25 retains one full-outstanding school register per approved submission, using existing batch allocations and prior posted direct benefit. No direct preparation, extra reservation ledger, FIFO/pro rata priority, funding exception, automatic payment or extra financial role is introduced. |
| C06 | **Confirmed by user, 2026-11-13:** signed acknowledgment showing Student ID/name, actual payment date, amount received and payment reference; Coordinator records document reference/page-line. | Task 26 stores explicit human verification against the exact posted payout and its private owned document. Generic proof is not signed automatically. Shared-owner bridges, representative rules and electronic-signature authentication are not inferred. |
| C07 | **Confirmed by user, 2026-11-13:** every liquidation additionally requires accepted signed acknowledgment for each payout and accepted liquidation support; apply to every future decision, including older payouts, while preserving accepted history. | Task 26 retains exact source IDs/checksums in an immutable checklist snapshot and rechecks them at acceptance. Existing reconciliation/deduction/evidence checks remain. No official filing template, new deadline or partial liquidation/return policy is invented. |
| C08 | Task 27 implements declared internal report units, current recorded state, screen/CSV/print and separate legacy/evidence exceptions within the requested report scope. Official provider form formats and historical cutoffs are not supplied. | Reuse confirmed submitted-billing, independent roster, funding, signed acknowledgment and acceptance rules. Count distinct Student/grant/submission identities separately; disclose unlinked or unsupported history. Do not reconstruct absent facts or label internal output an official provider form. |

Existing unresolved academic criteria/source mapping, provider-specific deductions, reversals/failed payments, returns/transfers and retention decisions remain open. The new workflow does not authorize invented scholarship policy, an EDUKAR intake engine or destructive cleanup.

## Smallest safe database changes proposed

**No migration is written or run in this task.** The following are staged proposals, not final table/column approval. Reuse existing identities, financial ledgers, source revision tables and evidence storage.

### M01 - One additive membership table first

**Historical conditional proposal, superseded by the confirmed multi-submission roster.** Two independent roster tables are necessary under the user's answer; they do not duplicate financial ledgers. `billing_batches` remains the submission header, with a nullable reference to its independent roster. See Task 24 for delivered scope and remaining classification gate.

Create only `scholarship_batch_memberships` for the first implementation stage. Suggested columns: ID, `billing_batch_id`, `assignment_id`, `student_id`, `program_id`, `academic_period_id`, assigning actor/time, timestamps and any reviewed withdrawal/correction provenance. Use restrictive FKs, unique `(billing_batch_id, student_id)`, indexed assignment/period scope, and composite context constraints consistent with the existing schema. Validate assignment period coverage and same student/program in the transaction.

This stores a fact absent from all current tables. It must not contain a bill amount, paid flag or fake evaluation. Do not insert into `billing_items` when adding a member. Existing billing item keys already identify student/assignment/program/period/batch; initially a transaction can verify that exact membership without adding a compulsory FK to historical bills. Keep immutable membership provenance/correction history; do not silently move/delete members referenced by submitted financial work.

Reuse `billing_batches` as the semester header. Its `scholarship_rate_id` is already nullable and `program_period_terms` can exist before financial confirmation. A future action change can create the rate-independent roster header and pin an approved rate under locks before the first financial item. Preserve finalized headers and current scope keys. **No second batch-header table or removal of financial constraints is necessary for this scope.** If C02 confirms a longer-lived cohort or multiple independent billing documents per roster, revisit that distinction before implementing the mapping; do not introduce both designs speculatively.

### M02 - Add only classification provenance that cannot be derived historically

Once C01 is answered, add a small append-only classification history, e.g. `scholarship_form_classifications`: membership ID, revision/supersedes link, source `academic_record_id`, Form 2/Form 3 code, template reference/version, reason, classifying actor/time and output snapshot where required. Unique membership/revision and restrictive same-scope FKs prevent unrelated source links. No row means unresolved classification; do not force unknown enrollment into Form 3.

Reuse `academic_records` for enrollment. Keep form-template definitions in reviewed program-specific configuration/artifacts unless the school actually needs version administration in the database. No new enrollment/student table, guessed status enum or generic policy designer is required.

### M03 - Retain prepared payroll and explicit acknowledgments without replacing payouts

**Task 26 delivered the confirmed acknowledgment portion:** `payment_acknowledgments` is an immutable payout/document/student verification fact, and nullable `liquidations.compliance_snapshot` retains the confirmed checklist/evidence at future decisions. No payout-to-payroll FK, new payment ledger or shared-document bridge was necessary. The conditional design below is historical; the current delivered schema and tested scope are described in [signed acknowledgment and liquidation](../sms/signed-payroll-and-liquidation.md).

**Task 25 delivered the minimal school preparation portion.** The two new preparation tables retain gross-only plans, identity, source eligibility and funding snapshots. Actual net/deduction authorization stays in the established payment workflow. The optional payout FK below was unnecessary for this task: planned and actual lines already reference the same bill, and adding a signature/payment attribution relationship before C06 would invent policy. Existing financial tables/rows remain unchanged. Direct payroll preparation and explicit acknowledgment storage below remain proposals, not delivered features.

After C05/C06, a minimal saved payroll design can use `payrolls` (batch/reference/preparer/date/template/frozen state) and `payroll_items` (payroll, existing billing item, planned gross/net and authorized-deduction basis). Freeze prepared/reviewed lines when used; do not store a manually toggled paid total. Add an optional indexed `payouts.payroll_item_id` for **new** payment records only, with same-item/batch validation. Actual payments remain `payouts`; installments remain against their original bill. Existing posted payouts stay unchanged and unlinked unless a separately reviewed association method is approved.

Reuse existing per-payout `documents` for proof. Add a small append-only acknowledgment-verification record linked to the payout and its owned document: explicit signed-proof kind, human verifier/time, reference/page/line and beneficiary/payment coverage. Existing `accepted` review and checksum remain necessary but are not a substitute for that verification. Do not retrofit signatures into old rows. A shared sheet covering several beneficiaries requires a reviewed evidence-link bridge, not violation of the existing exactly-one-owner document constraint; defer that extra table until C06 confirms the need.

If the school only requires a reproducible printed payroll and no independently saved lifecycle, review a smaller frozen register artifact approach before creating both payroll tables. It must still retain a preparation reference/time/scope independent of actual payouts. Current payment drafts must not become planned payroll records.

### M04 - Prefer read-only queries and existing evidence for the remainder

Funding queues and reports 2/4/6 need queries/views over existing financial sources, not new balance/status tables. Required document gating can initially validate confirmed evidence types through existing `documents` and preserve the checklist reference/results in an immutable review snapshot. Add a checklist-result table only if per-document decisions must be queried independently. A formal historical signed-payroll conclusion cannot be obtained from arbitrary review notes alone.

### Migration and historical-data safeguards

1. Preflight existing schema, constraints/triggers and duplicate contexts read only on the actual target version. Check required privileges and take a verified backup before any future schema rollout.
2. Use additive tables/nullable indexed references only. Do not recreate tables, drop uniqueness/history guards, reset databases or change student records. Existing global student/semester billing uniqueness and one-active-award enforcement remain intact.
3. Default legacy links to null/unknown. Do not infer unbilled memberships, form classifications, signatures or prepared payrolls from existing billed students. Existing bills prove their financial inclusion, not the complete original roster.
4. Respect immutable posted/submitted rows: adding a nullable column does not authorize updating old rows through history triggers. Any separately approved historical association/backfill needs its own reviewed, auditable method; never disable guards to make it succeed.
5. Preserve exactly-one-owner document constraints and private checksums. Any new owner/bridge needs explicit MySQL/SQLite trigger/check compatibility, not a blanket alteration of document ownership.
6. Verify native additive operations against both supported database engines and preserve existing composite FKs/triggers. Test preservation, races and rollback only in isolated databases. A populated new table must not be dropped in production rollback; roll back application behavior first and retain the facts.

## Overlap, ordering and unnecessary work

- **Keep pending assignment first.** Current program assignment already precedes evaluation. The missing step is membership, not an earlier award activation bypass.
- **Separate roster addition from Billing's Add scholar.** Similar labels conceal different facts and eligibility requirements; a roster action should never reserve billing uniqueness or cash.
- **Separate enrollment/form classification from academic eligibility.** Unresolved grade policy must not erase a known Registrar enrollment fact; Form 2 inclusion must not bypass eligibility authorization.
- **Separate payroll plans from actual payout drafts.** The latter deliberately require a payment that already occurred.
- **Keep funding, payment, acknowledgment and liquidation distinct.** Existing financial record separation is valuable. Add missing links/checks, not a second financial engine.
- **Remove misleading implications in a future UI revision.** Explain program batch context as unsaved reference or replace its purpose with an explicit saved roster action after review; preserve working selectors and historical ownership.
- **Do not rebuild authentication, dashboard, roles, student/course catalogs or existing financial tables.** Extra Finance/Viewer provisioning, a third mandatory operational role, provider portals, automatic payment execution, tuition collection, arbitrary deduction rules, a generic workflow builder and new application-intake processing are unnecessary for this request. Retain legacy access compatibility and the approved test exception.

## Proposed next implementation order and acceptance criteria

| Priority | Future work | Reviewable acceptance condition |
| --- | --- | --- |
| 1 | Confirm C01/C02/C04 and preserve a baseline | Approved batch meaning, template samples and report definitions; no record rewrites |
| 2 | M01 membership plus rate-independent roster setup | Existing/new student can join a same-program/period batch while assignment is pending and no enrollment/evaluation/rate/bill exists; zero billing items/receipts/payouts created |
| 3 | Batch validation/classification (M02 after template confirmation) | Current verified enrolled -> applicable Form 2; verified non-enrolled -> applicable Form 3 with retained member; unknown/pending correction -> unresolved; wrong template/period rejected |
| 4 | Reuse guarded billing and add funding queues | Only eligible enrolled roster members can be billed; draft/submitted/approved stages remain separate; Form 3 excluded; rate/budget/approval/duplicate/race safeguards retained |
| 5 | Confirm C05-C07, then payroll/acknowledgment/checklist work | Prepared payroll does not mark paid; actual payment needs independent reviewed proof and signed coverage; received money alone cannot complete liquidation |
| 6 | Six report modes and reconciliation | Reports 1/3 include never-billed members; 2 totals submitted amounts exactly; 4 separates batch cash from budgets/receipts; 5 proves signatures and partial/full payment; 6 lists accepted billings with current evidence exceptions |
| 7 | Two-role regression and school acceptance | One Registrar records and one Coordinator reviews; direct unauthorized requests/self-review blocked; auth/dashboard/history preserved on isolated SQLite and target MySQL/MariaDB |

Additional meaningful tests should cover same-batch duplicate membership, concurrent assignment/membership/billing attempts, member withdrawal after submission, stale classification after Registrar correction, alternate template version, partial funding, multiple payments/documents without inflated totals, generic proof rejected as signed proof, missing signature/support document blocking completion, shared-sheet coverage if authorized, and legacy null provenance remaining visible. Extend existing feature suites rather than duplicating them with implementation-mirroring tests.

## Evidence and test coverage map

| Area | Files inspected / useful existing regression coverage |
| --- | --- |
| Routing and permissions | `routes/web.php`, `routes/auth.php`, policies, `AppServiceProvider`, `User`; `tests/Feature/Auth/OperationalRoleSecurityTest.php`, `StaffAccessAuditTest.php` |
| Catalog/profile/assignment | Catalog/scholar/assignment controllers/requests/models/views; `ScholarRecordsTest`, `ScholarInformationTest`, `ProgramBatchNumberTest`, `BillingBatchOptionsTest` |
| Registrar/evaluation | `SaveAcademicVerification`, `EvaluateApplication`, `EligibilityEvaluator`, `SchoolGradingPolicy`, `EvaluationRun`; `AcademicVerificationTest`, `DirectValidationScopeTest`, `ScholarshipEvaluationTest` |
| Billing/budget integrity | `ManageBillingBatch`, `ManageScholarshipFunds`, `BillingEligibility`, rate/budget/item/approval models and views; `BillingBatchTest`, `ScholarshipFundsTest`, allocation/batch-number concurrency suites |
| Money/evidence/liquidation | `RecordFinancialActivity`, `FinancialEvidence`, `PrivateEvidence`, `ManageLiquidation`, `LiquidationSummary`, financial controller/requests/models/views; `FinancialRecordingTest`, `FinancialPostingConcurrencyTest` |
| Reports/dashboard | `ScholarshipFundsController::report`, `AllocationSummary`, `DashboardAnalytics`, `DashboardController`, `billing/report.blade.php`, liquidation printing; `DashboardAnalyticsTest`, fund-report tests |
| Schema/history | Foundation migration and additive `000001`-`000008`; contextual FKs, unique/check/history triggers; `ScholarshipFoundationTest`, `ScholarshipMigrationTest`, `ScholarshipConcurrencyTest` |
| End-to-end | `ScholarshipLifecycleTest`: production-review stopping case plus synthetic downstream cases using Registrar/Coordinator and the retained Test Administrator exception |

At the original analysis, tests did not establish independent pre-validation membership, Form 2/Form 3 templates, prepared payrolls or typed signatures. Tasks 24/25 now add membership/queue and school-preparation tests, including MariaDB races; see the current progress entries. Form templates and typed signatures still have no implementation. Accepted test PDFs are synthetic proof, not a test of a real student signature. A passing suite cannot certify missing business facts.

## Completion boundary

Created the eight requested analysis/workflow documents, updated the workflow index and [progress](../sms/progress.md). Application code, migrations, tests, authentication, dashboard assets, roles/accounts, environment configuration, evidence and business/student records were not changed. No migrations, seeders, posting operations, database resets or application tests were run. Documentation links, required-section coverage and Git whitespace/scope checks are the verification for this task. Stop here; all proposed implementation remains future work.
