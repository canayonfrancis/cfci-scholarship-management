# 09 - Final system readiness audit

Audit date: 2026-11-13, Asia/Manila. Audited application commit: `a013acc2f504a96e749b41d012a21151d282d1c8` (`Update fix`). The working tree was clean before this audit. This is an independent inspection and fresh execution of the existing tests, not acceptance of earlier completion summaries.

## Final readiness assessment

**NOT READY — CRITICAL ISSUES FOUND.** The complete teacher-approved workflow cannot presently be completed with ordinary production rules. Approved grading interpretation is unavailable, final Form 2/Form 3 classification is absent, and actual billing preparation does not establish or enforce its independent roster relationship. These are functional acceptance blockers; this conclusion does not assert a demonstrated theft of funds or compromise of student information.

The implemented records, Registrar queue, financial safeguards, payroll register, signed acknowledgments, liquidation checks and six internal reports have substantial passing automated coverage. They are suitable for a limited, explicitly scoped module review. Passing conditional financial tests does not establish an uninterrupted production happy path or justify signing off all seven corrective tasks as complete.

No application code, tests, migrations, authentication, dashboard design or school records were changed by this audit. Corrections below are recommendations only. Test fixtures and migration/rollback exercises used disposable databases. Existing local data was inspected through read-only queries and authenticated GET requests.

Implemented functionality is inventoried in the process matrix below. Missing functionality is principally final form classification and its billing integration; approved grading interpretation remains an intentional policy stop. The verified remaining application defect is the unsaved/unenforced roster association and misleading unlinked-history label in HI-01. No failing automated financial regression remains in this run; that is not a claim that untested operations are defect-free.

## Scope, evidence and limitations

Read the [implementation plan](../sms/implementation-plan.md), [progress](../sms/progress.md), [gap analysis](08-implementation-gap-analysis.md), workflow guides 01-07, retained screen guides and their current payroll/signature/report amendments. Inspected actual routes, controllers, requests, actions, services, policies, models, migrations, Blade templates and test assertions. The route inventory contains **110 registered routes**. Runtime: Laravel 12.69.2, PHP 8.5.12; configured local server: MariaDB 10.11.16.

Evidence files are ignored local artifacts under `storage/framework/testing/`, not committed records or documents containing student identities. They should be retained locally for the capstone review. HTTP-kernel checks exercise middleware, authorization, controller rendering and streamed CSV callbacks, but are not an interactive browser or real password-login session. Fixture login/logout tests do exercise authentication. A checksum proves file integrity, not whether a handwritten signature is authentic; authorized human review remains necessary.

Severity definitions: **Critical** prevents a required end-to-end acceptance path; **High** violates a central workflow relationship, prevents role-based UAT setup or requires substantial security remediation; **Medium** leaves a bounded operational/policy or acceptance gap; **Low** affects documentation or presentation without changing recorded transactions.

## Business process coverage

“Verified component” below means the listed behavior was inspected and exercised in the fresh tests. It does not certify missing institutional policy or an unavailable final classification.

