# Scholarship Management System progress

Cronasia Foundation College, Inc. (CFCI)  
Last updated: 2026-11-11, Asia/Manila  
Base application commit: `a345c39`  
Current milestone: Confirmed scholarship fund policy implemented: TES PHP 7,500, TDP PHP 13,500 and CoScho PHP 45,000 initial references; configurable programs and semester rate history; budgets, independent Administrator approval, adjustments and allocation reports. Numeric school grading criteria remain unconfirmed and continue to block pending validations. Earlier Task 6 monetary figures and financial permissions are superseded by Task 7 below.

The design and decision register are in [implementation-plan.md](implementation-plan.md); verified national-rule sources and execution boundaries are in [eligibility-rules.md](eligibility-rules.md). Task 2 authorized the database foundation; Task 3 supporting records; Task 4 Registrar verification; Task 5 school evaluation, external-decision recording and guarded activation. Unknown school grading, EDUKAR and financial rules remain unresolved. School eligibility never constitutes external approval or financial authorization.

## Task 9: Scholar Information enhancement (2026-11-11)

Implemented and verified. [Field mapping, security, history and operations](scholar-information.md) documents the complete enhancement.

- Reused the unique Student ID, names/contact columns, Student identity, staff permissions, scholarship relationships and existing academic periods/Registrar history. Added **24 nullable columns** for official identifiers, suffix/sex/birthdate, current academic profile, parent names, structured address and additional indicators using additive migration `000007_expand_scholar_information`.
- No academic-program reference table existed. Added the minimal `academic_programs` list with Administrator registration/availability controls; academic programs remain distinct from scholarship programs. No duplicate scholar/enrollment/pivot or location tables were introduced. Current academic profile metadata uses existing periods and audited snapshots; it never overwrites Registrar-certified semester records. Period labels referenced by past profile snapshots are retained.
- Updated Student, ScholarController/ScholarRequest, StudentPolicy, student audit handling and Create/Edit/View/directory Blade screens. Forms have eight sections with readable options, official-required/optional distinctions, responsive grids and retained normal input. Listings preserve search, filters, pagination and access checks while adding program/year/semester and completeness.
- Official completeness is computed independently of saveability and scholarship eligibility. Partial registrations remain valid; unknown values stay null, including sex/solo-parent/first-generation/disability. Required formats, unique Student ID, existing academic references, configurable year-level choices, parent/address fields and normalized Philippine contacts are enforced. Unchanged legacy phone values remain available for review.
- LRN, National ID and 4Ps ID are encrypted, hidden in serialization and redacted in audit states. Only authorized profile staff see protected details; listings never show identifiers. Scholar pages use private/no-store caching. Sensitive IDs are not flashed after validation errors; existing saved values remain available and the form explains re-entry.
- Fresh factories/seeders support five fictional academic programs and **45 complete / 35 incomplete** seed profiles, retaining all financial relationships and safe rerun rules. No automatic reseeding occurred. The old 80-student demo and three manual source students retain their values with the new columns null. A separate **`cfci_sms_profiles_demo`** contains the new fixtures plus two browser-created examples, **82 students / 46 complete / 36 incomplete**.
- **Full SQLite suite: 260 passed, two MySQL-only skips / 2,544 assertions.** New Scholar Information coverage: **41 cases / 305 assertions**. Sample suite: **11 passed / 360 assertions**. Existing validation/billing/approval tests pass; profile progression preserves their stored data and existing eligible billing.
- Applied the normal migration to both existing local MariaDB databases; compared 29 original non-infrastructure tables per database and confirmed existing values/credentials/history preserved. The new fixture passed 103 FK checks and all 36 existing billing eligibility/total checks; repeated seeding preserved every table. Actual receipts and payouts remain zero. `.env` is unchanged; no reset/destructive database command was used.
- Real Chrome desktop/mobile create/edit/partial/protected-view checks passed, screenshots inspected, no page overflow or JavaScript errors. Production build, Blade compilation, Pint and whitespace checks passed. `implementation-plan.md` and `sample-data.md` are updated; scholarship policy documents did not require changes.

Remaining inputs: Administrators supply the real registered academic-program names; configurable initial year-level choices are 1–6 entry choices, not an eligibility/duration rule. Existing unresolved school grading, CoScho, source-period and calendar decisions remain unchanged. Work stops after Scholar Information verification.

## Task 8: Safe development samples and complete academic years (2026-11-11)

Implemented and verified. [Sample-data operations, counts, accounts and commands](sample-data.md) describe the inspected modules, scenario boundaries and safe reruns.

- Added four model factories, `AcademicYearSeeder`, `DevelopmentSampleSeeder`, the isolated `SyntheticDemoSeeder`, configurable sample settings and `sms:seed-demo`. Existing models/relationships, authorization policies, financial actions, database constraints and audit/evidence services are reused; no schema or unsupported module was introduced. Default account seeding now preserves existing credentials and roles.
- The original `cfci_scholarship` database received only **40 semester records / 20 years, 2007-2008 through 2026-2027**. Existing values across **54 tables**, three manual students, account credentials/roles, program references, active/source context and `.env` were preserved. No calendar dates or actual active semester were guessed.
- Created the separate persistent **`cfci_sms_samples_demo`** database: **80 students, 60 assignments, 140 academic revisions, 700 subject rows, 60 internal semester contexts, 120 evaluation runs, 102 external decisions, 18 rates, 18 budgets, 36 batches/items, 18 independent approvals, 16 adjustments, 18 unposted funding drafts and 310 evidence files**. Fixed references use three programs, five staff roles and six program/semester scopes. Actual receipts/payouts remain zero.
- Current/demo examples include active/archived, synthetic eligible, failed, review, pending/external-approved, draft/submitted/independently-approved batches and retained correction history. Historical academic examples do not assume old scholarship programs or today's rates. CoScho remains pending because its eligibility policy is unconfirmed.
- The production grading policy is unchanged. Financial examples require explicit synthetic mode in an owned separate demo database; a normal sample run remains review-only and creates no billing. The temporary policy is restored on both success and rollback. This testing fixture is not school approval of academic rules.
- **Full SQLite suite: 219 passed, two MySQL-only skips / 2,236 assertions.** Eleven new sample tests cover repeatability, collisions, defaults, guards, failure rollback/file cleanup, preserved manual data and eligible/nonqualifying financial relationships. New tests build only fresh in-memory schemas additively.
- Actual persistent MariaDB checks passed for **101 foreign-key constraints, 310 document checksums, complete year sequence, amount/semester consistency, billing totals, no duplicate allocations or active assignment conflicts, and all four invalid billing scenarios**. Full reruns and a rejected attempt to change an existing demo's year preserved table fingerprints. Pint and whitespace checks passed. The earlier full MariaDB concurrency suite is documented under Task 7; it was not rerun for this task.

