# Student account creation without email

> **Initial password setup update (2026-11-19):** The user requested a dedicated Student password form. Authorized Registrar/Test Administrator users can now complete initial setup for an eligible linked pending Student from Manage login, without email. Saving a valid password clears the pending flag and verifies profile access atomically. See [implementation, layout and actual test evidence](22-student-initial-password-form.md). Earlier stage limitations below are retained as history.

**Date:** 2026-11-19, Asia/Manila

**Verdict: PASS for pending-account creation without email and removal of the
two password-link controls.** Student initial password setup and real credential
delivery remain separate limitations; this change does not enable pending login.

## Approved change

The user explicitly requested that **Create new account** work without email
notifications and that **Send password setup link** and **Send password reset
link** be removed from the account-management page. This supersedes the earlier
D02 restriction on creating pending accounts before delivery is verified.

Only account creation is independent of mail readiness. Sending credentials still
requires the existing verified secure channel. No SMTP certificate validation,
authentication, authorization or Student lifecycle check is weakened.

## Behavior and implementation

- `LinkStudentAccount::createForActor()` no longer checks credential delivery.
  It retains operator authorization and reauthorization, required identity/email/
  reason validation, locking/retries, duplicate/conflict/privileged-account
  rejection, canonical `student` membership, atomic association/audit and pending
  access postconditions.
- `StudentAccountController::createAccount()` creates the account without calling
  credential issuance. No email or password-reset token is generated, even if the
  configured delivery channel is verified. The confirmation explicitly says no
  email was sent and the Student cannot sign in yet.
- The detail page shows the normal identity-review creation form independently
  of SMTP status. Missing contact information must still be corrected. Recent
  operator password confirmation, CSRF, identity attestation and existing rate
  limits are retained.
- Both requested password-link buttons/forms are removed for unlinked, pending,
  active and inactive records. Linked records keep their lifecycle controls.
- The directory text and pending-state message explain creation without email.
  Exact repeated requests preserve the same account and state.

There is no new password field, predictable default password, public signup or
existing-account linking UI. New Users retain an undisclosed random hashed secret
and `student_setup_required=true`. They cannot authenticate or use the Student
portal until an approved secure password setup is completed. Removing the buttons
does not invent a replacement credential-delivery mechanism. Existing credential
services and protected endpoints retain their original authorization/readiness
checks for the existing authentication architecture.

## How to create an account now

1. Sign in as Registrar Staff or the retained Test Administrator.
2. Open **Student accounts**, search the existing Student and select **Manage login**.
3. Review the Student identity and contact email. Correct an invalid/missing email
   through the existing Scholar record first.
4. Select **Confirm password to continue** if shown and enter your own password.
5. Review **Confirmed login email**, enter the identity-review reason and check
   the identity confirmation box.
6. Select **Create new account**. The account is saved and linked with status
   **Pending password setup**. No email is sent.

This creates the account; it does not yet give the Student a usable password.
Existing enabled Student accounts continue to use their existing credentials.
Deactivation/reactivation never clears an outstanding setup requirement.

## Files changed

- `app/Actions/Accounts/LinkStudentAccount.php`.
- `app/Http/Controllers/StudentAccountController.php`.
- `resources/views/student-accounts/show.blade.php` and `index.blade.php`.
- `tests/Feature/Auth/StudentNewAccountTest.php`.
- `tests/Feature/Auth/StudentAccountCreationCommitTest.php`.
- `tests/Feature/Auth/StudentAccountProvisioningServiceTest.php`.
- `tests/Feature/Auth/StudentAccountInterfaceTest.php`.
- Current creation flow and dated reference notes in earlier reports.

No schema migration, environment change or real-account modification is required.

## Verification

An owned, isolated MariaDB test database is used; no real mail is sent. Updated
regressions cover no-mail creation both with unavailable and verified delivery,
no token generation, duplicate submissions, no duplicate audit/account, missing
and conflicting identity data, retained role protections and removal of the
password-link controls. Original SR-01, lifecycle, delivery and creation-race
regressions are retained.

Evidence directory: `%TEMP%\cfci-create-without-mail-edb1a961b8`, referenced by
`%TEMP%\cfci-create-without-mail-current.txt`. The temporary PHPUnit configuration
forces an owned MariaDB database and array sessions/cache. The test database is
removed after the checks; recreate an isolated test database before rerunning.

```powershell
$taskPath = (Get-Content "$env:TEMP\cfci-create-without-mail-current.txt" -Raw).Trim()
php vendor/bin/phpunit --configuration "$taskPath\mysql.phpunit.xml" --filter 'StudentNewAccountTest|StudentAccountCreationCommitTest|StudentAccountInterfaceTest|StudentAccountProvisioningServiceTest|StudentAccountCreationConcurrencyTest|StudentProvisioningRegressionTest|StudentCredentialDeliveryTest|CredentialDeliveryVerificationTest|StudentAccountLifecycleTest' --log-junit "$taskPath\regression.xml"
php vendor/bin/phpunit --configuration "$taskPath\mysql.phpunit.xml" --filter 'StudentAccountInterfaceTest|StudentNewAccountTest' --log-junit "$taskPath\final-interface.xml"
php vendor/bin/pint --test
npm run build
```

| Executed check | Actual result |
| --- | --- |
| Initial focused MariaDB run | 135 tests, 1,190 assertions; 132 passed and 3 failed. All three failures were old assertions requiring the login-email form to be absent, which conflicts with the newly approved creation form. |
| Final interface/creation run after correcting those assertions | **47 tests, 427 assertions, PASS**, zero failures/errors/skips. Includes both authorized roles, all three prior failing cases and an added pending/active/inactive check for removed controls. |
| Retained provisioning, SR-01, lifecycle, delivery and creation-race cases | Passed in the initial run; no application code changed after that run. The corrected interface assertions were strengthened to POST creation against existing accounts and confirm rejection without mutations. |
| Full repository Pint check | PASS, exit 0; final edited test files also passed. |
| PHP syntax | All 6 changed PHP files passed. |
| Production Vite build | PASS, exit 0; 61 modules transformed. |
| Whitespace and documentation links | `git diff --check` passed; no broken local links found. |
| Manual browser test | Not performed during this change; the server-rendered forms and actual HTTP actions were exercised through Laravel feature tests. |
| Actual SMTP delivery | Not attempted or claimed. Account creation deliberately sends no mail, whether delivery is available or unavailable. |

The full repository test suite was not rerun for this focused change. All
initially failing cases were rerun successfully in their complete affected test
classes; there are no unresolved failures from the executed checks. Existing
configuration, authentication routes and database schema were not changed.
