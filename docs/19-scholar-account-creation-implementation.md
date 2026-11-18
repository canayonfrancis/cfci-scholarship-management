# Scholar account creation and secure delivery readiness

> **Latest approved change (2026-11-19):** The user now permits pending account creation without email. Creation no longer requires mail readiness and sends no notification/token. Both password-link buttons are removed. Secure setup remains required before Student login; credential-delivery safeguards remain. See [current behavior and tests](21-student-account-creation-without-email.md). Earlier results below are historical and do not override this change.

> **Subsequent SMTP check (2026-11-19):** Gmail credentials are now configured locally. Verification is blocked by PHP rejecting the Avast-issued SMTP certificate; authentication and actual receipt have not been verified. See [current SMTP results and manual procedure](20-student-smtp-verification.md). The results below describe the earlier creation implementation stage.

**Date:** 2026-11-18, Asia/Manila  
**Final verdict: BLOCKED for live new-account creation and real credential delivery.**

The creation implementation and applicable automated checks pass. The user requested that mail credentials remain blank for later configuration. No real SMTP transmission, inbox receipt or delivery approval is claimed. Creation and credential-delivery controls remain blocked in the configured application. The lifecycle migration was already applied and was verified.

## 1. Approval and scope

Reviewed the approved [design](16-student-account-management-design.md), [shared service](16-student-account-provisioning-service.md), [interface](17-student-account-management-interface.md), [lifecycle](17-student-account-lifecycle.md) and [previous QA findings](17-student-account-management-verification.md).

Existing approval permits Registrar Staff plus the retained Test Administrator, explicit identity/email review, privileged-account protection, two Student lifecycle fields, separate scholarship status and safe confirmation/audit. New creation must wait for approved, tested real delivery. The user requested creation implementation, delivery testing and the pending migration, then clarified: leave credentials blank; they will add them later. Missing configuration is not treated as delivery approval.

The request removing web existing-account linking remains in effect. There is no restored `/student-accounts/{student}/provision` endpoint, User picker, password picker or public registration. The trusted console linking command and SR-01 behavior are retained. No Student Profile editing or new scholarship workflow is included.

## 2. Implementation and source evidence

### Shared create-only action

[`LinkStudentAccount::createForActor()`](../app/Actions/Accounts/LinkStudentAccount.php) implements the existing shared creation entry point, returning [`StudentAccountProvisioningResult`](../app/Actions/Accounts/StudentAccountProvisioningResult.php). No redundant provisioning service is added.

- Resolve an existing Student and fresh authorized Registrar/Administrator actor.
- Require verified delivery, a reviewed valid email, identity attestation and bounded management reason.
- Lock the email's User, selected Student and relevant role memberships. Recheck permission and delivery inside the transaction.
- Require the email to match the locked official contact. Reject shared contacts, occupied login emails and conflicting associations.
- Create a new User with a hashed, undisclosed random 64-character secret, `student_setup_required=true` and null disabled timestamp. This is not a temporary/default password and is never returned or delivered.
- Assign only canonical lowercase `student`, save the association and allowlisted `student_account_created` audit in the same retriable transaction.
- Verify email, association, sole canonical role, hash and pending flags, including denial of profile access while pending, before success.
- An exact valid repeat returns unchanged without another User, membership, creation audit or notification. Enabled, pending and inactive states are preserved. An existing unassociated or privileged User is never claimed.

Existing email/link/membership uniqueness constraints remain. No global role rename or business-data migration is added.

### HTTP and UI

Navigation: **Student accounts ? search Student ? Manage login**.

[`StudentAccountController`](../app/Http/Controllers/StudentAccountController.php), [`CreateStudentAccountRequest`](../app/Http/Requests/CreateStudentAccountRequest.php), [`ManageStudentAccountRequest`](../app/Http/Requests/ManageStudentAccountRequest.php), [`routes/web.php`](../routes/web.php) and the [detail view](../resources/views/student-accounts/show.blade.php) enforce:

| Action | Server-side requirements |
| --- | --- |
| GET directory/detail | Authenticated Registrar or retained Test Administrator; private/no-store response. |
| `POST /student-accounts/{student}/create` | Same permission, CSRF, operator-password confirmation within 900 seconds, existing actor/target rate limits, email/reason/identity validation, shared creation postconditions and verified delivery. |
| Existing PATCH deactivate/reactivate and POST setup-link/password-reset | Same management boundary and existing eligible Student-only target/lifecycle safeguards. |

The creation form shows the official contact email read-only, a reason and unchecked identity attestation. Invalid contacts link to existing Scholar correction. Unverified delivery disables creation/instructions with an explanation. The submit control prevents repeated browser submission; database protection does not depend on it. Actor/account/role IDs, passwords, tokens and mode overrides are prohibited request fields.

After creation commits, the controller invokes existing [`StudentCredentials::issue()`](../app/Actions/Accounts/StudentCredentials.php). The broker stores a token hash; notification occurs after its transaction commits. The existing reset form, password policy, configured expiry (60 minutes), one-use consumption and explicit login are reused. Transport failure reports pending setup accurately. Exact creation repeats do not resend tokens or create accounts.

### Dedicated verified credential delivery

[`config/mail.php`](../config/mail.php) adds `student_accounts`, a dedicated SMTP mailer with required STARTTLS or implicit TLS. Host, credentials and sender remain blank in local `.env` and its example. Unrelated default log mail configuration is retained; credential mail cannot use it.

[`StudentCredentialDelivery`](../app/Services/Accounts/StudentCredentialDelivery.php) requires valid SMTP/TLS settings, nonempty credentials, valid sender and trusted `APP_URL`. HTTPS is required except loopback in local/testing. Configuration alone is insufficient: readiness requires a cached receipt proof bound by HMAC to the exact mail configuration and application URL.

[`sms:verify-student-delivery`](../app/Console/Commands/VerifyStudentCredentialDelivery.php) sends a non-account verification code through [`CredentialDeliveryVerification`](../app/Mail/CredentialDeliveryVerification.php) and asks the operator to enter the received code at a hidden prompt. Only a hash is cached for the 15-minute, one-use challenge. The code cannot reset an account. Receipt confirmation approves the exact channel; changed settings or missing cache proof close the gate. `--status` is read-only and sends no mail.

[`AppServiceProvider`](../app/Providers/AppServiceProvider.php) reuses Laravel's `ResetPassword` notification, chooses the dedicated mailer and builds links from configured `APP_URL`, not an incoming Host header. No reset token/password is returned to the Registrar or passed to the log mailer. Secrets travel only through the established private recipient/form exchange. Operational exceptions are sanitized.

## 3. Earlier QA defects addressed

| Finding | Correction and actual evidence |
| --- | --- |
| SAM-01: direct PATCH committed but 302 could preserve PATCH to a GET-only detail route and yield 405 | Mutation/error responses and stale mutation confirmation now use 303. Confirmation returns to safe detail GET. Original external QA assertion passed; permanent lifecycle test asserts stale-confirmation, validation, deactivate/reactivate 303 and successful GET detail. |
| SAM-02: staff forgot-password could log a live reset token | `PasswordResetLinkController`, `User::sendPasswordResetNotification()` and notification rendering require verified dedicated delivery. Unready recovery creates no staff broker token/log notification. Original external QA assertion passed. |
| OBS-01: Student self-service password change retained an older broker token | Locked `StudentCredentials::changeOwnPassword()` changes the hash, revokes broker/remember tokens/database sessions and audits. `PasswordController` invalidates the current session and requires new login. New regression passed; ordinary staff password-change behavior remains. |

The previous independent QA report is historical and is not rewritten as a new independent PASS.

An initial MySQL race run found an identical concurrent creation repeat returning `email_conflict`. Initial Student lookup established a repeatable-read snapshot; a later nonlocking relationship check saw the old version after another transaction committed the link. `StudentAccess::validAssociation()` now verifies with a current locking read. The same-link and two conflict races then passed separately and in the full suite.

## 4. Database and preservation