School grading/CoScho policy, actual active/source-period selection, confirmed calendar dates and historical scholarship rules remain external inputs. Receipt/payout posting, deductions and liquidation workflows remain outside the implemented boundary. Work stops after sample-data verification.

## Task 7: Confirmed Scholarship Funds, Budgets and Approval Authority (2026-11-11)

Authoritative scope: the user's [confirmed allocation policy](confirmed-allocation-policy.md). Operation and permissions: [billing-batches.md](billing-batches.md). This change supersedes the earlier reversed TES/TDP reference figures and provisional financial authority. Billing records scholarship allocations and budgets, not tuition charges or student collections.

### Delivered

- Correct configurable initial references: **TES PHP 7,500, TDP PHP 13,500 and CoScho PHP 45,000 per semester**. Additional program codes are permitted; Administrators retain activation/deactivation and reference-amount controls. EDUKAR history remains accessible. The default seeder is idempotent and preserves custom configuration. A separate, explicitly invoked confirmed-policy seeder adopts the new reference values once and audits before/after values without rewriting semester rates or allocations.
- Academic years beginning at **2007-2008** and future consecutive years are supported. No scholarship's historical existence or rates are inferred from the school's establishment date. All assignments, validations, rates, budgets and allocation records retain their exact semester scope.
- New semester rate revisions retain source/actor/time and are immutable. A batch pins its approved rate; old approved allocations never reprice when a later rate is configured. Legacy confirmed semester rates are copied to history without modifying old terms. Missing grading interpretation or an unconfirmed CoScho/custom program eligibility profile still blocks new allocation; no academic policy is invented.
- Finance prepares and submits; a different Administrator reviews and approves. Finance users, including dual Finance/Administrator accounts, cannot approve a batch they prepared or submitted. Approval revalidates sources and appends an immutable approval record alongside the original immutable submission. Original submitted rows and their source snapshots remain untouched.
- Administrator-configured scholarship budgets retain revisions. Approved allocations and authorized adjustments consume capacity; unapproved submissions are reported separately. Current locks and transactions prevent competing approvals from spending the same remaining budget. A budget is not an actual funding receipt.
- Database uniqueness is strengthened from assignment/semester to **scholar/semester across all programs**, retaining the earlier keys. Program changes cannot create a second allocation. Administrator-only assignment creation/closure preserves prior history; the existing evaluated/verified activation workflow remains required. A duplicate-data migration preflight stops before DDL and requires authorized reconciliation; it never silently removes records.
- Administrators authorize append-only allocation adjustments with a fresh sequence, corrected effective amount, reason and reference. Original amounts remain unchanged. Increases need current eligibility, a supporting current rate and remaining budget; decreases cannot erase posted gross releases. Database guards protect the adjustment chain, scope and history. No replacement allocation is created after correction or program change.
- Allocation reports/CSV exports provide scoped budgets, approved original/effective allocations, adjustments, remaining budget, pending approvals, posted actual receipts and posted net cash releases. Historical inactive/archived scholars remain included. Sources are aggregated separately; CSV text is protected against spreadsheet formula injection. Approval/adjustment never creates receipts, payouts or paid/disbursed flags.

### Verification

- Full SQLite suite: **208 passed, 2 skipped / 1,879 assertions**. Skips are the two MySQL-only concurrency tests.
- Full isolated **MariaDB 10.11.16** suite: **210 passed / 1,917 assertions**. This includes a real competing-Administrator approval test against one available budget, and competing scholar/semester inserts across different programs/assignments. Both serialize correctly; only one commitment survives.
- **18 new scholarship fund tests** cover independent/self approval, budgets, rate history including retained legacy submissions, approval freshness, financial corrections, release floors, exact centavos, inactive history, reports/CSV, program changes, assignment coverage, configurable CoScho/custom programs, corrected idempotent references, 2007/future years, duplicate-data migration preflight and audit rollback. Existing assignment-access tests now reflect the confirmed Administrator-only authority.
- Blade compilation, production frontend build, Pint and `git diff --check` passed. The final one-time adoption safeguard was also checked independently on SQLite and MariaDB: replacing an Administrator's source notes does not make the adoption seeder overwrite later reference changes; immutable audit history identifies prior adoption.
- **Browser workflow passed** on an isolated database/storage: Finance login, batch creation, eligible scholar selection and submission; no Finance approval control; separate Administrator login, budget configuration, approval and authorized adjustment. The original PHP 7,500 allocation remains, its effective amount becomes PHP 7,000, and the PHP 15,000 budget reports PHP 8,000 remaining. Receipt and release totals remain zero. CSV values reconcile; desktop/mobile screenshots were inspected, mobile has no page overflow, and no JavaScript errors were recorded. Synthetic resolved academic prerequisites were confined to this disposable fixture, which was removed with its accounts/storage.
- **Local enablement passed:** additive migration `000006` applied and the explicit confirmed-policy seeder adopted TES PHP 7,500 / TDP PHP 13,500 / CoScho PHP 45,000 reference values. Before/after values and counts across **21 existing account/domain tables** were preserved, along with prior audit history and existing program identity/availability. Only requested program reference configuration, CoScho's program entry and corresponding audit events were added/updated; retained legacy rate metadata can be backfilled without rewriting its source rows. No passwords, roles, school-year choices, synthetic validations, budgets or allocations were seeded. The existing Administrator opens funds, reports and program creation with HTTP 200.

### Scope boundaries

CFCI grade conversion/threshold/full-scale criteria and new-program eligibility profiles remain unconfirmed; missing policy still means pending review. No historical rate is assigned automatically, and configured budgets never imply cash receipt. Receipt/payout posting, deduction settlement, liquidation, automatic cross-program fund transfers and unrelated financial functionality are not introduced. Existing navigation design and its scroll/fade behavior are preserved.

## Task 6: Semester Billing and Batch Management (2026-11-11)

