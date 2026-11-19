# Defense readiness and end-to-end workflow

Status: **IMPLEMENTED — PENDING MANUAL VERIFICATION**

## Latest correction: COSCHO certification and an actionable isolated demonstration

**The source-level cause of the reported screen is confirmed:** the previous
catalog/classifier recognized `UAT-COSCHO` only. Opening `COSCHO` therefore still
returned no policy, even if a developer expected the fictional workflow to apply.
No runtime environment settings or existing operational records were inspected.

The implementation now also supports **COSCHO inside the isolated demonstration
environment**, while retaining the `UAT-COSCHO` alias for earlier fictional records.
Do not rename an existing program, relink real beneficiaries or enable the sample
on the operational installation. A real COSCHO assessment at port 8000 remains
blocked until a genuine compliance basis is approved. Use the separate site below.

### Actual changes in this correction

- One shared catalog predicate now recognizes COSCHO and UAT-COSCHO only with
  `APP_ENV=local`, `SMS_DEFENSE_DEMO=true`, MySQL/MariaDB and resolved database
  **exactly `cfci_sms_defense_demo`**. Production TDP/TES criteria are unchanged.
- Fictional `UAT-DEF-` Student IDs and `@example.invalid` student emails are checked
  at semester confirmation, evaluation, activation and billing. A nonfictional
  identity gets an explicit message and cannot submit the sample assessment.
- The existing school-compliance form now contains **School compliance result**
  (Eligible / Ineligible / Needs review), **Supporting certification / reference**,
  **Evaluator remarks / supporting reference**, and a required verification
  confirmation. Accepted private certification/enrollment files still come from
  Supporting evidence. No grade/GWA or unrelated TDP/TES checklist is introduced.
- The service validates the compact input and feeds the requested finding into the
  existing evaluator. Eligible cannot override missing verified enrollment,
  unconfirmed semester criteria, missing/changed evidence or an award conflict.
- The same COSCHO gate reaches the existing fictional submission classifier and
  independent approval. The classifier is still called and can reject unknown
  enrollment, excluded members, duplicate/missing items or changed source revisions.
- Fictional terms remain identifiable after disabling demo mode. New financial use
  is blocked outside isolation; XLSX, CSV, payroll and liquidation prints preserve
  the sample label from the database/retained policy markers.
- Added **`php artisan sms:defense-status`**, a read-only diagnostic. It prints only
  environment/driver/database name/flag, checks the isolated schema and sample
  active period, and reports missing prerequisites. It creates no records, runs no
  migration/seed, changes no configuration and verifies no functional happy path.
  If isolation fails, it does not query business tables.

### Exact isolated setup — developer actions, NOT executed here

1. Make a separate application folder, for example
   `C:\Users\DOKII\Documents\Capstone\cfci-defense-demo`. Copy the **current source
   including these changes**, manifests and public static assets. Exclude the real
   `.env`, bootstrap caches, operational databases/backups, storage/uploads/sessions/
   logs and populated scholar workbooks. Do not expose this demo through the
   operational web server or share its private storage.
2. Using your local database administration interface, create a **new empty**
   MySQL/MariaDB database `cfci_sms_defense_demo`, UTF-8/utf8mb4. Use a dedicated
   database account restricted to `cfci_sms_defense_demo.*`, with permissions needed
   for the existing tables/foreign keys/triggers. Do not rename, copy or reset the
   operational database. If the demo database already contains prior fictional
   data, retain it and reuse that setup; use a separate local database instance if
   an entirely fresh same-named demonstration database is needed.
3. Create a fresh demo `.env` from `.env.example` and set:

   ```dotenv
   APP_ENV=local
   APP_URL=http://127.0.0.1:8001
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=cfci_sms_defense_demo
   DB_USERNAME=<dedicated local demo account>
   DB_PASSWORD=<local secret, never committed>
   SMS_DEFENSE_DEMO=true
   SESSION_DRIVER=file
   SESSION_COOKIE=cfci_defense_demo_session
   CACHE_STORE=file
   QUEUE_CONNECTION=sync
   ```

   Leave any database URL override blank/unset and remove inherited process-level
   connection overrides. Use the dedicated instance's port if different. Do not
   copy SMTP credentials; no email or account-delivery operation is part of this demo.
4. In PowerShell **in the demo folder**, the preparation commands are:

   ```powershell
   Set-Location 'C:\Users\DOKII\Documents\Capstone\cfci-defense-demo'
   New-Item -ItemType Directory -Force -Path bootstrap/cache,storage/logs,storage/framework/views,storage/framework/sessions,storage/framework/cache/data,storage/app/private,storage/app/scholarship-private,storage/app/academic-private | Out-Null
   composer install
   npm ci
   php artisan config:clear
   php artisan route:clear
   php artisan view:clear
   php artisan key:generate
   php artisan sms:defense-status
   ```

   **Stop if the printed environment/driver/database/flag are not exactly local /
   mysql / cfci_sms_defense_demo / true.** A clean new database is expected to
   report missing schema; this command never fixes it automatically. After verifying
   that this is the separate empty database, the developer may explicitly run:

   ```powershell
   php artisan migrate
   npm run build
   php artisan serve --host=127.0.0.1 --port=8001
   ```

   These commands are instructions only. None were executed for this correction.
   Do not run seeds, `migrate:fresh`, `sms:seed-demo`, resets or the legacy synthetic
   fixtures. Existing migrations include the documented temporary-exclusion column/
   trigger change; no new migration was added by this correction.
5. Create/reuse only the three fictional staff accounts using the exact secure
   Tinker instructions under **Developer setup: separate database and application
   copy** below. Choose the password privately; no account password is supplied by
   this report. They are `registrar@example.invalid` (R),
   `coordinator.a@example.invalid` (A), `coordinator.b@example.invalid` (B).
6. Browse **`http://127.0.0.1:8001/login`**, not the operational URL. The authenticated
   page must display **UAT SAMPLE — NOT FOR OFFICIAL SUBMISSION**. As A register/open
   `2026-2027 / Semester 2`, select it as **Active validation period**, create the
   fictional course and a **COSCHO** program whose name includes the sample label.
   Configure reference amount `45000.00` in this demo program only. Follow the
   detailed registry/bulk-upload steps below; do not reuse real records.
7. In another demo-folder terminal run **`php artisan sms:defense-status`** again.
   It should report configuration, schema and the open active sample period
   available. A failure identifies the prerequisite to resolve. Its success does
   **not** mean login, financial transactions or browser compatibility were tested.

Sample student data (full ten-column CSV is in the walkthrough below):

| Student ID | Fictional name | Contact email | Enrollment for this demo |
|---|---|---|---|
| UAT-DEF-001 | Mira DemoSantos | mira@example.invalid | Registrar-verified Enrolled |
| UAT-DEF-002 | Lio DemoReyes | lio@example.invalid | Registrar-verified Enrolled |
| UAT-DEF-003 | Neri DemoCruz | neri@example.invalid | Registrar-verified Not enrolled |

Create the `COSCHO-1.0-2-2026-2027` roster and pending assignments through the
existing Batch/Roster UI, confirm the configured demonstration semester rate as A,
create the billing draft, and verify those two enrollment groups as R. The detailed
steps below give the exact fields, menus, source references and supporting files.
Earlier UAT-COSCHO datasets remain usable under the same isolation gates; use their
existing program consistently rather than changing historical codes.

### Short run sheet: compliance through liquidation

Every step below is **NOT EXECUTED**. D means today's Manila date. Every uploaded
file must visibly say **UAT SAMPLE — NOT FOR OFFICIAL SUBMISSION** and contain only
fictional information/signature marks. No actual money is received or transferred.

1. **A**, billing draft **Roster Validation → Start evaluation**, confirm COSCHO /
   current sample scholar → **Open validation**. **School compliance → Applicable
   COSCHO rules**: review the fictional basis and **Confirm semester rules** once
   for that program/period. A conflicting old confirmation is not overwritten.
2. **A**, **Supporting evidence → Add evidence**: upload and accept the fictional
   institutional certification as **Evidence for the verified facts / school
   certifications**, and a separate current sample enrollment certificate as
   **Current COR/COE or permitted certified enrollment list**. A reference string
   alone does not replace these required private accepted files.
3. **A**, **School compliance**: choose **School compliance result: Eligible**;
   reference `UAT-CERT-001` / `UAT-CERT-002`; remarks
   `Reviewed fictional certification and current enrollment for UAT only`; check
   the verification confirmation → **Evaluate and record school decision**.
   Expect a current Eligible result only if all existing checks pass. Repeat for
   Mira and Lio. Neri is not qualified by this action.
4. **A**, **External approval → Record external decision**: authority
   `UAT fictional scholarship provider`, Approved, award `UAT-AWARD-001` /
   `UAT-AWARD-002`, decision date D, corresponding fictional award PDF and verified
   identity/program/semester notes. Confirm verification and save. **Award activation
   → Activate award**, review the modal, then explicitly **Activate award** for
   each qualified scholar. External approval alone does not activate them.