| Process | Actual entry points and persistence | Rules, invalid operations and history | Assessment |
| --- | --- | --- | --- |
| Create/select Scholarship Program | `/scholarship-programs`; `ScholarshipProgramController`, program request/policy and `scholarship_programs` | Coordinator maintains catalog; validated identifiers and retained availability/history. A catalog amount does not authorize payment. | Verified component. Program-form contextual billing dropdown is not a saved roster assignment. |
| Create semester batch | `/scholarship-batches`; `ManageScholarshipRoster::create`; `scholarship_batches` | Program/period/batch-number unique; Coordinator creates; no rate, evaluation, bill or cash needed. Immutable roster history. | Verified component. Independent roster is correctly distinct from a billing submission. |
| Register scholar and assign program/batch | `/scholars`, assignment routes and `scholars.batch-memberships.store`; `SaveScholarshipAssignment`, `ManageScholarshipRoster::join` | Pending assignment can join a compatible roster before enrollment. Student/program/period and assignment coverage checked; duplicate roster/student rejected. Joining creates no billing item. | Verified component. One roster can support multiple submissions; no student backfill. |
| One active scholarship | `ActivateAward`, `ScholarshipAssignment`, generated `active_student_id` unique key | Student locking plus database uniqueness; pending assignment is not activation; conflicting active award rejected. Qualification and external approval remain separate. | Guard verified, including competing database writes. Ordinary new activation remains blocked by CR-01. |
| Validate enrollment | `/academic-verifications`; roster `?view=awaiting_verification`; `SaveAcademicVerification`, `AcademicRecord`, `EvaluationRun` | Latest exact-benefit-period revision, server verifier/time, correction reason and retained superseded sources. New pending correction/unknown enrollment returns to queue; no older favorable fallback. Non-enrolled member retained. | Verified component. Known enrollment is explicitly checked, because verified standing alone can coexist with unknown enrollment. |
| Form 2/Form 3 | Roster/report displays contain a pending-template notice | No approved template, persisted classifier, template/source revision history, stale-classification state or official output exists. Enrollment and eligibility outcomes are not form codes. | **Missing; CR-02.** Unknown is correctly not automatically Form 3. Final Form 3 exclusion is not an implemented named classification gate. |
| Billing | `/billing-batches`, item add/remove/submit and independent approval; `ManageBillingBatch`, `BillingEligibility`, `ManageScholarshipFunds` | Exact approved rate, active assignment, current qualified evaluation, verified exact-semester external approval, financial limits and authorization; duplicate student/semester reservations rejected; submitted items frozen. | Existing financial guards verified. **Roster/member/form integration missing: HI-01.** Billing UI creates an unlinked header. |
| Await funds / actual receiving | `/financial-records/funding-status`, receipt recording/posting and batch allocation; `BatchFundingStatus`, `RecordFinancialActivity` | Draft, submitted/unapproved, awaiting actual cash, funded/unpaid, applied and review remain distinct. Only usable posted, scoped, intact receipts allocated to the batch count; budget and unallocated receipt do not. | Verified component, including over-allocation/reversal review and concurrency. |
| Payroll preparation | `financial.payrolls.store/show/print`; `PreparePayroll`; `payrolls`, `payroll_items` | One retained full-outstanding internal school register per approved submission. Full effective batch coverage required; planned lines reference exact bills and snapshot reference, beneficiary, preparer/time and amounts. No payout/cash/deduction is created. | School-held path verified. Direct actual recording preserved; direct payroll preparation deliberately deferred, ME-01. |
| Actual payment and signed evidence | Financial payout routes, private evidence and `financial.acknowledgments.store`; `VerifyPaymentAcknowledgment`, `SignedAcknowledgment` | Actual posted unreversed payout; explicit signed document type; exact Student ID/name/date/net/reference; human verifier/reference/page-line, immutable checksum. Generic payment proof alone is insufficient. Wrong payout, amount and self/unauthorized review rejected. | Verified component. Preparation alone is never paid. School and direct actual payment paths remain distinct. |
| Liquidation | `financial.liquidations.store/refresh/submit/review/print`; `ManageLiquidation`, `LiquidationSummary` | `draft -> submitted -> accepted/returned`; independent reviewer; full coverage, signatures for every payout, accepted owned support, authorized/settled deductions and reconciled usable funding. Returned/new-sequence and accepted history retained. | Verified component. Receipt, payroll plan or submitted liquidation alone is not liquidation. Later evidence problems produce exceptions without rewriting acceptance. |
| Six reports | `/scholarship-reports/{report?}`; `ScholarshipReportController`, `ScholarshipReports`, report index/print Blade views | Read-only recorded facts, program/roster/submission/year/semester filters, full-result totals and distinct units, CSV and print, private no-store responses. | All six internal modes exercised. Roster-scoped financial completeness remains limited by HI-01 and unknown classifications. |

Financial HTTP lifecycle tests use genuine application routes and assertions across the available stages, but three downstream success variants replace `SchoolGradingPolicy::check()` with an explicitly labeled test-only pass. At [ScholarshipLifecycleTest.php:188](../../tests/Feature/Integration/ScholarshipLifecycleTest.php), that mock is visible; at line 277 the same test asserts that ordinary billing still has a null roster link. Reporting fixtures at [ScholarshipReportsTest.php:63](../../tests/Feature/Reporting/ScholarshipReportsTest.php) explicitly link draft headers for scoped-query tests. Neither proves that staff can create that relationship through the current UI. Separate production-mode cases verify that unresolved grading, non-enrollment and unknown enrollment block activation/payable billing while retaining membership.

## Findings and recommended verification

### CR-01 - Critical: production grading prevents ordinary new qualification

Affected: [SchoolGradingPolicy.php:9](../../app/Services/Eligibility/SchoolGradingPolicy.php), [EligibilityEvaluator.php](../../app/Services/Eligibility/EligibilityEvaluator.php), [ActivateAward.php:22](../../app/Actions/Eligibility/ActivateAward.php) and [BillingEligibility.php:18](../../app/Services/Billing/BillingEligibility.php).