Implementation and operating instructions: [billing-batches.md](billing-batches.md). This milestone implements billing preparation/submission and stops before funding, payouts, deductions, liquidation or reporting.

### Validation prerequisite verified

Before implementation, the unchanged SQLite suite passed **155 tests / 1,297 assertions**, with one MySQL-only concurrency test skipped. Direct validation, reported grades, source/active-period configuration, immutable revisions, external approval and guarded activation are functional. School grading rules remain unresolved; production `SchoolGradingPolicy` continues to return pending review. Billing never turns pending review into eligibility. Successful downstream billing tests use clearly labeled synthetic resolved prerequisites, as the existing eligibility regression suite does.

### Delivered

- Billing list filters for program, academic year, semester and status; sequential program/semester batch numbers; draft detail editing; individual scholar search/add/remove; pagination; derived billed totals and submission actor/date/reference. Existing Blade layout/navigation/branding are reused; dashboard sample charts/data are unchanged.
- Explicit Administrator confirmation of a positive semester billing amount and approved school policy/amount reference, independent of academic-policy confirmation. TDP PHP 7,500 / TES PHP 13,500 remain reference figures; EDUKAR has no inferred amount. No real semester amount, policy, eligibility result or Finance membership is seeded.
- Conservative billing policy is offered for explicit per-semester confirmation: active assignment/student/program, current eligible validation, applicable confirmed policy and exact-semester verified external approval with current usable evidence. This is an operational confirmation gate for unresolved R03/R07/R10 decisions, not a claim those school answers were supplied. An existing individual authorization is an additional cap and requires provenance. Unknown school criteria still block real billing.
- Finance prepares/edits/submits; Administrator configures; Coordinator/Viewer can read; Registrar/no-role cannot access billing. Administrator status alone does not confer Finance responsibility. Every route and transactional action enforces authorization, and posted actor/status/amount fields are ignored unless explicitly accepted by the operation.
- Additive migration `000005` reuses the existing financial models/keys. Independent academic/financial confirmation history, restrictive actor FKs, draft versioning, submission shape guards and submitted-batch/item immutability work on SQLite and MariaDB. Native additive SQLite columns avoid rebuilding established tables/triggers. Rollback restores the preceding academic-history guard; reapplication retains existing domain rows.
- Mutations lock current records and use transactions, unique indexes and stale-form versions. Duplicate assignment/semester billing is prevented across draft/submitted/void history. Draft removals release reservations and retain audit events. Submission rechecks all items and snapshots their amount/policy, validation and external approval; one invalid scholar or audit failure rolls back the whole batch. Totals use integer centavos and are derived from items.
- Submission does not create funding receipts, allocations, payouts, deductions or liquidation records. Submitted records have no uncontrolled edit/reopen/delete route, and database triggers also prevent raw mutations and late items. Reviewed corrections/reversals remain future work.

### Verification

- New billing feature coverage: **35 passing tests / 360 assertions**, including TDP/TES/configured decimal amounts, legacy unknown totals, unknown EDUKAR amount, explicit policy/reference confirmation, duplicate billing, totals, edits/removal, stale forms, permissions, submission status/provenance, immutability, academic/context/external-evidence invalidation, individual caps, mixed-batch rollback, audit rollback and migration rollback/reapply.
- Full SQLite suite: **190 passed, 1 skipped / 1,657 assertions**. The only skip is the MySQL-specific two-writer concurrency test. Run with the repository's standard PHPUnit environment.
- Full isolated **MariaDB 10.11.16** suite: **191 passed / 1,675 assertions**, including competing assignment/billing inserts, submitted-history constraints and migration rollback/reapply. The concurrency test now applies all current domain migrations and accumulates readiness output from process start to remove its Windows startup race. Disposable databases and isolated storage were removed.
- Local migration `2026_11_11_000005_add_semester_billing_management` applied successfully. Before/after hashes and counts preserved every existing value across **23 account/domain tables**, including credentials, roles, academic records, financial history and audit events. No real billing terms, Finance role, sample scholar, synthetic validation or billing item was seeded.
- The existing test Administrator rendered `/billing-batches` through the local authenticated HTTP kernel with **HTTP 200**. Semester amount/policy confirmation remains a school operation; Finance responsibility must be assigned explicitly.
- Blade compilation, Vite production build, Pint and `git diff --check` passed. HTTP feature tests exercise batch pages and mutations on both database engines. No browser-based visual inspection is claimed.

### Remaining boundary

Approved numeric grading interpretation, differing financial gates, historical semester billing, per-scholar exceptions/partial bills, confirmed-term amendments, void/reversal/correction authority, funding/payout/liquidation and live financial reports are not inferred. The implemented uniform-semester policy must be explicitly confirmed against an actual school source before use. Stop after billing batch management verification.

## CFCI clarification: confirmed scope and pending rules (2026-11-11)

This user-provided school clarification supersedes earlier assumptions about application processing and grade-entry format.

| Area | Confirmed requirement | Still pending / treatment |
| --- | --- | --- |
| Grade presentation | Subject rows with Prelim, Midterm, Semi-Final, Final, Average, Equivalent, units and remarks; examples include reported values 92/93/94 and equivalents 1.1/1.2/1.5/1.7/2.2 | Conversion formula, passing thresholds and full scale are unconfirmed. Copy the source values; never calculate, convert, infer a scale from examples or assign automatic standing. |
| Grading source | Depends on the benefit/validation semester and confirmed school policy | First semester is common, not a default. Require an explicitly configured source period and school policy reference; missing source/policy means pending review. |
| Validation target | Currently active academic year and semester configured by the school | The example 2026–2027 / first semester is illustrative. Neither it nor a year/semester fallback is automatically selected. Open-for-maintenance periods are separate from the single active validation context. |
| Application processing | Outside this system; validate existing students and Registrar academic records directly | No scholarship intake/submission workflow. Retain the legacy `scholarship_applications` table/model only as internal semester-validation context to preserve FKs and history; do not expose its name as an application service. |
| EDUKAR/AKLAT | Students apply directly through the provider | Do not implement application requirements, application workflows or EDUKAR exceptions. Existing academic data and separately recorded external award status can be reviewed. This is a scope exclusion, not a request to build an EDUKAR application engine later. |
| TDP/TES | Verify official published national eligibility conditions; implement only confirmed conditions applicable to direct validation | National intake and billing forms are not mandatory requirements of this direct-validation screen. Unconfirmed required school criteria or unavailable academic information produce `needs_review` (pending review), not automatic eligible/ineligible. |