5. **R**, refresh **Roster Validation**, select Mira/Lio → **Review selected
   scholars**, confirm → **Save selected draft items**. Expect two stored amounts
   of PHP45,000 and prepared total PHP90,000. Neri and active temporary exclusions
   stay unavailable. Export **All roster members** (three rows) and **Billing items
   only** (two rows); both totals must use those two stored items. Drafts/UAT output
   remain labelled, including exclusion reasons/remarks.
6. **B**, **Billing batches → Configure scholarship rates and budgets → Manage
   semester rate and budget history**: approve demo budget PHP90,000, ref
   `UAT-BUDGET-01`. **R**, batch **Submission**, ref `UAT-SUBMIT-01` →
   **Validate and submit UAT sample**. **B**, same tab, approval ref
   `UAT-ALLOC-APPROVAL-01` → **Review eligibility, check budget and approve**.
   Submitted items/classification are rechecked; preparer A and submitter R cannot
   approve their own allocation. This is fictional classification, not official forms.
7. **R**, **Liquidation and Payment → Payments and liquidation**, save fictional
   school receipt PHP90,000 / D / `UAT-RECEIPT-01`; upload labelled receipt evidence.
   **B** accepts evidence and **Verify and post actual receipt**. **R** opens the
   approved batch and **Record receipt allocation** for PHP90,000. Optional payroll:
   `UAT-PAYROLL-01` → **Validate funding and retain payroll**. This creates no payment.
8. **R**, batch **Record a student payment**: school path, gross/net PHP45,000,
   date D, Cash, `UAT-PAY-001` / `UAT-PAY-002`. Save each draft; upload payment proof
   and separate Signed acknowledgment. **B** accepts the evidence, **Verify recorded
   payment**, then **Verify signed payment acknowledgment** with exact sample ID,
   name, payment reference, date, net amount, `UAT-ACK-001` / `UAT-ACK-002` and
   page1/line1. A prepared payroll or generic proof is not an acknowledged signature.
9. **R**, batch **Liquidation records**, ref `UAT-LIQ-01` → **Prepare / open
   liquidation draft**, upload fictional reconciliation as Liquidation support.
   **B** accepts evidence. **R** **Refresh from verified records → Validate and
   submit**. **B** records review notes → **Accept complete liquidation**. Expected
   gross/paid/signed PHP90,000, zero deductions/outstanding/unspent allocated cash.
   Use **Reports** with COSCHO / 2026-2027 / Semester2 / this roster and submission;
   the existing six report modes, CSV and print retain their normal source checks.

The longer walkthrough below supplies individual field labels and values for
receipts, payment acknowledgments, liquidation, exports and reports.

### Source inspection, modified files and unresolved prerequisites

Inspected `EvaluateApplication` → `EligibilityEvaluator` → `ActivateAward` →
`BillingEligibility`/`BillingRoster` → `ManageBillingBatch::change` →
`ManageScholarshipFunds::approve`, both `exportScholars` scopes, and existing
financial receipt/payment/liquidation/signature/report operations. The existing
rate snapshot, budget checks, temporary exclusions and duplicate prevention remain.
Both export scopes still use current-batch stored gross amounts, not all roster
entitlements; export actions do not create/approve/submit records.

Modified: `OfficialRuleCatalog`, `EligibilityEvaluator`, `EvaluateApplication`,
`EvaluateScholarshipRequest`, `ScholarshipEvaluationController`, its existing
school-compliance partial, `BillingRoster`, `ManageScholarshipFunds`,
`RecordFinancialActivity`, `BillingBatchController` (XLSX label),
`ScholarshipReportController` (retained-policy UAT label), `ScholarshipFundsController`
(allocation CSV sample label), `EvaluationWorkspace`
(current confirmed applicable basis before showing readiness), financial payroll/
liquidation print views and this report. Added only the read-only
`app/Console/Commands/DefenseStatus.php`; no table, policy-management module,
dependency, public registration, email change, migration or seed was added.

Static template/document search still finds no approved official Form 2/Form 3
files/applicability definitions; the available scholar import workbook is not an
official financial form and its personal data was not inspected. Real submission
continues to call/reject through `BillingRoster::submissionClassification()`;
only the isolated fictional classifier is available for the sample.

**Actual validation for this correction:** source review and `git diff --check`
only. No diagnostic command, PHP syntax/build command, test, browser action, schema
operation, account creation, import or database query was run. No operational
record/configuration was changed. The previous compilation results later in this
report apply to the earlier source version, not this correction.

**Remaining demo prerequisites:** the developer must prepare the isolated DB/source
copy, apply existing migrations with appropriate trigger permissions, create staff/
fictional data, accept intact private evidence, confirm the sample basis/rate/budget
and personally verify the walkthrough. Stale contexts, conflicting immutable policy
confirmations, wrong URLs/flags/identities, incomplete proof, prior reservations or
insufficient receipts legitimately block it; they are not silently repaired.
No new source-level blocker was identified in the traced happy path, but runtime
success is **UNVERIFIED**, not a PASS verdict.

**Production remains restricted:** no genuine COSCHO institutional/provider
certification acceptance basis has been supplied, and official Form 2/Form 3
requirements remain absent. The actual operational assessment is not unlocked by
fictional evidence or by merely setting a flag on its real database. SMTP/provider
delivery, real payments and provider acceptance are neither needed nor claimed for
this sample. Public registration and Student Profile Management are unchanged.

Billing scholar list export and Reason for Delisting integration have been added;
see the corresponding final sections for manual checks. Both additions received
static source review only; the earlier compilation results below predate them.
Temporary exclusion recording requires the new, unexecuted migration documented
in the delisting section. No registry data has been inserted or updated here.

The isolated fictional walkthrough is supported by source code from enrollment to
school evaluation, external approval, explicit activation, billing submission,
independent allocation approval, receipt allocation, payment, signed acknowledgment,
liquidation and existing reports. **It has not been functionally tested.** No demo
database was created or inspected, no records were inserted, and no migrations,
seeds, browser sessions or automated tests were executed for this task.

Actual CoScho compliance and official Form 2/Form 3 submission remain blocked.
This implementation supplies a deliberately separate fictional scenario, not an
official CoScho policy, certification authority, payable Form 3 rule or bank service.

## Implemented changes and boundaries

The existing `OfficialRuleCatalog` now contains one fictional institutional
certification scenario, `UAT-INSTITUTIONAL-CERTIFICATION-v1`. It is available only
when **all** of these conditions hold:

- `APP_ENV=local`.
- `SMS_DEFENSE_DEMO=true` (default: false).
- The resolved default database driver is `mysql` (MySQL/MariaDB).
- Its resolved database name is exactly `cfci_sms_defense_demo`.
- The program is `COSCHO` (or the retained `UAT-COSCHO` alias), exclusively with fictional students inside these isolation gates. Operational COSCHO receives no fictional basis.
- Evaluation, activation and billing concern a student number starting `UAT-DEF-`
  whose email ends in `@example.invalid`.

It reuses the existing certification/evidence types and evaluation outcomes:
an explicit Eligible/Ineligible/Needs review certification finding, a `UAT-` source reference and verification confirmation,
required remarks, accepted eligibility-support and enrollment evidence, actual
Registrar-verified current enrollment, confirmed semester criteria and no
conflicting active award. It does not expose TDP/TES checklists for this sample.
Missing evidence or an unverified finding remains Needs review; a supported
negative finding remains Ineligible. Enrollment alone never activates an award.
The institution's actual certification acceptance rules still need confirmation
before any real unsupported program can produce a positive decision.

The fictional program uses the existing fixed-rate approval operation. Its amount
is read from the configured program and approved semester rate, never from a new
hardcoded financial amount. The regular rate revision and billing snapshots remain.

`BillingRoster::submissionClassification()` remains in the submission transaction.
For actual programs it still rejects submission. For the isolated sample it
validates the **whole roster**: every member has known, verified current enrollment;
every enrolled sample without an explicit semester exclusion appears exactly once
in the bill with the same assignment; not-enrolled and explicitly excluded members
are not billed. The normal eligibility checks separately require
active awards, current qualified decisions, external approvals and authoritative
amounts. Unknown enrollment, missing eligible members, extra or duplicate items,
wrong scopes and invalid student identities block the sample submission.

The fictional classification uses `UAT_ENROLLED` / `UAT_NOT_ENROLLED` (and
`UAT_BILLING_EXCLUDED` for an explicit recorded exclusion), **not**
official Form 2/Form 3 codes. It retains membership/assignment IDs, academic record
IDs, revisions, lock versions, verification actors/timestamps, policy version and
the sample label in existing billing-item `basis_snapshot.uat_classification`.
Existing finalization/audit records identify the submitting actor/time. Independent
approval recalculates and compares classification; changed sources cannot be
silently approved. Submission failure rolls back finalization with its transaction.

