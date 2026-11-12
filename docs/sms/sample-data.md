# Development sample data

Verified on 2026-11-11 against the implemented records, Registrar verification, direct validation, exact-semester external approval, activation, billing, budget, independent approval, adjustment and reporting workflows. No schema changes or additional business modules were needed.

## Scholar Information extension (2026-11-11)

The current StudentFactory/DevelopmentSampleSeeder now populate the extended fields documented in [scholar-information.md](scholar-information.md). Fresh datasets register five explicitly fictional `[SAMPLE]` academic programs and produce **45 complete / 35 incomplete** profiles among 80 students. Contact placeholders are non-dialable; LRN is `0` or null, PhilSys stays null, and 4Ps values use `SAMPLE-*` references. Parent/address/sex/birthdate fields and nullable optional indicators are fictional; historical courses remain unspecified rather than inventing old school offerings. Profile completeness does not drive eligibility.

Migration `000007` has been applied safely to the existing application and older demo. Their existing students are unchanged, with new columns null. Completed fixture markers still prevent automatic retroactive updates. No database reset or unrequested reseeding occurred.

The separate **`cfci_sms_profiles_demo`** was created for the extended fixtures. Initial seeded counts below remain the same except **five academic programs and 1,029 audit events**. Browser verification added two clearly identified `SAMPLE-UI-*` students, so its present totals are **82 students, 46 complete / 36 incomplete and 1,032 audit events**. All 36 billing items retain valid eligibility and totals, and actual receipts/payouts remain zero. A complete profile can still be failed, pending, unassigned or unbillable.

Safe create/rerun command (existing completed records and browser edits are preserved):

```powershell
php artisan sms:seed-demo --database=cfci_sms_profiles_demo --academic-year=2026-2027 --synthetic-policy --no-interaction
```

For browsing, use the same separate-terminal instructions below with `DB_DATABASE=cfci_sms_profiles_demo`. Initial sample-role logins/passwords are unchanged. Real application academic-program names must be registered by an Administrator; fictional offerings are not inserted into that database.

Latest verification: **260 passed, two skipped / 2,544 assertions** in the full SQLite suite; **11 sample tests / 360 assertions**; **41 new Scholar Information cases / 305 assertions**. Actual MariaDB demo checks verified **103 FKs**, retained billing relationships and safe full-table reruns. The earlier Task 8 counts/results below describe the original dataset before this extension.

## Where the data lives

- **Application database `cfci_scholarship`:** added only 40 academic-period rows covering 20 academic years, 2007-2008 through 2026-2027. All pre-existing rows across 54 tables, including the three manually entered students, account credentials, roles, program configuration, audit history and active/source context, were preserved. `.env` is unchanged.
- **Separate database `cfci_sms_samples_demo`:** contains the complete fictional dataset below. The ordinary application connection still points to its original database. Demo records are not copied into it.
- **Normal sample profile:** `DevelopmentSampleSeeder` creates nonfinancial, pending/review scenarios using the unchanged production policy. It is independently available for local/testing environments; only its calendar seeder was run in the original database.

CFCI's grading interpretation and CoScho eligibility profile remain unconfirmed. Genuine eligible validations and production billing cannot be fabricated from unknown rules. `SyntheticDemoSeeder` temporarily resolves the academic prerequisite **only while creating the isolated financial demonstration**. Its context reference, evaluation explanations, evidence, rate references and audit events explicitly identify the synthetic scenario. It does not create a numeric grading scale or a CoScho eligibility profile. The production policy binding is restored even on failure; subsequent re-evaluation still uses the ordinary policy and can return pending review.

## Seeders, factories and configuration

| Component | Purpose |
| --- | --- |
| `AcademicYearSeeder` | Add missing consecutive years and semesters without changing existing dates or configuration. |
| `DevelopmentSampleSeeder` | Fictional current/historical students, academic evidence/grades, pending assignments, semester contexts, review evaluations and pending external decisions. |
| `SyntheticDemoSeeder` | Isolated eligible/ineligible/pending examples; workflow-created rates, budgets, billing, independent approvals and corrections. |
| `DatabaseSeeder` | Existing local test Administrator, created only when missing; existing credentials and roles are never reset or elevated. It does not automatically run sample seeders. |
| `StudentFactory` | Filipino names, reserved `SAMPLE-*` identifiers, fictional `example.test` emails, no potentially real phone numbers. |
| `AcademicRecordFactory` / `AcademicRecordGradeFactory` | Pending academic records and independently reported subject values, without inferred grade conversion. |
| `ScholarshipAssignmentFactory` | Valid related student/program/period; pending assignment by default. |
| `config/sample-data.php` | Explicit terminal year, confirmed-year fallback, configurable initial program references and sample password. |
| `sms:seed-demo` | Create or reopen an owned, separate MySQL/MariaDB demo database using additive migrations. |