Implementation is complete for this clarification. As-reported grade values use text storage with numeric-format/length validation so no unconfirmed grade precision is rounded or converted. Existing semester-average values remain historical data. The school grading-policy check deliberately remains pending in production until the actual required academic criteria are confirmed; entering high grades or attaching all national evidence cannot bypass that check.

### Changes delivered after clarification

- Added Registrar subject entry/viewing for the stated columns, including units and remarks. Values such as `92.7500`, `1.10` and `3.00` remain exactly as reported; Average and Equivalent are not calculated. Standing remains manually recorded, and entering grades cannot silently set passing/failing. Blank/unavailable values are not replaced by zero. Numeric formatting/length and request-size checks are technical limits, not an assumed academic scale.
- Additive migration `2026_11_11_000004_add_reported_grades_and_academic_context` adds `academic_record_grades` and the singleton `academic_validation_context`. Grade rows have an indexed restrictive academic-record FK and unique optional subject code per revision. Database triggers protect their insert/update/delete/move history once the parent is verified. Corrections retain previous rows and create a new revision. Existing parent-table triggers and existing records are not rebuilt or rewritten.
- Administrators configure one active target period and the school-confirmed source period/reference in **Academic periods**. Registrar/Coordinator accounts can view these settings but cannot change them. A version token prevents stale configuration saves; changes and their previous state are audited atomically. The singleton key constraint prevents competing active-context rows. The migration seeds only an empty context; it does not choose a live school year or semester.
- Direct validation uses the configured target, rejects a different posted benefit period, and independently retrieves current-period enrollment and source-period reported grades. It never silently substitutes the benefit semester, first semester, or an older verified record when the latest source revision is pending. Context and source snapshots are retained with every run. Context changes and pre-clarification scope invalidate reliance on old decisions while preserving the historical decisions and existing awards.
- Removed automatic application submission. The retained `scholarship_applications` model/table serves internal relational validation context only; its legacy state is not an application workflow. Removed intake/billing form types from direct-validation uploads and mandatory checks. Existing source metadata and uploaded history remain readable. National checks continue to use the verified official source profiles, with the actually applied direct-validation evidence keys snapshotted separately.
- EDUKAR exposes existing academic review and external award-status recording, with no application category, intake checklist, intake uploads or exceptions form. No EDUKAR application requirements/criteria/exception engine is planned as follow-up work. Missing school academic policy leaves academic review pending; an external approval still does not imply school eligibility.
- `SchoolGradingPolicy` returns review while the conversion, passing and full-scale/academic interpretation rules are unconfirmed. This missing prerequisite takes precedence over both pass and fail outcomes; posted flags or high grades cannot bypass it. Existing national-rule/activation regression tests explicitly mock a **synthetic** resolved school prerequisite to exercise downstream safeguards; that test setup is not production school policy or a production bypass.
- Dashboard labels/navigation now refer to academic records and validations, with the existing layout, branding, cards, CSS, sample values and chart structure preserved. The chart labels identify their demonstration data. No reporting engine or live dashboard analytics were added.

### Verification after clarification

| Check | Result |
| --- | --- |
| Full SQLite suite | **155 passed, 1 skipped, 1,297 assertions**; the only skip is MySQL-specific concurrency. |
| Full isolated MariaDB 10.4.28 suite | **156 passed, 1,315 assertions**, including migration rollback/reapply, immutable grade rows and the existing race checks. Temporary databases were removed. |
| Clarification coverage | **11 new feature tests** for separate target/source periods, missing policy overriding both pass/fail, no default period, no older-source fallback, context invalidation and authorization, duplicate/stale config, immutable grade children, raw grade corrections, excluded EDUKAR/intake processing, and audit rollback. Registrar tests were updated for as-reported subject grades; earlier national tests retain explicit synthetic prerequisites. |
| Browser workflow | Actual Administrator source/target configuration, Registrar subject entry/draft/reopen/verification, exact decimal text preservation, direct Coordinator validation with pending grading policy, denied activation, and EDUKAR without intake fields passed. Add/remove subject rows and mobile width checks passed, with no JavaScript page errors. |
| Visual review | Six screenshots at desktop/mobile sizes generated; grade detail table, mobile editor and preserved dashboard layout inspected. Browser data used disposable DB/storage; server and database removed afterward. |
| Build / views / formatting | `npm run build`, `php artisan view:cache`, Pint and `git diff --check` passed. |
| Official source recheck | TDP MC02/2026 and TES MC01/2026 were downloaded again from UniFAST; both SHA-256 hashes still match the reviewed source versions in [eligibility-rules.md](eligibility-rules.md). |
| Local enablement | Additive migration applied successfully. Existing domain values/counts, academic data, accounts/credentials and roles were checked before/after and preserved. No live sample students, grade rows or source-period choices were inserted. |

### Current operation and remaining school decisions

The existing Registrar account can enter/view reported grades under **Academic records**. An administrator must configure the actual active/source periods in **Academic periods** using the school's real policy reference; the example supplied in the conversation is not used as live configuration. The relevant format is implemented, while **conversion formula, passing thresholds, full scale and any required eligibility interpretation remain pending**. Automatic validation stays `needs_review` until those required school rules are supplied and implemented. Billing, reporting and external application processing remain outside this change.

## Task 5: Automated Scholarship Validation and Evaluation (before clarification)

### Registrar prerequisite rechecked

Before this task, all migrations through Task 4 were applied locally and the unchanged SQLite suite passed **113 tests / 749 assertions**, with only the MySQL concurrency test skipped. Registrar draft/verification, staff/time provenance, immutable corrections, private certificates, role permissions and academic-source freshness are operational. **Full Task 4 acceptance remains pending the numeric grading format**; this was not misreported as complete. A further school-rule clarification was requested during Task 5 and no format/EDUKAR policy was supplied. The evaluation module does not invent a GPA scale or treat an unverified grade as a verified pass.

### Rule verification

