# Initial Student password setup

> **Default update (2026-11-19):** At the user's explicit request, the authorized initial password form now prefills both password fields with `CRONASIA`. The operator can replace it before saving. This is a shared initial default, not a forced-first-login-change mechanism. Saving is still required to complete setup; existing saved passwords are never overwritten automatically. Validation retries keep password fields empty. This supersedes the earlier no-default decision for this initial form only.

The default update was verified on isolated MariaDB: **28 tests, 222 assertions passed**, including saving/logging in with `CRONASIA` and overriding it with a unique password. Production build, Pint and whitespace checks passed. Configuration: `student_accounts.initial_password`. Evidence: `%TEMP%\cfci-password-default-332a08f4e6`.

**Date:** 2026-11-19, Asia/Manila

**Verdict: PASS for the operator-managed initial password form.** Actual Student
login and own-profile access were verified after setup using fictional accounts.

## Problem and approved change

The user reported entering a password in Laravel's new-password form while the
Student account still showed **Pending password setup**, and requested a working
Student password form matching the existing interface.

The earlier read-only inspection confirmed that the reported account existed,
was linked to exactly one Student, had canonical `student` membership and had
only the creation event recorded. No completed activation was recorded. The
Student's password/hash was not read by that inspection.

The existing `/reset-password` handler requires a valid password-broker token.
Saving a browser password or submitting the form with an absent, invalid or
expired token does not complete setup. The old submission's exact response is
not available, so no claim is made that a particular token error occurred.
The separate **Confirm password to continue** page validates the signed-in
operator's password and never sets the Student's password.

This change provides authenticated, operator-managed **initial** password setup
without requiring email or a broker token. It retains Registrar Staff and the
existing Test Administrator exception as responsible roles. It does not provide
unauthenticated setup, public registration or arbitrary password reset.

## User flow

1. Sign in as Registrar Staff or the retained Test Administrator.
2. Open **Student accounts**, search the intended Student and select **Manage login**.
3. The account must already be linked and show **Pending password setup**.
4. Under **Set Student password**, select **Confirm password to continue** if
   requested. Enter your own operator password, then return to the Student page.
5. Enter **New Student password** and **Confirm Student password**. These are the
   Student's new credentials, separate from the operator confirmation.
6. Enter the **Identity review / setup reason** without including any password,
   and check the identity-review box.
7. Select **Save Student password**. Successful completion redirects to fresh
   details showing **Linked and configured** and profile access **Configured**.
8. The Student signs in through the existing login page with the linked email
   and saved password, then opens **My student profile**.

No email is sent. Passwords are never returned in the confirmation, an audit,
a URL or old form input. This implementation does not apply a password to an
existing real account automatically. The operator must save through the form.

## Architecture and security

- New `SetStudentInitialPasswordRequest` reuses management authorization,
  normalized email/reason, identity attestation and prohibited role/account-ID
  fields. It requires matching password fields and the existing password policy.
- Password rules are shared with the service. Bcrypt's 72-byte limit and null
  characters are validated safely before hashing, including multibyte input.
- New POST `/student-accounts/{student}/initial-password`, named
  `student-accounts.initial-password`, uses existing authentication, management
  Gate, CSRF, private/no-store responses, 15-minute operator-password confirmation
  and actor/target throttling.
- `StudentCredentials::setInitialPassword()` rechecks a fresh authorized actor,
  locks User/Student/actor/memberships in a retriable transaction, and requires an
  exact valid association, sole lowercase `student` role, valid matching login
  email, an account that has not been deactivated, and an outstanding setup requirement.
- Active, inactive, unlinked, mixed/staff-role, noncanonical-role and conflicting
  targets cannot be changed. A completed setup cannot be replayed to replace a
  password. A disabled pending account must first use authorized reactivation.
- The transaction hashes the new password, clears `student_setup_required`,
  revokes old broker/remember tokens and target database sessions, and records
  `student_account_activated` with actor, Student, reason and source
  `operator_password_form`. Other users' sessions are untouched.
- Association, lifecycle, hash and authorized own-profile access are checked
  before commit. Audit failure or failed postconditions roll back credential,
  lifecycle and revocation writes. Errors are sanitized; password inputs are
  marked sensitive and never flashed for validation/confirmation retries.
- Existing valid reset tokens still use the original reset handler. Manual
  setup revokes any old token. Email delivery readiness/TLS checks are unchanged,
  and the previously removed password-email buttons remain absent.
- Student, scholarship, roster and billing records are not changed. No schema,
  mail configuration or public authentication route changes are required.

## Layout

The form reuses existing `dashboard-card`, `records-form-card`, record fields,
primary buttons, notices and inline errors. Password fields are masked, use
`autocomplete=new-password`, appear side by side on desktop and stack on mobile.
The Student email is visible above the form. The identity checkbox starts
unchecked. Submission is guarded in Alpine and the server rejects repeats.

Only one operator-confirmation link is displayed for a pending account; the
secondary lifecycle section refers to it. Field IDs remain unique across the
password and lifecycle forms. No unrelated module was redesigned.

