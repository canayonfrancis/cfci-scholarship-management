# Cronasia SMS integration review

Reviewed 2026-11-12, Asia/Manila, against [implementation-plan.md](implementation-plan.md), [progress.md](progress.md) and the latest dashboard-only presentation request.

**Result: functional integration verified for continued local testing and school UAT; production deployment is not cleared.** The required school grading interpretation is still unconfirmed, dependency advisories remain, and production configuration/recovery have not been accepted. No production rule, rate, account or financial event was invented to make the workflow pass.

## Official modules and supporting functionality

| Module | Verified integration | Operational boundary |
| --- | --- | --- |
| Automated Scholarship Validation and Evaluation | Registrar revisions and reported subject grades; Coordinator assessment and immutable snapshots; exact-semester external decisions/private evidence; guarded activation and award-conflict prevention | Production grading conversion/threshold/full-scale interpretation remains unconfirmed, so complete verified data still produces `needs_review`. CoScho/custom profiles are not guessed |
| Billing, Liquidation, and Deduction | Configurable approved rates, budgets, draft/submitted batches, independent approval, preserved adjustments; actual receipts, scoped cash allocation, school/direct payments, exact-case authorized deductions, evidenced settlement and independently accepted liquidation | A budget or bill never creates received/paid cash. Missing provider deduction authority blocks a deduction. Reversals/returns/transfers, partial deduction settlement and official provider filing rules remain outside the implemented recording workflow |
| Reporting, Analytics, and Overview Dashboard | Live distinct counts, current validation states, program distribution, financial indicators, filters and contained Recent Validations table; existing financial allocation report/CSV and printable liquidation | General `/reports` pages remain removed per the latest request. Dashboard counts describe current recorded state for selected benefit periods, not reconstructed historical as-of state |
| Supporting functions | Staff authentication/provisioning policies; scholar information and protected identifiers; scholarship/academic program separation; periods, assignments, immutable audit/history and private storage | Actual school source/active-period configuration and approved staff-role assignments are deployment inputs. No school SIS, provider portal or bank integration is claimed |

## BPM coverage of the requested ten-step process

Coverage follows the user's ten-step business process. The end-to-end test uses real Laravel HTTP endpoints and actual session login/logout for each role. Reference accounts/programs/periods are isolated fixtures; business records are created through their normal workflows.

| Step | Role and transition tested | Result |
| --- | --- | --- |
| 1. Staff login | Administrator, Registrar, Coordinator and Finance authenticate through the existing login route; five roles additionally checked in Chrome | Passed; native authentication, role gates and denied requests retained |
| 2. Register scholar and assignment | Administrator registers the Student and creates a pending assignment; duplicate institutional ID rejected | Passed; registration does not itself activate an award |
| 3. Registrar academic verification | Registrar records subject values, source reference and private certificate; server actor/time and precise reported text retained | Passed; verified history/corrections and stale forms covered by the regression suite |
| 4. Automated school evaluation | Coordinator creates the semester context, confirms the applicable official profile, records verified facts/evidence and runs evaluation | Production policy correctly returns review and blocks activation. A separate test-only resolved grading prerequisite exercises downstream success; the deployed policy is unchanged |
| 5. External approval | Coordinator records the issued authority decision/reference/date and accepted private proof for the exact assignment/semester | Passed; it remains separate from school qualification. Supersession, revocation, expiry and missing proof are covered by existing tests |
| 6. Activate scholarship | Coordinator activates from the current qualified run plus exact external approval; repeated activation rejected | Passed under the synthetic grading prerequisite; database/concurrency tests enforce one active scholarship across programs |
| 7. Semester billing | Administrator configures the rate/budget; Finance creates/adds/submits; another Administrator approves. A second batch cannot reserve the same student/semester | Passed; no receipt or payout exists merely because the allocation is approved |
| 8. Funding and student payments | Finance records actual receipt/payment drafts and allocates posted school cash; independent Administrator verifies evidence and posts | Passed; institutional Student ID snapshot, exact centavos, release limits, idempotency, separate direct payments and competing posting covered |
| 9. Liquidation evidence | Exact-case provider/beneficiary authority supports a deduction; separate settlement proof is verified; incomplete report is blocked, refreshed, submitted and independently accepted | Passed; immutable payment/settlement coverage reconciles without double counting |
| 10. Monitoring and reports | Dashboard reconciles to the same records; existing financial report/CSV and printable liquidation render | Passed; dashboard-only analytics preference preserved and removed report URLs remain 404 |