- Reviewed UniFAST's official issuance index and signed **MC 02 series of 2026 (TDP)** and **MC 01 series of 2026 (TES)**, which specify effectivity from the first semester of AY 2026-2027. Source URLs, hashes, relevant sections and limits are documented in [eligibility-rules.md](eligibility-rules.md). Scanned documents were OCR-extracted and relevant eligibility pages visually verified. Neither older web summaries nor a third-party university's local criteria were adopted as CFCI policy.
- The Coordinator explicitly confirms the applicable official profile for a program/semester. Numeric semester codes 1/2 from AY 2026-2027 onward are supported; older/custom/summer periods require reviewed mapping and remain `needs_review`. No policy is automatically seeded or confirmed in the application database.
- EDUKAR/AKLAT has no invented criteria. Unknown profiles, incomplete evidence, unresolved special categories or unreviewed exceptions cannot produce eligible. TDP/TES retain their official aid/exception distinctions; the older brochure's income cap and the school's 4/4/5 planning durations are not hard-coded eligibility cutoffs.

### Delivered workflow

- `/evaluations`: program/semester filters and a paginated assessment list, using the existing layout/cards/navigation. Coordinators select a program and semester, then a scholar with a pending/active assignment to that program. The existing unique student/program/semester application is reused; wrong-program and competing-assignment links are rejected.
- Latest Registrar revision fetched from the benefit semester, with source fields and verification metadata pinned. A newer pending correction prevents fallback to an older verified record. No school SIS or national portal integration is claimed. Retention and non-academic/institutional facts are explicitly verified by the Coordinator against certified private sources; the software evaluates those documented facts without inventing a numerical grade rule.
- Fixed, program-specific PHP checks in `EligibilityEvaluator`, with an official profile catalog rather than a generic configurable rule language. Results are **eligible**, **ineligible**, or **needs_review**, mapped to existing database outcomes `passed`, `failed`, `needs_review`. Incomplete mandatory facts/evidence take precedence and remain review cases. There is no override-to-eligible control.
- Conditional evidence requirements for new/continuing applicants, LOA returns, transfers, applicable legacy-PNSL residency and TES-3A claims. Unsupported direct-grantee submission alternatives/special categories require review. Duration is an evidenced manual review of actual curriculum/award history, grace periods, LOA/transfer treatment and approved exceptions; potentially exceeded/undocumented duration remains review, not an automatic rejection from planning years.
- Each evaluation appends a completed immutable run: submission revision, academic IDs/revision/verified state, facts and source references, private document IDs/checksums/reviewers, applied fact/document keys, official rule/source/hash and confirmation snapshot, structured results, reasons, evaluator/Coordinator, server date and evaluator notes. Previous runs are never updated or deleted.
- Private evidence uploads/reviews use a dedicated `scholarship` disk at `storage/app/scholarship-private`, outside public and generic local-serving roots. PDF/JPG/PNG are checked by MIME and extension, up to 2 MB per request. Hashed filenames, checksums, ownership checks, authenticated policy-protected attachment downloads, private/no-store caching and nosniff are used. Accepted evidence metadata is immutable. A replacement remains a new retained document; a new upload/review increments the application's submission revision and requires reevaluation.
- Current-decision reads check fresh application/source revisions, latest run, live award-conflict state and available matching evaluation evidence. New server-pinned verified snapshots safely establish same-second ordering; legacy decisions without those snapshots retain Task 4's conservative time ordering. A correction, changed submission, missing/corrupted evidence or newly conflicting active award cannot silently leave an older eligible decision current.
- Separate external-decision entry records the verified authority decision/reference/date, any stated validity, exact assignment/program/student/semester, staff recorder, private accepted proof and verification notes. Approved/rejected/revoked decisions and corrections append with predecessor links. Exact duplicates and stale predecessor forms are rejected. An older approval cannot bypass a later revocation. Assignment-wide legacy approvals are retained without automatic semester carry-forward. EDUKAR/AKLAT external evidence can be recorded while its school eligibility remains review.
- Award activation is an explicit separate action. A transaction locks the student/application, requires the latest current eligible official-rule school evaluation and its available evidence, the latest verified valid external approval for the exact context, a pending assignment, active scholar, available program and no active-scholarship conflict. Inclusive validity dates are compared as Manila calendar dates. Actor/time and evaluation/approval FKs are retained on the assignment; audit failure rolls activation back.
- **The old assignment-form activation bypass is closed for every role, including administrator.** New assignments are pending; pending-to-active transitions must use this module. Existing active/historical assignments remain intact, and maintenance/closure/relinquishment still works. Existing records regression tests now reflect this explicitly requested gate rather than expecting ungated activation.
- No automatic award termination, financial entitlement authorization, bill, payout or report is created. Revocations/corrections are retained and require staff follow-up; future posting must recheck school freshness, latest approval, funding and its own financial requirements.

### Database and permissions

Additive migration `2026_11_11_000003_add_evaluation_and_activation_provenance` adds nullable `evaluated_by`/`evaluated_at` to evaluation runs, plus `activation_evaluation_id`, `activation_approval_id`, `activated_at` to assignments. Actor/source FKs restrict deletion/update and are indexed. Existing uniqueness, contextual FKs and immutability remain in force. SQLite table rebuilds explicitly preserve the existing history/check triggers; rollback removes new indexes before columns. Existing account, session, academic and unrelated-data preservation is exercised through a full migration/rollback/reapply test.

| Role | Read assessments / private supporting evidence | Confirm official semester profile, evaluate, review evidence, record external decisions, activate |
| --- | --- | --- |
| `coordinator` | Yes | Yes |
| `registrar`, `administrator` | Yes | No |
| `finance`, `viewer`, no staff role | No | No |
| Guest | Login required | Login required |

Policies, Form Requests and actions enforce the permissions on direct requests and downloads. Facts, rule versions, actors, timestamps, application context, school outcome and activation provenance cannot be mass-assigned by the browser. No existing accounts were granted Coordinator authority automatically. Provision an approved account explicitly through the existing `sms:grant-role` command when needed.

### Verification and local enablement

Browser verification used a disposable MariaDB database and isolated private storage; its server/database were removed afterward. No test scholars or evaluations were inserted in the application database.