The sample submission button is enabled only for the guarded program/environment.
The production blocker remains visible elsewhere. The demo database shows a
permanent sample notice even if its enable flag is subsequently disabled. Existing
CSV reports, printable reports, payroll registers and liquidation summaries mark
fictional output **UAT SAMPLE — NOT FOR OFFICIAL SUBMISSION**. Report exports also
detect UAT program rows; financial printouts detect the UAT program independently
of the enable flag. No official form or new report was created.

Existing financial approval checks reject the UAT batch when demo mode is disabled.
No payment transfer is implemented. Only the developer, working inside the isolated
database, may record fictional receipts/payments for this walkthrough.

## Existing functionality reused

| Operation | Existing implementation / safeguard |
|---|---|
| Registry and roster | Program reference amount, academic period, course registry, scholar bulk upload, existing batches/memberships and pending assignments |
| Enrollment | Billing Roster Validation, `SaveAcademicVerification`, exact roster period, evidence and new enrollment-only revisions |
| School compliance | Existing evaluation workspace, request, `EvaluateApplication`, `EligibilityEvaluator`, immutable `EvaluationRun`, `PrivateEvidence`, semester criteria confirmation |
| External approval | Existing External approval tab and `RecordExternalApproval`; exact award/semester, authority/reference, date, verification and private evidence |
| Activation | Existing `ActivateAward`; pending award, current qualified run, verified enrollment/approval, one active award and audit |
| Billing | `BillingEligibility`, `ManageBillingBatch`, approved rate snapshots, bulk item selection, semester duplicate prevention and transactional revalidation |
| Budget and approval | `ManageScholarshipFunds`, approved budget, independent approval; preparer/submitter cannot approve their own bill |
| Finance | `RecordFinancialActivity`, `FinancialEvidence`, `PreparePayroll`, `SignedAcknowledgment`, `ManageLiquidation`, existing reconciliation and independent review |
| Reports | Existing six reports, recorded-roster/billing links, year/semester/program filters, CSV and print |

No tables, migrations, dependencies, modules, email changes, automatic activations,
grade/GWA rules or additional approval stages were introduced. Existing historical
decisions and actual financial snapshots are not rewritten.

## Original defense-workflow review and check results (before scholar export)

| Check | Actual result |
|---|---|
| Targeted source review | Reviewed evaluation, activation, billing, financial evidence/payment/liquidation, report filters and role policies. No additional financial integration defect was established by static inspection. |
| Form/template search | Targeted `rg -n -i` searches and file inventory review found descriptions/blockers, not approved templates or applicability rules. `docs/steps/04-form2-form3-and-billing.md`, `docs/sms/billing-roster-integration.md` and `docs/sms/progress.md` explicitly document the missing approved source. |
| PHP syntax | `php -l <each changed non-Blade PHP file>`: all 15 files passed after final edits. |
| Blade compilation | Standalone installed Laravel Blade compiler plus PHP `token_get_all(..., TOKEN_PARSE)`: all seven changed templates compiled and parsed. No views were rendered. The first compiler invocation lacked the `files` container binding and failed before compilation; registering `Illuminate\Filesystem\Filesystem` in the standalone container resolved that check setup error. |
| Frontend compilation | `npm run build`: exit 0; Vite 7.3.6, 67 modules, production assets generated. |
| Whitespace | `git diff --check`: passed. Git emitted line-ending normalization notices on Windows; no whitespace errors. |
| Functional, security, DB, browser and automated tests | **NOT EXECUTED**, as instructed. No claim of successful login, submission, cash reconciliation or report correctness from runtime execution. |

The Blade check instantiated a minimal application container with only `files`,
an in-memory `view` config repository and `ViewServiceProvider`, called
`compileString()` on changed templates, and syntax-parsed the generated PHP. It did
not load `.env`, boot application providers, render views or connect to a database.

## Files modified

- `config/sample-data.php` — opt-in defense flag; legacy seed flag remains separate.
- `app/Services/Eligibility/OfficialRuleCatalog.php` — isolation/student guards and fictional certification criteria.
- `app/Services/Eligibility/EligibilityEvaluator.php` — compact fictional certification assessment using existing outcomes.
- `app/Actions/Eligibility/EvaluateApplication.php` — fictional identity guard and category-safe snapshot.
- `app/Actions/Eligibility/ActivateAward.php` — fictional identity/isolation guard; regular activation requirements retained.
- `app/Http/Requests/EvaluateScholarshipRequest.php` — fictional finding/reference/remarks validation.
- `app/Http/Controllers/ScholarshipEvaluationController.php` — truthful fictional confirmation notification.
- `app/Models/ScholarshipProgram.php` — guarded fixed-rate recognition for the sample program.
- `app/Services/Billing/BillingEligibility.php` — fictional identity/isolation guard.
- `app/Services/Billing/BillingRoster.php` — guarded, revision-traceable fictional classification; real submission restriction retained.
- `app/Actions/Billing/ManageBillingBatch.php` — retain sample classification in existing submission snapshots.
- `app/Actions/Billing/ManageScholarshipFunds.php` — compare current sample classification at independent approval.
- `app/Actions/Billing/RecordFinancialActivity.php` — UAT batch cannot count as financially approved outside enabled isolation.
- `app/Http/Controllers/BillingBatchController.php` — sample-specific draft confirmation text.
- `resources/views/evaluations/partials/school-compliance.blade.php` — certification fields and explicit fictional basis notice.
- `resources/views/billing/show.blade.php`, `resources/views/billing/partials/submission.blade.php` — guarded sample submission and preserved official blocker.
- `resources/views/layouts/app.blade.php` — isolated database notice.
- `app/Http/Controllers/ScholarshipReportController.php` — sample identification for report output/CSV.
- `resources/views/scholarship-reports/print.blade.php`, `resources/views/financial/payroll-print.blade.php`, `resources/views/financial/print.blade.php` — printable sample notices.
- This report.

## Developer setup: separate database and application copy

**Instructions below were not executed.** Do these only in a separate local copy.
The original defense-path changes require no migration of their own; the later
semester-exclusion addition requires the migration documented at the end of this report.

1. Create a separate folder such as `C:\Users\DOKII\Documents\Capstone\cfci-defense-demo`.
   Copy the current application source, including these uncommitted changes, and
   package manifests into it. Do not copy the operational `.env`, cached bootstrap
   configuration, databases/backups, uploads, sessions, logs or populated workbooks.
   A clean HEAD-only checkout will omit this implementation until changes are saved
   there. Keep the operational folder and its `.env` unchanged.
2. In a local database administration tool, create a **new empty** MySQL/MariaDB
   database named exactly `cfci_sms_defense_demo` using `utf8mb4`. Create a dedicated
   local database user granted access **only** to `cfci_sms_defense_demo.*`; never use
   the operational database credentials or restore operational records into it.
3. In the demo copy create the normal writable `bootstrap/cache`, `storage/logs`,
   `storage/framework/views`, `storage/framework/sessions`,
   `storage/framework/cache/data`, `storage/app/private`,
   `storage/app/scholarship-private` and `storage/app/academic-private` directories.
   Do not symlink these private directories to `public` or the operational storage.
4. Install dependencies there with `composer install` and `npm ci`. Create a fresh
   `.env` from `.env.example`. Configure these values; keep database secrets local:

   ```dotenv
   APP_ENV=local
   APP_URL=http://127.0.0.1:8001
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=cfci_sms_defense_demo
   DB_USERNAME=<dedicated demo database user>
   DB_PASSWORD=<local secret>
   SMS_DEFENSE_DEMO=true
   SESSION_DRIVER=file
   SESSION_COOKIE=cfci_defense_demo_session
   CACHE_STORE=file
   QUEUE_CONNECTION=sync
   ```

   Leave any database URL override unset/blank; ensure process-level environment
   variables do not override this file. Do not copy SMTP secrets. No email/account
   setup action is part of this demonstration; all sample emails are undeliverable.
5. In the **demo folder only**, run `php artisan config:clear`,
   `php artisan key:generate`, then `php artisan tinker`. Before any schema command,
   inspect the resolved target (no credentials):

   ```php
   dump(app()->environment(), DB::connection()->getDriverName(), DB::connection()->getDatabaseName(), App\Services\Eligibility\OfficialRuleCatalog::demonstrationEnabled());
   ```

   Require exactly `local`, `mysql`, `cfci_sms_defense_demo`, `true`. Stop on any
   mismatch. Exit Tinker. Only after confirming the separate **empty** target, run
   `php artisan migrate` there. Do not run `migrate:fresh`, reset commands, seeders,
   `sms:seed-demo` or the old synthetic fixtures. Their separate console-only
   policies are not this runtime walkthrough.
