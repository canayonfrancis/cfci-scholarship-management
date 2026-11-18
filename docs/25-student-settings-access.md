# Student restriction from Profile & settings

Date: 2026-11-19 (Asia/Manila)

Verdict: **PASS** for this access restriction.

The user requested that Students have no access to Profile & settings. Student
personal-information editing remains available through **My student profile >
Edit my information**.

## Implementation

- `app/Providers/AppServiceProvider.php` defines the `account-settings` gate using
  the existing `StudentAccess::usesStudentCredentials()` classification. It also
  covers unlinked Student memberships, capitalized legacy memberships and linked
  accounts with missing memberships. Existing recognized staff permissions,
  including already assigned mixed staff memberships, are retained. Ordinary
  non-Student accounts retain their existing settings access.
- `routes/web.php` requires the gate for GET, PATCH and DELETE `/profile`.
- `routes/auth.php` requires the same gate for PUT `/password`, the password
  action previously exposed inside that settings page. Students cannot bypass
  the restriction through a direct form or JSON request.
- `resources/views/layouts/navigation.blade.php` uses the gate for both the
  desktop Profile & settings link and mobile Settings link.
- `resources/views/student/profile.blade.php` and `student/edit.blade.php` remove
  Student settings links. The profile explains that changing contact email
  does not change the login email and directs account questions to the Registrar.
- Updated `StudentRoleTest`, `StudentNewAccountTest` and
  `StudentAccountLifecycleTest` for the new permission rule. Credential-service
  token-revocation coverage is retained as direct service tests; it does not
  imply that Students can invoke those methods through a settings route.
- `docs/24-student-profile-editing.md` updates current guidance and identifies
  screenshots captured before this restriction.

No schema migration, password reset, real account edit or email delivery was
performed. Login, logout, initial password setup and the secure password broker
remain under their existing rules.

## Executed verification

Tests used newly owned, isolated local MariaDB databases with
`utf8mb4_unicode_ci`; the configured application database was excluded.

```powershell
php vendor/bin/phpunit --configuration <isolated-mysql.phpunit.xml> --filter 'StudentRoleTest|StudentNewAccountTest|StudentAccountLifecycleTest|StudentProvisioningRegressionTest|StudentProfileEditingTest|ProfileTest|PasswordUpdateTest|AuthenticationTest|StudentInitialPasswordTest|OperationalRoleSecurityTest' --log-junit <settings-results.xml>
php vendor/bin/pint --test app/Providers/AppServiceProvider.php routes/web.php routes/auth.php tests/Feature/Auth/StudentRoleTest.php tests/Feature/Auth/StudentNewAccountTest.php tests/Feature/Auth/StudentAccountLifecycleTest.php
npm run build
```

- Targeted regression suite: **175 tests, 1,615 assertions passed**. Includes
  Student HTML/JSON settings denial, unchanged records after denied writes,
  legacy/incomplete associations, all six recognized staff roles, retained mixed
  staff permissions, ordinary account settings, own-profile editing, initial
  password setup, lifecycle, authentication and original SR-01 regressions.
- Laravel Pint: passed for all six changed PHP files.
- PHP syntax checks: passed for all six changed PHP files.
- Production Vite build: passed (61 modules).
- Actual Chrome/Playwright checks: **7 passed**, with no page JavaScript errors:
  Student desktop settings link absent; direct `/profile` returns 403; own
  information save works; mobile Settings absent; logout works; Registrar desktop
  settings access works; Registrar mobile Settings remains available.
- A full repository suite was not rerun for this scoped permission change.

## Manual verification

1. Sign in with an enabled Student account and open the user menu. It shows
   **My student profile** and **Log out**, without **Profile & settings**.
2. On a narrow/mobile screen, open navigation. There is no **Settings** entry.
3. Type `/profile` directly into the address bar. Expect **403 Forbidden**.
4. Open **My student profile > Edit my information**, save a valid personal
   change, and confirm it appears on the profile.
5. Sign out and sign in as Registrar. Confirm **Profile & settings** remains in
   the user menu and opens normally, and **Settings** remains on mobile.

Existing credential-delivery limitations are unaffected by this change; no email
delivery claim is made.