Cause: the production grading check unconditionally returns `review` because school grade conversion, passing thresholds and complete interpretation rules are unconfirmed. This is a deliberate protective stop, not a regression to bypass. A normally recorded new scholar cannot produce the current qualified decision required for activation/billing.

Recommended correction: obtain the approved criteria, applicable program/period versions and grade-source mapping; implement only the authorized interpretation while preserving missing-data review. No invented grades, forced activation or test fixture import into school data.

Verification: execute an unmocked HTTP journey with approved representative passing/failing/boundary cases; verify exact source revisions, correction invalidation, pending external approval rejection and successful activation only with all real prerequisites. Until then, this required positive path is **not verified**.

### CR-02 - Critical: final Form 2/Form 3 classification is absent

Affected: [roster controller:64](../../app/Http/Controllers/ScholarshipBatchController.php), [roster view](../../resources/views/scholarship-batches/show.blade.php), [report service:25](../../app/Services/Reporting/ScholarshipReports.php), [billing eligibility](../../app/Services/Billing/BillingEligibility.php), and the domain schema (no classification storage). Existing [workflow 04:27](04-form2-form3-and-billing.md) explicitly describes this limitation.

Cause: approved program-specific Form 2/Form 3 templates, applicability and status mappings are not available in the repository. There is an exact-period verified enrollment queue, but no retained final classification, template version, classifying actor/time or stale classification after correction. No final forms can be generated. Non-enrolled/unknown negative tests passing is not proof of this missing feature.

Recommended correction: obtain the actual approved templates and mappings, then retain minimal append-only classification provenance linked to the existing membership and exact academic revision. Implement freshness and output/gates from those approved inputs. Any additive migration must preserve all current history and leave unknown historical classifications unknown.

Verification: real approved template examples for each applicable program/status; enrolled, verified not-enrolled, unknown and newer-pending-correction scenarios; stale source rejection; Form 3 never payable; academic/external decisions remain independent. **Blocked on school confirmation and implementation.**

### HI-01 - High: billing does not connect to or enforce independent membership

Affected: [BillingBatchController.php:54](../../app/Http/Controllers/BillingBatchController.php), [ManageBillingBatch.php:59](../../app/Actions/Billing/ManageBillingBatch.php), [BillingEligibility.php:18](../../app/Services/Billing/BillingEligibility.php), [ScholarshipReports.php:113](../../app/Services/Reporting/ScholarshipReports.php), and [roster migration:41](../../database/migrations/2026_11_13_000009_add_independent_scholarship_rosters.php).

Cause: the nullable, scope-guarded `billing_batches.scholarship_batch_id` exists, but billing creation neither validates nor saves a roster. Candidates start from semester applications; item addition/submission checks financial eligibility without independent membership or final classification. A new UI-created financial header is consequently reported as “Unlinked historical submission,” even though it is new, and its financial records are absent from a specific-roster filter. This is incomplete integration, not a reason to remove the existing financial guards. Current production grading blocks ordinary new qualification, so this audit does not claim a live unqualified payout was reproduced.

Recommended correction: require an explicitly selected compatible roster for new draft submissions; validate same student/assignment/program/period membership and current approved payable classification at add and submit, under existing locks. Keep one roster to many submissions. Reuse the existing nullable column; no duplicate billing ledger is needed. Decide a separately authorized policy for legacy unlinked history rather than updating submitted rows or inferring membership. Improve the unlinked label to describe missing provenance accurately.

Verification: ordinary HTTP creation persists the link without `forceFill`; nonmember/wrong program/period/assignment and stale/Form 3 sources reject; duplicate and concurrent operations retain existing uniqueness; one roster with two separate submissions reconciles in all six reports. Legacy finalized records remain immutable and explicitly unlinked.

### HI-02 - High: configured environment lacks operational UAT accounts

Affected: configured `users`/`user_roles` records; [User.php:15](../../app/Models/User.php), [access audit command](../../routes/console.php) and [account inventory](../users/system-users.md). This is environment setup, not an identified role-policy bypass.

Evidence: fresh read-only `sms:audit-access` returns one retained technical Administrator, **zero Coordinators and zero Registrars**, zero unexpected roles, zero roleless accounts and zero multi-role accounts. The two normal roles work in isolated fixtures, but actual independent staff sessions cannot be demonstrated here.

Recommended correction: separately provision authorized, distinct staff identities through the existing reviewed provisioning process and approved account details; retain the two operational roles. Arrange recording/review duties so independent review is possible. No account or password was created/changed during the audit.

Verification: log in with each staff identity in a UAT environment; exercise allowed/denied writes, private downloads, own-action review denial, logout and profile retention. Do not demonstrate all duties using only the technical account.

