# Teacher-approved BPM coverage and testing report

Review date: **2026-11-13, Asia/Manila**. Project: Cronasia Foundation College Scholarship Management System. Task 28 in [progress](progress.md) records execution and final test evidence.

## Acceptance decision

**The implemented components pass automated verification after the funding-source regression described below, but the complete teacher-approved production workflow is not ready for unconditional acceptance.** Approved school grading interpretation and applicable Form 2/Form 3 templates are still missing. Final classification and its connection to roster-based billing selection remain unimplemented, rather than being represented by guessed rules.

The review exercises genuine HTTP program/period/roster creation, scholar registration/assignment, Registrar verification, financial preparation, payroll, signed acknowledgment, liquidation and six reports. Downstream success cases use an explicitly **test-only mocked grading prerequisite**. They do not mock an approved Form 2/Form 3 classifier, supply a production grading threshold or prove the missing classification gate exists. Production-mode tests verify that unresolved eligibility stays blocked.

## BPM coverage matrix

| Teacher step | Result | Verified behavior / boundary |
| --- | --- | --- |
| Create/select Scholarship Program | Verified | Coordinator creates the program through HTTP; duplicate identities, reference fields, policies and catalog audit are covered by catalog tests. |
| Create Semester Batch | Verified for independent roster | Correct program and academic-period FKs; creation before rates, enrollment, evaluation or billing; immutable scoped identity and duplicate/race protection. One roster can support multiple financial submissions. |
| Add/select scholar and assign program/batch | Verified | HTTP pending assignment and roster join before any academic record/billing item; Registrar cannot assign a scholarship or roster. Membership creates no payable item. One-active-award constraints and concurrent assignment tests remain included. |
| Validate Enrollment | Verified | Registrar verifies exact-period sources with retained revisions. Coordinator-only users cannot perform Registrar writes. Latest pending/unknown corrections stay pending; non-enrolled members remain on the roster. |
| Classify Form 2 / Form 3 | **Blocked by approved templates** | No final template/version/status mapping, classification history or stale-classification implementation exists. Tests assert pending classification rather than inventing Form 3 for unknown enrollment. |
| Billing | **Partially verified; final integration blocked** | Active/current qualified evaluation, verified exact-semester external approval, approved rate, independent financial approval, limits, immutable submission and duplicate/race safeguards pass. Real unconfirmed/non-enrolled/unverified cases cannot acquire payable items. Existing HTTP financial creation still does not bind/select the roster or enforce final form classification; synthetic financial tests retain that unlinked fact. |
| Funds | Verified for current recording paths | Actual receipts/private proof and independent posting; scoped allocation/capacity, dates, duplicate protection and school/direct separation. Budgets and unallocated receipts do not become batch funding. |
| Payroll preparation | Verified for school-held path | Full approved-batch funding required; retained planned lines/reference/preparer/date, tied to existing bills. Preparing/printing creates no actual payout or signature. Direct payroll preparation remains deferred pending provider-funding evidence. |
| Actual payment and signed acknowledgment | Verified | Actual gross/net and individually authorized deductions remain separate. Ordinary proof is insufficient for signed reporting. Explicit authorized verification matches exact payout, Student ID/name/date/net/reference and retains document reference/page-line/checksum/verifier/time. Private ownership, role and history checks pass. |
| Liquidation | Verified after regression fix | Full payment/settlement/cash reconciliation, required signed acknowledgments, accepted support, independent review, frozen checklist and exact coverage. Current unusable funding sources now block completeness. Original accepted decisions and real paid amounts remain intact after evidence/source exceptions. |
| Six Reports | Verified for internal operational output | Membership-first grants/unbilled, submitted-only billing, batch-level funding gaps, signed actual payment portions and currently supported accepted liquidation. Separate recorded acceptance, current evidence exceptions, distinct identities, exact whole-result totals, filters, CSV/print and authorization. Unknown history is not backfilled. |

### Cross-cutting verification

- **One active scholarship per student:** existing model/action constraints, generated/unique database enforcement and MariaDB competing-assignment/semester-billing race. Repeated activation and competing financial reservations are rejected.
- **Role boundaries:** normal roles remain Registrar and Coordinator, with the retained Test Administrator exception under the same integrity rules. Expanded denied-write tests cover roster creation/membership and signature verification and snapshot the new metadata tables. Unknown-role tests cover roster/report pages, payroll preparation and financial CSV/print. Forged role payloads never grant access.
- **Authentication/dashboard:** real isolated login/logout/session regeneration, credential validation, profile restrictions and dashboard statistics are exercised. The extended lifecycle verifies dashboard original billing, actual school funding, student net and completed liquidation against its single financial dataset. No production credentials or account memberships are changed.
- **Historical retention:** accepted decisions, posted payouts, immutable signature/payroll facts and submitted coverage are not rewritten. Current evidence/funding exceptions affect readiness and verified report counts, not recorded actual payment amounts or past acceptance status.

