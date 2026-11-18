# 15 — Student role implementation

> **Student profile update (2026-11-19):** The user has now requested Student self-service information editing. An enabled, correctly linked Student can edit their own allowlisted personal/contact/address/family fields through My student profile. Staff-only record and account permissions remain. See [current implementation and actual test results](24-student-profile-editing.md); earlier read-only/deferred scope below is historical.

Verified on **2026-11-15 (Asia/Manila)**.

Students can use the existing login form and view their own linked student
profile at `/student/profile`. Their official school record is read-only.
Existing staff permissions and authentication mechanisms remain in place.

## 1. Analysis of the existing architecture

### Roles and database relationships

- Role memberships already use `user_roles`, `UserRole`, `User::roles()` and
  `User::hasAnyRole()`. There is no separate role catalog to seed or duplicate.
  The database already accepts the canonical lowercase `student` role string;
  older tests referenced it, but treated it as unsupported.
- `user_roles` already has a user foreign key and a unique `(user_id, role)`
  constraint. Student access reuses this membership pattern.
- `students.user_id` already exists as a nullable, unique foreign key to `users`.
  `User::student()` and `Student::user()` already implement the one-to-one link.
  The existing foreign key restricts deletion of accounts linked to retained
  student records.
- Before this change, no Student portal or ownership authorization existed.
  `StudentPolicy` inherited staff-only directory/detail permissions, and the
  access-audit command classified `student` as unexpected.
- The configured MySQL database's read-only account audit showed three accounts:
  one Coordinator, one Registrar and one Administrator, with no Student
  memberships. The same counts were observed after implementation.

**No migration is required:** the existing schema already provides the role
membership and unique account-to-student relationship. No second role table,
duplicate role seed, extra student identifier or authentication guard was added.

### Authentication, routing and navigation

The project uses Laravel 12/Breeze with the existing `web` session guard,
email/password login, hashed passwords, five-attempt login rate limiting,
session regeneration, intended redirects, password management and logout session
invalidation. Public GET/POST `/register` are already unavailable.

Protected business routes use `auth` and existing controller/FormRequest policy
checks. Staff membership checks remain in `RecordPolicy`, individual policies
and financial Gates. Navigation already follows those permissions. Account
settings at `/profile` act on the authenticated User and accept only validated
account fields; they do not assign roles or link student records.

The role sets retain their existing meanings:

| Role set | Memberships | Result of this change |
| --- | --- | --- |
| Operational staff | `coordinator`, `registrar` | Unchanged |
| Approved technical exception | `administrator` | Unchanged |
| Existing staff compatibility | Above roles plus `finance`, `viewer` | Existing policy branches preserved |
| Student | `student` | Recognized for own-profile access; excluded from staff role sets |

Earlier documentation describing Student access as unsupported is superseded by
this confirmed requirement. Historical credential inventories were not edited.

## 2. Implemented behavior and authorization

### Login and landing page

- A Student-only account defaults to `/student/profile` after successful login.
- Direct `/dashboard` access by a Student-only account redirects to that profile
  before staff analytics are built.
- Staff default redirects and all existing login protections remain unchanged.
- Laravel's intended redirects remain supported. If a guest first requests a
  staff URL and subsequently logs in as a Student, following that intended URL
  still returns **403**; authentication does not bypass its policy.
- Desktop navigation, mobile navigation and the user menu provide a link to the
  student's profile. The role label is **Student**. Staff navigation links are
  not shown to Student-only accounts.

### Own-profile access

`GET /student/profile` is named `student.profile` and uses `web`, `auth` and
`cache.headers:no_store;private` middleware. It accepts no record-ID route
parameter.

`StudentProfileController` first authorizes `StudentPolicy::viewSelf`, then
queries **the authenticated user's `student()` relationship**. When linked,
`viewOwnProfile` also requires the Student membership and an exact match between
the record's `user_id` and the authenticated user's ID. Query parameters such as
`student_id`, `user_id`, `id` and `student_number` do not select the record.

The page reuses the existing profile-details partial to show the linked
student's identification, personal, academic, family, address and contact
information. `viewProtectedInformation` permits the linked Student to view
their own protected identifiers; other students' information remains denied.
Existing encrypted casts and hidden serialization fields remain unchanged.
Staff-only audit history is not loaded or included in the Student page.

