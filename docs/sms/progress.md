# Scholarship Management System progress

Cronasia Foundation College, Inc. (CFCI)  
Last updated: 2026-11-11, Asia/Manila  
Base application commit: `a345c39`  
Current milestone: Task 3 Scholarship and Scholar Records implemented, tested, and enabled locally; eligibility evaluation, billing, and reporting workflows remain outside this task.

The design and decision register are in [implementation-plan.md](implementation-plan.md). Task 2 authorized the database foundation; Task 3 authorizes supporting records and their staff permissions. Unknown eligibility, financial, and external-approval rules remain unresolved. This tracker distinguishes recordkeeping from evaluation or financial authorization.

## Task 3: Scholarship and Scholar Records — completed

### Task 2 verification

Before implementation, the foundation migration was confirmed as applied in `cfci_scholarship_management`. The local server identifies itself as **MariaDB 10.4.28**, accessed through Laravel's MySQL driver. All **61 baseline tests / 230 assertions** passed on a newly created disposable database on that server, including the two-writer uniqueness and migration rollback checks. SQLite's baseline was **60 passed / 220 assertions**, with the MySQL concurrency test skipped. No application data was reset for verification.

### Functionality delivered

- Scholar directory with pagination, name/student-number/email search, record-status and active-program filters, add/edit forms, individual details, and scholarship history. `students` remains the single identity table; no duplicate `scholars` table was introduced.
- TDP, TES, and EDUKAR program list/search/add/edit/view screens, reference amounts, planning durations, and availability for new active assignments. Codes retain their original identity. Reference amount changes require a school source/reason and are audited; existing semester terms and entitlements are not recalculated.
- Academic-year and semester list/filter/add/edit/view screens using `academic_periods`. Calendar dates may remain unknown. Duplicate year/semester pairs and reversed date ranges are rejected. A semester already referenced by records retains its academic-year label and code; dates and open/closed status remain maintainable with audit history.
- Nested assignment creation and status forms, protected by scoped route binding and policies. A request cannot update another scholar's assignment by changing its URL or posted student ID.
- Manual assignment states: pending, active, ended/historical, and relinquished. Activating is explicit registry recordkeeping; it does not create an evaluation, external approval, entitlement, bill, or payout. Planning duration never automatically closes or disqualifies a scholar.
- One active scholarship across programs is enforced by the existing database unique index and a transaction locking the student. Concurrent uniqueness failures become field-validation messages. Duplicate pending/active program/start-semester records are also rejected inside that lock.
- Active assignments can remain active or be closed; they cannot be moved back to pending to free the active slot. New pending assignments can be activated or closed. Legacy suspended records remain read only until suspension/reactivation semantics are confirmed.
- Relinquishment is stored as existing status `ended` plus `closure_reason = relinquished`, preserving the original program, scholar, start period, actors, reason, and dates. Closed records stay visible and cannot be edited, reopened, or deleted. Exact historical dates can remain unknown; the closing semester and reason are required for new closed records.
- An additive migration, `2026_11_11_000001_preserve_closed_scholarship_assignments.php`, adds the nullable indexed closure reason and database triggers preventing edits/deletes of ended assignments. Existing rows are not reclassified or rewritten.
- Atomic append-only audit events for scholar, catalog, period, and assignment mutations. Each contains the acting staff member, action, limited before/after fields, timestamp, and supplied reason. An audit failure rolls back its record mutation.
- Archiving a scholar with an active scholarship is rejected rather than silently closing the scholarship. Disabling a program prevents new active assignments but retains its existing assignments. No destructive record routes are exposed.
- Existing dashboard layout, images, cards, brand colors, and responsive navigation are reused. New record styles are scoped so the dashboard's fixed table layout does not affect record tables. Mobile tables scroll inside their cards without widening the page. Navigation now links to Scholars and Scholarships; the records subnavigation exposes Academic periods. Displayed staff roles/initials come from the actual account.
- Linked-account deletion now reports a validation error while retaining the signed-in session. Unlinked account deletion continues to pass its original regression test. No password or login-flow changes were made in this task.

### Staff permissions

The records feature uses the plan's staff roles with the following conservative permission matrix. There is no global administrator bypass for future academic/evaluation/financial policies.