The sample profile and each reserved staff/student identity have ownership/completion audit markers. Existing manual collisions cause a transaction rollback. Completed datasets are left unchanged on rerun, including manual edits to sample records and passwords. The demo command refuses the current application database, an existing database without its ownership marker, production environments, implicit synthetic mode, and changing the year of an existing dataset. It never resets a database.

Database work is transactional. Only newly created private evidence files are cleaned up on failure; previously committed evidence is retained. Demo financial work commits separately from its normal baseline so an interrupted attempt can be retried. No destructive database commands are used.

## Verified row counts

Counts are totals in a newly created database for each profile. The complete demo column is the actual persistent database created locally; the normal profile is verified in isolated tests.

| Existing collection | Normal profile | Complete isolated demo |
| --- | ---: | ---: |
| Academic years / semester rows | 20 / 40 | 20 / 40 |
| Scholarship programs | 3 | 3 |
| Staff accounts / role memberships | 5 / 5 | 5 / 5 |
| Students | 80 | 80 |
| Scholarship assignments | 60 | 60 |
| Academic record revisions | 100 | 140 |
| Subject grade rows | 500 | 700 |
| Program/semester terms | 3 | 6 |
| Internal semester validation contexts (`scholarship_applications`) | 60 | 60 |
| Evaluation runs | 60 | 120 |
| External approval history | 60 | 102 |
| Approved semester rate revisions | 0 | 18 |
| Budget revisions | 0 | 18 |
| Billing batches / items | 0 / 0 | 36 / 36 |
| Independent batch approvals | 0 | 18 |
| Append-only billing adjustments | 0 | 16 |
| Draft, unposted funding placeholders | 0 | 18 |
| Private supporting documents | 100 | 310 |
| Audit events | Generated by workflows | 949 |
| Payouts / posted receipts | 0 / 0 | 0 / 0 |

Programs, five supported staff roles, singleton context and program/semester scopes are reference/configuration records, so they are not inflated to 15. Payout, deduction, funding allocation and liquidation posting services remain unimplemented; no unsupported financial transactions are fabricated. Draft funding rows exercise the existing receipt relationship without claiming funds arrived.

### Scenarios

- 60 current students plus 20 archived historical students; 54 active and 26 archived overall. Historical academic examples span 2007-2008 through 2025-2026 and include both semesters. They carry no unsupported historical scholarship assignments or rates.
- Normal profile: all 60 validations require review; all assignments and external decisions remain pending. Verified reported academic evidence alone never makes a student eligible.
- Complete demo: latest evaluations contain **36 synthetic passes, six failures and 18 review cases**. The review cases cover incomplete academic evidence, unconfirmed CoScho rules and inactive students. There are 36 active and 24 pending assignments, with no conflicting active assignments.
- 60 original pending external decisions are retained, followed by 42 fictional verified approvals for the exact assignment and semester. Six failed students have external approval but still cannot be billed.
- 36 batches, one eligible student per batch: **18 independently approved, nine submitted awaiting approval and nine draft**. Finance prepares/submits; a different Administrator approves. Each scope receives sequential batch numbers through its existing workflow.
- Configurable semester references are **TES PHP 7,500; TDP PHP 13,500; CoScho PHP 45,000**. The demo command copies the current application reference values on initial creation. Three explicit reconfirmations per program/semester retain those configured amounts; they do not assert a historical price change. Existing completed billing never reprices on rerun.
- Six scopes cover three programs and both semesters of 2026-2027. Only demo Semester 1 is the active/source period. CoScho has reference rates/budgets and pending records, with no eligible billing. Calendar dates stay null because the school calendar is unconfirmed.
- Eight approved allocations each have a PHP 100 decrease and restoration, producing 16 immutable adjustment entries while retaining the original allocation amount.
- Approved allocations: TES PHP 67,500 and TDP PHP 121,500. Awaiting approval: TES PHP 37,500 and TDP PHP 54,000. **Actual received and released amounts are PHP 0.00 throughout.**