An unlinked Student account sees **No student profile linked**, with instructions
to contact the Registrar or Coordinator. The controller never claims a record
by matching email, selects the first available student, or creates a record
automatically. A link without the Student membership does not grant portal
access.

### Permission boundaries

| Page/action | Student-only account |
| --- | --- |
| Existing login, logout and password management | Allowed through existing authentication |
| `/student/profile` | Own linked record only; private, non-cacheable response |
| Own protected profile fields | Read-only, ownership checked |
| Unlinked account's Student page | Informational empty state; no student data |
| Own account settings (`/profile`) | Existing own-account name/email/password behavior retained |
| Update official student details, identifiers or account links | Denied |
| Staff `/scholars` directory and `/scholars/{id}` pages | Denied, including the student's own record through staff routes |
| Other students' records | Denied; URL/query IDs cannot change the own-profile selection |
| Academic verification, evaluations and scholarship/roster management | Denied |
| Billing, receipts, payments, payroll, deductions and liquidation | Denied |
| Private evidence and report HTML/CSV/print routes | Denied |
| Public registration, browser account creation or role assignment | Not exposed |

Student record corrections remain a staff responsibility. Updating a login
account's name/email does not alter official student fields or transfer its
foreign-key link. Linked-account deletion retains the existing foreign-key
restriction and returns the existing account-settings validation error.

The project already supports multiple explicit memberships. An account that
separately has an existing staff role retains that role's permissions; adding
Student membership does not revoke staff access. Student membership alone never
grants a staff role or staff privileges. Roleless and unrecognized-role accounts
retain their existing restrictions.

## 3. Trusted setup of existing accounts

The new console-only command links an **existing account** to an **existing
student record** and ensures one canonical Student membership:

```sh
php artisan sms:link-student "existing-account@example.test" "EXISTING-STUDENT-NUMBER"
```

These arguments are placeholders; use the verified existing account email and
institutional Student ID. Account identity must be checked by the trusted
operator before running the command. It has no browser route and does not create
accounts, generate passwords, create student records or accept an arbitrary role.
Existing trusted account provisioning remains separate; public registration was
not introduced.

The command:

1. Locks the existing User and Student rows within a database transaction.
2. Rejects missing records, a student already linked to a different account, or
   an account already linked to another student.
3. Associates the record and uses `firstOrCreate` for the existing `student`
   membership, with the existing unique constraints as database enforcement.
4. Preserves credentials and all other memberships.
5. Writes one `student_account_linked` audit event when access/linkage changes.
   The event records link IDs and trusted-console provenance, not passwords or
   decrypted profile identifiers. Repeating an already-completed link creates
   neither duplicate memberships nor duplicate audit events.
6. Rolls back both linkage and membership if auditing fails. Unique-constraint
   conflicts return failure without leaving a partial link.

The existing `sms:grant-role` command still permits only its original operational
staff choices. Student setup does not expand that command or the sample staff
provisioning choices.

`sms:audit-access` now recognizes `student` and reports `student_accounts` and
`student_accounts_without_profile`. Existing operational/technical/legacy counts
remain available. Its strict two-account inventory flag keeps its prior meaning;
Student accounts are not hidden from total account counts.

## 4. Files changed for this requirement