6. Bootstrap three fictional staff accounts using the existing user/membership
   models in this new database. No public staff-account creation route is added.
   In that demo PowerShell terminal choose a password privately:

   ```powershell
   $demoSecret = Read-Host 'Choose a private demo staff password' -AsSecureString
   $env:UAT_STAFF_PASSWORD = [System.Net.NetworkCredential]::new('', $demoSecret).Password
   php artisan tinker
   ```

   Paste into Tinker (no password is printed or supplied by this report):

   ```php
   DB::transaction(function () {
       abort_unless(App\Services\Eligibility\OfficialRuleCatalog::demonstrationEnabled(), 403);
       abort_unless(strlen((string) getenv('UAT_STAFF_PASSWORD')) >= 12, 422);
       foreach ([['UAT Registrar', 'registrar@example.invalid', 'registrar'], ['UAT Coordinator A', 'coordinator.a@example.invalid', 'coordinator'], ['UAT Coordinator B', 'coordinator.b@example.invalid', 'coordinator']] as [$name, $email, $role]) {
           if (App\Models\User::where('email', $email)->exists()) {
               throw new RuntimeException('Demo account already exists; use it rather than duplicating or changing it.');
           }
           $user = App\Models\User::create(['name' => $name, 'email' => $email, 'password' => Illuminate\Support\Facades\Hash::make(getenv('UAT_STAFF_PASSWORD'))]);
           $user->roles()->create(['role' => $role]);
       }
   });
   ```

   Exit Tinker; clear the process secret with
   `Remove-Item Env:UAT_STAFF_PASSWORD`, then `$demoSecret = $null`.
   This is developer-only preparation of fictional staff in a new isolated DB,
   not permission to change existing operational memberships.
7. Run `npm run build`, then `php artisan serve --host=127.0.0.1 --port=8001` in
   that copy. Use `http://127.0.0.1:8001/login`; do not use the operational port.
   Confirm the sample notice after login. If it is absent, stop and check isolation.
   Do not change the operational database name merely to enable sample rules.

## Manual walkthrough: all steps NOT EXECUTED

The developer must record actual outcomes separately. Everything below describes
**expected**, not observed, results. The sample amount and documents are fictional.
No actual funds are received/sent and no document represents a genuine authority
decision or genuine beneficiary signature. Put the full sample label on every file.
Use today's Manila date as **D** for authority, receipt, payment and signature dates.
Do not change the system clock or enter future payment/decision dates.

### Roles

| Short name | Login email created in setup | Operations used |
|---|---|---|
| A | `coordinator.a@example.invalid` | Registries, roster creation, semester rate, school evaluation, external decision, activation |
| R | `registrar@example.invalid` | Scholar upload, enrollment verification, bill item preparation/submission, receipt/payment/payroll/liquidation preparation |
| B | `coordinator.b@example.invalid` | Budget, independent bill approval, financial evidence/receipt/payment/signature verification, liquidation acceptance |

Each uses the privately chosen setup password. Use the user menu **Log out** when
switching roles, or separate browser profiles. R cannot confirm school policy,
evaluate or activate awards; A/B cannot use Registrar-only enrollment verification.
Existing Test Administrator exceptions are preserved, but not needed in this guide.

### 1. Register the fictional period, course and scholarship — A

1. Log in as A. **Information Registry → Academic Year → Add academic year**.
   Enter **Start year: 2026**, **End year: 2027**, **Semester code: 2**,
   **Semester status: Open**. Leave optional dates empty for this fictional period.
   Click **Save academic year and semester**. Expected: one `2026-2027 / 2` period.
2. **Information Registry → Semester → Active validation period**. Select
   `2026-2027 / 2` in **Active academic year / semester for validation** and click
   **Save validation period**. Expected: evaluations use that period. Do not change
   the context again during the walkthrough, since it would stale decisions.
3. **Information Registry → Course / Program**: **Complete program name** =
   `UAT Bachelor of Science in Information Technology`, **Available for new profiles**
   = Yes. Click **Register program**. Expected: one active sample course.
4. **Information Registry → Scholarship Grant / Program → Add program**:
   **Program code** = `COSCHO`; **Program name** =
   `CoScho UAT SAMPLE — NOT FOR OFFICIAL SUBMISSION`;
   **School-stated semester amount (PHP)** = `45000.00`;
   **Availability for new active assignments** = Active;
   **School source / reference notes** =
   `UAT-RATE-01: fictional demonstration reference; not official CoScho authority`.
   Leave optional duration/billing-reference selections blank. Click **Save program**.
   Expected: a separate UAT program, with a reference amount but no approved rate yet.

### 2. Register three fictional scholars — R

**Information Registry → Scholars → Bulk Upload → Download Template**. Use a new
copy; do not use the populated operational workbook. Keep exactly these headers:

```csv
Student ID,Last Name,First Name,Middle Name,EXT,Sex,Course,Year Level,Email,Contact
UAT-DEF-001,DemoSantos,Mira,,,Female,UAT Bachelor of Science in Information Technology,1,mira@example.invalid,00000001
UAT-DEF-002,DemoReyes,Lio,,,Male,UAT Bachelor of Science in Information Technology,1,lio@example.invalid,00000002
UAT-DEF-003,DemoCruz,Neri,,,Female,UAT Bachelor of Science in Information Technology,1,neri@example.invalid,00000003
```

Use a UTF-8 CSV or the downloaded XLSX; keep Student ID and Contact as text.
Choose **Scholar spreadsheet (required)** → **Preview**. Expected: three valid new
rows and no duplicates. Check **I reviewed this preview...** → **Confirm Import**.
Expected: three active profiles, no login accounts, awards or billing yet. Additional
profile-detail completeness badges can remain incomplete; do not invent real IDs.
These numbers/email addresses are fictional and must never be contacted.

### 3. Create one batch/roster with pending assignments — A

1. **Information Registry → Batch → Create batch**: **Scholarship program** =
   COSCHO; **Academic year / semester** = `2026-2027 / 2`;
   **Batch number** = `1.0`. Review generated name `COSCHO-1.0-2-2026-2027`;
   click **Create batch**. Expected: one empty scholarship batch, no financial bill.
2. Open that batch → **Manage roster membership → Add scholars**. Search `UAT-DEF-`
   with **Student ID or name → Search / filter**. Select only the three sample rows.
   **Scholarship starting date for new pending assignments** = `2026-11-01`
   (fictional date; selected period has no date boundary). Click **Review selected
   scholars**, verify three names, then **Confirm selected scholars**.
   Expected: three memberships and three pending assignments; no automatic activation.

### 4. Confirm the demonstration rate and create a draft — A

**Billing and Validation → Billing batches → Create billing**. Select the existing
UAT roster in **Existing semester roster**. Review program, period, member count
three and read-only reference PHP 45,000.00. Enter **School rate approval reference**
= `UAT-RATE-01 — fictional semester rate approval`; check **Confirm the configured
reference amount...** → **Confirm semester rate**. Expected: an approved historical
semester rate, not scholar eligibility or a bill yet. The modal reopens; select the
same roster if needed. Enter **Preparation reference (optional)** = `UAT-BILL-01` →
**Create draft and review roster**. Expected: financial `Batch 1`, linked to the
existing scholarship roster, approved rate PHP 45,000.00, zero prepared items.

### 5. Verify two enrolled scholars and one not enrolled — R

1. Open **Billing and Validation → Billing batches → Batch 1 → Roster Validation →
   Verify enrollment**. Select **Mira and Lio only**. Choose **Enrollment finding for
   this semester: Enrolled**; **Registrar source / verification reference** =
   `UAT-ENROLL-01`; **Enrollment notes / reason** =
   `Fictional checked enrollment list: Mira and Lio enrolled; sample only`.
   Attach a labelled fictional PDF covering those two via **Supporting enrollment
   record (optional)**; check **I reviewed each selected scholar...** →
   **Save enrollment findings**. Expected: both current enrollment findings verified;
   awards remain pending and not yet payable.
2. Reopen **Verify enrollment**; select **Neri only**, choose **Not enrolled**;
   reference `UAT-NOT-ENROLLED-03`; notes
   `Not enrolled in the fictional selected semester — temporary billing exclusion`.
   Attach matching fictional proof if desired, confirm review and save.
   Expected: Neri remains in the roster with a pending assignment, excluded from
   ordinary billing; no permanent delisting or scholarship termination. To retain
   an applicable registered reason, use the explicit **Record billing exclusion**
   operation described below; selecting a reason never terminates the scholarship.

### 6. School certification, external approval and activation — A

Repeat the following for **Mira and Lio only**. Use the existing billing row
**Start evaluation** link (or **Billing and Validation → Evaluations → Start
assessment**, choose COSCHO → **Find assigned scholars**). Confirm the intended
**Assigned scholar** and active period → **Open validation**. The second scholar's
assessment is a separate record; do not reuse another student's context.