| Role | Read records/history | Add/edit scholars | Manage programs/periods | Create/update open assignments |
| --- | --- | --- | --- | --- |
| `administrator` | Yes | Yes | Yes | Yes |
| `coordinator` | Yes | Yes | No | Yes |
| `registrar` | Yes | Yes | No | No |
| `finance` | Yes | No | No | No |
| `viewer` | Yes | No | No | No |
| Authenticated account without a staff role | No | No | No | No |

All routes require authentication. Laravel policies and Form Requests enforce permissions on direct HTTP requests as well as the interface. No public role editor or automatic role assignment based on email was added. A trusted operator can explicitly provision an existing account with `php artisan sms:grant-role user@example.test coordinator`; supported roles are listed by the command. Grants are idempotent, audited, and do not change credentials.

### Local enablement

The additive Task 3 migration was applied to the verified local database. The optional reference seeder populated TDP/TES/EDUKAR without overwriting existing reference records; EDUKAR's amount remains null. The existing `admin@gmail.com` account was explicitly granted `administrator` through the trusted console command. Account rows, credentials, and existing scholar/semester/assignment counts were checked before/after and were unchanged. No sample scholars or academic periods were inserted in the application database.

Entry points: `/scholars`, `/scholarship-programs`, `/academic-periods`. The existing admin can use these immediately after reloading the app. Other existing accounts need explicit staff-role provisioning; signing in alone does not grant access.

### Current verification

| Check | Result |
| --- | --- |
| Full SQLite suite | **93 passed, 1 skipped, 530 assertions**. Only the MySQL-specific concurrency test is skipped. |
| Full isolated MariaDB 10.4.28 suite | **94 passed, 540 assertions**, including all existing tests and the new records tests. The temporary databases were removed afterward. |
| Records feature coverage | **33 tests** covering role matrices for reads/direct POST/PATCH, search/filter/pagination, validation, immutable identities, duplicate assignments, relinquishment/replacement, cross-scholar access, no mass-assigned roles/accounts, audit rollback, escaped content, and account retention. |
| Migration round trip | Both engines verified Task 2 plus Task 3 migration → rollback → reapply while preserving pre-existing authentication, sessions, and an unrelated sentinel table. |
| Browser workflow | Actual login, semester creation, scholar creation, active assignment, duplicate-active rejection, relinquishment, replacement assignment, retained history, search, and unknown EDUKAR display passed against disposable data. |
| Desktop/mobile review | Seven screenshots inspected/generated at 1440px and 390px widths; mobile navigation worked, pages/forms did not overflow, and no JavaScript page errors were observed. External font requests were blocked in the final automation pass to keep local checks independent of font-provider latency. |
| Build/templates/style | `npm run build`, `php artisan view:cache`, and Laravel Pint passed. No frontend dependency or lockfile changes were required. |

Re-verified on **2026-11-11** against the approved scope: both Task 2 and Task 3 migrations are applied locally, all three program codes are present, EDUKAR's amount remains unknown, and the existing administrator has the required record-management permissions. Fresh full-suite runs again passed **93 tests / 530 assertions on SQLite** (one MySQL-only skip) and **94 tests / 540 assertions on isolated MariaDB**. The production build and Pint also passed. The existing implementation was retained without duplicate controllers, routes, migrations, or data; application records and role assignments were preserved. The desktop/mobile browser evidence above remains from the original Task 3 verification, since no UI code changed during this recheck.

Implementation locations: `app/Http/Controllers/{Scholar,ScholarshipProgram,AcademicPeriod,ScholarshipAssignment}Controller.php`, corresponding Form Requests, `app/Policies/`, `app/Actions/Records/`, `resources/views/{scholars,programs,periods,assignments}/`, and `tests/Feature/Records/ScholarRecordsTest.php`.

### Task 3 boundary

Task 3 is complete. No eligibility engine, Registrar academic-verification UI, external-approval workflow, billing UI/posting service, report generation, or live dashboard statistics were implemented. The current staff matrix is for supporting records; future module permissions and school-specific approval, suspension, correction, renewal, and financial rules still require the decisions below. Closed history corrections must be designed explicitly rather than implemented by reopening/deleting past records.

## Task 2: database foundation completed on 2026-11-11