| File | Purpose |
| --- | --- |
| `app/Models/User.php` | Reuses canonical Student role string; adds Student-only landing-page helper |
| `app/Policies/StudentPolicy.php` | Adds portal and record-ownership abilities; allows own protected profile information |
| `app/Http/Controllers/StudentProfileController.php` | Authorizes and resolves the current user's linked profile |
| `app/Http/Controllers/Auth/AuthenticatedSessionController.php` | Student-specific default destination; existing intended redirect retained |
| `app/Http/Controllers/DashboardController.php` | Redirects Student-only users before staff analytics |
| `routes/web.php` | Adds the authenticated, private own-profile GET route |
| `resources/views/student/profile.blade.php` | Read-only profile and unlinked-account state |
| `resources/views/layouts/navigation.blade.php` | Student role label and own-profile links in desktop/mobile/user navigation |
| `resources/views/components/records-page.blade.php` | Optional eyebrow text; existing staff default preserved |
| `resources/views/scholars/profile-details.blade.php` | Optional suppression of staff academic-history guidance on the Student page |
| `app/Console/Commands/LinkStudentAccount.php` | Idempotent, transactional, audited console linkage of existing records/accounts |
| `app/Console/Commands/AuditStaffAccess.php` | Recognizes Student memberships and reports missing profile links |
| `tests/Feature/Auth/StudentRoleTest.php` | Login, ownership, private fields, navigation, role/link injection and restricted route tests |
| `tests/Feature/Auth/StudentAccountLinkTest.php` | Link creation, reuse, conflicts, rollback, credential preservation and access-audit tests |
| `tests/Feature/Auth/OperationalRoleSecurityTest.php` | Moves Student out of the obsolete unrecognized-role test matrix; dedicated Student denial tests replace it |
| `tests/Feature/Auth/StaffAccessAuditTest.php` | Uses an actually unsupported role for unexpected-role audit coverage |
| `docs/15-student-role-implementation.md` | Analysis, permission boundaries, setup and actual verification results |

No schema, registration route, authentication guard, staff role sets or business
authorization rules were replaced. Existing table-control work was preserved.

## 5. Actual verification results

All application tests used the repository's configured **SQLite in-memory test
database**, not the configured MySQL application database.

| Verification | Actual result |
| --- | --- |
| `php artisan test --compact tests/Feature/Auth/StudentRoleTest.php` | **20 passed**, 277 assertions |
| `php artisan test --compact tests/Feature/Auth tests/Feature/ProfileTest.php` | **82 passed**, 707 assertions |
| `php artisan test --compact` | **530 passed, 11 skipped**, 6,962 assertions; 81.71 seconds |
| `npm run build` | Passed; Vite production build completed |
| Laravel Pint on changed PHP files | Passed |
| `git diff --check` | Passed |
| Real Chrome/Playwright browser walkthrough | Passed using a separate temporary SQLite database with synthetic Student, other-Student and Coordinator accounts |

The 11 skipped cases require an isolated MySQL/MariaDB `_test` connection for
locking/concurrency checks. They were not reported as passing, and this run does
not establish MySQL concurrency behavior. No migration or test provisioning was
run against the configured MySQL application database.

### Verified security and regression cases

- Correct Student credentials authenticate through the real login endpoint;
  incorrect credentials and the existing five-attempt throttle prevent login.
  Session IDs rotate on login, and logout invalidates private session state.
- The Student's own linked information, including their own encrypted-at-rest
  identifiers, appears on their private profile. Another student's identifiers,
  name, address and record number do not appear.
- Direct foreign-record URLs return 403; the ID-less profile route ignores forged
  query identifiers. Official profile writes are denied, and a profile-ID path or
  unsupported write method cannot select or modify another student's record.
- Staff directories, staff detail records, private evidence and all six report
  modes in HTML/CSV/print remain denied to Students.
- **44 school-record mutation routes** return 403 even with forged Administrator
  or Coordinator payloads. Database snapshots verify that the attempted requests
  leave accounts, roles, student records and financial/academic records unchanged.
- Own account settings cannot assign roles, relink student records or modify
  another User. Password updates work through the existing endpoint; deletion of
  a linked account leaves retained student records intact.
- Both role membership and account linkage are required. Matching email alone
  never grants access. Repeated console linkage is idempotent; conflicting links
  are refused, and an audit failure rolls back the transaction.
- All five existing staff roles retain their dashboard and existing directory/
  financial access. Registrar/Coordinator/Administrator security checks and
  explicit multi-role behavior continue to pass in the full suite.
- The browser walkthrough verified Student login/logout, ownership and query-ID
  isolation, staff-route denials, desktop/mobile profile navigation, no horizontal
  page overflow at 390 pixels, and the existing Coordinator dashboard. Test
  screenshots and preview fixtures were kept outside the repository.

No real Student login was created or linked in the configured database during
implementation. Deployment requires a trusted operator to link the intended
existing account and student record with the command above.