### HI-03 - High: unresolved dependency security advisories

Affected: [composer.lock](../../composer.lock), [package-lock.json](../../package-lock.json), [package.json](../../package.json) and the installed dependency/build environment.

Fresh Composer audit returned **11 advisory records across six locked packages**, zero abandoned packages, with a nonzero audit result. Versions/counts: `league/commonmark 2.10.1` (2), `symfony/http-foundation v7.4.18` (5), `symfony/http-kernel v7.4.18` (1), `symfony/mime v7.4.18` (1), `symfony/routing v7.4.18` (1), `symfony/yaml v8.1.6` (1; development dependency).

Fresh npm audit reports **six affected packages: two critical, three high and one moderate**: concurrently, shell-quote, follow-redirects, source-map-js, vite and postcss-selector-parser. `npm audit --omit=dev` reports zero. All declared npm dependencies are development dependencies, but that classification alone does not establish that bundled browser code or development/build machines are unaffected. These are scanner findings; exploit reachability was not reproduced for each advisory. Successful build/tests do not clear them.

Recommended correction: controlled dependency remediation and applicability review, with supported fixed versions, full regression tests and build verification. Do not suppress advisories, blindly upgrade frameworks or expose the development server. No dependency changes were made here.

Verification: retain advisory identifiers from the audit JSON, document applicability/remediation, rerun both scanners and the database/UI suites, and review deployed serving/build configuration.

### ME-01 - Medium: direct-provider payroll preparation remains undefined

Affected: [PreparePayroll.php:112](../../app/Actions/Billing/PreparePayroll.php), [payroll guide](../sms/payroll-preparation.md), gap decision C05. School-held full-batch preparation is implemented. Existing direct actual payouts remain recorded without fabricated school cash. No approved proof of provider funding availability was supplied for preparing a direct payroll; this behavior was intentionally deferred under the user's “preserve” answer.

Recommended correction/verification: confirm whether direct preparation belongs in the acceptance scope and which exact availability evidence is accepted before adding it. Test independently from school receipts. Installment plans, replacement registers, partial preparation, new cash reservations and payment priorities are not approved by the current basic-register requirement.

### ME-02 - Medium: account lifecycle and deployment acceptance remain unverified

Affected: [User.php:5](../../app/Models/User.php), [authentication routes](../../routes/auth.php), `users` and deployment configuration. The model does not implement `MustVerifyEmail`, so use of `verified` middleware does not enforce verified email for this model. Account activation/deactivation is not modeled. Password authentication, authorization and retained-history deletion handling pass their tests; no email/deactivation policy is invented here.

Recommended correction/verification: confirm the school's account lifecycle and controlled provisioning requirements, then implement only approved changes separately. Real mail, public-server hardening, backup restoration, monitoring, recovery and production-engine/version/privileges have not been accepted by this audit. Rehearse them outside school records before rollout. This does not assert an unauthenticated domain-route bypass.

### LO-01 - Low: historical documentation contains superseded current-tense statements

Affected: [workflow 05:30](05-fund-receiving-and-payroll.md), [implementation plan:16](../sms/implementation-plan.md) and historical sections of [gap analysis](08-implementation-gap-analysis.md). Some retained text says typed signature coverage is absent/proposed, while Task 26 amendments and actual code implement it. Historical Task 2/11 status tables similarly must not be read as the present inventory.

Recommended correction: consolidate current versus historical labels in a subsequent documentation pass; retain chronology and use this audit plus the latest amendments for acceptance. Verify documentation against actual routes/models and current tests. This audit does not rewrite earlier policy decisions.

## Database consistency and preservation findings

Read-only inspection of the configured schema found **41 primary keys, 58 unique constraints, 47 CHECK constraints and 122 foreign keys**. **121 foreign keys use RESTRICT/RESTRICT**; the one CASCADE/RESTRICT relationship is nonhistorical user-role membership. **102 trigger definitions** were retained. The generated `scholarship_assignments.active_student_id` expresses student ID only for `active`, with the unique key protecting one active scholarship. MariaDB deployment behavior is tested independently of SQLite's trigger equivalents.