- Added one additive migration: `database/migrations/2026_11_11_000000_create_scholarship_foundation_tables.php`. It creates the plan's 20 new supporting/domain tables and never recreates or alters existing authentication tables.
- Added 20 Eloquent models with explicit fillable attributes, relationships, nullable unknown amounts, fixed-decimal monetary casts, JSON snapshots, and date casts. The existing `User` model only gained inverse relationships; its authentication behavior and fields remain unchanged.
- Added an optional, idempotent `ScholarshipProgramSeeder`: new TDP/TES records retain the school-stated PHP 7,500/PHP 13,500 figures and planning durations 4/4; EDUKAR's amount is null and planning duration is 5. It uses `firstOrCreate`, preserves existing reference data, and is not automatically invoked by `DatabaseSeeder` or migrations.
- Added database constraints and database-level history protection, including raw-SQL/bulk-write coverage. No controller, route, UI, stylesheet, dashboard fixture, or existing authentication migration was changed.
- Added relational, non-destructive migration/rollback, and two-writer MySQL concurrency tests. Verification used SQLite in memory and a newly initialized, socket-only disposable MySQL instance. The project's configured application database was not migrated, reset, seeded, or otherwise modified.

### Approved naming reconciliation

The user's provisional names map to the Task 1 plan rather than creating competing tables for the same facts:

| Provisional name | Implemented mapping |
| --- | --- |
| `users` | Reuse the existing table and `User` model. |
| `scholars` | `students` / `Student`; scholar status comes from assignments and semester history, not a second person table. |
| `scholarship_programs`, `academic_periods`, `scholarship_assignments` | Same names, with `program_period_terms` for semester configuration. |
| `academic_verifications` | Versioned `academic_records` / `AcademicRecord`, including Registrar verification metadata. |
| `eligibility_evaluations` | `scholarship_applications` and versioned `evaluation_runs`; `external_approvals` records external decisions independently. |
| `billing_batches`, `billing_items` | Same names. One billing item per assignment and academic period across all batches. |
| `batch_fund_receipts` | `funding_receipts` plus `funding_allocations`, preserving receipt identity when money is allocated across batches. |
| `scholar_payouts` | `payouts` linked through billing items to students, plus `deductions`, `liquidations`, `liquidation_items`, and `documents` for student-specific financial evidence. |
| Supporting history/access | `user_roles` and append-only `audit_events`, as proposed in Task 1. |

The latest duplicate-billing requirement supersedes the plan's tentative split-billing proposal. `UNIQUE(assignment_id, academic_period_id)` applies across all batches, including draft/void records; a unique application reference also prevents billing the same semester application twice. Multiple batches can contain different assignments. The same assignment can be billed in a later semester. Partial payouts can be represented without creating another bill, but permission to use them is not assumed. A void bill does not automatically free its unique slot; correction/reissue rules still need R10.

### Integrity implemented

| Invariant | Database implementation |
| --- | --- |
| One active scholarship per student | Stored nullable `active_student_id` generated from assignment status, with a unique index. Inserts and status updates are protected, while multiple historical ended assignments are allowed. |
| Student/program/semester consistency | Composite foreign keys on applications, evaluations, bills, allocations, payouts, and liquidation lines. Context IDs are intentionally explicit so that the database can reject mismatched links rather than merely check that each ID exists. |
| Academic and evaluation history | Unique academic revisions and evaluation run numbers; pinned input/rule snapshots; immutable verified/rejected academic records and completed school decisions; predecessor links constrained to the same student/context. |
| Independent external decisions | Separate assignment/semester-scoped approval records and evidence, with contextual FKs and immutable recorded decisions. Billing guards reject approvals for another semester/assignment and prevent a linked pending approval from being moved out of scope. Assignment-scoped approvals point to a pending/existing assignment, avoiding a circular chosen-approval FK. Date validity, carry-forward, and activation gates are still workflow decisions. |
| Monetary storage | MySQL `DECIMAL(12,2)`, nonnegative amount checks, nullable unknown amounts, and decimal-string Eloquent casts. Posted payouts require known gross/net amounts and cannot have net greater than gross. SQLite uses NUMERIC affinity; MySQL verifies actual precision/scale. |
| Funding and corrections | Receipt/allocation/payout posting keys are unique. Reversal records retain the original and use unique, same-scope reversal links plus a correction reason. Reversal amounts remain positive; future reconciliation must apply their entry type exactly once. |
| Financial history | Triggers reject updates/deletes of posted receipts/allocations/payouts, finalized bills/batches, accepted liquidations/evidence, and audit events, including bulk SQL. Finalized batch contents and accepted liquidation contents cannot be extended or moved into afterward. |
| Deductions | Monetary basis is locked after authorization or parent payout posting; new or existing deductions cannot be attached/moved onto a posted payout. A later one-time full settlement can be recorded without changing that basis; the settled row is immutable. Partial settlements and corrections require reviewed workflow/schema extensions if needed. |
| Student liquidation and evidence | Liquidation lines target exactly one payout or deduction and must match the report's batch; duplicate targets within a submission are rejected. A student's payout is traceable through its bill. Private-document metadata has exactly one existing owner FK. No files are uploaded by this foundation. |
| Referential retention | Business/actor FKs restrict deletion/update; no cascade can remove academic or financial history. Only nonhistorical user-role membership cascades on account removal. Linked-account deletion needs an appropriate UI response/deactivation policy before domain screens are exposed. |

