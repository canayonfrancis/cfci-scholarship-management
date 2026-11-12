# Authentication and authorization review

Updated: 2026-11-12, Asia/Manila. The user's latest clarification supersedes the initial audit's proposed Administrator conversion and Registrar financial prohibition. Historical verification remains in Tasks 14 and 15 of [progress.md](progress.md).

## Confirmed responsibilities

- **School Scholarship Coordinator (`coordinator`)**: scholarship configuration/evaluation, recording external decisions, and approval/verification of recorded billing and financial activity.
- **Registrar Staff (`registrar`)**: student/academic verification plus recording billing, receipts, payments made outside this system, evidence and liquidation submissions. Recording does not grant approval authority.
- **Existing Test Administrator (`administrator`)**: explicitly retained by the user as a full-feature test-access exception. It can exercise academic, evaluation, billing and financial responsibilities. Eligibility, evidence, immutable history, amount limits and different-person review rules still apply. There is no blanket `Gate::before` bypass.

One Registrar recording and one Coordinator reviewing can complete the workflow. A second Coordinator and conversion of the existing Administrator are no longer required. Coordinator-created records still require another authorized reviewer where the existing workflow calls for independent review.

**This is a recording system.** Receipts and payments describe events outside the application. Saving, submitting or verifying records does not execute a bank transfer, send funds, collect tuition or make an online payment. Existing financial calculations and evidence requirements remain intact; an expected entitlement is not represented as an actual payment.

## Existing authentication and role storage

The existing Laravel 12/Breeze implementation is reused: `web` session guard, Eloquent User provider, hashed password cast, email/password validation, login rate limiting, session regeneration, intended redirects (dashboard by default), password reset/confirmation/update and logout session/CSRF rotation. Login/profile payloads cannot assign roles or update another account. Public GET/POST `/register` remain 404; no browser account/role editor was introduced.

`users` stores login identities; `user_roles` stores memberships with a user FK and unique `(user_id, role)`. No authentication table, package, migration or replacement login system was added. `OPERATIONAL_ROLES` remains exactly Coordinator/Registrar; the trusted grant command and new default/sample fixtures provision only these roles. `TECHNICAL_ROLES` identifies the approved existing Administrator exception without creating or reclassifying an account.

Business controllers, Form Requests, policies and action gates enforce permissions, including direct requests and private downloads. Navigation uses those policies. The existing linked-account deletion safeguard is retained.

**Compatibility limit:** the older `finance` and `viewer` policy branches remain for pre-existing datasets. Neither is present in the configured database or offered by trusted new provisioning. Removing their remaining code compatibility from separately configured legacy demos is separate from the now-approved Administrator exception; existing accounts were not silently disabled, mapped or deleted. Unrecognized `admin`, `student`, `applicant`, `super_admin`, uppercase role names and roleless accounts receive no business privileges.

`User` still does not implement `MustVerifyEmail`, so the existing dashboard `verified` middleware does not enforce email verification. Activation/deactivation is not modeled. These previously unresolved account-lifecycle decisions were not changed by the permission clarification.

## Actual configured database accounts

Read-only Laravel inventory targets MySQL-driver database **`cfci_scholarship`** (MariaDB 10.11.16), rather than assuming accounts from seeders, earlier progress entries or other demo databases.

| Measure | Current count |
| --- | --- |
| Total accounts | **1** |
| Active accounts | **Unknown / not modeled**: users has no enabled/disabled state |
| Coordinator | **0** |
| Registrar | **0** |
| Approved test Administrator | **1** |
| Finance / Viewer / other memberships | **0 / 0 / 0** |
| Accounts without roles / multiple memberships | **0 / 0** |
| Exactly one Coordinator and one Registrar exists | **No** |

The retained account is ID 1, Test Admin (`test@example.com`), assigned only `administrator`. No credential/hash/token is included here. Its identity, credentials and memberships were not changed. Successful test logins use isolated fixtures; no real-account password reset, login attempt or mail delivery is claimed.

`php artisan sms:audit-access` reports aggregate account/membership counts, approved technical roles, remaining legacy roles and unrecognized roles without identities or credentials. Counts by role can overlap for multi-role accounts; total users are counted separately. The existing exact-one-each flag still denotes the strict two-account inventory, so an extra technical account is visible in totals rather than hidden.

## Current permission matrix

These columns assume only the named membership. Closed/verified/posted history and other record-state rules remain enforced for every column.

| Responsibility | Coordinator | Registrar | Test Administrator |
| --- | --- | --- | --- |
| Dashboard, student/scholar records, relevant scholarship information | Yes | Yes | Yes |
| Program/period/course configuration and assignment management | Yes | Read only | Yes |
| Academic record/grade/certificate verification and corrections | Read only | Yes | Yes |
| School eligibility evaluations and verified external decision recording | Yes | Read only | Yes |
| Billing drafts, items, submission and financial allocation reports | Yes | Yes | Yes |
| Rates/budgets, approved-amount adjustments | Yes | No | Yes |
| Billing approval | Yes, different actor | No | Yes, different actor |
| Receipt/payment drafts, recorded receipt allocations, liquidation preparation/submission | Yes | Yes | Yes |
| Read/upload private financial evidence | Yes | Yes; authorizing deduction proof remains restricted | Yes |
| Verify evidence and recorded receipts/payments; authorize deductions/settlement; review liquidation | Yes, existing actor checks | No | Yes, existing actor checks |
| Execute/send payments | No implementation | No implementation | No implementation |
| Public registration or browser role assignment | Unavailable | Unavailable | Unavailable |

