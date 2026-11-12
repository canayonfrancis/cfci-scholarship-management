# Billing linked to independent scholarship rosters

Task 30, 2026-11-13 (Asia/Manila). Implements the roster-relationship portion of HI-01 from the [final audit](../steps/09-final-system-readiness-audit.md). CR-01 and CR-02 are not resolved by membership.

## Behavior and UI

1. Create the program/period roster under **Scholars -> Semester rosters** and assign students through the existing Scholars workflow. Pending assignments and roster preparation still require no rate or bill.
2. In **Scholarship funds and allocations -> Prepare a semester allocation batch**, choose the program and academic year/semester, then the **Scholarship semester roster**. Only matching roster options are enabled/displayed; changing program/period clears the selection. A missing roster has a link to the existing roster page. The approved rate requirement for financial preparation remains.
3. Creating the draft saves the existing `billing_batches.scholarship_batch_id`. The detail page links to that roster. One roster can have several separately numbered financial submissions; creation alone adds no billing item.
4. Candidates are limited to exact roster student/assignment/program/period membership. Existing eligibility rejection reasons remain visible. Both item addition and final submission validate that membership again under transaction locks; HTTP filtering alone is insufficient.
5. Submission still rechecks existing eligibility, rate, entitlement and external approval. It additionally fails closed with a `classification` validation error while approved Form 2/Form 3 templates and current payable classification are unavailable. A failure rolls back all item finalization, audit inserts, header/version changes and submission provenance.

Normal Coordinator/Registrar permissions, legacy technical/compatibility permissions and independent financial approval remain unchanged. The server validates every roster selection independently of the browser. No client-supplied paid, approved, submission or classification flag authorizes a transition.

## Data and historical preservation

**No database change or migration.** Reuses existing roster/header/membership tables, nullable billing roster FK, program/period scope triggers, composite member constraints, immutable historical guards, and global student/semester billing uniqueness. There is no new relationship, transaction ledger, roster backfill or change to student identity.

Existing unlinked headers remain null, including submitted/approved history. Reports and detail pages say **No recorded roster link**; they do not assume all unlinked records are historical submissions or guess a roster. Existing unlinked drafts can be viewed and their existing draft details/removal handled under current rules, but cannot acquire new items or be submitted without the required relationship. No relink/update control is added: prepare a new linked draft instead. Existing draft reservations continue to prevent duplicate billing; resolving a reservation uses the existing authorized draft-removal action, never history deletion.

Already submitted records remain readable and eligible for their established funding/payment/liquidation paths under their existing safeguards. The new final-submission block does not reopen or reclassify accepted financial history. Reports filter only recorded links; unlinked history remains in unfiltered output and outside a specific-roster filter. Roster-based not-billed logic continues matching any submitted student/program/period bill, avoiding accidental rebilling across rosters.

## Remaining institutional dependencies

- **CR-01:** production `SchoolGradingPolicy` still returns review pending approved grading interpretation/source rules. No ordinary new qualification/activation is enabled here.
- **CR-02:** no Form 2/Form 3 code, mapping, version or historic classification is invented. `BillingRoster::submissionClassification()` rejects final submission explicitly until approved, revision-traceable payable classification exists. A future implementation must check each relevant current academic revision/template and reject Form 3, unknown and stale classifications. No operational setting disables this guard.
- The full teacher workflow remains not ready for unconditional UAT. Existing account/security/deployment findings from the audit are outside HI-01 and remain unchanged.

## Verification