The migration explicitly supports MySQL 8.0.16+ (enforced CHECK constraints) and SQLite with stored generated-column support. SQLite row-check triggers provide the equivalent checks because SQLite cannot add CHECK constraints with ALTER TABLE. Historical-record triggers are installed on both engines. Other database drivers fail before any domain tables are created rather than silently omit integrity rules. Deployment-specific MySQL privileges/version still need confirmation.

### Task 2 verification baseline

| Check | Result |
| --- | --- |
| Full `php artisan test` on SQLite 3.50.4 | **60 passed, 1 skipped, 220 assertions**. The skipped test is explicitly MySQL-only concurrency. All 26 original tests remain in the passing suite. |
| Full `php artisan test` on isolated MySQL 9.2.0 | **61 passed, 230 assertions**; no skips. |
| MySQL two-writer tests | A competing insert blocks until the first commit, then fails with duplicate-key error 1062 for both active assignment and assignment/semester billing uniqueness. |
| Additive migration → rollback → reapply, both engines | Passed with pre-existing account/password hash, session payload, and an unrelated legacy-table sentinel preserved. Only newly created domain tables are rolled back in these disposable tests. |
| Foreign keys/unique/check/history constraints | Exercised through Eloquent and raw writes: orphan/context/approval-scope mismatches, duplicate keys, negative amounts, unknown finalized amounts, incompatible revision/reversal links, immutable records, evidence ownership, locked-parent contents, and deductions moved onto posted payouts. |
| Laravel Pint | Passed for models, the migration, optional seeder, and new database tests. |

Default tests: `php artisan test`. For MySQL integration, configure an isolated database ending in `_test` through process environment variables, clear any `DB_URL` override, and run the same command. The migration/rollback and race tests create and drop their own random temporary databases; the test account needs those privileges. Never point the test suite's `RefreshDatabase` tests at the application database. No reusable credential file was added to the repository.

At Task 2 completion, the additive foundation/reference seed had been verified only on disposable databases. Task 3 subsequently confirmed the foundation was applied locally and applied its own additive migration/reference seed as described above. Future environments can use `php artisan migrate` and the separate `php artisan db:seed --class=ScholarshipProgramSeeder`; no reset command is required.

## Confirmed scope

| Official module | Existing implementation | Remaining implementation |
| --- | --- | --- |
| Automated Scholarship Validation and Evaluation | Relational records, revision/snapshot storage, independent decisions, and one-active constraint | Registrar/Coordinator screens and policies, confirmed automated checks, decision transitions, renewal/activation workflow, and audit-event production |
| Billing, Liquidation, and Deduction | Billing/funding/payout/deduction/liquidation tables, relationships, constraints, and immutable-history storage | Posting/reconciliation services, aggregate limits, reviewed deduction/settlement/correction rules, evidence upload/review, and UI |
| Reporting, Analytics, and Overview Dashboard | Custom dashboard presentation and Manila-time greeting/date; sample statistics/charts | Database reporting, real filters/analytics, financial reconciliation, and real dashboard data |
| Supporting functionality | **Scholar/program/academic-period screens, assignment/history management, staff policies and explicit role provisioning, atomic audit events, linked-account deletion handling**, plus Task 2 persistence | Evidence upload/download, future module permissions, and any reviewed history-correction workflow |