1. **School compliance → Applicable UAT-COSCHO rules**: read the fictional basis,
   check **I reviewed these existing rules...** → **Confirm semester rules**.
   Expected: one immutable semester confirmation reused by both assessments; do not
   re-confirm for the second scholar. It is labelled fictional, not official policy.
2. **Supporting evidence → Add evidence**: upload a labelled fictional certification
   for that scholar/period as **Evidence for the verified facts / school
   certifications**; select **I verified this evidence...** only after reviewing the
   sample → **Upload evidence**. Repeat for **Current COR/COE or permitted certified
   enrollment list** with the fictional enrollment file. PDF/JPG/PNG, maximum 2 MB
   each. Expected: accepted, private evidence with checksum/reviewer; an unverified
   upload needs **Review evidence → Review notes / original checked → Accept evidence**.
3. **School compliance**: **School compliance result** = **Eligible**;
   **Supporting certification / reference** = `UAT-CERT-001` for Mira or
   `UAT-CERT-002` for Lio. **Evaluator remarks / supporting reference** =
   `Reviewed fictional certification and current enrollment for UAT only`.
   Check **I reviewed the fictional institutional certification...** and click
   **Evaluate and record school decision**. Expected: current Eligible
   (`passed` / `qualified`) only when all enrollment, evidence and confirmed-basis
   requirements pass. Otherwise the existing evaluator retains Needs review or
   Ineligible. The selected result cannot override those checks. No grade/GWA or
   unrelated TDP/TES checklist is required.
4. **External approval → Record external decision**:
   **Verified approving authority** = `UAT fictional scholarship provider`;
   **External decision** = Approved;
   **Authority reference / award number** = `UAT-AWARD-001` or `UAT-AWARD-002`;
   **Authority decision date** = D; leave optional validity dates blank;
   **Official decision evidence** = the clearly labelled fictional award PDF for
   this exact scholar/UAT program/period;
   **How the external decision was verified** =
   `Compared the fictional award, sample identity, program and semester`.
   Check **I verified the authority decision...** → **Record external decision**.
   Expected: verified semester approval retained separately; assignment still pending.
5. **Award activation → Activate award**. Review exact scholar, period, evaluation
   run and award reference in the modal → **Activate award**.
   Expected: that assignment becomes active with audit/history references. No
   duplicate active award. A missing/currently invalid prerequisite blocks activation.

Do not evaluate/approve/activate Neri as an enrolled scholar. Enrollment and roster
membership for Neri remain preserved.

### 7. Add eligible billing items, review, submit and independently approve

1. **R:** Return to **Billing batches → Batch 1 → Roster Validation**, refreshing
   the page after activation. Expected: Mira/Lio Eligible and available, Neri
   Not enrolled/Excluded. Select the two eligible billing checkboxes → **Review
   selected scholars**. Expected: PHP 45,000.00 each; selected total PHP 90,000.00.
   Check **I have reviewed the selected roster...** → **Save selected draft items**.
   **Billing Items** must show exactly two rows and prepared total PHP 90,000.00.
   Already included students become unavailable for duplicate selection. Changing
   the program reference later must not rewrite the pinned approved rate/history.
2. **B:** **Billing batches**, filter COSCHO and this period → **Filter**.
   Expand **Configure scholarship rates and budgets → Manage semester rate and budget
   history** for that scope. Under **Configure available scholarship budget** enter
   **Approved budget (PHP)** = `90000.00`;
   **Budget approval reference / reason** = `UAT-BUDGET-01 — fictional planning limit`;
   click **Record budget revision**. Expected: approved planning budget, zero actual cash.
3. **R:** Open **Batch 1 → Submission**. Read sample-classification notice.
   **Submission reference** = `UAT-SUBMIT-01` → **Validate and submit UAT sample**.
   Expected: both items finalized, retained classification includes all three source
   revisions, header Submitted/Awaiting Coordinator approval. Any unknown enrollment,
   omitted non-excluded enrolled sample, not-enrolled/excluded item, stale validation or changed approval
   blocks the whole transaction. This generates no official Form 2/Form 3 document.
4. **B:** Reload **Batch 1 → Submission**. **Coordinator approval reference** =
   `UAT-ALLOC-APPROVAL-01` → **Review eligibility, check budget and approve**.
   Expected: approved allocation PHP 90,000.00, remaining planning budget zero;
   no received funds or payments. R (submitter) and A (preparer) cannot approve it.

### 8. Fictional receipt, funding and optional payroll

1. **R:** **Liquidation and Payment → Payments and liquidation → Record an actual
   school receipt**. In this isolated UAT database only, select COSCHO / period2;
   **Actual amount received (PHP)** = `90000.00`; **Date actually received** = D;
   **Source / funder** = `UAT fictional provider`;
   **Receipt / bank reference** = `UAT-RECEIPT-01` → **Save actual receipt draft**.
   Expected: draft not counted as cash. No real receipt/bank transfer is represented.
2. On **School funding receipt → Private evidence**, select **Receipt evidence**,
   choose the labelled fictional receipt PDF → **Upload private evidence**.
   **B:** open that receipt by its source link; inspect proof; **Review notes** =
   `UAT sample receipt proof reviewed` → **Accept evidence** →
   **Verify and post actual receipt**. Expected: verified fictional receipt PHP90,000.
   The uploader/recorder cannot self-verify this financial evidence/receipt.
3. **R:** **Payments and liquidation → Submitted billing batches → Batch 1 →
   Payments & liquidation**. Under **Record allocation of verified school
   receipts**, **Verified receipt in this semester** = UAT-RECEIPT-01;
   **Actual receipt amount to allocate (PHP)** = `90000.00` →
   **Record receipt allocation**. Expected: PHP90,000 usable funding allocated once;
   outstanding student allocation PHP90,000, paid zero. Planning budget alone could
   not fund this operation.
4. Optional existing payroll: **R**, same page, **Payroll reference** =
   `UAT-PAYROLL-01` → **Validate funding and retain payroll** → **Open payroll**.
   Expected: two retained planned lines of PHP45,000, planned total PHP90,000,
   no automatic payments/signatures. Click **Print internal register**; its print
   view carries the sample label.

### 9. Record two fictional payments and verify exact signed coverage

Repeat for Mira and Lio. In **Batch 1 funding page → Record a student payment**:

1. **R:** **Student / billing item** = intended sample; **Payment path** =
   Paid through the school; **Gross benefit applied (PHP)** = `45000.00`;
   **Amount actually paid to student (PHP)** = `45000.00`;
   **Actual payment date** = D; **Payment method** = Cash;
   **Payment / transaction reference** = `UAT-PAY-001` or `UAT-PAY-002` →
   **Save payment record draft**. Expected: draft; not counted as paid.
   Do not add deductions; no deduction authority is claimed in this scenario.
2. **R:** Open the payment via the scholar name under **Student payment records**.
   In **Private evidence**, upload one **Payment evidence** PDF and a separate
   **Signed acknowledgment** PDF. Each names this exact sample ID/name, payment
   reference, D and net amount45,000, with an explicitly fictional signature mark,
   document ref `UAT-ACK-001`/`UAT-ACK-002`, page1/line1 and the full sample label.
   Do not forge or copy a real beneficiary's signature.
3. **B:** Open that **Student payment record**, inspect and **Accept evidence** for
   both files with review notes `Reviewed fictional UAT payment/acknowledgment`.
   Click **Verify recorded payment**. Expected: posted fictional payment subject
   to current eligibility, allocation, proof and funding checks. After both payments,
   school-paid total PHP90,000; remaining student allocation zero, cash balance zero.
4. **B:** In **Signed payment acknowledgment**, choose that R-uploaded file under
   **Signed acknowledgment owned by this payout**. Enter:
   **Acknowledgment document reference** = UAT-ACK-001 / UAT-ACK-002;
   **Page and line covering this payment** = `Page 1, line 1`;
   **Student ID shown on acknowledgment** = UAT-DEF-001 / UAT-DEF-002;
   **Beneficiary name shown on acknowledgment** = Mira DemoSantos / Lio DemoReyes;
   **Actual payment reference shown on acknowledgment** = UAT-PAY-001 / UAT-PAY-002;
   **Actual payment date shown on acknowledgment** = D;
   **Student amount received shown on acknowledgment (PHP)** = `45000.00`.
   Check the signature/coverage confirmation only after comparing the fictional
   document → **Verify signed payment acknowledgment**.
   Expected: exact verified signed coverage retained; ordinary payment proof alone
   was insufficient. No real-world signature validity or payment is asserted.

### 10. Liquidate and review — R then B

1. **R:** Back to **Batch 1 funding page → Liquidation records**:
   **Liquidation reference** = `UAT-LIQ-01` → **Prepare / open liquidation draft**.
   Expected: two payment lines totalling PHP90,000; paid, signed, evidenced and
   allocated balances must reconcile. Missing signatures/payments remain blockers.