| Invariant | Actual design and verification |
| --- | --- |
| Roster identity and scope | Unique program/period/batch number; composite membership-to-roster and membership-to-assignment FKs; unique roster/student; action validates assignment period coverage. Immutable roster/member history, competing join coverage. |
| Multiple submissions per roster | Nullable same-scope roster link on existing billing headers, not a unique one-to-one link. Migration rejects wrong linked program/period. Application integration remains HI-01. |
| Billing scope/duplicates | Student/semester billing uniqueness and application/assignment/program/period references; transaction locks and submitted-history guards. A draft reservation is not a submitted bill but still consumes the protected slot. |
| Funding/payment integrity | Decimal storage and centavo arithmetic, scoped receipts/allocations, idempotency/uniqueness, independent evidence review, cumulative entitlement/cash limits and serialized writes. Direct student benefit is not school cash. |
| Payroll sources | Unique billing header/register and same-batch composite item FKs; unique planned bill line; immutable prepared register. Planning adds no financial transaction and does not itself reserve or release cash. |
| Signature provenance | Unique verified document, exact actual payout/student coverage, server actor/time, signed type and checksum; append-only verification. New generic evidence is never silently upgraded. |
| Liquidation | Exactly one payout/deduction target per line, correct batch and unique coverage, explicit support/signature compliance snapshot, independent acceptance, frozen submitted/accepted history. |
| Documents/security | Private disks outside public symlink with serving disabled, authorized owner-scoped download, metadata/checksum integrity and exactly-one-owner guards. Registrar/financial/viewer access is not interchangeable. |

Configured-source counts at inspection: **311 students, four programs, 40 academic periods, one assignment, one academic record and one billing header**. There are **zero rosters, memberships, evaluation runs, billing items, receipts, allocations, payroll headers/lines, payouts, acknowledgments, liquidations/lines and documents**. The one billing header is unlinked. Do not infer a historical roster or signed evidence from it.

Read-only aggregate checks found zero duplicate active assignments, duplicate student/period bills, duplicate roster/student pairs, or invalid membership, linked-header, bill-item, payroll-item, payout-batch or acknowledgment scope. Most financial/roster checks are **vacuous on this local dataset** because the relevant tables are empty; populated test fixtures provide the corresponding execution evidence. This is not a blanket certification of unseen production data.

The 32 local GET checks preserved fingerprints of all **34 business/account tables**, all **102 trigger definitions** and `.env` before/after those reads. Account auditing and schema queries were SELECT-only. Tests were explicitly pointed at in-memory SQLite or runner-owned randomly named `_test` databases, not the configured school database; only those owned test databases were dropped. No reset, seed, migration or student update was executed against the configured schema. **All 299 protected source/config file SHA-256 hashes, including `.env`, matched the pre-test baseline after verification.** Generated build/Blade/test artifacts are ignored; tracked changes are documentation only.

The earlier Task 28 liquidation funding-source regression is **fixed in the audited commit**, not an unresolved defect: `LiquidationSummary` reuses `BatchFundingStatus` receipt reversal/global capacity checks. All six current regression cases pass; accepted decisions remain recorded with current evidence exceptions.

## Roles and permission findings

| Capability | Registrar Staff | School Scholarship Coordinator |
| --- | --- | --- |
| Read operational records and authorized reports | Allowed | Allowed |
| Register/update scholar profile | Allowed | Allowed |
| Maintain programs/periods, program assignments and roster membership | Denied for writes | Allowed |
| Write/verify/correct Registrar academic facts | Allowed under latest-revision guards | Denied |
| Evaluate, record verified external decisions and activate award | Denied | Allowed under eligibility/source guards |
| Configure rates/budgets, independently approve billing/adjustments | Denied | Allowed, with independent-review restrictions |
| Prepare billing/receipt/payment/payroll/liquidation records | Allowed | Allowed; cannot self-review where prohibited |
| Verify/post receipt/payment, authorize deduction, verify signed acknowledgment, accept liquidation | Denied | Allowed under evidence/independence/reconciliation guards |
| Download private supporting evidence | Allowed within existing evidence policy/owner scope | Allowed within existing evidence policy/owner scope |

Source: `AcademicRecordPolicy`, `ScholarshipAssignmentPolicy`, `ScholarshipBatchPolicy`, `BillingBatchPolicy`, `FinancialRecordPolicy`, provider gates and action-level authorizations. Guests/unexpected roles fail protected operations. Ordinary form input cannot set actor IDs, verified timestamps or roles. Cross-owner evidence and modified/missing files are rejected in executed tests.

There remain exactly **two designated operational roles**. The retained Administrator is an existing authorized technical/testing exception, not a new operational post. Legacy `finance`/`viewer` compatibility branches still exist in `User::STAFF_ROLES` and some policies, but neither role is assigned in the configured schema; no such accounts were added. Their eventual retirement is a separate compatibility decision, not permission to add operational roles or rewrite history. Independent recording/review guards also apply when using the retained technical account.

## Six report verification results