Confirmed program facts: TDP PHP 7,500 per semester, TES PHP 13,500 per semester, EDUKAR amount unconfirmed; school planning durations are 4/4/5 years respectively. These durations are not unconditional eligibility cutoffs. One active scholarship per student and semester billing with multiple batches are confirmed.

## Analysis completed on 2026-11-10

- [x] Inspected repository structure and local instructions; no applicable `AGENTS.md` was found.
- [x] Verified installed Laravel **12.69.2** and the declared PHP **^8.2** requirement.
- [x] Inspected routes, all application models/controllers, authentication, Form Requests, migrations, factory/seeder, configuration, frontend entry points, and dashboard/layout components.
- [x] Identified reusable authentication/profile behavior and dashboard presentation.
- [x] Confirmed only Laravel infrastructure tables are declared; no scholarship or financial domain model exists.
- [x] Mapped missing functionality to the three official modules and supporting records.
- [x] Identified program-label, approval-state, authentication, setup, and placeholder-data conflicts.
- [x] Proposed a staged logical schema, independent school/external decisions, one-active enforcement, semester batches, financial reconciliation, private evidence, and retained history.
- [x] Listed unresolved rules and the migration/implementation phase each affects (R01–R14).
- [x] Created `docs/sms/implementation-plan.md` and this progress tracker.
- [x] Kept application source, authentication behavior, and dashboard assets/design unchanged.

## Original verification baseline (2026-11-10)

These historical results describe the pre-foundation baseline. Current results appear above.

| Check | Result | Limit |
| --- | --- | --- |
| `php artisan --version` | Laravel Framework 12.69.2 | Runtime version, not only the Composer constraint |
| `php artisan route:list --except-vendor` | 18 application routes; bootstrap separately configures `/up` | Scholarship and financial routes do not exist |
| `php artisan test` | **26 passed; 67 assertions** | Uses SQLite in memory; does not validate MySQL constraints/concurrency or unimplemented scholarship workflows |
| `npm run build` | Passed; Vite 7.3.6, 59 modules transformed | Build success is not browser/visual verification; generated assets are ignored by Git |
| `composer validate --no-check-publish` | Passed | Configuration validity, not security clearance |
| `vendor/bin/pint --test` | Passed | Read-only style check |
| Direct installed `verified` middleware probe with an unverified `User` | Returned HTTP 200; model does not implement `MustVerifyEmail` | Confirms the verification enforcement gap; it was not fixed in this documentation task |

Earlier `composer audit --locked --format=json` and `npm audit --json` checks at the same application commit reported **12 Composer advisories across six packages** and **six affected npm packages** (two critical, three high, one moderate; flagged npm packages are development dependencies). Audits were not repeated for this documentation-only update. Package maintenance remains pending, and advisory counts do not establish every finding's reachability through this application.

The original analysis did not validate MySQL. The current foundation has now been tested on isolated MySQL 9.2.0; real mail, external systems, and browser rendering remain outside this task. No existing school records were modified.

## Future implementation tracker

“Pending review” means the design/rules are not yet accepted. “Not started” means no implementation is claimed.

| Phase | Status | Review/dependency gate |
| --- | --- | --- |
| 0. Review schema and baseline decisions | Initial database schema authorized on 2026-11-11 | R01–R14 operational decisions remain open except the now-explicit duplicate-billing rule; deployment DB/version still needs confirmation |
| Database foundation | **Completed** | Additive migration, models, relational constraints, history storage, optional reference seeder, and SQLite/MySQL tests implemented; no existing application data changed |
| 1. Supporting records and access / Task 3 | **Completed and tested** | Scholar/program/period CRUD, manual assignments/history, role policies, and local administrator provisioning delivered; future module permissions remain separate |
| 2. Academic verification and school evaluation | Persistence complete; workflow/UI not started | R02 criteria, verified-source/revision gates, and Registrar/Coordinator policies |
| 3. External approval and assignments | Manual assignment/history UI and one-active constraint complete; external approval workflow not started | R03/R04 external validity, approval gates, suspension/reactivation, and correction rules remain unresolved |
| 4. Semester billing and funding | Persistence/uniqueness complete; posting/UI not started | R01/R07/R08/R10/R11; aggregate funding and entitlement checks require transactional services |
| 5. Payout, deduction, and liquidation | Persistence/history storage complete; workflow/UI not started | R09 plus reviewed posting, corrections, settlements, accepted-coverage checks, and evidence rules |
| 6. Reporting and dashboard integration | Not started | Persisted source workflows and R14 metric/export definitions; preserve current dashboard design |
| 7. School acceptance and rollout | Not started | Agreed examples, reconciled reports, imports, MySQL verification, retained evidence/history, and dependency maintenance |

