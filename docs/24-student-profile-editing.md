# Student self-service profile editing

**Date:** 2026-11-19, Asia/Manila

**Verdict: PASS for the implemented own-profile editor.** Initial full-suite
issues were confined to the two new concurrency test cases and were corrected
and rerun successfully. The full run's original nonzero result is retained below.

The user requested that Students also be able to edit their information. This
authorizes the Student Profile Management stage that earlier implementation
reports deliberately deferred. This change adds editing of the authenticated
Student's own personal/contact information while retaining staff control of
official identifiers, academics, account lifecycle and scholarship workflows.

## How to use it

1. Sign in with an enabled Student account whose password setup is complete.
2. Open **My student profile** (the normal Student landing page).
3. Select **Edit my information**.
4. Review the Student ID and login email shown above the form. Edit the available
   fields, then select **Save my information**.
5. The page returns to **My student profile**, showing the saved information and
   a confirmation. Validation errors appear beside the relevant fields.

Changing the Student contact email does not change the login email, account
display name or password. Students no longer have access to **Profile & settings**;
contact the Registrar for assistance with the login account. See
[the access restriction and verification](25-student-settings-access.md).
No email notification is sent by a profile edit.

## Editable fields and permissions

| Information | Student self-service |
| --- | --- |
| First, middle and last name; suffix | Editable on the Student's own record. First/last names cannot be cleared. |
| Sex and birthdate | Editable; existing choices/date rules apply, including no future birthdate. |
| Contact email and phone | Editable; email is normalized to lowercase and recognized Philippine phone formats use the existing normalizer. |
| Street/barangay, city/municipality, province, region, ZIP | Editable. |
| Father's names and mother's maiden names | Editable. |
| Student ID, LRN, PhilSys/National ID, 4Ps ID | Staff-controlled; no editable input or accepted update field. |
| Academic program, year level, academic period | Staff-controlled. |
| Record status, disability/solo-parent/first-generation/IP indicators | Staff-controlled. |
| Scholarships, rosters, grades, eligibility, billing | Not modified by this feature. |
| User/account association, roles, passwords, lifecycle flags | Not accepted by the Student profile endpoint. |

All 19 editable fields are centrally allowlisted. Other authenticated roles,
mixed memberships and noncanonical memberships cannot use this Student
self-service writer. Existing Administrator/Registrar/Coordinator permissions
through the standard staff module are unchanged. An archived Student record
remains archived; an enabled linked Student may update these personal details
without changing scholarship or enrollment status.

Missing account links cannot be claimed by matching an email. Pending/disabled
accounts cannot bypass the existing login/session restriction to edit profiles.
Students still cannot use staff Scholar edit/update routes, even for their own
record. No additional approval queue or public registration is introduced.

## Architecture and protection

| Route | Purpose |
| --- | --- |
| `GET /student/profile`, `student.profile` | Existing own-profile display with new Edit action. |
| `GET /student/profile/edit`, `student.profile.edit` | Authorized own-profile form. |
| `PATCH /student/profile`, `student.profile.update` | Authorized allowlisted update, CSRF and 20 requests/minute limit. |

The routes accept no Student identifier. The target is always resolved from the
authenticated User's existing Student association. GET query IDs cannot select
another Student, and forged update fields are rejected rather than mass assigned.
New routes use private/no-store responses.

`StudentPolicy::updateOwnProfile()` requires existing own-profile access and the
verified sole canonical `student` association. The existing staff `update`
ability was not broadened to include Students.

`UpdateOwnStudentProfile` locks the current User, role memberships and Student,
then rechecks authorization inside a retriable transaction. Validation is shared
with `StudentProfileUpdateRequest`, using existing profile choices and phone
normalization. Absent PATCH fields are retained; optional values can be cleared.
Invalid phone changes fail, while an unchanged legacy number is retained for review.

The form carries an HMAC of the saved record state, not a selectable Student ID.
The action compares it after acquiring locks. A stale form cannot overwrite a
newer staff/Student save, including changes within the same timestamp second.
On conflict the form reloads the latest saved values and clears prior flashed
inputs so values from another tab cannot silently overwrite the newer data.

Each changed save records `student_profile_updated` in the existing audit table
with actor, timestamp, subject and before/after submitted editable fields.
Government IDs, login credentials and the version value are not audit payload
fields. No-change saves create no duplicate audit or timestamp update.

Saving and the required audit are atomic. The action verifies ownership, current
role/lifecycle, saved editable values and unchanged protected columns before
commit. Canceled audit writes, tampering or failed postconditions roll back.
Unexpected failures are sanitized and do not log private input values.

## UI and screenshots

The Student profile shows **Edit my information**. The later settings-access
restriction removes **Account settings** from Student pages and navigation.
The form reuses existing record cards, labels, fields, notices and buttons;
two-column desktop sections stack on mobile. It includes save/cancel controls,
submission protection and clear guidance about staff-maintained fields.