All six modes share read-only reporting logic, validated filters and private HTML/CSV/print. The dedicated reporting suite executed **24 cases / 364 assertions** on SQLite, with populated isolated fixtures; the complete suite also runs these cases on MariaDB. All 18 format/route combinations additionally returned 200 against the configured local data and CSV stream callbacks executed. Local financial empty results are expected, not evidence of a real paid student or accepted liquidation.

| Required report / mode | Counting and status rule inspected and exercised | Result / limit |
| --- | --- | --- |
| Grants by Program/Batch / `grants` | Membership-first; includes pending, never-billed, non-enrolled and unknown members without approved rate/evaluation. Rows, distinct students and student/program/period grants counted separately. | Pass for recorded memberships, including one student in multiple rosters. No absent historical memberships reconstructed; form remains pending. |
| Billed Scholars and Total / `billed` | Finalized items under submitted header with submission provenance, or supported legacy finalized provenance; draft/void excluded. Original, signed adjustments and effective total separate. | Pass for submitted/unapproved versus draft and multiple submissions. New UI financial roster scope remains HI-01. |
| Not Billed / `not-billed` | Start from roster; no submitted bill for same student/program/period in any submission. Draft reservations remain not billed but visible as reserved. | Pass, including scholars with no evaluation or bill. Billing-submission filter prohibited from changing this roster denominator. |
| Awaiting Funds / `awaiting-funds` | Approved unpaid bill plus usable actual allocated receipt shortfall. Effective benefit minus posted direct benefit minus usable school funding; count shortfall once per batch. | Pass; awaiting approval, funded-but-unpaid, unallocated receipt and bad/reversed evidence are separate. No invented per-student cash priority. |
| Paid with Verified Signed Payroll / `paid-signed` | Aggregate qualifying actual posted unreversed payouts once per bill, not once per document. Positive student net plus matching explicit signature and ordinary proof required; authorization/deduction equation checked. | Pass for duplicate evidence, generic proof exclusion, reversal and partial signed portions. Labels distinguish partial coverage; zero-net exceptions are not counted as student cash paid. |
| Liquidated Billings / `liquidated` | Independently accepted, confirmed checklist and currently complete financial/evidence coverage. Sum student net plus settled deductions once. | Pass. Draft/submitted/returned, unsupported legacy acceptance and later evidence loss excluded from currently verified totals; recorded acceptance shown separately. |

Further executed checks include exact PHP **7,500.25 = 7,000.00 student net + 500.25 authorized/settled deduction** reconciliation in the conditional HTTP lifecycle; 26-row pagination versus complete CSV/print totals; cross-program/year/semester/roster filter validation; formula-leading CSV value escaping; and no source writes during report generation. Reports reflect current recorded state, not reconstructed historical as-of balances or approved provider forms. Tests with forced draft roster links establish query behavior, not completion of the missing UI linkage.

## Fresh automated and read-only test results

| Check | Passed | Failed/errors | Skipped | Evidence / qualification |
| --- | --- | --- | --- | --- |
| Full SQLite in-memory suite | **474**, 6,296 assertions | **0 / 0** | **10** | `final-readiness-audit-sqlite.xml`; 484 discovered cases, 55.57 seconds. |
| Full isolated MariaDB 10.11.16 suite | **484**, 6,431 assertions | **0 / 0** | **0** | `integration-review-30a6ea633bf2/mysql-junit.xml` and `mysql-suite.txt`; runner duration 280.78 seconds. Only owned test databases removed. |
| Authenticated local HTTP-kernel reads | **32 responses**, all 200 | **0** | 0 | `bpm-readiness-3b1632e7a9c4/result.json`; existing authorized identity, array sessions/cache; 18 report formats plus 14 base reads. |
| Schema/access audit | Aggregate counts and constraints obtained | No query failure | 0 | `final-readiness-schema.json`; account audit executed separately. Sparse-data limits above apply. |
| Pint read-only style | Passed | 0 | 0 | `php vendor/bin/pint --test app routes tests`; no formatter writes. |
| Blade compilation | Passed | 0 | 0 | `php artisan view:cache`; ignored compiled artifacts only. |
| Frontend production build | Passed, 60 modules transformed | 0 | 0 | `npm run build`, Vite 7.3.6; ignored build output only. No new visual/browser acceptance. |
| Composer security audit | **Not clean** | 11 advisory records / 6 packages | Not applicable | `final-readiness-audit-composer.json`; HI-03. This is distinct from a PHPUnit failure. |
| npm security audit | **Not clean** | 6 affected packages | Not applicable | `final-readiness-audit-npm.json`; production-only scan zero, HI-03. |

