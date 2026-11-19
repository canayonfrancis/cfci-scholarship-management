# Defense readiness and end-to-end workflow

Status: **IMPLEMENTED — PENDING MANUAL VERIFICATION**

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
- The program code is exactly `UAT-COSCHO`, not the actual `COSCHO` program.
- Evaluation, activation and billing concern a student number starting `UAT-DEF-`
  whose email ends in `@example.invalid`.

It reuses the existing certification/evidence types and evaluation outcomes:
reviewed certification finding Yes/No/Unverified, a `UAT-` source reference,
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
every enrolled sample appears exactly once in the bill with the same assignment;
no not-enrolled member is billed. The normal eligibility checks separately require
active awards, current qualified decisions, external approvals and authoritative
amounts. Unknown enrollment, missing eligible members, extra or duplicate items,
wrong scopes and invalid student identities block the sample submission.

The fictional classification uses `UAT_ENROLLED` / `UAT_NOT_ENROLLED`, **not**
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

## Actual review and check results

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
There is no migration required by this change in the operational installation.

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
   **Program code** = `UAT-COSCHO`; **Program name** =
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
   UAT-COSCHO; **Academic year / semester** = `2026-2027 / 2`;
   **Batch number** = `1.0`. Review generated name `UAT-COSCHO-1.0-2-2026-2027`;
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
   ordinary billing; no permanent delisting or scholarship termination. Do not use
   Reason for Delisting to represent this temporary exclusion.

### 6. School certification, external approval and activation — A

Repeat the following for **Mira and Lio only**. Use the existing billing row
**Start evaluation** link (or **Billing and Validation → Evaluations → Start
assessment**, choose UAT-COSCHO → **Find assigned scholars**). Confirm the intended
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
3. **School compliance**: **Fictional institutional certification confirms
   demonstration compliance** = **Yes / complies**; its **source reference** =
   `UAT-CERT-001` for Mira or `UAT-CERT-002` for Lio.
   **Evaluator remarks / supporting reference** =
   `Reviewed fictional certification and current enrollment for UAT only`.
   Click **Evaluate and record school decision**. Expected: current Eligible
   (`passed` / `qualified`) if all sources are intact; no grade/GWA or TDP/TES
   checklist is required. Missing finding/proof remains Needs review.
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
2. **B:** **Billing batches**, filter UAT-COSCHO and this period → **Filter**.
   Expand **Configure scholarship rates and budgets → Manage semester rate and budget
   history** for that scope. Under **Configure available scholarship budget** enter
   **Approved budget (PHP)** = `90000.00`;
   **Budget approval reference / reason** = `UAT-BUDGET-01 — fictional planning limit`;
   click **Record budget revision**. Expected: approved planning budget, zero actual cash.
3. **R:** Open **Batch 1 → Submission**. Read sample-classification notice.
   **Submission reference** = `UAT-SUBMIT-01` → **Validate and submit UAT sample**.
   Expected: both items finalized, retained classification includes all three source
   revisions, header Submitted/Awaiting Coordinator approval. Any unknown enrollment,
   omitted enrolled sample, not-enrolled item, stale validation or changed approval
   blocks the whole transaction. This generates no official Form 2/Form 3 document.
4. **B:** Reload **Batch 1 → Submission**. **Coordinator approval reference** =
   `UAT-ALLOC-APPROVAL-01` → **Review eligibility, check budget and approve**.
   Expected: approved allocation PHP 90,000.00, remaining planning budget zero;
   no received funds or payments. R (submitter) and A (preparer) cannot approve it.

### 8. Fictional receipt, funding and optional payroll

1. **R:** **Liquidation and Payment → Payments and liquidation → Record an actual
   school receipt**. In this isolated UAT database only, select UAT-COSCHO / period2;
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
   Funding, payments & liquidation**. Under **Record allocation of verified school
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

Use **Reports**, select **Scholarship program: UAT-COSCHO**, **Academic year:
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
- Actual `COSCHO` remains unsupported for a positive compliance decision, even inside
  this demo database. The fictional program cannot qualify a non-UAT student identity.
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
   enrolled sample members; it is not a production partial-submission specification.
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