| Final check | Result |
| --- | --- |
| Full SQLite suite | **144 passed, 1 skipped, 1,225 assertions**. Only the MySQL concurrency test is skipped. |
| Full isolated MariaDB 10.4.28 suite | **145 passed, 1,243 assertions**, including all four two-writer uniqueness cases and migration rollback/reapply. Temporary databases were removed. |
| Module feature coverage | **31 evaluation feature tests**: eligible/ineligible TDP/TES, incomplete/missing/corrupt evidence, source provenance, unconfirmed/EDUKAR/historical policies, conditional requirements, duration exceptions, SMART conflict, reevaluation/history, stale forms, external corrections/revocation/expiry, award conflicts, role matrices, IDOR protection, mass-assignment rejection and atomic audit rollback. |
| Final prerequisite/module/migration rerun | **52 passed / 702 assertions** across Registrar verification, evaluations and migration retention after the last source-snapshot/UI adjustments. |
| UI / build / formatting | Six browser screenshots and the end-to-end workflow passed; `npm run build`, `php artisan view:cache`, Pint and `git diff --check` passed. |
| Local enablement | Additive Task 5 migration applied successfully. All existing domain-table values/counts, academic data, user accounts/credentials and role rows were checked before/after and preserved. No rule profile or Coordinator role was automatically granted/confirmed locally. |

- Actual browser workflow passed: Coordinator login, program/semester/assigned-scholar selection, official-profile confirmation, incomplete `needs_review`, private evidence uploads, eligible reevaluation, retained run history, denied activation without approval, separately recorded verified external decision, successful guarded activation, and Registrar read-only access.
- Six desktop/mobile screenshots were generated; desktop evaluation history and mobile assessment/index layouts were inspected. No page overflow or JavaScript page errors occurred. The existing dashboard design/assets were retained.
- Database races now cover active-award uniqueness, billing uniqueness, academic revisions and evaluation run numbers. Tests use isolated databases, never the application's records.

### Remaining limits

Numeric Registrar grading format/source-semester policy and EDUKAR rules remain pending R02. National slot prioritization/cross-matching/funding and special PNSL/SARDO guidelines are not simulated. The current HEI evidence path does not automate direct applicant-to-CHEDRO alternatives. Suspension/reactivation and mid-semester policy/financial-rate revisions still require their reviewed design. Confirming a semester's `program_period_terms` uses the existing whole-row immutable confirmation; it does not fill or authorize an unknown monetary amount. Future finance work must review any necessary terms/rate revision before editing a confirmed row. No billing or reporting module was implemented.

## Task 4: Registrar Academic Verification (original delivery; grade entry superseded above)

### Previous task verified

Task 2 and Task 3 migrations were confirmed applied locally, and the unchanged SQLite suite passed **93 tests / 530 assertions**, with the single MySQL-only concurrency test skipped. The existing records controllers, role policies, scholar history, and dashboard layout were reused. The approved mapping remains **`academic_records` / `AcademicRecord`** for the provisional `academic_verifications` name; no competing academic-verification table was created.

### Functionality delivered

- `/academic-verifications`: paginated revision list with scholar, semester, and verification-status filters; links from each scholar's details page; detail screens with Registrar information, private certificates, correction reasons, and revision history.
- Registrar selection of scholar and academic period; enrollment states `unverified`, `enrolled`, and `not_enrolled`; separately recorded academic standing `unverified`, `passing`, and `failing`. These describe manually checked facts, without deriving a scholarship decision or connecting to a student information system. Closed semesters and historical/archived scholars can still have historical records recorded or corrected.
- Draft saving and explicit verification. Verification requires an identified Registrar source and at least one checked fact (enrollment or standing). Any remaining `unverified` field stays unverified. Staff identity and verification time come from the authenticated Registrar and server clock; the browser cannot assign actors, revision numbers, status, or timestamps. Dates display in Asia/Manila.
- An additive migration adds `academic_standing` (default `unverified`), `correction_reason`, and `lock_version`. Existing academic values and verification history are retained. The existing contextual FKs, unique scholar/semester/revision key, and immutable-history triggers remain in force.
- One initial record per scholar/semester through the workflow. Draft edits use a student/record transaction lock and an optimistic version token to reject stale forms. Completed revisions cannot be edited or deleted. An explicit correction with a reason creates the next revision and links its predecessor; corrections of superseded records and duplicate correction submissions are rejected. No destructive routes exist.
- Grade certificates use the dedicated **`academic`** private disk at `storage/app/academic-private`. It is outside the public symlink and outside the existing `local` disk's signed serving root; generic signed storage URLs cannot serve it. Hashed server filenames, actual MIME and extension checks, a limit of three PDF/JPG/PNG files per request at 2 MB each, checksums, upload actors, and exactly-one-owner document FKs are used. The limits fit this local PHP installation's 2 MB/file and 8 MB/POST settings; they are technical upload limits, not an academic document checklist.
- Downloads require authentication, academic-record authorization, matching record/document ownership, and the expected disk/type/path. Responses are attachments with private/no-store caching and `nosniff`. Missing files and mismatched owners return 404. Finance/viewer accounts cannot read academic records or download certificates.
- Verification accepts and locks attached evidence using the existing document review metadata and immutable-document trigger. Corrections retain the original record and its certificates; certificates are not silently copied to the new revision. New supporting evidence can be uploaded with the correction. Draft files remain private and are retained when that draft is verified.
- Record and certificate-upload audit events commit atomically with their database changes. File storage is cleaned up if the transaction/audit fails. Scholar identities, scholarship assignments, external approvals, financial records, and dashboard assets/design are not rewritten.

### Academic corrections and current decisions

`EvaluationRun::isCurrent()` and `ScholarshipApplication::currentSchoolDecision()` provide the required authoritative read path for an effective school decision. They query fresh persisted input state rather than trusting a cached relationship or the historical `school_decision` string alone. A decision is unavailable when:

- its source academic revision has been superseded, including by a pending correction;
- either linked source is missing or not Registrar-verified;
- source verification does not strictly precede completion and the Coordinator decision;
- the application's submission revision changed, the application was withdrawn, or a newer evaluation run exists (including a pending run).

Grade-source periods may differ from the benefit/enrollment period: corrections invalidate decisions that actually reference that grade record. No new source-semester rule is inferred. Historical decisions and their snapshots remain immutable. Related decisions on the academic detail page explicitly show when Coordinator reassessment is required. Verifying a correction does **not** revive an older decision; a fresh evaluation run and Coordinator decision are required.

The existing database timestamps have second precision. Same-second verification/decision ordering is treated conservatively as requiring reassessment. Future evaluation/posting services must use this current-decision API inside their posting transaction and coordinate source locks with the student lock used here. They must separately enforce eligibility, external approval, funding, and other posting requirements; freshness alone is not billing authorization. Historical bills/payments continue to retain their original source snapshots. No evaluation engine or posting service was added in this task.