The ten SQLite skips are explicitly database-specific race cases: allocation approval (1), manual batch number (1), financial posting (1), payroll competing preparation/payment (3), roster membership (1), active assignments/semester billing (1), signed acknowledgment duplicate/conflicting verification (2). **All ten executed and passed on MariaDB**, with 134 assertions in the seven concurrency classes. SQLite has no equivalent proof of InnoDB row-lock/repeatable-read behavior. The additional one-assertion difference between full-suite totals is in the engine-specific migration test.

Selected **executed SQLite** coverage, not additional tests beyond the full-suite total:

| Existing test class | Executed cases | Assertions | What the inspected assertions establish |
| --- | --- | --- | --- |
| `ScholarshipRosterTest` | 18 | 91 | Pending/no-rate membership, duplicate/scope/role constraints and latest enrollment queue. |
| `PayrollPreparationTest` | 20 | 269 | Full actual funding, no automatic payout, planned source identity, direct path boundary, immutable/idempotent preparation. |
| `SignedAcknowledgmentTest` | 31 | 353 | Missing/wrong evidence, exact actual payment, private integrity/role guards and future/historical liquidation rules. |
| `FinancialRecordingTest` | 40 | 638 | Actual receipts/allocations/payments, amounts/deductions/evidence and independent complete liquidation. |
| `ScholarshipLifecycleTest` | 6 | 1,119 | Three production stopping cases and three explicitly conditional downstream financial role variants. |
| `FinancialEvidenceConsistencyTest` | 6 | 80 | Reversed/overallocated receipt at draft, submitted and accepted liquidation stages. |
| `ScholarshipReportsTest` | 24 | 364 | Six report populations, exact totals, evidence/status separation, filters, privacy and unchanged history. |
| `OperationalRoleSecurityTest` | 12 | 273 | Denied/allowed operational writes, unexpected roles, profile privacy and retained technical scope. |
| `AuthenticationTest` / `DashboardTest` | 4 / 1 | 8 / 9 | Fixture login/logout/invalid authentication and dashboard rendering. |
| `ScholarshipMigrationTest` | 1 | 21 | Disposable additive migration/rollback preserves pre-existing authentication and unrelated sentinels. Not a live rollback. |

The suite also includes database history, raw-write constraints, academic corrections/source freshness, other auth/profile and reporting tests. No new tests or implementation fixes were added under this audit-only scope. Test results establish the scenarios actually asserted; they do not establish missing classification or real-school criteria merely because a test class exists.

MariaDB was exercised through Laravel's `mysql` driver. A separate Oracle MySQL server/version was not available for this fresh audit run; do not describe the MariaDB result as a newly executed Oracle MySQL test. Deployment on another engine/version needs its own isolated compatibility run. Final documentation checks passed for all 89 local links in the three changed documents and `git diff --check`.

## Manual UAT cases to execute

**Not executed as interactive school UAT in this audit.** Use an isolated UAT dataset, approved documents and separate authorized Coordinator/Registrar logins. Do not modify the 311 existing student records to demonstrate these cases. Record actor, IDs, screenshots/exports, expected/actual result and institutional reviewer/date for each run.