## Verified regression and smallest fix

**QA-01: liquidation could certify cash rejected by funding monitoring.** A fully paid, signed, supported batch could still have `LiquidationSummary.complete = true` after either:

1. A retained posted reversal of its funding receipt; or
2. Another retained allocation exceeding that receipt's global cash capacity by **PHP 0.01**, while the reviewed batch's own allocated cash still matched its paid/settled totals.

`BatchFundingStatus` correctly reported a source-review exception, but liquidation previously checked allocation totals and receipt-file integrity without sharing all receipt usability/capacity checks. This allowed inconsistent future submission/acceptance and an incorrect currently-verified liquidation report conclusion.

Added six regression cases: both source corrections at draft, submitted and accepted stages. **All six failed before the fix** with the monitor reporting `review` while liquidation remained complete (`bpm-source-regression-before.xml`). The minimal production change injects the existing `BatchFundingStatus` into `LiquidationSummary`, scopes it to the current batch and includes its existing source issues in completeness. There is no new financial policy, table, balance, correction UI, source mutation or state rewrite.

**All six pass after the fix: 80 assertions** (`bpm-source-regression-after.xml`). Draft submission and new acceptance are blocked; accepted history becomes a current exception and is excluded from currently verified liquidation totals. Signed actual student-payment totals and original payout/acceptance attributes remain exactly unchanged. These correction records are isolated retained-history fixtures, not authorization to implement operational reversals/returns/transfers without their approved workflow.

## End-to-end test expansion

[ScholarshipLifecycleTest](../../tests/Feature/Integration/ScholarshipLifecycleTest.php) now covers the actual teacher sequence's available components:

1. Real isolated account login; Coordinator HTTP program and period creation.
2. HTTP independent roster creation while no rate/financial batch exists.
3. Scholar profile, pending program assignment and roster membership, with no academic record or bill.
4. Registrar queue before/after exact-period verification; reverse role denial; classification explicitly pending templates.
5. Production-mode grading/non-enrollment/unknown-enrollment denial, or a labeled test-only grading fixture for downstream success.
6. Activation, approved semester configuration and billing; drafts remain unbilled until submission; duplicate submission/reservation and self-approval are rejected.
7. Unfunded payroll denial, actual receipt posting, unallocated-versus-allocated cash checks, funded payroll retention/printing and zero new payouts at preparation.
8. Actual student payment and specifically authorized deduction; generic proof alone does not enter the signed report.
9. HTTP explicit signed-acknowledgment verification, accepted liquidation support, separate deduction settlement and refreshed coverage.
10. Independent liquidation acceptance, unchanged prepared payroll, all six report row counts/totals and matching dashboard aggregates.

The successful financial dataset reconciles **PHP 7,500.25 original/effective benefit and school cash = PHP 7,000.00 actual student net + PHP 500.25 separately authorized/settled deduction**. The payroll is a gross plan and is never added again as a payment. All documents/grades/authority in these tests are clearly synthetic fixtures.

The focused integration run passes **12 cases / 1,199 assertions**: three recorder/reviewer variants, one production grading stop, two enrollment stop cases and six funding-source regressions. Evidence: `storage/framework/testing/bpm-integration-sqlite.xml`.

## Test execution and isolation

| Check | Result / evidence |
| --- | --- |
| Full SQLite | **474 passed; ten MariaDB-only skips; 6,296 assertions**, no failures. `storage/framework/testing/bpm-review-full-sqlite.xml` |
| Full MariaDB/MySQL driver | **484 passed / 6,431 assertions**, no skips/failures; MariaDB 10.11.16, about 276 seconds. `storage/framework/testing/integration-review-f6fa4b982010/mysql-junit.xml` and `mysql-suite.txt` |
| Current configured database HTTP read checks | **32 successful responses**, including dashboard, records/catalog creation forms, academic/evaluation queues, rosters, billing/funding and six report modes in HTML/CSV/print. CSV stream callbacks executed. |
| Business-data preservation | All **34 account/domain table fingerprints**, **102 trigger definitions** and `.env` hashes unchanged before/after local reads. `storage/framework/testing/bpm-readiness-c2028bb10eee/` |
| Static checks | Scoped Pint, Blade compilation, route inspection, local Markdown links and Git whitespace checks are recorded in Task 28. |