Preflight found `2026_11_18_000001_add_student_lifecycle_to_users` already recorded exactly once, both `users.student_setup_required` (default false) and `users.student_access_disabled_at` (nullable) present, and existing users without nondefault lifecycle flags. **No migration reapplication, column drop or existing-record reset occurred.** The migration file was not modified.

A Laravel-encrypted User/schema/migration backup was retained outside the repository before deployment verification. Immediate pre/post checks found no changes to original row projections. Final row counts/sorted-row hashes matched all **40 non-session tables**, including users, roles, Students, scholarships, rosters, billing and audit. Only the live `sessions` table differed at final snapshot; an existing application server was running concurrently. Session contents were not restored, deleted or inferred from that difference.

Automated tests used owned `cfci_new_account_<random>_test` databases and separate owned race databases on **MariaDB 10.11.16**, MySQL driver, `utf8mb4_unicode_ci`. Browser preparation used a separate fictional runtime database. Cleanup verified deletion only of the two owned main/runtime databases; race tests clean up their own databases. The configured database was explicitly excluded. Private browser fixture credentials were removed; encrypted backup/evidence retained.

## 5. Test commands and actual results

Evidence directory: `%TEMP%\cfci-new-account-20261118-f78c9ded`. Its isolated PHPUnit configuration forces the owned database, ignores application config cache and uses array cache/sessions. Test mail uses Mail/Notification fakes and a test-only readiness override, never real approval.

```powershell
$taskPath = (Get-Content "$env:TEMP\cfci-new-account-current.txt" -Raw).Trim()
php vendor/bin/phpunit --configuration "$taskPath\mysql.phpunit.xml" --log-junit "$taskPath\full-mysql.xml"
php vendor/bin/phpunit --configuration "$taskPath\mysql.phpunit.xml" "$taskPath\OriginalQaSecurityRegressionsTest.php" --filter 'test_direct_patch_mutation_must_redirect_with_get_semantics|test_public_staff_recovery_must_not_write_a_live_bearer_token_into_log_transport'
php vendor/bin/phpunit --configuration "$taskPath\mysql.phpunit.xml" --filter test_http_lifecycle_confirmation_validation_status_filters_and_blocked_delivery
php vendor/bin/pint --test
node --test tests/js/data-tables.test.js
npm run build
php artisan sms:verify-student-delivery --status
```

Owned databases were removed after testing. Before rerunning, create an explicitly owned isolated database and update the isolated configuration. Do not use destructive test traits against real records.

| Executed check | Actual result |
| --- | --- |
| Focused creation/delivery/interface/service/auth run | **91 tests, 776 assertions, PASS.** |
| Initial committed-creation/race run | **6 tests, 54 assertions, 1 failure.** Identical concurrent repeat failed due to snapshot issue above; corrected and retested. |
| Corrected creation races | **3 tests, 42 assertions, PASS.** Exact repeat unchanged; different Student rejected; competing email rolled back without orphan. |
| Full applicable MariaDB suite | **684 tests, 8,396 assertions, 0 failures/errors/skips, PASS**, 10m 48.781s. Includes original SR-01, console provisioning, lifecycle and operational workflows. |
| Two original external QA failure assertions, adapted only class/database prefix | **2 tests, 10 assertions, PASS.** Reused adversarial assertions, not a new independent certification. |
| Permanent lifecycle redirect assertions added after full run | **1 test, 20 assertions, PASS.** No application code changed after the full run. |
| PHP syntax on all 25 PHP files changed/added in this task | **25 passed, 0 failures.** |
| Full repository Pint `--test` | **PASS**, exit 0. |
| Node table-controls tests | **3 passed, 0 failures**, exit 0. An earlier incorrect `*.test.mjs` pattern discovered zero tests and is not counted; the actual `.test.js` file was executed. |
| Production Vite build | **PASS**, exit 0; 61 modules transformed and production assets generated. |
| Whitespace review | `git diff --check`: **PASS**, exit 0. |
| Type checking | **Not applicable:** no TypeScript configuration or type-check script exists in the frontend package. |
| Actual configured delivery `--status` | **Exit 1, expected blocked status:** mail is not configured; no email sent. |
| Chrome/Playwright browser smoke | **BLOCKED:** two local preview-server attempts timed out at `/login` before a UI scenario executed. No manual/browser flow or successful screenshot is claimed. |
| Real SMTP/inbox receipt/setup email | **NOT EXECUTED / BLOCKED:** user deferred credentials, sender and controlled inbox. Fakes prove software behavior only. |