Actual screenshots use newly fictional records. They were captured before the
later settings-access restriction and may show the former Account settings link:

- [Desktop edit page](screenshots/24-student-profile-edit-desktop.png).
- [Mobile edit page](screenshots/24-student-profile-edit-mobile.png).
- [Updated Student profile](screenshots/24-student-profile-updated-mobile.png).

## Files changed

- New `app/Actions/Records/UpdateOwnStudentProfile.php`.
- New `app/Http/Requests/StudentProfileUpdateRequest.php`.
- `app/Http/Controllers/StudentProfileController.php`.
- `app/Policies/StudentPolicy.php`, `routes/web.php`.
- New `resources/views/student/edit.blade.php`; updated `student/profile.blade.php`.
- New `tests/Feature/Records/StudentProfileEditingTest.php`.
- New `tests/Feature/Database/StudentProfileConcurrencyTest.php`.
- `tests/Feature/Auth/StudentRoleTest.php`: retains staff mutation denial and
  updates the old 405 expectation for the newly authorized own-profile route.
- This report, screenshots and dated scope notes in previous stage reports.

No schema migration, production-data conversion or environment change is needed.
The shared staff profile editor, account settings and password setup forms retain
their existing behavior.

## Test evidence

Evidence directory: `%TEMP%\cfci-student-profile-773f6e4edd`, referenced by
`%TEMP%\cfci-student-profile-current.txt`. PHPUnit uses an explicitly owned
isolated MariaDB database with `utf8mb4_unicode_ci`; browser tests use a separate
owned runtime database with fictional Users/Students. Mail/notifications are faked.

```powershell
$taskPath = (Get-Content "$env:TEMP\cfci-student-profile-current.txt" -Raw).Trim()
php vendor/bin/phpunit --configuration "$taskPath\mysql.phpunit.xml" --filter 'StudentProfileEditingTest|StudentRoleTest'
php vendor/bin/phpunit --configuration "$taskPath\mysql.phpunit.xml" --log-junit "$taskPath\full-mysql.xml"
node "$taskPath\browser-profile.cjs"
php vendor/bin/pint --test
npm run build
```

The isolated databases/private fixtures are removed after testing. Recreate
explicitly owned test databases before rerunning; no existing source records
are reset or used as test fixtures.

The initial focused run passed **63 tests / 540 assertions**. Browser verification
passed **10 checks**: own edit navigation, desktop/mobile including 320px, invalid
phone handling, saving and normalization, unchanged login email, stale-tab
conflict, query/URL ownership checks, forged-association denial, unchanged
credentials/other Student profile and no JavaScript runtime errors. Desktop and
mobile screenshots were visually inspected.

| Executed check | Actual result |
| --- | --- |
| Initial focused Student profile/role run | **63 tests, 540 assertions, PASS.** |
| Full applicable MariaDB suite | **760 tests, 8,949 assertions; 758 passed, 1 error and 1 failure**, both in the new profile concurrency test. The existing authentication, provisioning/SR-01, staff records, scholarship, roster, billing and reporting cases passed in this run. |
| Initial concurrency issues | The copied race fixture incorrectly marked the editing Student as pending; the child process also caught `HttpException` but misclassified Laravel's `AuthorizationException`. The fixture and child classification were corrected. |
| Final focused profile, role and concurrency run | **66 tests, 562 assertions, 0 failures/errors/skips, PASS.** Includes both previously failing race cases, stale-tab input clearing, and an added late authorization-denial response test. |
| Authorization error handling review | The new controller now preserves Laravel authorization exceptions as access denial rather than wrapping them as a generic save error. A direct JSON regression confirms HTTP 403 and no changes. |
| Desktop/mobile browser | **10 checks passed**, no JavaScript errors or horizontal overflow at 390px/320px. Screenshots were visually inspected. |
| Full repository Pint | PASS; final changed PHP files were checked again after the refinements. |
| Syntax | All **8 changed/new PHP files** passed. |
| Production build | PASS, exit 0, 61 modules transformed. |
| Environment/schema | No schema changes; `.env` matches its starting hash. |

The full suite was not repeated after the narrowly scoped concurrency-harness
and authorization-response corrections. The complete affected test classes and
both new race cases were rerun; no executed-check failures remain unresolved.
The original full-suite failure is not described as a passing full run.

The stale-save response was also strengthened to clear any older flashed inputs
from another tab. The final run verifies that conflict recovery displays current
saved data rather than stale field values.

No real Student/account records were used as test data. Final cleanup removes
only the explicitly owned test/runtime databases and private fictional browser
credentials, with the configured source database excluded. No account roles,
passwords or lifecycle flags are modified by personal-profile editing.