2. **R:** **Liquidation record → Private evidence**, type **Liquidation support**:
   upload a labelled fictional reconciliation PDF listing both payments, receipt
   allocation90,000, no deductions and zero outstanding/unspent balance.
   **B:** open **Liquidation records → Report 1**, inspect the file, enter review
   notes `Reviewed fictional UAT reconciliation` → **Accept evidence**.
3. **R:** same draft → **Refresh from verified records**, review checklist →
   **Validate and submit**. Expected: submitted report with retained source checklist.
4. **B:** reload report; **Review / return reasons** =
   `Fictional UAT receipt, payments, exact signed coverage and support reconcile` →
   **Accept complete liquidation**. Expected: independently accepted liquidation;
   originals, proof and approval history retained. Incomplete sources must block it.
5. **Printable summary** must show the sample label. Do not submit it to a provider.

### 11. Existing Reports — R, A or B

Use **Reports**, select **Scholarship program: COSCHO**, **Academic year:
2026-2027**, **Semester: 2**, **Semester roster batch (recorded link)** = sample
roster → **Generate report**. Where offered, select **Financial billing submission:
Batch 1**; do not use that financial filter for the roster/not-billed denominator.

| Existing report button | Expected result at the stated stage |
|---|---|
| Grants by Scholarship Program and Batch | Three roster rows, including Neri; no official form classification asserted. |
| Billed Scholars | Two submitted billing rows, gross PHP90,000; drafts did not count before submission. |
| Not Billed Scholars | Neri only after submission; retained Not enrolled finding and membership. |
| Grants Awaiting Funds for Payroll | After independent approval and before receipt allocation: two unpaid items / one batch shortfall PHP90,000. After full funding: no awaiting-funds shortfall; funded-but-unpaid is separate. After payment: no unpaid item. Inspect this report at those stages rather than expecting a historical reconstruction. |
| Paid Grants with Verified Signed Payroll | Two items, verified paid/signed net PHP90,000 after step9. Actual explicit acknowledgment coverage is required, not simply a prepared payroll register. |
| Liquidated Billings | One independently accepted, currently complete billing liquidation after step10; missing/changed proof would be disclosed rather than silently treated as complete. |

Use **Export CSV** and **Print complete report**. Expected: matching filtered
complete results and sample notices. Reports describe current recorded facts,
not historical as-of snapshots. No new report or unsupported status filter was added.

### Developer negative checks (not executed)

- Unknown enrollment, absent certification proof or unverified certification cannot
  qualify/activate a sample. Not-enrolled Neri cannot be added to ordinary billing.
- A repeated item or existing semester bill is rejected; a stale batch version must
  reload; a rejected bulk operation leaves no partial selected additions.
- Student/unauthorized roles cannot invoke evaluation, enrollment or financial writes;
  Registrar cannot evaluate/activate. Preparers cannot approve their own submission,
  financial evidence or liquidation where independent review is required.
- Editing an enrollment source after UAT submission must block independent approval
  due to the retained revision comparison. The submitted historical snapshot remains.
- In a separate configuration check, disabling the demo flag must block fresh UAT
  qualification/activation/billing and use of the batch for allocation/payment. Do not disable it midway through
  the happy-path demonstration and then assume current outputs remain payable.
- Actual/nonfictional COSCHO beneficiaries remain prohibited from fictional qualification, even inside this demo database. Only the isolated sample identities may use the COSCHO demonstration basis; operational COSCHO remains blocked.
- An actual/non-UAT billing request still hits the original final classification
  exception. No official Form 2/Form 3 output or automatically payable Form 3 exists.

## Remaining production blockers and limitations

1. **Actual CoScho compliance basis:** institution/provider must identify the applicable
   authorized requirements, program/semester mapping, who may certify, what findings
   and evidence establish compliance, and whether that certification is sufficient
   for a positive school decision. No genuine approval was supplied by the project
   documents. The UAT basis does not resolve this production requirement.
2. **Official submission:** actual approved Form 2/Form 3 templates, versions, provider/
   program applicability, payable/excluded mappings and revision-freshness/output
   requirements remain absent. Real final submission stays blocked.
3. **Environment preparation and functional verification:** the developer must create
   the isolated empty DB, apply existing migrations there, prepare staff/files and
   personally execute the guide. SMTP, live payments, actual bank receipts and
   provider acceptance are neither required nor claimed by this fictional walkthrough.
4. The sample classifier deliberately requires the whole known roster, including all
   enrolled sample members without an explicit semester exclusion; it is not a
   production partial-submission specification.
   A stale submitted UAT classification is rejected at approval, not silently repaired.
   Keep the demo period/sources stable between submission and approval; preserve any
   stale submission as history and use a separately prepared fictional scenario if a
   correction path is needed. Do not delete historical records to force the demo.
5. Existing TDP/TES source-based rules and EDUKAR's unsupported variable-entitlement
   path are unchanged. No official CoScho rules, academic thresholds, delisting rules
   or direct-provider payroll funding rules were invented.

**Final assessment:** source-level isolated demonstration support implemented;
runtime readiness **pending developer setup and manual verification**. Production
compliance and official billing submission are **BLOCKED** on the approvals above.

## Additional requirement: billing scholar list export

Status: **IMPLEMENTED — PENDING MANUAL VERIFICATION**. This addition was reviewed
statically only. No application code, syntax/compiler commands, automated tests,
exports, browser actions, migrations or database queries/writes were executed for
this export task. The earlier PHP/Blade/Vite results in this report apply only to
the preceding defense-workflow implementation, not to these export changes.

### Navigation, format and permissions

**Billing and Validation → Billing batches → open a batch**. Beside Edit details /
Back to billing, select **All roster members** or **Billing items only**, then click
**Export scholar list**. The form uses ordinary GET navigation and no new frontend
dependency, tab or module.

Route: `GET /billing-batches/{batch}/scholar-list?scope=roster` or `scope=items`,
named `billing-batches.scholars.export`. It requires authentication and the existing
`BillingBatchPolicy::view` permission, checked before export processing and again
after loading the record for reporting. Current permitted roles are Registrar,
Coordinator, retained Test Administrator, and existing legacy Finance/Viewer
memberships, exactly as on the billing detail page. Students and other unauthorized
accounts cannot export by directly requesting the URL. Invalid scopes are rejected.

The existing `ScholarImportTemplate` ZIP/XML workbook generator is reused and
extended with `writeReport()`. No Excel package or other dependency is installed.
The existing PHP ZIP extension used by Download Template must remain enabled.
The original template retains Scholars / Instructions / Courses, course validation,
its named range and its existing text-style indexes; common package assembly and
archive writing are now shared with the report.

The output is a real `.xlsx`, with one **Scholar list** sheet, merged report
headings/metadata, readable column widths, wrapped row content, table header
filters, frozen institution/title rows and numeric PHP currency cells. Student IDs
are explicit `inlineStr` cells with Text formatting; no numeric conversion or
spreadsheet formula is used to preserve leading zeros. All other untrusted text
is also stored as literal text, XML-escaped, with invalid XML control characters
removed. Only validated decimal billing amounts become numeric cells.

The filename contains the recorded roster name, billing batch name/reference,
export scope and Manila generation timestamp. Drafts have a **DRAFT** filename
prefix and workbook notice. The isolated demo DB and the UAT program produce
**UAT SAMPLE — NOT FOR OFFICIAL SUBMISSION** inside the workbook and a UAT filename
prefix, including when a UAT program's runtime enable flag is disabled. Every file
is labelled an internal report, not an official Form 2/Form 3 submission.

### Data, totals and historical amounts

The report includes the institution/report titles, program name/code, recorded
roster, billing name/reference, academic year, semester, allocation status, approved
applied rate, generation time and actual linked `preparedBy` user. The exporter
does not substitute the downloading user when the preparer is unknown.

All thirteen requested columns are included: sequential number, Student ID, full
name, course/program, year, assignment status, current enrollment, school compliance,
external approval, billing eligibility, billing inclusion, individual billing amount
and existing exclusion/review reason. No emails, phone numbers, protected national
identifiers, passwords, tokens, uploaded files or unrelated personal details are
exported.

- **All roster members:** uses the opened batch's persisted `scholarship_batch_id`
  and `BillingRoster::readiness()` before pagination. It ignores the visible page,
  search and table filters. Eligibility/enrollment/reasons match the existing
  readiness operation. Inclusion explicitly distinguishes this batch, another
  transaction's reservation/bill, and not included. An eligible unadded member is
  not labelled included and has no prepared amount in this batch.
- **Billing items only:** queries only `$batch->items()`, on all pages. A different
  transaction's items cannot become exported rows. The existing `gross_amount` is
  used, not the latest program reference or a newly computed rate entitlement.
  Legacy items without a matching roster assignment remain visible as retained
  billing history with a review reason; no roster membership is fabricated.
