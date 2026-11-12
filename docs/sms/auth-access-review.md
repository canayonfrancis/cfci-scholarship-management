# Authentication and two-role authorization review

Review date: 2026-11-12, Asia/Manila. Scope: the existing application, its configured database and isolated test databases. `implementation-plan.md` and `progress.md` were reviewed before source changes.

**Result: safe permission and provisioning fixes are implemented and tested; complete removal of legacy access awaits an account decision.** The only configured account has `administrator`. Automatically removing that access would conflict with the user's explicit lockout/preservation boundary. No existing account or membership was changed. No new authentication system, migration, table, package or generic permission layer was added.

## Current authentication

- Laravel's existing Breeze controllers use the `web` session guard and Eloquent `User` provider. Login validates email/password, limits repeated failures, regenerates the session and follows the intended URL, defaulting to `/dashboard`. Both operational roles use this same flow.
- `User` hashes passwords through its existing cast and hides password/remember-token values. Its assignable attributes remain name, email and password. Login/profile input does not assign roles or change another account. Roles reside in `user_roles`, with a user FK and unique `(user_id, role)`; there is no role column or activation flag on `users`.
- Password reset, confirmation and update reuse the existing implementation. Logout calls the existing guard logout, invalidates the session and regenerates the CSRF token. The existing linked-account deletion safeguard remains in place.
- Public GET/POST `/register` routes do not exist; both return 404. There is no web account/role management interface. The trusted `sms:grant-role` console command now accepts only `coordinator` and `registrar`, preserves credentials and audits idempotent grants. The factory does not automatically give an account a role. Local/testing seeders now create only operational memberships and leave pre-existing users/roles untouched.
- Business routes require authentication. Controllers, Form Requests, policies and the existing action/service gates enforce authorization, including direct mutations and private downloads. There is no global Administrator `Gate::before` bypass. Navigation uses these same policies. The profile dropdown now spells out the two operational names and marks known legacy memberships for review.
- The dashboard's `verified` middleware does not enforce email verification because `User` does not implement `MustVerifyEmail`; this previously documented policy decision remains pending. Enabling it without reviewed mail/account handling could disrupt access, so this audit does not change it.

## Current role definitions and account inventory

The operational provisioning allowlist is `User::OPERATIONAL_ROLES = ['coordinator', 'registrar']`.

**Compatibility limitation:** the existing `STAFF_ROLES` list and legacy policy branches still recognize `administrator`, `finance` and `viewer`. These are reviewed transition exceptions, not new role choices. They must be removed after approved account mapping; this implementation does not claim strict two-role-only authorization yet. A raw/trusted database write can still introduce role text because the existing schema has no role CHECK; no public endpoint can do so. Unrecognized roles such as `admin`, `student`, `applicant`, `super_admin`, uppercase `COORDINATOR` and arbitrary values receive no business privileges.

Read-only inspection and the new `php artisan sms:audit-access` command checked the connection actually loaded by Laravel: MySQL driver, database **`cfci_scholarship`**. This inventory is not inferred from seeders, old progress entries or separately configured demo databases.

| Measure | Current configured database |
| --- | --- |
| Total user accounts | **1** |
| Administratively active accounts | **Unknown / not modeled**; no activation/deactivation field or active-account rule exists |
| Coordinator accounts | **0** |
| Registrar accounts | **0** |
| Legacy Administrator accounts | **1** |
| Finance / Viewer accounts | **0 / 0** |
| Other role memberships | **0** |
| Accounts without roles / with multiple roles | **0 / 0** |
| Exactly one Coordinator and one Registrar | **No** |

The only account is ID **1**, **Test Admin**, `test@example.com`, with `administrator`; its email is marked verified and it has no Student login link. Its name/email and seeder match indicate a local test-account origin, not approval to repurpose it. No password/hash/token is included in the report. Existing authentication has no disabled-account filter; this does not establish actual recent login activity or an administrative active count. No real account login, password reset, mail delivery or credential modification was attempted.

The audit command prints aggregate counts, non-operational/legacy/unrecognized role counts, roleless/multiple-membership counts and the exact-one-each result. It performs no writes and prints no account identities or credentials. Counts by role are membership counts and may overlap; total accounts are counted independently.

## Permission comparison after safe fixes

These columns refer to an account holding only the named operational role. Legacy compatibility remains as stated above. School qualification, verified external approval and payment posting remain distinct.