Coverage includes pending creation by both authorized roles, own-profile access after single-use setup, admin/foreign-profile denial, privileged/unknown-role protection, idempotence, duplicate/changed emails, missing Student, conflicting links, audit/postcondition rollback, post-commit notification, sanitized failure, configuration-bound receipt challenges, invalid/expired challenges, staff log protection and Student password-change token revocation. Retained lifecycle tests cover invalid/expired/reused account tokens, deactivation, old sessions, reactivation and business-record preservation.

Final review confirmed the original `.env` content matches its starting hash when
the newly appended blank configuration block is removed, and the existing
lifecycle migration file matches its starting hash. No credentials were replaced
or disclosed. The final test-only change strengthened the retained lifecycle
redirect assertions; its targeted MariaDB rerun passed without an application
code change or unnecessary repeat of the full suite.

## 6. Modified files

Application/configuration:

- `app/Actions/Accounts/LinkStudentAccount.php`, `StudentAccountProvisioningResult.php`, `StudentCredentials.php`.
- `app/Services/Accounts/StudentCredentialDelivery.php`, `StudentAccess.php`.
- New `app/Console/Commands/VerifyStudentCredentialDelivery.php`.
- `app/Http/Controllers/StudentAccountController.php`, `Auth/PasswordController.php`, `Auth/PasswordResetLinkController.php`.
- `app/Http/Middleware/ConfirmStudentAccountPassword.php`.
- New `app/Http/Requests/CreateStudentAccountRequest.php`; existing `ManageStudentAccountRequest.php`.
- `app/Models/User.php`, `app/Providers/AppServiceProvider.php`.
- New `app/Mail/CredentialDeliveryVerification.php`.
- `config/mail.php`, new `config/student_accounts.php`, `.env.example`, local ignored `.env` (blank dedicated settings appended).
- `routes/web.php`, `resources/views/student-accounts/index.blade.php`, `show.blade.php`, new `resources/views/emails/credential-delivery-verification.blade.php`.

Tests:

- New `tests/Feature/Auth/StudentNewAccountTest.php`, `CredentialDeliveryVerificationTest.php`, `StudentAccountCreationCommitTest.php`.
- New `tests/Feature/Database/StudentAccountCreationConcurrencyTest.php`.
- Updated `tests/Feature/Auth/PasswordResetTest.php`, `StudentCredentialDeliveryTest.php`, `StudentAccountLifecycleTest.php`; no original regression suite removed.

Documentation: this report, `docs/18-scholar-account-creation-flow.md` and current-stage notes in the design/service/interface/lifecycle references.

## 7. Manual verification and remaining limitations

Follow the [step-by-step flow](18-scholar-account-creation-flow.md). In isolated development/staging, supply real `STUDENT_MAIL_*` settings and approved HTTPS `APP_URL`; clear/rebuild config cache and run the receipt command with a controlled inbox. Enter the actual privately received code, then verify `--status` succeeds.

Using a newly fictional Student and controlled recipient, a Registrar should search **Student accounts**, choose **Manage login**, confirm their own password, review email, attest identity and submit **Create new account**. Verify one pending canonical linked account. Have the recipient open the actual setup email, choose a password and sign in. Check **My student profile**, denial of another Student's records/management endpoints, repeated requests, deactivation/old-session denial, reactivation/new login, safe audit and preserved scholarship records.

These real-delivery and manual browser steps were **not performed**. Do not enable live creation, assert inbox deliverability or start Student Profile Management based only on fake-mail tests. Real delivery and browser acceptance remain open despite passing software regressions.