Registrar recording permission does not include configuring scholarship policy, approving external awards, changing approved amounts, authorizing deductions, verifying/posting payment records, accepting evidence or accepting liquidation. Backend regression tests deny **26** configuration/approval mutation routes, including forged Coordinator role payloads.

## Changes made after clarification

1. Added the missing Administrator permissions in the academic, evaluation, billing preparation and financial recording policies, preserving existing account rows. Updated its profile label to Test Administrator and the audit command classification to approved technical access.
2. Added Registrar financial/billing read, recording, submission and evidence access through the existing policies. Coordinator/Administrator verification gates and different-actor checks are unchanged. Dashboard/navigation now expose the authorized financial records to Registrar.
3. Updated financial pages and payment-verification confirmation to state that they record events outside the system. No payment gateway, bank integration, new money movement or accounting calculation was added.
4. Updated fresh synthetic fixtures to one Registrar recorder and one Coordinator reviewer. Completed datasets and existing sample accounts are retained without rerunning their seeders against the application database.
5. Updated direct-request role matrices, login/navigation coverage, credential-preserving audit tests and complete HTTP workflows. The full lifecycle runs as Registrar/Coordinator, Administrator/Coordinator and Registrar/Administrator, exercising both recording and approval responsibilities. Outgoing Laravel HTTP requests are prevented and none are sent.

## Files modified after clarification

| Area | Paths |
| --- | --- |
| Roles, inventory and backend permission checks | `app/Models/User.php`; `app/Console/Commands/AuditStaffAccess.php`; `app/Policies/AcademicProgramPolicy.php`; `app/Policies/AcademicRecordPolicy.php`; `app/Policies/ScholarshipApplicationPolicy.php`; `app/Policies/BillingBatchPolicy.php`; `app/Policies/FinancialRecordPolicy.php` |
| Recording confirmation and fixtures | `app/Http/Controllers/FinancialRecordController.php`; `database/seeders/Support/SampleData.php`; `database/seeders/SyntheticDemoSeeder.php` |
| Existing UI copy and role label | `resources/views/layouts/navigation.blade.php`; `resources/views/billing/index.blade.php`; `resources/views/billing/show.blade.php`; `resources/views/financial/index.blade.php`; `resources/views/financial/batch.blade.php`; `resources/views/financial/payout.blade.php` |
| Auth and financial regression tests | `tests/Feature/Auth/OperationalRoleSecurityTest.php`; `tests/Feature/Auth/StaffAccessAuditTest.php`; `tests/Feature/Billing/BillingBatchTest.php`; `tests/Feature/Billing/FinancialRecordingTest.php`; `tests/Feature/Billing/ScholarshipFundsTest.php` |
| Other regression tests | `tests/Feature/DashboardAnalyticsTest.php`; `tests/Feature/Eligibility/ScholarshipEvaluationTest.php`; `tests/Feature/Integration/ScholarshipLifecycleTest.php`; `tests/Feature/Records/AcademicVerificationTest.php`; `tests/Feature/Seeders/DevelopmentSampleSeederTest.php` |
| Documentation | This report; `docs/sms/progress.md`; `docs/sms/implementation-plan.md`; `docs/sms/sample-data.md` |

## Verification

| Check | Result |
| --- | --- |
| Full SQLite suite | **343 passed, 3 skipped, 4,052 assertions** (`php artisan test --compact`); skips are the existing MySQL-only concurrency cases |
| Full workflow | One Registrar + one Coordinator succeed; approved Test Administrator succeeds in either responsibility. Production grading remains unresolved and correctly blocks activation; successful downstream cases use an explicitly synthetic test prerequisite |
| Access denial | Registrar approval/configuration requests return 403; role injection and unknown-role privilege attempts remain denied; legacy-compatible fixtures remain covered |
| No payment execution | Each complete workflow prevents outgoing Laravel HTTP requests and asserts none were sent; receipt/payment rows are recorded and reconciled normally |
| Style | Scoped Laravel Pint passed |
| Isolated MariaDB access/lifecycle subset | **35 passed, 913 assertions**; disposable `_test` database removed; configured application database untouched |
| Templates / whitespace | `php artisan view:cache` and `git diff --check` passed |
| Preserved accounts and records | All **16 stored database/environment fingerprints** matched, including credential and role rows without printing values; read-only policy probes confirm the real Test Admin's academic, evaluation, billing and financial feature permissions |

These final results are also recorded in Task 15 of the progress tracker. Initial template replacement mistakes were corrected and the full suite rerun cleanly. Verification scripts/output stay in ignored `storage/framework/testing`; no application database reset or migration was run.

## Remaining decisions and limits

- The configured application still needs approved Coordinator and Registrar identities if those staff accounts are to be used. No usernames, real staff identities or credentials were invented or existing accounts converted. The existing test Administrator remains available.
- Decide separately whether to remove dormant Finance/Viewer compatibility from older demo configurations. No new grants for these roles are allowed; the configured application has no such accounts.
- Email-verification/deactivation policy, production provisioning and the previously documented academic/provider/deployment decisions remain separate. No second Coordinator or Administrator conversion approval is required under the user's revised recording/review workflow.