SQLite uses explicitly forced `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` and an empty `DB_URL`. MariaDB uses an owned random `cfci_integration_<random>_test` database; race tests use separate owned `_test` databases. Database resets/migration fixtures occur only there. Runs are sequential because fake private evidence storage is shared. No main/school/demo database reset, migration, seeding or business write is performed.

Useful reproduction commands are `php artisan test --compact --no-ansi` under those isolated testing settings; focused suites are `tests/Feature/Integration`, `tests/Feature/Auth`, `tests/Feature/Billing`, `tests/Feature/Records` and `tests/Feature/Reporting`. The private local runner scripts/evidence reside under Git-ignored `storage/framework/testing` and are not application endpoints.

## Unresolved policy and integration blockers

1. **School grading interpretation:** `SchoolGradingPolicy::check()` intentionally returns review. Confirmed conversion, passing thresholds/full scale, treatment of incomplete/dropped/failed subjects and applicable program interpretation must be supplied and implemented; a “Passing” field or external approval does not replace this.
2. **C01 Form 2/Form 3:** approved exact templates, program applicability/version/effective period and special-status mapping remain absent. Final classification, revision/staleness provenance and enforcing it in billing are not implemented or proven.
3. **Roster-to-financial workflow integration:** the optional same-scope FK exists, but actual HTTP billing creation/selection does not yet bind and restrict the submission to classified roster members. The lifecycle exposes unlinked financial history rather than silently forcing a link in the fixture. Define/implement this with the approved templates and legacy-association policy; do not retroactively relink protected history.
4. **Direct payroll funding evidence:** direct actual payment recording/signature/liquidation are retained; preparation of a direct payroll before payment is deferred under the user's “preserve” decision.
5. Official provider filing formats/deadlines, broader receipt pooling/returns/transfers, representative/electronic signature authentication and historical as-of reconstruction remain separate approved-scope requirements. Existing internal report and signature/checklist delivery is not an official provider certification.

No blocker is “resolved” by disabling financial eligibility, manufacturing an external decision, guessing a Form 3 payable category or changing actual student records.

## Deployment/account and dependency findings

Read-only `php artisan sms:audit-access` currently reports **one retained Test Administrator, zero Coordinator and zero Registrar accounts** in local `cfci_scholarship`; no unexpected/roleless memberships. Isolated tests prove the two normal roles, but operational provisioning of the actual staff accounts is still needed. No account/password/membership is created or changed by this QA task. Email verification and account deactivation are not currently modeled/enforced; these remain existing account-policy decisions, not newly implemented controls.

`composer audit --locked --format=json` returned a nonzero advisory result: **11 advisory records across six locked packages**, zero abandoned packages. Local evidence: `storage/framework/testing/bpm-composer-audit.json`.

| Locked package | Version | Advisory records |
| --- | --- | --- |
| `league/commonmark` | `2.10.1` | 2 |
| `symfony/http-foundation` | `v7.4.18` | 5 |
| `symfony/http-kernel` | `v7.4.18` | 1 |
| `symfony/mime` | `v7.4.18` | 1 |
| `symfony/routing` | `v7.4.18` | 1 |
| `symfony/yaml` | `v8.1.6` | 1 |

These are Composer-reported dependency findings, **not a claim that each advisory has a reproduced exploit in this Laravel application**. Framework dependency maintenance and deployment-specific host/proxy/mail controls require their own controlled update/applicability check and regression pass. Dependencies were not silently upgraded or advisories suppressed during a BPM review. Passing application tests is not a production security approval.

## Review limits and next acceptance gate

This review proves Laravel HTTP/controller/service/database behavior, role denial, private evidence integrity, report calculations and supported concurrency in isolated tests, plus authenticated read-only rendering against retained local data. It does not claim a new visual browser interaction audit, a real handwritten-signature authenticity test, a real bank transfer, real-account password login or completed provider filing. The application records external events; it does not send payments.

Supply the grading and form/template requirements, finish roster/classification-to-billing integration, provision authorized operational accounts and address dependency/deployment readiness before full school acceptance. Re-run this matrix with approved school examples once those prerequisites exist. Preserve the current working authentication/dashboard, protected source histories and independently verified financial stages throughout.