| Responsibility | Coordinator | Registrar Staff | Backend enforcement / result |
| --- | --- | --- | --- |
| Dashboard and scholarship monitoring | Yes | Yes, academic/scholarship scope | Existing dashboard scope; Registrar receives no financial metrics/links |
| View/manage scholar profiles | Yes | Yes | `StudentPolicy`, scholar controller/Form Request; protected identity rules retained |
| View programs, periods, courses and assignments | Yes | Yes | Existing record policies |
| Manage scholarship programs, periods and academic-course references | **Yes, corrected** | No | `RecordPolicy` inherited by the existing reference policies |
| Create/update pending or active assignments | **Yes, corrected** | No | `ScholarshipAssignmentPolicy`; closed history and activation gates retained |
| View academics and relevant validations | Yes | Yes | `AcademicRecordPolicy`, `ScholarshipApplicationPolicy`; private ownership/checksum guards retained |
| Verify enrollment/standing, record grades, upload/correct grade certificates | Read only | **Yes** | Existing Registrar-only academic write/correction policy |
| Perform evaluations and record verified external decisions | Yes | No | Existing Coordinator-only evaluation/external requests and action gates |
| Configure rates/budgets; prepare, edit and submit semester billing | **Yes, corrected** | No | `BillingBatchPolicy`, controllers/requests and billing actions |
| Approve billing or adjust approved allocations | **Yes, corrected** | No | Existing policy and locked action; self-approval remains denied |
| Record funds/payments, allocate cash and prepare liquidation | **Yes, corrected** | No | `FinancialRecordPolicy` and existing gates/actions |
| View/upload/review private financial evidence, verify/post, authorize documented deductions, settle and review liquidation | **Yes, corrected**, subject to independent review | No | Existing financial gates, owner/checksum checks and different-actor requirements |
| Dashboard reports, financial allocation report/CSV and liquidation printout | Yes | Dashboard/nonfinancial scholarship views only | Existing routes and financial view gates; removed general Reports pages remain absent |
| Public registration or browser role assignment | No | No | No routes/editor; validated profile/login payloads do not assign roles |

Financial authorization does not override policy, evidence, actual-funds, amount, immutable-history or different-actor requirements. The person who prepared/submitted a bill cannot approve it. The recorder/uploader/preparer cannot perform the corresponding independent receipt/payment/evidence/liquidation review. Tests exercise this using different Coordinator identities, not a third role.

## Confirmed problems and corrections

1. **Five old staff roles and provisioning paths contradicted the new role requirement.** Added the separate two-value operational allowlist; restricted trusted grants and sample/default seeders. Fresh normal sample datasets now use two accounts; isolated synthetic financial samples use a second Coordinator for independent review. Existing default-account collisions, reserved sample identities and completed datasets retain their original accounts/credentials/roles. No seeder was run against the configured application database.
2. **Coordinator catalog/assignment writes were denied.** Added Coordinator to the existing reference/assignment writer policies. The Registrar's scholar/academic responsibilities and the activation/history safeguards remain intact.
3. **Coordinator financial writes were denied by Finance/Administrator-only checks.** Added Coordinator to the existing preparation, configuration, verification and financial-evidence policies. Existing financial actions remain unchanged except explanatory role wording. The Registrar still cannot mutate approvals, billing, financial transactions, deductions or liquidation.
4. **Workflow wording instructed users to find Finance/Administrator roles.** Updated active workflow descriptions and profile role labels to the intended operational responsibilities. Historical audit rows/documents and earlier implementation records were not rewritten. Legitimate Student domain models, `applicant_type` eligibility facts and academic course names such as Business Administration are data, not authentication roles.
5. **Historical account descriptions could be mistaken for the current inventory.** Added a repeatable, aggregate-only audit command and recorded the actual configured database/counts. Active status is explicitly unknown instead of inferred from verified email or session rows.

## Files changed by this audit

| Area | Exact paths |
| --- | --- |
| Role definitions and trusted inventory/provisioning | `app/Models/User.php`; new `app/Console/Commands/AuditStaffAccess.php`; `routes/console.php` |
| Backend permissions | `app/Policies/RecordPolicy.php`; `app/Policies/AcademicProgramPolicy.php`; `app/Policies/ScholarshipAssignmentPolicy.php`; `app/Policies/BillingBatchPolicy.php`; `app/Policies/FinancialRecordPolicy.php` |
| Provisioning fixtures | `database/seeders/DatabaseSeeder.php`; `database/seeders/DevelopmentSampleSeeder.php`; `database/seeders/Support/SampleData.php`; `database/seeders/SyntheticDemoSeeder.php` |
| Workflow messages only | `app/Actions/Billing/ManageLiquidation.php`; `app/Actions/Billing/RecordFinancialActivity.php`; `app/Services/Billing/BillingEligibility.php`; `app/Services/Billing/FinancialEvidence.php`; `app/Services/Billing/LiquidationSummary.php`; `app/Http/Controllers/BillingBatchController.php`; `app/Http/Controllers/ScholarshipEvaluationController.php` |
| Role labels/workflow copy | `resources/views/layouts/navigation.blade.php`; `resources/views/dashboard.blade.php`; `resources/views/academic-programs/index.blade.php`; `resources/views/assignments/form.blade.php`; `resources/views/billing/index.blade.php`; `resources/views/billing/show.blade.php`; `resources/views/billing/report.blade.php`; `resources/views/evaluations/create.blade.php`; `resources/views/financial/index.blade.php`; `resources/views/financial/batch.blade.php`; `resources/views/programs/index.blade.php` |
| New regression tests | `tests/Feature/Auth/OperationalRoleSecurityTest.php`; `tests/Feature/Auth/StaffAccessAuditTest.php` |
| Existing regression tests updated for intended permissions | `tests/Feature/Records/ScholarRecordsTest.php`; `tests/Feature/Billing/BillingBatchTest.php`; `tests/Feature/Billing/FinancialRecordingTest.php`; `tests/Feature/Billing/ScholarshipFundsTest.php`; `tests/Feature/Eligibility/DirectValidationScopeTest.php`; `tests/Feature/Integration/ScholarshipLifecycleTest.php`; `tests/Feature/Seeders/DevelopmentSampleSeederTest.php` |
| Documentation | This report; `docs/sms/progress.md`; `docs/sms/sample-data.md` |