The end-to-end synthetic financial example explicitly configures PHP **7,500.25** as a test rate: actual school receipt **7,500.25**, student net **7,000.00**, authorized/settled deduction **500.25**, and remaining allocated cash **0.00**. The dashboard student-payout indicator is **7,000.00**, excluding the deduction. This fixture tests centavo accuracy; it does not replace the school's configured reference amounts or establish deduction permission.

## Verification results

| Check | Result |
| --- | --- |
| Unchanged pre-review Laravel baseline | 304 passed, three MySQL-only skips; 3,100 assertions |
| Final available SQLite suite | **307 passed, three MySQL-only skips; 3,348 assertions; zero failures/errors** |
| Full isolated MariaDB 10.11.16 suite | **310 passed; 3,399 assertions; zero failures/errors/skips** |
| New process-level feature tests | Two HTTP lifecycle cases, including the real unresolved-policy block and the synthetic downstream path; 242 assertions |
| Concurrent updates | Real two-process active-award/billing uniqueness, academic revision/evaluation sequencing, competing budget approvals and repeatable-read payment posting passed on MariaDB |
| Local database relationships | **107 foreign keys checked per database / 428 checks total** across the main application and three demos; no orphaned references or duplicate active-award/student-semester bill/student-program-semester evaluation contexts |
| Evidence integrity | **940 documents verified** across the three demos (310 + 310 + 320), with matching private files/checksums. Main database has no uploaded evidence |
| Financial history | Posted gross stays within effective allocations; accepted demo liquidation remains complete; original submissions, adjustments, snapshots, actors and append-only audit/history guards pass |
| Dashboard reconciliation | Main database: 3 students and zero awards/funding/payouts. Financial demo: 80 students, 36 active awards, 18 pending checks, 36 school-eligible students, 18 pending external decisions, PHP 280,500 submitted bills, PHP 7,500 actual funding, PHP 22,500 student net and 17 incomplete approved batches |
| Browser checks | Five staff roles, 18 normal workflow pages, desktop/mobile layouts, scholar/assignment forms, Registrar editor, evaluations, billing, payments, liquidation, evidence responses, existing CSV/printing and dashboard-only navigation passed. Screenshots inspected; no JavaScript exceptions |
| Access and errors | Unauthorized role requests, ownership mismatches, removed/missing routes, invalid form fields/files, stale versions, missing/corrupt evidence, self-approval, duplicate records and transactional audit failure rollback covered |
| Build/runtime | Production Vite build, Blade compilation, Composer validation/non-dev platform checks, Pint and whitespace checks passed. Local runtime: PHP 8.5.12, Laravel 12.69.2, MariaDB 10.11.16 |

Browser checks use the local PHP server and compiled Vite assets; external font availability and production hosting/static delivery are not certified. The suite uses in-memory SQLite or newly created isolated MariaDB databases, never a reset of existing application/demo databases. Before/after preservation checks passed for the main database and previous demos, and the successful browser run preserved all non-infrastructure financial-demo tables and `.env`. No existing user password/role, student, academic record, monetary history or private file was intentionally changed. Existing concurrency tests retain their uniquely named synthetic cash-race database as evidence.

## Confirmed bug and test failures resolved