- School/external status presentation reuses `EvaluationWorkspace`, including
  current/stale school results and verified applicable external approval. These
  current facts do not replace an item's historical evaluation, rate or approval
  snapshots.
- Totals always describe the whole linked roster and whole selected bill, whichever
  row scope is exported: roster members; current Eligible / Pending review / Excluded
  counts; roster members already billed/reserved; included item count; prepared gross.
  Eligibility counts are independent of inclusion. Reservations include this and
  other batches, so reservation/inclusion counts overlap rather than being summed.
- Prepared total uses the existing `Money` integer-centavo helpers over the same
  stored current-batch item collection used for export, corresponding to the
  original gross total shown by `BillingBatch::totalAmount()`. It is never a sum of
  all roster entitlements and does not apply later adjustments to historical gross.
  A missing stored amount remains **Unconfirmed**, not a false zero. A genuinely
  empty bill totals PHP 0.00. Only currently included rows have individual amounts.
- An unlinked legacy bill is labelled **No recorded roster link — none inferred**;
  All roster members has no data rows, while Billing items only retains its items.
  A recorded roster with a conflicting program/period is rejected instead of
  widening the export to another scope.

All business-data operations are SELECT/read operations within a reporting
transaction, including evidence integrity checks. No provisioning, activation,
approval, submission, audit insertion or financial mutation action is invoked.
The private temporary workbook is generated before returning a download, deleted
after sending, and cleaned up if generation fails. Responses use private/no-store
and nosniff headers. Normal authentication/session middleware is unchanged.

### Affected files and commands

- `routes/web.php` — protected, noncached scholar-list route.
- `app/Http/Controllers/BillingBatchController.php` — scope validation, authoritative
  report projection/totals, existing status presentation, private XLSX response.
- `app/Services/Billing/BillingRoster.php` — expose the reservation already retrieved
  by readiness so inclusion does not need a second reservation lookup.
- `app/Services/Records/ScholarImportTemplate.php` — share existing OOXML/package,
  escaping and ZIP writing; add report worksheet and currency formatting.
- `resources/views/billing/show.blade.php` — scope selector and export button.
- This report — export behavior and manual verification instructions.

The export alone requires no migration, dependency install, seed or frontend asset
build. The later semester-exclusion feature requires the migration below.
If deploying with cached routes/views, the developer may run
`php artisan route:clear` and `php artisan view:clear` in the intended installation.
Neither command was executed here. Static diff/whitespace review found no whitespace
errors (`git diff --check`); syntax, compilation, functional and browser checks for
this addition are **NOT EXECUTED**.

### Developer manual verification — all NOT EXECUTED

Use only the isolated fictional data from this report. Do not change operational
Student IDs, amounts or financial history to prepare a check.

1. As R, open **Billing and Validation → Billing batches → Batch 1** before adding
   items. Select **All roster members → Export scholar list**. Expect three rows,
   current statuses/reasons, no included scholars, blank individual prepared amounts,
   total prepared PHP 0.00 and a DRAFT/UAT label. Eligible is distinct from included.
2. After adding Mira/Lio as described above, export both scopes. All roster members
   should contain three rows; Billing items only two. Both headers must report
   included count two and prepared total PHP 90,000.00; item amounts are PHP 45,000.00
   each. Neri remains excluded/not included with the existing Not enrolled reason.
   With current qualified sources, Eligible count is two, Excluded one and reserved
   count two; reservations include the two current-batch items.
3. Apply a search/table filter hiding one scholar, or use a page size smaller than
   the full roster in a larger isolated fixture. Export again. Expect full scoped
   results, not just displayed rows. No re-entry of student or period data is needed.
4. Open another isolated draft linked to the same roster while the original bill
   reserves those students. All roster members should identify their other-transaction
   inclusion, with blank current-batch amounts; Billing items only must omit them.
   Prepared total and included count must refer only to the opened draft.
5. Inspect header program/period/roster/billing reference, actual preparer, approved
   applied rate and Manila generation time against the opened bill. Verify an old
   draft/finalized bill retains its stored gross amounts even after a later reference
   amount or approved rate revision; current status changes must not rewrite the bill.
6. In an isolated fixture already containing a fictional Student ID such as
   `00001234`, inspect the exported cell in Excel: it must remain Text and exactly
   retain all zeros. If that fixture is unavailable, mark this case Not Tested rather
   than altering operational IDs. Literal formula-looking text must remain text.
7. Try no-item and unlinked legacy fixtures if available: expect labelled empty
   scope/unconfirmed legacy information, no invented roster and no false totals.
8. As an unauthenticated user, request the export URL: expect login handling. As a
   Student, directly request the same existing batch URL: expect authorization denial.
   A permitted role with an invalid `scope` must receive validation failure. Confirm
   exporting does not alter assignment statuses, bill lock/version/status, items,
   amounts, approvals or business audit history.
9. Open the downloaded XLSX in Excel/LibreOffice. Expect no repair prompt, clear
   headings, correct row count, numeric PHP amount cells and DRAFT/UAT/internal-report
   notices. This runtime workbook compatibility check has not been performed here.
10. Because package-writing code is shared, also use **Scholars → Bulk Upload →
    Download Template** in the demo environment. Confirm its three sheets, Text ID/
    Contact cells, active-course dropdown and unchanged upload instructions. This
    template regression check is also awaiting the developer.

The export does not resolve the production compliance or official submission
blockers listed above. **Export implementation completed; manual verification pending.**

## Additional requirement: Reason for Delisting integration

Status: **IMPLEMENTED — PENDING DEVELOPER MIGRATION AND MANUAL VERIFICATION**.
Static source/diff review only; no syntax/build commands, automated tests, browser
checks, migrations, seeds, registry lookups or database changes were executed for
this addition. The earlier compilation results do not verify this addition.

### Register missing reasons through the existing interface

No reasons are seeded or hardcoded into application views/JavaScript. The existing
registry already normalizes case/whitespace, validates uniqueness and enforces a
database unique key, including inactive entries. Its Add reason form defaults new
records to Active. Existing registry records are not overwritten or reactivated.

As **Coordinator or retained Test Administrator**, open **Information Registry →
Reason for Delisting**. For each reason below:

1. Set the search **Status** filter to **All statuses**. Search for the wording or
   a distinctive phrase and inspect existing variants, including punctuation/dashes.
2. If the reason already exists, leave it unchanged; do not recreate an inactive
   reason. Resolve availability with the institution separately if necessary.
3. If missing, click **Add reason**, paste the exact **Reason**, choose **Status:
   Active**, then **Save reason**. Repeat only for missing entries. A duplicate
   validation error means the existing entry must be reviewed instead of recreated.

| # | Developer-provided reason | Initial status if newly registered |
|---|---|---|
| 1 | Availing other scholarships | Active |
| 2 | Course not in the registry | Active |
| 3 | Dropped | Active |
| 4 | Exceeded HEI maximum residency | Active |
| 5 | Graduated | Active |
| 6 | Not enrolled – candidate for graduation/completed academic requirements | Active |
| 7 | Not enrolled for the current semester | Active |
| 8 | Not enrolled with LOA | Active |
| 9 | Submitted fake documents | Active |
| 10 | Transferred without notice | Active |

This is a registration checklist, not evidence that these records exist in the
database. Registrar can use available reasons but cannot create/edit the registry
under the unchanged Phase 1 registry permission policy.

### Temporary semester exclusion

In **Billing and Validation → Billing batches → open a draft → Roster Validation**,
**Registrar, Coordinator or retained Test Administrator** can use **Record billing
exclusion**. This new policy ability requires the existing draft/update state;
legacy Finance/Viewer and Student accounts cannot invoke the exclusion endpoint.

The shared compact dialog retrieves the current page's roster members and all
Active registry reasons. The selected membership supplies the student, exact
assignment, program and semester; those details are not re-encoded. Staff choose
**Record / replace temporary semester exclusion**, select an applicable reason,
optionally record **Remarks / school reference**, confirm the reviewed decision,
and click **Save semester decision**. No reason is selected automatically. The list
does not establish which reasons apply to a particular scholar or program.

Saving records a reason ID **and a copy of its exact current wording**, remarks,
responsible actor/time and monotonic decision revision on the existing roster
membership. Enrollment, grades, school decisions, award status and roster identity
are unchanged. Current validation/summary results refresh after redirect. The
recorded reason is shown in the existing Reason column and dialog preview.

To lift a recorded temporary hold, explicitly choose **Release recorded semester
exclusion**, enter the required release remarks/reference, confirm review and save.
The original wording/remarks/actor/time remain in the snapshot alongside release
details. Existing audit before/after snapshots preserve earlier replacements and
releases. Release never verifies enrollment, activates an award or makes someone
payable without the other existing eligibility requirements.