## Open decision summary

The full questions and schema impacts are recorded in the plan; no school answer is inferred here.

| Decision | Current state |
| --- | --- |
| R01 — Rates, EDUKAR, gross/net meaning, and revisions | School-stated TDP/TES figures known; EDUKAR and financial interpretation pending |
| R02 — Academic/document eligibility rules and source period | Registrar/Coordinator responsibilities known; thresholds, checklist, scale, and revisions pending |
| R03 — External approval scope and posting gates | Separate recording confirmed; authority, validity, renewal, and timing pending |
| R04 — Assignment activity and effective dates | Manual pending/active/closed/relinquished registry states and retained history implemented for Task 3; automatic approval gates, suspension/reactivation, and retrospective corrections still pending |
| R05 — Academic-period structure | Semester billing confirmed; exact school calendar/codes and evidence-period mapping pending |
| R06 — Applications, renewals, pending choices, and legacy scholars | Proposed semester application uniqueness and import provenance pending |
| R07 — Batch composition and installments | Multiple batches and one bill per assignment/semester confirmed; repeated semester billing prohibited. Partial payouts, payees, and posting gates remain pending |
| R08 — Funding scopes, allocation, returns, and transfers | Funding tracking confirmed; earmarking/pooling and movement rules pending |
| R09 — Deductions and settlement | Tracking confirmed; types, basis, caps, balance sources, and settlement rules pending |
| R10 — Financial authority and corrections | Posting/approval/reversal responsibilities and failed-payment handling pending |
| R11 — Liquidation scope and acceptance | Evidence tracking confirmed; batch versus receipt scope, cash/gross basis, and accepted proof pending |
| R12 — Access, accounts, retention, and evidence | Supporting-record role matrix, trusted console provisioning, and linked-account deletion handling implemented. Future module permissions, verification policy, portal scope, and evidence retention/access remain pending |
| R13 — MySQL target and constraint verification | Local server confirmed as MariaDB 10.4.28 through Laravel's MySQL driver; all 94 current tests pass on an isolated database there. Earlier MySQL 9.2.0/SQLite checks also passed; production target/setup alignment remains separate |
| R14 — Imports and report definitions | Dashboard presentation exists; historical scope, denominators/as-of rules, and export formats pending |

## Remaining issues and stopping boundary

The database foundation and Task 3 supporting-record functionality are complete. Work stops after the tested records screens and permissions. Dashboard design and sample statistics remain; eligibility, billing, and reporting implementation has not been started.

Remaining issues are explicit:

- R01–R14 still govern unknown business rules. EDUKAR and unconfirmed semester authorization amounts remain null; no grade threshold, income rule, deduction percentage, duration cutoff, external-approval carry-forward, or funding sufficiency rule was invented.
- Aggregate entitlement, allocation capacity, payout/deduction totals, and cumulative accepted liquidation coverage need transactional posting services with locks and idempotency. This foundation provides the keys, snapshots, and relationships, not permission to post arbitrary financial rows. Reversal amounts/source state and coordinated financial corrections also need those reviewed services.
- The initial plan's one-program/semester-per-batch, scoped receipts, batch liquidation, and one application per student/program/semester are reflected in the schema. If the school confirms pooled receipts across scopes, receipt-based liquidation, partial deduction settlements, or mid-semester terms revisions, add an explicit follow-up migration before implementing the affected workflow.
- Supporting-record policies, explicit role provisioning, and transactional audit-event writers are now implemented. Future module policies, student/external portals, and document upload/download/retention workflows remain pending. Existing email-verification enforcement and dependency-advisory findings remain outside this task.
- The additive Task 3 migration/reference seed/administrator-role grant were applied locally, with account/credential and record-count preservation verified. No database reset or school-record deletion occurred. Production rollout/backups are not established by local or isolated verification.