| Case | Actor and procedure | Expected result / acceptance evidence | Present UAT status |
| --- | --- | --- | --- |
| U01 | Coordinator creates/selects program, creates benefit-period roster before configuring a rate. | Roster persists; correct program/period; no bill, cash or member created incidentally. | Manual pending. |
| U02 | Registrar registers synthetic scholar; Coordinator saves pending assignment and joins roster before enrollment. | Exact identity/program/period retained; membership exists; billing item count remains zero. | Manual pending. |
| U03 | Repeat join; try another student's assignment and wrong program/out-of-period scope. | Validation error; no duplicate or partial audit mutation. | Manual pending. |
| U04 | Registrar opens awaiting queue, verifies exact-period enrollment and creates a reasoned correction. | Latest source/actor/time visible; old revision unchanged; newer pending correction returns scholar to queue. | Manual pending. |
| U05 | Record verified not-enrolled and unknown enrollment in separate cases. | Both remain roster members; unknown pending, no automatic Form 3 and no payable bill. | Manual pending. |
| U06 | Apply approved Form 2/Form 3 template and then correct its source. | Exact template/source/actor output; stale classification blocked; Form 3 never payable. | **Blocked CR-02.** |
| U07 | Evaluate real approved passing/failing grade examples, then record separate exact-semester external decision. | Reproducible school outcome; no automatic external approval; activate only when all actual prerequisites pass. | **Blocked CR-01.** |
| U08 | Try concurrent second active award and second semester bill for same scholar. | At most one protected record; understandable error; no overwritten history. | Manual pending; database races automated. |
| U09 | Create two billing submissions against one roster; attempt nonmember, stale source and wrong scope. | Correct persisted roster links; eligible members only; independent submissions without duplicate scholar billing. | **Blocked HI-01/CR-02.** |
| U10 | Save draft, submit, independently approve; attempt self-approval and submitted edit. | Draft excluded from billed; submitted counted before approval; own approval/immutable edit rejected. | Manual pending, financial prerequisites blocked. |
| U11 | Record receipt draft, accept/post real test proof and allocate partially then fully. | Budget/draft/unallocated receipt not usable batch funding; funding queue updates; receipt alone never pays/liquidates. | Manual pending. |
| U12 | Try payroll before full coverage, then prepare funded internal register and repeat request. | First rejected; one retained linked register; preparer/date/reference/amount correct; no payout or cash posting. | Manual pending. |
| U13 | Record school actual payout with test identity/date/ref, independent proof posting and authorized deduction if applicable. | Gross = student net + authorized deduction; cash and remaining entitlement limits; no duplicate or self-post. | Manual pending. |
| U14 | Record retained direct actual-payment path. | Accepted provider payment proof; no fictitious school receipt/deduction. Do not prepare unsupported direct payroll. | Manual pending; direct preparation ME-01. |
| U15 | Upload generic proof, wrong-student acknowledgment, wrong net amount and then correct signed coverage. | Generic/wrong coverage never signed; only independent Coordinator verification of exact payout qualifies with page-line/ref. | Manual pending; human signature authenticity not automated. |
| U16 | Request private files as guest/unexpected role, use wrong owner URL and test a changed/missing test file. | Denied/unavailable; no storage path or sensitive identity disclosure; legitimate intact owner download works. | Manual pending. |
| U17 | Try liquidation with missing signature, support, unpaid balance and unsettled deduction separately. | Each prevents submission/acceptance; issue explains source; receiving funds is insufficient. | Manual pending. |
| U18 | Complete evidence/reconciliation, refresh/submit; attempt self-review, then independent accept/print. | Correct coverage, immutable checklist/actor/date; one accepted complete billing; return requires retained new sequence. | Manual pending. |
| U19 | In disposable fixtures remove accepted source evidence or simulate reviewed reversed funding after acceptance. | Original acceptance/payment retained; current evidence exception; not counted as currently verified liquidation. | Manual pending; no live correction/reset. |
| U20 | Generate all six reports using multi-roster/multi-submission test data, then filter and export/print all rows. | Roster includes never-billed; distinct counts, exact cents, once-per-batch shortfall, signed-only payments and accepted-only verified liquidation reconcile. | Manual pending; full linked path HI-01. |
| U21 | Log in/out as both roles; test denied actions, password error, profile flow, dashboard navigation/mobile/print. | Correct permissions, intact history and Cronasia presentation; no operational Administrator dependency. | **Staff sessions blocked HI-02**; browser review pending. |
| U22 | Authorized deployment owner rehearses backup restoration and private-file recovery in a separate environment. | Restored constraints, actors, evidence/checksums and totals match; deployment configuration/security accepted. | Not tested. |

## Outstanding institutional requirements and next acceptance gate

Already confirmed and preserved: one independent semester roster supports multiple submissions; **billed means submitted, not draft**; a basic internal school payroll is sufficient; wait for full approved-batch funding; direct actual recording is preserved; signed acknowledgment has exact Student ID/name, payment date, amount/reference plus Coordinator document reference/page-line; every future liquidation decision needs signed coverage for every payout and accepted support; previously accepted history remains unchanged.

Still required: approved grading interpretation and grade-source mapping; actual applicable Form 2/Form 3 templates/status/version rules; final roster/classification billing integration; authorized staff account provisioning; dependency remediation/applicability review. Confirm direct-provider preparation evidence only if that path is to be included. Official provider filing formats, special/historical program rules, unknown EDUKAR amount, production reversals/refunds/transfers, partial deduction settlements, historical as-of reconstruction, electronic/representative signature rules, account lifecycle and retention remain separate unapproved extensions where applicable; do not invent them for the demonstration.

Reassess full UAT readiness after CR-01/CR-02/HI-01 are resolved using approved inputs, operational accounts exist, dependency findings have a reviewed disposition, and a fresh **unmocked**, normally entered full workflow passes on both supported engines without source-history rewrites. Then execute and obtain school sign-off on the manual cases. No deployment, code fix, schema migration or real-record change is authorized or performed by this audit document.