### Grading decision still required — R02

Neither approved document specifies CFCI's actual grade format, scale, precision, special marks, or whether entries are semester averages or subject-level grades. A clarification was requested; no grading policy was supplied during this implementation. **Numeric grade entry is deliberately disabled in the delivered application** (`config/sms.php`, `grading = null`), and forged numeric submissions are rejected. Original grade certificates and manually verified standing can be recorded now.

There is a tested configuration hook for a school-approved decimal average format, including its label, range, precision, and source reference, snapshotted on the academic revision. Synthetic test values are not school policy and have not been enabled locally. Do not enable this hook with guessed values. If CFCI confirms subject-level grades or marks such as INC, implement their reviewed structure/validation instead of forcing them into a numeric average. No passing cutoff, automatic standing conversion, unit requirement, or mandatory certificate checklist has been invented. Full acceptance of the requested grade-entry feature remains pending this answer.

### Permissions and operation

| Role | View records / private certificates | Create, edit draft, verify, correct |
| --- | --- | --- |
| `registrar` | Yes | Yes |
| `coordinator` | Yes | No |
| `administrator` | Yes | No, unless separately granted `registrar` |
| `finance`, `viewer`, or no staff role | No | No |
| Guest | Login required | Login required |

Roles are enforced by policies, controllers, and Form Requests, including direct POST/PATCH and certificate URLs. Administrator status alone does not grant Registrar authority. No existing account was automatically granted `registrar`. A trusted operator can explicitly provision an approved Registrar with the existing `php artisan sms:grant-role user@example.test registrar` command. No account or credential changes are part of this task.

### Verification and local enablement

Browser checks used a disposable MariaDB database, isolated storage, and synthetic Registrar/Coordinator accounts, then removed the server and database. Real application data was not used for UI tests.

| Final check | Result |
| --- | --- |
| Full SQLite suite | **113 passed, 1 skipped, 749 assertions**. Only the MySQL concurrency test is skipped. |
| Full isolated MariaDB 10.4.28 suite | **114 passed, 763 assertions**, including academic-revision race enforcement. Temporary databases were removed. |
| Registrar feature tests | **20 tests** covering role matrices/direct requests, drafts, verification provenance, duplicates, immutable corrections, private evidence, disguised MIME content, file limits, atomic rollback/cleanup, stale forms, decision freshness, filters, and history links. |
| Build / views / formatting | `npm run build`, `php artisan view:cache`, `vendor/bin/pint --dirty --test`, and `git diff --check` passed. |
| Local additive migration | `2026_11_11_000002_add_registrar_verification_fields` applied successfully to the verified local database. All existing business-table counts, academic values, account rows/credentials, and role rows were checked before/after and preserved. No sample records or new roles were inserted. |

- Browser workflow passed: real login, scholar preselection, draft plus PDF upload, verification actor/date, byte-for-byte certificate download, duplicate-context rejection, linked correction, original passing record retained alongside corrected failing record, Coordinator read access without verification authority, and guest download redirection.
- Six screenshots at 1440px and 390px were generated; desktop details and mobile correction/index layouts were visually inspected. Mobile forms/pages did not overflow and no JavaScript page errors were observed. External font requests were blocked for reproducible local checks.
- Database tests cover authentication/unrelated-data retention and existing verified academic values through migration → rollback → reapply on both engines, plus competing academic-revision inserts alongside the existing active-assignment/billing races.

### Task boundary and remaining work

The operational Registrar workflow is functional; numeric grades remain gated on R02, so Task 4 is not described as fully accepted. Existing dashboard design is preserved. No Coordinator evaluation UI/engine, external-approval workflow, billing, reports, automatic integration, new staff accounts, or automatic role grants were implemented. Confirm the grading format, then complete/enable only that grade-entry requirement before proceeding to the next module.

### Subsequent user-requested local Registrar test account

On 2026-11-11, the user explicitly requested an account for testing the dashboard. Created **Registrar Test** (`registrar@cfci.test`) in the verified local application database and explicitly granted only `registrar` through the existing trusted role command. Account creation and role provisioning were audited. Existing accounts and their credentials were preserved. The generated password was provided directly to the user and is not stored in this document or application source.

Verified actual HTTP login at `http://127.0.0.1:8000`: dashboard, scholar directory, academic-verification list, and Registrar entry form returned 200; academic-period administration returned 403. The test session was logged out. No scholar, academic, scholarship, or financial test records were created.

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

Task 3 is complete. At that milestone no eligibility engine, Registrar academic-verification UI, external-approval workflow, billing UI/posting service, report generation, or live dashboard statistics had been implemented. Task 4 above subsequently adds academic verification. The Task 3 staff matrix is for supporting records; future module permissions and school-specific approval, suspension, correction, renewal, and financial rules still require the decisions below. Closed assignment-history corrections must be designed explicitly rather than implemented by reopening/deleting past records.

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
| Automated Scholarship Validation and Evaluation | **Registrar reported subject grades/revisions, configured active/source periods, direct school validation, immutable snapshots/history, private evidence, separate external status, guarded activation and current-decision API** | CFCI grade conversion/threshold/full-scale criteria, applicable special/historical policies, reviewed suspension/reactivation, and future financial integration. EDUKAR applications/requirements are excluded, not pending implementation. |
| Billing, Liquidation, and Deduction | **Semester billing/batch UI, approved configurable amounts, duplicate guards, transactional submission, role policies and immutable submitted history**, plus financial persistence | Funding/payout/liquidation workflows, reconciliation, reviewed deduction/settlement/correction rules and financial evidence |
| Reporting, Analytics, and Overview Dashboard | Custom dashboard presentation and Manila-time greeting/date; sample statistics/charts | Database reporting, real filters/analytics, financial reconciliation, and real dashboard data |
| Supporting functionality | **Scholar/program/academic-period screens, assignment/history management, staff policies and explicit role provisioning, atomic audit events, linked-account deletion handling, private academic/evaluation/external evidence**, plus Task 2 persistence | Financial evidence/workflows, future financial permissions, and any reviewed assignment-history correction workflow |