- Full SQLite in-memory suite: **491 passed, 11 database-specific skips, 6,511 assertions**, zero failures/errors; about 58 seconds. Evidence: `storage/framework/testing/hi01-full-sqlite.xml` and `.txt`.
- Full isolated **MariaDB 10.11.16: 502 passed / 6,658 assertions**, zero failures/errors/skips; about 285 seconds. Evidence: `storage/framework/testing/integration-review-7bfb65136a47/mysql-junit.xml`, `mysql-suite.txt` and `hi01-mariadb-final-runner.txt`. All 11 database-specific cases executed. The new linked-reservation race passed with 11 assertions; the updated manual-number race passed with eight assertions. MariaDB used Laravel's `mysql` driver; a separate Oracle MySQL server was not tested.
- New `BillingRosterIntegrationTest`: **11 passed / 89 assertions** on SQLite. Covers persisted links, multiple headers, missing/nonexistent/wrong program/wrong period, membership-filtered candidates, direct nonmember/assignment attacks, duplicate billing, revalidation at submission, explicit real classification blocking and atomic rollback, direct action validation and unlinked legacy retention.
- Reporting suite: **30 passed / 489 assertions** on SQLite. All six modes now test specific-roster filtering against two normally HTTP-created linked billing headers and exact financial totals. Conditional success fixtures assume resolved grading/classification only in tests; they do not create an operational classifier. Legacy unlinked financial history is constructed explicitly as retained pre-integration fixtures rather than weakening current action checks.
- Database coverage includes the existing competing manual-number test with persisted roster assertions and a new two-writer linked-batch reservation test under an old repeatable-read snapshot. The losing writer must return a billing validation error with one bill/audit remaining and unchanged losing-header version. SQLite skips this database-specific race; execute it on MariaDB.
- Existing rate, external approval, financial cap, funding, payroll, signature, liquidation, authorization and immutable-history suites also run. A populated roster rollback test now verifies the existing safe refusal to drop roster history, rather than attempting a destructive rollback after linked fixture creation.
- Local read-only HTTP checks: **32 responses returned 200**, including all six HTML/CSV/print modes; CSV callbacks executed. All **34 business/account fingerprints, 102 trigger definitions and `.env`** match both before/after those reads and the preceding audit baseline. Final post-test evidence: `storage/framework/testing/bpm-readiness-e39a74731163/`; earlier verification is retained in `bpm-readiness-b28a1cfb7a85/`. No configured-school database migration/reset/seed, account change or real student update was executed.
- Scoped Pint, Blade compilation, all 92 local documentation links and `git diff --check` pass. This task does not claim new interactive-browser, real-bank or handwritten-signature authenticity verification.

The first MariaDB run had 500 passes and two failing older fixture contracts: `AllocationApprovalConcurrencyTest` built only the pre-roster schema, and `FinancialPostingConcurrencyTest` omitted the newly required roster selection. Their schema setup/request data were updated, preserving the original budget/cash assertions. The full rerun above passed. Earlier focused SQLite fixture/assertion failures were also corrected before the passing full SQLite run. These failures were not suppressed or counted as successful tests.

Source preservation check: 280 of the prior audit's 299 protected files remain byte-identical; the 19 changed existing files are exactly the intended application/seeder/test files in the inventory below. Four source/test files are new. Migrations, routes, authentication, dashboard, configuration, dependency locks and `.env` remain unchanged. The preceding audit documentation was retained.

The existing explicitly guarded synthetic demo remains usable with the new relationship: it creates fictional rosters/members and restores both original services in `finally`. Its synthetic classification prerequisite performs the same strict `SampleData::guardSynthetic()` check as the established synthetic academic path: only testing SQLite in memory or an explicitly owned/named/enabled demo database, never the configured school database or production. It produces no approved Form 2/Form 3 classification. The operational binding always rejects missing classification. The demo was exercised only inside existing isolated automated fixtures, not seeded into school records.

## Files changed for HI-01

Application:

- `app/Actions/Billing/ManageBillingBatch.php`
- `app/Http/Controllers/BillingBatchController.php`
- `app/Models/BillingBatch.php`
- `app/Services/Billing/BillingRoster.php` (new)
- `app/Services/Reporting/ScholarshipReports.php` (unlinked label only)
- `resources/views/billing/index.blade.php`
- `resources/views/billing/show.blade.php`

Existing isolated demo compatibility:

- `database/seeders/SyntheticDemoSeeder.php`
- `database/seeders/Support/SyntheticBillingRoster.php` (new, guarded synthetic prerequisite)

Tests and fixtures:

- `tests/Concerns/BuildsBillableAllocations.php`
- `tests/Concerns/BuildsPayrollRecords.php`
- `tests/Feature/Billing/BillingRosterIntegrationTest.php` (new)
- `tests/Feature/Billing/BillingBatchTest.php`
- `tests/Feature/Billing/FinancialRecordingTest.php`
- `tests/Feature/Billing/ProgramBatchNumberTest.php`
- `tests/Feature/Billing/ScholarshipFundsTest.php`
- `tests/Feature/Database/BillingRosterConcurrencyTest.php` (new)
- `tests/Feature/Database/BatchNumberConcurrencyTest.php`
- `tests/Feature/Database/AllocationApprovalConcurrencyTest.php`
- `tests/Feature/Database/FinancialPostingConcurrencyTest.php`
- `tests/Feature/Integration/ScholarshipLifecycleTest.php`
- `tests/Feature/Reporting/ScholarshipReportsTest.php`
- `tests/Feature/Seeders/DevelopmentSampleSeederTest.php`

Documentation: this guide, `docs/sms/progress.md`, the workflow index, workflow 04 and the gap-analysis amendment. The preceding audit document is retained as the findings at its audited commit; it is not rewritten to imply CR-01/CR-02 are fixed.