Actual screenshots use fictional records and empty password fields:

- [Desktop password form](screenshots/22-student-password-desktop.png).
- [Mobile page and form](screenshots/22-student-password-mobile.png).
- [Completed setup state](screenshots/22-student-password-complete.png).

## Files changed

- `app/Actions/Accounts/StudentCredentials.php`.
- New `app/Http/Requests/SetStudentInitialPasswordRequest.php`.
- `app/Http/Controllers/StudentAccountController.php`.
- `routes/web.php`.
- `resources/views/student-accounts/show.blade.php`, `index.blade.php`.
- New `tests/Feature/Auth/StudentInitialPasswordTest.php`.
- New `tests/Feature/Database/StudentInitialPasswordConcurrencyTest.php`.
- This report, screenshots, the current creation guide and dated reference notes.

## Verification evidence

Evidence is retained at `%TEMP%\cfci-student-password-a21b68a64e`, referenced by
`%TEMP%\cfci-student-password-current.txt`. Automated and browser tests use owned,
isolated MySQL/MariaDB databases. All mail is faked. No real account credentials
are used by the tests.

```powershell
$taskPath = (Get-Content "$env:TEMP\cfci-student-password-current.txt" -Raw).Trim()
php vendor/bin/phpunit --configuration "$taskPath\mysql.phpunit.xml" --filter StudentInitialPasswordTest
php vendor/bin/phpunit --configuration "$taskPath\mysql.phpunit.xml" tests/Feature/Auth tests/Feature/Database --log-junit "$taskPath\auth-database.xml"
node "$taskPath\browser-password.cjs"
php vendor/bin/pint --test
npm run build
```

Isolated databases and private browser fixtures are removed after completion;
recreate explicitly isolated fixtures before rerunning. Final executed results
are recorded below.

| Executed check | Actual result |
| --- | --- |
| Initial new feature test run | 27 tests, 145 assertions, 18 failures. The fixture snapshots used unsynchronized model attributes/order, and a fault-injection Gate did not override the policy. These test-harness issues were corrected; no security requirement was removed. |
| Corrected initial password suite | 27 tests, 216 assertions, PASS. |
| Existing and new authentication/database suites | **295 tests, 2,561 assertions, no failures/errors/skips, PASS**, 9m 42.791s. Includes existing login/roles, original SR-01, provisioning, lifecycle, tokens, session controls and concurrency suites. |
| New concurrent initial-password cases within that run | **2 tests, 18 assertions, PASS**. Waiting setup requests recheck completed/deactivated state and cannot overwrite or activate the target. |
| Final new-feature run after explicit malformed-email guard and clearer length validation | **28 tests, 222 assertions, no failures/errors/skips, PASS**. No further application behavior changes were made after this run. |
| Real Chrome/Playwright flow against isolated runtime | **10 checks passed**, no JavaScript errors. Covered pending login denial, operator confirmation, desktop/mobile layout, weak-password validation with empty secret fields, successful setup/status refresh, Student login/own profile, foreign/admin denial, preserved other Student session, repeated setup rejection and direct unauthorized POST denial. |
| Visual review | Desktop and full mobile screenshots inspected; consistent existing styling, stacked mobile fields, unique IDs and no page overflow. The first cropped mobile capture included the sticky header; the final full-page artifact shows normal header placement. |
| Full repository Pint check and final changed-file checks | PASS. |
| PHP syntax | All 6 changed/new PHP files passed. |
| Production Vite build | PASS, exit 0, 61 modules transformed. |
| Documentation links / whitespace | No broken local links; `git diff --check` passed. |
| Real email | Not sent or claimed; all mail is faked during tests. SMTP verification remains a separate issue. |

The browser fixture bootstrap initially had a bracket typo. It was fixed before
fixtures were seeded, and the subsequent runtime and browser checks passed.
Screenshots contain only fictional identities and no entered passwords. `.env`
matches its starting hash; no mail credentials or schema were changed.

The complete billing/reporting test collection was not rerun: this change ran
the full authentication and database test directories plus the final focused
suite. Student/scholarship records are not part of the password update, and the
feature tests verify that the Student record is preserved. There are no unresolved
failures from the executed checks.

## Remaining scope

- This is initial setup for eligible pending accounts, not a replacement for
  resetting an already active Student's password. The form disappears after setup.
- A deactivated pending account must be explicitly reactivated by an authorized
  operator before setup; password setup cannot bypass deactivation.
- SMTP approval, public reset-token rules and the removed email-link buttons
  remain unchanged. No password is displayed after saving and no unapproved
  automated credential-delivery channel was introduced.
- Existing real accounts are not silently activated or assigned passwords by
  this implementation. Complete the form for the intended Student to enable login.

Final cleanup verified removal of both owned test/runtime databases, excluded
the configured source database and removed private fictional browser credentials.
A final read-only check of the originally reported account found the same pending
flag, unchanged timestamps and creation-only audit history. No password/hash was
read by that check, and the account awaits the operator's actual form submission.