Latest confirmed program facts: TES PHP 7,500 per semester, TDP PHP 13,500 per semester, CoScho PHP 45,000 per semester; retained EDUKAR amount unconfirmed; school planning durations are 4/4/5 years respectively. These durations are not unconditional eligibility cutoffs. One active scholarship per student and semester billing with multiple batches are confirmed.

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
| 1. Supporting records and access / Task 3 | **Completed and tested** | Scholar/program/period CRUD, assignments/history and role policies; Task 5 replaces manual new activation with evaluation/approval gates |
| 2. Academic verification and school evaluation | **Registrar reported columns and direct validation implemented/tested** | Formula, thresholds/full scale and any required academic interpretation remain unconfirmed; source/active periods require real school configuration. EDUKAR intake is excluded. |
| 3. External approval and assignments | **Verified external decision recording and guarded activation implemented/tested** | Exact-semester scope, validity, supersession and conflict gates implemented; suspension/reactivation and retroactive history repairs still need review |
| 4. Semester billing and funding | **Scholarship budgets, allocation preparation/submission, independent approval, adjustments and allocation reports implemented/tested; cash posting not started** | Explicit semester amount/policy confirmation required; R08/R10/R11 and reviewed corrections/funding remain pending |
| 5. Payout, deduction, and liquidation | Persistence/history storage complete; workflow/UI not started | R09 plus reviewed posting, corrections, settlements, accepted-coverage checks, and evidence rules |
| 6. Reporting and dashboard integration | Not started | Persisted source workflows and R14 metric/export definitions; preserve current dashboard design |
| 7. School acceptance and rollout | Not started | Agreed examples, reconciled reports, imports, MySQL verification, retained evidence/history, and dependency maintenance |

## Open decision summary

The full questions and schema impacts are recorded in the plan; no school answer is inferred here.

| Decision | Current state |
| --- | --- |
| R01 — Rates, EDUKAR, gross/net meaning, and revisions | TES PHP 7,500, TDP PHP 13,500 and CoScho PHP 45,000 semester references confirmed; revisioned allocation rates implemented. Retained EDUKAR amount remains unknown; net cash is not inferred |
| R02 — Academic/document eligibility rules and source period | Reported column format confirmed and implemented; active/source periods explicitly configurable. Conversion, passing/full-scale academic criteria and actual school source-policy mapping remain pending. Verified TDP/TES conditions apply only to direct validation; EDUKAR intake/requirements/exceptions are excluded. |
| R03 — External approval scope and posting gates | Verified exact-semester external records, stated validity, supersession/revocation and activation gates implemented. Assignment-wide carry-forward, renewal exceptions and financial timing still need review |
| R04 — Assignment activity and effective dates | Pending/active/closed/relinquished history retained; new activation requires current eligibility, verified external approval and an available active slot. Suspension/reactivation and retrospective corrections still need review |
| R05 — Academic-period structure | Semester billing confirmed; exact school calendar/codes and evidence-period mapping pending |
| R06 — Applications, renewals, pending choices, and legacy scholars | Existing semester application uniqueness implemented/reused; duplicate contexts and cross-assignment relinking rejected. Simultaneous pending programs remain possible; import provenance and exceptional renewals still need review |
| R07 — Batch composition and installments | Multiple batches and one bill per assignment/semester confirmed; repeated semester billing prohibited. Partial payouts, payees, and posting gates remain pending |
| R08 — Funding scopes, allocation, returns, and transfers | Funding tracking confirmed; earmarking/pooling and movement rules pending |
| R09 — Deductions and settlement | Tracking confirmed; types, basis, caps, balance sources, and settlement rules pending |
| R10 — Financial authority and corrections | Finance preparation/submission and independent Administrator approval/adjustment authority confirmed and implemented; cash reversals and failed-payment handling remain pending |
| R11 — Liquidation scope and acceptance | Evidence tracking confirmed; batch versus receipt scope, cash/gross basis, and accepted proof pending |
| R12 — Access, accounts, retention, and evidence | Supporting-record, Registrar and Coordinator policies, trusted provisioning, private academic/evaluation/external evidence, and linked-account retention implemented. Financial permissions, email verification policy, portals and long-term retention still need review |
| R13 — MySQL target and constraint verification | Local MariaDB 10.4.28 through Laravel's MySQL driver; all 156 current tests pass on an isolated database there. Earlier MySQL 9.2.0/SQLite checks also passed; production setup alignment remains separate |
| R14 — Imports and report definitions | Dashboard presentation exists; historical scope, denominators/as-of rules, and export formats pending |

## Remaining issues and stopping boundary

The database foundation, supporting records, reported Registrar subject grades and clarified direct-validation/approval/activation safeguards are implemented and tested. Work stops here. Required grading interpretation rules remain pending; EDUKAR application processing is excluded. Dashboard design/sample statistics are preserved. Task 7 adds verified scholarship budgets, allocation approvals, adjustments and reports; receipt/payout posting, liquidation and general dashboard analytics remain unimplemented.

Remaining issues are explicit:

- R01–R14 still govern unknown business rules. EDUKAR and unconfirmed semester authorization amounts remain null; no grade threshold, income rule, deduction percentage, duration cutoff, external-approval carry-forward, or funding sufficiency rule was invented.
- Billing semester amounts and existing individual authorization caps are now enforced transactionally. Remaining allocation capacity, payout/deduction totals and cumulative accepted liquidation coverage need posting services with locks and idempotency. This foundation provides the keys, snapshots, and relationships, not permission to post arbitrary financial rows. Reversal amounts/source state and coordinated financial corrections also need those reviewed services.
- The initial plan's one-program/semester-per-batch, scoped receipts, batch liquidation, and one application per student/program/semester are reflected in the schema. If the school confirms pooled receipts across scopes, receipt-based liquidation, partial deduction settlements, or mid-semester terms revisions, add an explicit follow-up migration before implementing the affected workflow.
- Supporting-record, Registrar and Coordinator policies, explicit provisioning, private evidence and transactional audits are implemented. Billing policies are implemented as described in Task 6; later financial permissions, student/external portals and long-term evidence retention remain pending. Existing email-verification enforcement and dependency-advisory findings remain outside this task.
- Additive migrations through semester billing management are applied locally, with account/credential and domain-value/count preservation verified. Existing account roles are retained; no Coordinator role was granted during these changes. No database reset or school-record deletion occurred. Actual active/source-period selection remains a school configuration step. Production rollout/backups are not established by local or isolated verification.