The `is_open` period flag controls maintenance access, not the actual active/source validation selection. Calendar seeding preserves both flags and singleton context. In the original database the active/source selection remains unconfigured; school configuration is still required. Only the separate demo deliberately selects one synthetic current semester.

## Safe commands

Run from the project directory with local development configuration:

```powershell
# Add only missing calendar rows to the selected development database.
php artisan db:seed --class=AcademicYearSeeder --no-interaction

# Rerun the complete separate demo; completed data remains untouched.
php artisan sms:seed-demo --academic-year=2026-2027 --synthetic-policy --no-interaction

# Optional nonfinancial pending samples in the currently selected LOCAL database.
# This adds fictional records to that database; it is not the complete billing demo.
php artisan db:seed --class=DevelopmentSampleSeeder --no-interaction

# Future dataset: use a different demo name to preserve the previous dataset.
php artisan sms:seed-demo --database=cfci_sms_2028_demo --academic-year=2028-2029 --synthetic-policy --no-interaction

php artisan test --filter=DevelopmentSampleSeederTest
php artisan test
```

Academic years are generated from 2007 through the explicit `--academic-year` / `SMS_SAMPLE_ACADEMIC_YEAR` selection. Without an override, generation reaches at least the school-confirmed 2026-2027 and extends to a later configured active year. It does not guess a school rollover date from the calendar. A future year needs no seeder code change. School policy authorization is still separate from sample generation.

To browse the existing demo, open a **separate PowerShell terminal** from the project directory:

```powershell
$env:DB_DATABASE = 'cfci_sms_samples_demo'
$env:DB_URL = ''
php artisan serve --host=127.0.0.1 --port=8001
```

Open `http://127.0.0.1:8001/login`. These are process-scoped environment overrides; closing that terminal leaves `.env` and the ordinary server unchanged. Do not use cached production configuration for this local command.

Fresh datasets use the two operational roles below. Normal samples create one account per role; synthetic financial demos add a second Coordinator identity for independent review. Existing completed datasets and legacy accounts are preserved, so their historical account counts may differ. See [auth-access-review.md](auth-access-review.md).

| Demo role | Login email |
| --- | --- |
| School Scholarship Coordinator | `sample.coordinator@example.test` |
| Registrar Staff | `sample.registrar@example.test` |
| School Scholarship Coordinator, separate financial reviewer (synthetic demo only) | `sample.coordinator.reviewer@example.test` |

Initial password: **`CfciSample2026!`**, configurable through `SMS_SAMPLE_PASSWORD` before initial creation. Reruns do not reset passwords or removed roles. Accounts and evidence are development fixtures only; they are absent from the original application database.

## Verification

- **Full SQLite suite: 219 passed, two skipped, 2,236 assertions.** The skips are the two MySQL-only concurrency cases. Prior Task 7 MariaDB concurrency results remain separately recorded in `progress.md`; this task does not claim a new full MariaDB test run.
- **11 sample-data tests / 357 assertions:** complete/future years, existing dates/context preservation, safe default factories, foreign keys, review-only production policy, full billing scenarios, repeated seed hashes, credential/role preservation, student/staff identifier collisions, invalid year, production/isolation guards, and failed demo transaction/file cleanup with policy restoration. These new tests create schemas additively in fresh in-memory connections and never reset an existing database.
- Actual persistent MariaDB demo: verified **101 foreign-key constraints**, all **310 private document checksums**, semester-specific rates, batch totals, eligible active assignments and zero duplicate student/semester bills. Failed, pending, CoScho and inactive test cases were all rejected by the real billing eligibility service after the synthetic policy was restored.
- Repeating the full demo preserved row hashes across all 54 existing tables. Attempting a different year in the same demo was rejected and also preserved those hashes.
- Before/after application comparison confirmed all existing rows across 54 tables and `.env` unchanged, with only the requested 40 academic-period additions. No new school validation, financial row, account or role was inserted into that database.
- Pint and whitespace checks passed. No frontend behavior changed in this task.
