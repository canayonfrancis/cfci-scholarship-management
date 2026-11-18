# Profile & Settings removal for every role

Date: 2026-11-19 (Asia/Manila)

The user requested removal of Profile & Settings for all roles. The page, its
navigation links and its dedicated account-edit, password-update and self-deletion
endpoints have been removed, including access for Registrar, Coordinator,
Test Administrator, Finance, Viewer and Student accounts.

Changes:

- Removed desktop/mobile Settings links in `layouts/navigation.blade.php`, the
  dashboard Settings shortcut and the remaining conditional Student profile link.
- Removed GET/PATCH/DELETE `/profile` and PUT `/password`, along with route names
  `profile.edit`, `profile.update`, `profile.destroy` and `password.update`.
  Old URLs no longer have corresponding application routes.
- Removed the unused `account-settings` gate and its provider import.
- Deleted `ProfileController`, the dedicated `Auth/PasswordController`,
  `ProfileUpdateRequest`, `profile/edit.blade.php` and its three form partials.
- Marked the prior Student-only settings restriction report as historical.

The separate Student portal (`/student/profile` and its own information editor)
remains available through its existing authorization checks. Login, logout,
password confirmation, secure password recovery/setup, Student account management
and account lifecycle services retain their existing routes and business logic.
No user accounts, passwords, roles, Student records or database schema were changed.

Static checks: PHP syntax and Laravel Pint on the four modified PHP files, source
reference checks for removed routes/classes/views, and whitespace checks. No
automated or manual functional tests were executed, following the user's existing
testing restriction. Existing test files were left intact; tests expecting the
removed settings feature need developer review before subsequent test runs.

Manual checks for the developer, not executed by the agent:

1. Sign in under each existing role. Confirm no Profile & Settings link appears
   in the user menu, mobile navigation or dashboard shortcuts.
2. Navigate directly to `/profile`. Expect the application's missing-page response.
3. Confirm old PATCH/DELETE `/profile` and PUT `/password` requests cannot modify
   accounts; no such routes remain.
4. Sign in as Student and open My student profile, then Edit my information.
   Confirm the existing personal-information editor remains available.
5. Confirm login/logout, required password confirmation and authorized Registrar
   Student account actions retain their existing behavior.

No frontend asset rebuild, dependency installation or migration is required. If a
deployment uses cached routes/views, refresh them through its normal deployment
procedure. No functional verification verdict is claimed.