Server-side `BillingEligibility` rejects an active hold for that exact scholar,
assignment and academic period, including direct billing requests. Another roster
for the same award/semester cannot bypass it. A conflicting second decision is
rejected; the dialog identifies the original roster to manage. Invalid/inactive
reason IDs, wrong-roster membership IDs, stale revisions, missing confirmation and
unauthorized requests are rejected. Existing transaction/student locks serialize
the decision with billing preparation; failures do not retain partial decisions.

If the scholar is already included/reserved in any semester bill, recording or
releasing a hold is rejected. First use **Billing Items → Remove draft item** where
authorized and editable. Submitted/approved financial history requires the existing
authorized correction process; this action never removes or reprices it. Freshly
excluded scholars cannot become new billing items or contribute to the prepared
amount. Historical inclusion and current eligibility remain separately labelled.

### Separate scholarship closure / delisting review

The row's **Review scholarship status** link opens the existing assignment form,
only for **Coordinator or retained Test Administrator** with permission to update
the pending/active assignment. Registrar does not gain termination permissions.

The user must deliberately choose the existing **Ended / historical** or
**Relinquished** status and provide the existing closing semester and school
reference/remarks. The optional Active registry selector now submits the reason ID;
`SaveScholarshipAssignment` validates it on the server and copies its wording into
the existing `status_reason` with the remarks. Existing closure provenance/audit
and historical-assignment write restrictions remain. There is no new Terminated
status, automatic closure, reinstatement action or per-reason transition mapping.
Selecting a reason alone never closes a scholarship. Return to the bill and refresh
validation after using this existing separate workflow.

### Export and historical preservation

**All roster members → Export scholar list** includes the recorded reason and
remarks in its existing **Exclusion or review reason / remarks** column. It retains
current inclusion/eligibility distinctions, blank prepared amounts for unadded
excluded scholars, and the prepared gross total from actual current-batch items.
Released reasons are explicitly labelled historical, with release remarks.
Scholarship closure reasons/remarks come from the retained assignment history.
Billing items only continues to include only the current bill's stored items.

Registry edits or deactivation do not rewrite existing membership snapshots,
assignment `status_reason`, audit events or billing amount snapshots. New decisions
require an Active registry entry; an existing hold can still be released after its
reason becomes inactive. Long export remarks remain intact in the cell; row height
is capped at Excel's supported range, so the formula bar can be used to inspect a
long value. Draft/UAT/internal-report labels and official submission restrictions
remain in place. The fictional UAT classifier now also retains explicit exclusion
snapshots and rejects billing those members; it does not turn an exclusion into
an official Form 2/Form 3 decision or permit unknown enrollment.

### Necessary database change — prepared, NOT executed

`2026_11_20_000001_add_billing_exclusion_to_roster_memberships.php` adds one nullable
JSON `billing_exclusion` field to the **existing** `scholarship_batch_memberships`
table. A dedicated period-specific state is necessary: an assignment reason applies
to the award, while academic verification describes enrollment. Reusing either as
a temporary billing flag would conflate these separate records. No table, service
or new enrollment/approval workflow is introduced.

The original roster migration rejects every membership UPDATE. The new migration
replaces only that UPDATE trigger with an identity guard that still rejects changes
to ID, roster, scholar, assignment, program, semester, assigning actor/time and
creation time. Only the new decision metadata and normal updated timestamp may
change. Existing composite foreign keys and the deletion guard remain. MySQL/MariaDB
and the repository's SQLite trigger forms are retained. Rollback refuses to remove
any recorded decision, including a released decision.

The developer must review this migration and its trigger permissions against a
backup/isolated environment before applying it. Confirm the intended database and
the existing Phase 1 registry/roster migrations, then run **only when the developer
chooses to apply the pending schema**:

```powershell
php artisan migrate --path=database/migrations/2026_11_20_000001_add_billing_exclusion_to_roster_memberships.php
```

This command has **not** been executed. No seed, reset, rebuild, dependency install
or frontend asset build is required. Until both registry and exclusion storage are
available, recording is visibly disabled and a direct write fails with an explicit
prerequisite error; existing enrollment, exports and billing history remain readable.
If using cached routes/views, the developer may clear those caches after deployment.

### Files affected by this addition

- The new migration above — nullable metadata and preserved membership identity guard.
- `app/Models/ScholarshipBatchMembership.php` — JSON cast and exact award/semester hold lookup.
- `app/Actions/Records/ManageScholarshipRoster.php` — transactional record/release, current-reason validation and existing audit logging.
- `app/Policies/BillingBatchPolicy.php` — scoped `recordExclusion` permission.
- `app/Services/Billing/BillingEligibility.php` — authoritative exclusion guard.
- `app/Services/Billing/BillingRoster.php` — effective reasons/current exclusion labels, closure wording and guarded fictional classification.
- `app/Http/Controllers/BillingBatchController.php`, `routes/web.php` — protected POST `/billing-batches/{batch}/exclusion`, registry-backed dialog data and export wording.
- `resources/views/billing/partials/exclusion-dialog.blade.php` — shared record/release modal.
- `resources/views/billing/partials/roster-validation.blade.php`, `resources/views/billing/show.blade.php` — controls, existing closure navigation and error handling.
- `resources/views/billing/partials/submission.blade.php` — accurate UAT exclusion description; real submission remains blocked.
- `app/Http/Requests/ScholarshipAssignmentRequest.php`, `app/Actions/Records/SaveScholarshipAssignment.php`, `resources/views/assignments/form.blade.php` — server-validated registry choice for explicit existing closure.
- `app/Services/Records/ScholarImportTemplate.php` — safe maximum row height for longer retained remarks.
- This documentation — actual reason list, schema prerequisite and manual checks.

### Developer manual verification — all NOT EXECUTED

Use the separate fictional demo database and staff accounts above. The developer
must apply the schema first; these are instructions, not performed tests.

1. As Coordinator, use **Information Registry → Reason for Delisting** and the
   checklist above to register missing names. Confirm new reasons are Active and
   case/spacing duplicates are rejected, including existing Inactive entries.
2. As Registrar, open the demo **Batch 1 → Roster Validation → Record billing
   exclusion**. Choose Neri, **Record / replace temporary semester exclusion**, the
   available reason **Not enrolled for the current semester**, and remarks
   `UAT enrollment-source review; temporary semester exclusion only`. Confirm review
   and save. Expect Excluded with that wording, preserved verified Not enrolled
   finding, preserved pending assignment and unchanged roster membership/amounts.
3. Export **All roster members**. Expect the reason/remarks on Neri's row, no
   individual prepared amount for Neri and unchanged PHP90,000 total if Mira/Lio
   are the two stored items. Current included, excluded and review states remain
   distinct. Exporting must not save another decision or alter financial records.
4. In isolated data only, edit/deactivate that registry entry as Coordinator.
   Reopen the roster/export: expect the original recorded wording retained. The
   inactive entry cannot be selected for a new decision. Existing closure reasons
   must likewise retain their original text; do not alter operational registry data
   merely to exercise this case.
5. As Registrar, release Neri's hold with remarks `UAT hold released for review`.
   Expect retained historical wording and release details. Neri remains unpayable
   because enrollment is still Not enrolled and the award is pending; no automatic
   activation or billing item is created.
6. For an isolated enrolled/active/otherwise eligible sample **not yet in a bill**,
   record an applicable explicit hold. Expect it unavailable for selection, with
   direct add requests rejected and prepared total unaffected. Release the hold:
   remaining existing eligibility checks still apply; inclusion is a separate action.
7. Attempt a decision for a scholar already included/reserved, a membership from
   another roster, an inactive/invalid reason ID, stale revision, or missing review
   confirmation. Expect rejection without partial metadata/audit changes. A second
   roster for the same award/period must not bypass an existing hold. Submitted
   bills cannot use this action to rewrite existing financial history.
8. As Coordinator, for a separate fictional pending/active award, use **Review
   scholarship status**. Explicitly choose the appropriate existing closure status,
   closing semester, a registered reason and school reference; **Save assignment**.
   Expect only that explicit action to close it, retaining the reason/reference and
   audit history. Registrar cannot invoke this update; selecting a reason alone
   must not change status. Closed assignment history remains read-only.
9. Direct POSTs from Student, Viewer and legacy Finance accounts must be denied;
   Registrar/Coordinator cannot record against an unauthorized or submitted batch.
   Before schema deployment, verify the clear prerequisite message and disabled UI.
10. On the isolated MySQL/MariaDB database, the developer should independently check
    migration/trigger behavior: metadata updates work, changing original membership
    identity or deleting membership remains rejected, and rollback with recorded
    decisions is refused. No trigger behavior is claimed tested by this report.

**Review result:** source implementation and documentation completed; whitespace
review (`git diff --check`) found no errors. Runtime schema, permissions, financial
regression and manual UI/export behavior remain **UNVERIFIED**. No production
compliance or official submission blocker was bypassed.