Pre-existing uncommitted scholar-filter, CSS, JavaScript, scholar-information documentation, navigation and test edits were preserved. This audit adds only its role-label change to the already edited navigation file, its permission/provisioning assertions to the existing records test and its own progress entry. No changes were made to the pre-existing `ScholarController`, scholar directory, CSS or JavaScript work. Temporary verification/fingerprint scripts are under ignored `storage/framework/testing`, not application source.

## Verification

| Check | Result / limits |
| --- | --- |
| Baseline full `php artisan test --compact` before changes | **310 passed, 3 skipped, 3,381 assertions** on SQLite |
| Final full `php artisan test --compact` | **340 passed, 3 skipped, 3,640 assertions** on SQLite; skips are the existing MySQL-only concurrency cases |
| Isolated configured MariaDB, auth/operational/lifecycle subset | **32 passed, 502 assertions** using `--filter=OperationalRoleSecurityTest\|StaffAccessAuditTest\|ScholarshipLifecycleTest`; fresh randomly named `_test` database created and removed; main database untouched |
| New security coverage | 30 cases: role-specific login, authorized intended redirect, regenerated session, logout/session clearing, authorized pages/navigation, **38 direct Registrar mutation endpoints denied with 403**, denied private financial downloads, role injection, unrecognized/roleless business-access denial, exact account counts, aggregate-only read-only reporting, restricted/audited/idempotent role grants, seed preservation |
| Existing scholarship lifecycle | Full HTTP lifecycle now uses only Coordinator/Registrar memberships, with a second Coordinator reviewing; actual-funds/payment/deduction/liquidation and dashboard/report reconciliation pass. Downstream success uses the existing explicitly synthetic academic prerequisite; production grading stays pending and blocks activation |
| Blade / formatting / whitespace | `php artisan view:cache`, scoped `php vendor/bin/pint --test ...`, and `git diff --check` passed |
| Read-only configured database checks | Inventory repeated; stored fingerprints of users (including credential columns, never printed), memberships and 13 retained school/financial/audit tables plus `.env` matched across final verification |

Intermediate test failures were stale expectations for the former Coordinator restrictions/Administrator UI wording and strict attribute-array ordering in new preservation tests; expectations/snapshots were corrected. The final runs above are clean. No real-account password test or real mail/browser session is claimed. No schema or transaction calculation changed, so the full MySQL concurrency suite was not rerun in this audit; its prior results remain historical in the progress tracker.

## Decisions required before complete cutover

1. **Review ID 1, Test Admin (`test@example.com`).** Proposed mapping, if this is the intended staff identity: grant `coordinator`, verify access, then audit removal of `administrator`, retaining ID, credentials and all relationships. The account's purpose must be confirmed before applying this change. No mapping has been applied.
2. **Provision the approved Registrar identity.** No Registrar exists in the configured database. Do not mistake successful isolated test logins for the existence of a real Registrar account. The present task did not authorize silently creating identities or selecting real staff credentials.
3. **Resolve the account-count/independent-review conflict.** One Coordinator and one Registrar cannot complete existing independently reviewed financial posting while keeping the Registrar out of financial mutations. Keep the safeguard and authorize a second Coordinator identity, or explicitly approve a revised business requirement. This is about identity count, not introducing a third role; no automatic exception was introduced.
4. **Complete the legacy policy cutover after reviewed provisioning.** Restrict `STAFF_ROLES` and all policy role checks to the two operational identifiers, remove `administrator`/`finance`/`viewer` compatibility, and rerun the positive/negative matrices. Identify any separately configured old demo accounts before using the strict code with them. Do not map Viewer/Finance to privileged roles automatically or delete historical memberships/account relationships to make counts appear correct.
5. **Previously unresolved account policy:** decide whether email verification and administrative deactivation are required, including mail/retention handling. This audit reports their absence; it does not invent an account lifecycle or alter existing users to implement one.

The user's instruction to report changes that could lock out existing users is the reason the account mapping and legacy-access removal remain unapplied. All independent safe fixes and verification above are complete and reviewable.