1. **Academic certificate download integrity:** the Registrar download action checked ownership/private location and file existence, but served a file even after its bytes changed. A regression reproduced HTTP 200 for corrupted evidence. The action now requires the stored SHA-256 checksum to match a streamed hash before download, returning 404 otherwise. Verified record/document metadata remains untouched, and existing authorization/no-store/nosniff behavior is retained. Legacy certificate rows without a verifiable checksum are not served as verified evidence.
2. **MariaDB enum rejection assertion:** strict MariaDB rejects an invalid `academic_standing` enum with error 1265 before the CHECK guard runs. The existing integrity-test helper misclassified that valid database rejection as an unrelated SQL failure. It now recognizes that specific diagnostic while continuing to reject unrelated missing-table/column errors; the test also verifies no invalid row persisted.
3. The first MariaDB run had **four failing cases**: three older permission fixtures lacked checksums required by the new download guard, and one was the enum diagnostic above. The fixtures now contain the checksum of their actual bytes. The complete rerun passes all 310 tests. No remaining Laravel test failures are known from this review.

No unrelated feature, redesign, dependency upgrade or new migration was introduced. The existing Cronasia branding, navigation, charts, table fix, business policies and approved reference amounts remain intact.

## Deployment readiness and remaining limitations

**Ready:** continued local demonstration, regression testing and supervised school UAT of the implemented recordkeeping/permissions/history workflows. Successful downstream examples must keep their synthetic academic prerequisites clearly identified.

**Not ready for unrestricted production processing.** Resolve these concrete requirements first:

| Requirement | Current state / required work |
| --- | --- |
| Approved school academic interpretation | Confirm and implement grade conversion, passing thresholds/full scale and required interpretation. Confirm CoScho/custom program eligibility where used. Main application active/source periods are currently unconfigured; supply the actual school mapping/reference |
| Dependency maintenance | Fresh `composer audit --locked --format=json` reports **11 advisories across six packages**: league/commonmark and symfony/http-foundation, http-kernel, mime, routing and yaml. Fresh `npm audit --json` reports **six affected packages: two critical, three high, one moderate**, in the declared development dependency graph. Remediate compatible versions and rerun the suites/audits before deployment; these counts alone do not demonstrate exploitability through this application |
| Production environment and secrets | Configure production mode/debug off, HTTPS, secure session cookies, trusted host/proxy handling and least-privilege database/private-disk access. Retain the encryption APP_KEY needed for existing protected identifiers. Replace example/demo credentials and explicitly provision approved staff roles on the production installation |
| Recovery and operating acceptance | Establish and restore-test consistent database, private evidence and encryption-key backups. Confirm evidence retention/access, storage monitoring, real mail/password-reset delivery and production error logging. No production restore or deployment drill was performed |
| Email verification policy | `User` still does not implement `MustVerifyEmail`; the existing `verified` middleware is not an email-verification gate. Decide the school's policy and implement/enforce/test it if required; do not treat the middleware label as enforcement |
| Financial/provider exceptions | Basic independently verified recording works. General provider deduction permissions, official filing templates/deadlines, production reversal/return/transfer workflows, partial deduction settlement and exceptional historical repairs still require reviewed policy/workflows |
| Production database and volume | Current full verification targets MariaDB 10.11.16 with strict mode. Verify the exact deployment database/runtime and operational volume; the two-writer tests and small fictional dataset do not constitute a load test |
| Historical reporting and external systems | Dashboard is current recorded state filtered by benefit period. Reconstructed as-of history, bulk historical imports and official provider exports remain separate. Staff record actual issued decisions/receipts/payments; software does not execute bank transfers or grant provider approval |

## Reproduction and evidence

Run `php artisan test --compact --no-ansi` for the available SQLite suite. MySQL-only cases require a **new isolated MySQL/MariaDB database ending in `_test`**, supplied through environment variables; never point the test runner at an application/demo database. The full MariaDB run used a guarded random integration database and removed only that database on completion.

Local review artifacts are ignored under `storage/framework/testing`: `integration-sqlite-junit.xml`, `integration-review-baa1b9ff83a1/mysql-junit.xml`, the accompanying test output, dependency audit JSON, browser screenshots and read-only review helpers. The durable process/regression coverage is in the repository test sources at `tests/Feature/Integration/ScholarshipLifecycleTest.php` and the existing module/integrity tests. This document and Task 12 in [progress.md](progress.md) are the final status record.
