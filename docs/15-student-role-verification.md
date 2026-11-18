# Student role verification

**Review date:** 2026-11-18, Asia/Manila
**Final verdict: FAIL — a provisioning consistency defect was reproduced.**

Canonical lowercase Student accounts passed the executed authentication,
ownership, authorization and browser checks. However, the trusted link command
can report success and commit a student-account link while leaving the account
without recognized Student access on the application's MySQL/MariaDB collation.
Finding **SR-01** below explains the precondition, evidence and recommended fix.
No cross-student private-data disclosure or Student-only privilege escalation
was observed in the executed tests.

This was a verification-only task. Application code was not changed, defects
were not patched, and Student Profile Management was not started. The results
and recommended fix must be reviewed before that work proceeds.

## 1. Scope, reference and data isolation

The required reference was
[15-student-role-implementation.md](15-student-role-implementation.md). Its
implementation claims were checked against the current working tree and fresh
test executions; its historical test results were not counted as new results.

The repository was already dirty at the start of review. The baseline commit was
`3ade32a`, and the working tree also contained earlier table-control changes.
Student-related tracked diffs and untracked implementation files were inspected
without reverting or staging them. A before/after SHA-256 inventory covered 347
pre-existing source/configuration/documentation files, including `.env`, without
displaying their contents or secrets. The only new repository file produced by
this review is this report. Generated build/test artifacts are separate from
application source changes.

Test isolation:

- Repository tests ran against explicitly selected SQLite `:memory:` storage.
- MySQL checks used a newly created database whose generated name matched
  `cfci_student_review_<random>_test`, using `utf8mb4_unicode_ci`. The temporary
  PHPUnit configuration forced the test database name and disabled database-URL
  overrides. Existing database tests additionally guard `_test` connections and
  create their own isolated race/migration databases.
- Browser/HTTP checks used another newly created SQLite file and synthetic
  Student, other-Student, unlinked-Student and Coordinator accounts. Existing
  application login credentials were not used or reproduced.
- No existing application database records were reset, deleted or provisioned.
  Only the MySQL database created by this review was dropped during cleanup;
  the task-owned HTTP preview was stopped and its temporary plaintext fixture
  credentials were removed.
- Supplementary probes and private logs stayed outside the repository under
  `%TEMP%\cfci-student-verification-20261118-47743909`. No application/test source
  file was added to implement the probes.

Environment actually used: PHP **8.5.12**, Node **26.13.0**, npm **11.20.0**,
MariaDB **10.11.16** through Laravel's MySQL driver, and headless Chrome through
Playwright for runtime checks.

## 2. Implementation summary and reviewed files

The implementation extends the existing Laravel/Breeze session authentication
and policy architecture. It does not add a guard, role table, registration
endpoint or general-purpose account/role-management interface.

| Implementation file | Review result and source evidence |
| --- | --- |
| `app/Models/User.php` | `STUDENT_ROLE` is lowercase `student` at line 15. Existing operational/technical/staff sets at lines 17–24 match HEAD. `student()` at line 63 reuses the existing one-to-one relationship. `hasAnyRole()` at line 73 uses strict membership comparison; `isStudentOnly()` at line 80 separates the Student landing page from explicit staff memberships. |
| `app/Policies/StudentPolicy.php` | `viewSelf()` at line 12 requires Student membership. `viewOwnProfile()` at line 17 also checks the record's `user_id`. `viewProtectedInformation()` at line 22 preserves staff access and permits the owner. Inherited staff directory/write permissions were not broadened. |
| `app/Http/Controllers/StudentProfileController.php` | Lines 14–17 authorize the portal, select through the authenticated user's `student()` relationship and check ownership. No query or route ID selects the profile. |
| `app/Http/Controllers/Auth/AuthenticatedSessionController.php` | Lines 27–33 retain authentication, session regeneration and intended redirects, changing only the default Student destination. |
| `app/Http/Controllers/DashboardController.php` | Lines 16–18 redirect Student-only users before staff analytics are built. Other dashboard/table changes belong to earlier work and were not attributed to the Student role. |
| `routes/web.php` | Lines 28–30 add the private, authenticated, ID-less Student GET route. Existing account settings remain at lines 101–103. |
| `resources/views/student/profile.blade.php` | Lines 1–14 render the read-only linked profile or a safe unlinked state, with an account-settings link and no official-record edit action. |
| `resources/views/layouts/navigation.blade.php` | Lines 12–18 add the permitted Student link; the same navigation list is reused on mobile. The role-label mapping recognizes `student`; the user menu links to the own-profile page. Staff links still depend on existing Gates. |
| `resources/views/components/records-page.blade.php` | Adds optional eyebrow text while retaining the existing staff default. |
| `resources/views/scholars/profile-details.blade.php` | Reuses escaped Blade output and protected-field policy checks; permits suppression of irrelevant staff-history guidance on the Student page. Student rendering supplies an empty audit-history collection. |
| `app/Console/Commands/LinkStudentAccount.php` | Lines 23–50 lock, validate, link and audit within a transaction. The unchecked result of `firstOrCreate()` at line 40 causes SR-01 under a noncanonical existing membership. |
| `app/Console/Commands/AuditStaffAccess.php` | Recognizes Student memberships and reports Student counts/missing links. Existing inventory and staff-role categories remain. |
| `tests/Feature/Auth/StudentRoleTest.php` | Covers Student login, ownership, missing links, official-record denial, privilege injection, staff compatibility and registration restrictions. The 44-route mutation-denial matrix begins at line 217. |
| `tests/Feature/Auth/StudentAccountLinkTest.php` | Covers canonical membership reuse, idempotence, conflicting links, credential preservation and audit-failure rollback. It does not contain the MySQL mixed-case regression exposed by SR-01. |
| `tests/Feature/Auth/OperationalRoleSecurityTest.php` | Student was removed from the obsolete unknown-role matrix; dedicated Student tests replace that coverage. Existing staff checks remain. |
| `tests/Feature/Auth/StaffAccessAuditTest.php` | Uses an actually unsupported membership for unknown-role reporting, rather than treating canonical Student as unknown. |

Additional existing architecture reviewed:

- `database/migrations/2026_11_11_000000_create_scholarship_foundation_tables.php:22`
  defines role memberships and the composite unique constraint; line 38 defines
  the nullable, unique Student-to-User FK with restricted deletion/update.
- `app/Models/Student.php:25` exposes `user_id` to trusted model operations, but
  current HTTP requests do not accept it as an assignable student-link field.
  Its encrypted/hidden identifier configuration remains at lines 36–44.
- `config/auth.php` retains the `web` session guard and Eloquent User provider.
  `app/Http/Requests/Auth/LoginRequest.php:45` retains credential verification and
  rate limiting; roles and identifiers are not authentication credentials.
- `app/Policies/RecordPolicy.php:12`, the academic/application policies, and
  `app/Policies/FinancialRecordPolicy.php:9` retain staff-only business access.
  Financial write requests authorize through
  `app/Http/Requests/FinancialRecordRequest.php:11`.
- `app/Http/Requests/ProfileUpdateRequest.php:17` accepts only account name/email;
  `app/Http/Controllers/ProfileController.php:31` fills only validated fields on
  the authenticated User. Student links and memberships are not accepted there.
- `bootstrap/app.php:7` registers web/console routing. `route:list --path=api`
  found no registered API routes. Direct JSON requests to real web endpoints
  were tested; this is not a claim that a separate REST API exists.

## 3. Verified permissions and functional scenarios

The following results apply to canonical lowercase memberships. An explicitly
multi-role account retains its separately granted staff permissions, as the
reference specifies; Student membership itself does not grant those permissions.

| Scenario / action | Executed result | Evidence |
| --- | --- | --- |
| Student login with valid credentials | PASS; default destination is `/student/profile`, session rotates | `StudentRoleTest.php:34`; real browser login |
| Invalid credentials / repeated attempts | PASS; invalid password denied and existing throttle enforced | `StudentRoleTest.php:51`, `:63`; `LoginRequest.php:45` |
| Correct account association | PASS; the authenticated account's FK relationship selects the record | `StudentProfileController.php:15`; Student-role tests and supplementary JSON probe |
| Own profile and protected identifiers | PASS; own information visible, other-student private fields absent; `no-store, private` response | `StudentPolicy.php:17`; `StudentRoleTest.php:92`; browser check |
| Other student's record / query tampering | PASS; foreign query IDs do not change selection; staff detail URLs denied; unsupported Student ID path is 404 | `StudentRoleTest.php:120`; supplementary JSON probe and browser check |
| Administrative directories, details, financial pages, reports and evidence | PASS; Student-only requests denied | Existing policies; `StudentRoleTest.php:191` |
| Direct JSON requests / alternate API-shaped path | PASS; authenticated JSON requests retain policy denial; `/api/students` is 404 | Supplementary `test_json_requests_cannot_change_role_link_or_official_records`; runtime HTTP checks |
| Role/link injection through own account settings | PASS; roles, links and other accounts remain unchanged | `ProfileUpdateRequest.php:17`; `ProfileController.php:31`; `StudentRoleTest.php:160` and JSON/browser probes |
| Official student-profile writes | PASS; staff record writes are 403; `/student/profile` has no PATCH action | `RecordPolicy.php:27`; `StudentRoleTest.php:120`, `:217` |
| CSRF on real HTTP requests | PASS; missing-token PATCH returns 419; valid-token forged staff write returns 403 | Isolated runtime browser/HTTP check |
| Missing link / email matching another record | PASS; safe empty state, no automatic email-based claim | `StudentRoleTest.php:138`; `student/profile.blade.php:8`; browser check |
| Missing Student role despite a link | PASS; portal authorization denied | `StudentRoleTest.php:149`; `StudentPolicy.php:12` |
| Duplicate / dangling FK association | PASS; both SQLite and MySQL reject invalid persistence | Existing migration constraints; supplementary `test_database_rejects_duplicate_and_dangling_account_links` |
| Linked-account deletion | PASS; retained-record restriction leaves account/link intact | `StudentRoleTest.php:181`; existing FK and `ProfileController.php:45` |
| Existing staff login and authorized actions | PASS; all five staff role branches retained; broader workflow/security suites passed | `StudentRoleTest.php:258`, `OperationalRoleSecurityTest.php:31`, full regression suite |
| Explicit Student plus staff membership | PASS; intentional role union preserved | `StudentRoleTest.php:269`; `User.php:80` |
| Public registration / HTTP role provisioning | PASS; unavailable | `StudentRoleTest.php:279`; unchanged `routes/auth.php` and console-only provisioning |
| Stored HTML in profile fields | PASS; Blade output escaped, no other-record data included | Profile-details partial; supplementary output-escaping probe |
| Desktop/mobile navigation and logout | PASS; Student link visible, staff links absent, no 390-pixel horizontal page overflow, logout blocks profile access | Existing Student tests and fresh Chrome check; zero JavaScript exceptions observed |
| Mixed-case existing Student membership on MySQL | **FAIL; command returns success but profile remains 403** | SR-01 |

Database snapshots in the repository's 44-route denial test verified no account,
role, academic or financial mutations from those rejected requests. These were
isolated test records, not the application's existing records.

## 4. Student account provisioning procedure

Provisioning remains a trusted-console operation:

1. A trusted operator establishes an existing login account and an existing
   student record, and verifies that they represent the same intended person.
   Account creation remains separate; no public registration was added.
2. Check that neither is linked elsewhere and review any existing memberships.
   Until SR-01 is fixed, explicitly check for case/whitespace variants of the
   Student membership; do not rely solely on a case-insensitive SQL match.
3. Run the following only for the intended development/test fixtures or as an
   approved operational provisioning action:

   ```sh
   php artisan sms:link-student "<existing-account-email>" "<existing-student-number>"
   ```

4. Verify the stored membership is exactly lowercase `student`, the unique FK
   points to the intended User, login opens the correct profile, and staff access
   is denied for a Student-only account. A successful command exit alone is not
   sufficient while SR-01 exists.
5. Repeat canonical linkage to check idempotence: one Student membership and no
   duplicate link audit event. Conflicting/missing associations must fail without
   partial changes. Existing credentials and other memberships must remain intact.
6. Use the read-only `sms:audit-access` aggregate report to check missing links;
   inspect noncanonical membership values separately when diagnosing SR-01.

The existing `sms:grant-role` still permits only Coordinator/Registrar grants.
There is no HTTP route to call either console command. The local credential
inventory intentionally contains test credentials (`docs/users/system-users.md:12`
includes the Student entry); no values are repeated here. That development
inventory is not an account-provisioning API or a production secret store.

## 5. Database migration verification

No Student-specific schema migration is required: the original schema already
models both membership uniqueness and the optional one-to-one account link.
The review found no need to duplicate a role catalog or add a second link column.

Fresh application migrations executed successfully on isolated SQLite and
MariaDB databases. The existing migration/rollback preservation test also passed
on MariaDB, exercising preservation of synthetic authentication/session and
unrelated records inside a database created by that test. Invalid link and
duplicate-association checks passed on both engines.

This did not apply migrations to, roll back, or reset the configured application
database. Its migration status and production deployment were not changed.
SR-01 is a value-comparison/provisioning defect, not a missing FK migration.

## 6. Commands and actual results

All results below were executed on 2026-11-18. Runs overlap; totals should not be
added as distinct test coverage. Private logs/JUnit XML were retained outside the
repository; credentials and tokens are omitted from this report.

`$ReviewDir` below denotes the temporary review directory from section 1. SQLite
commands explicitly selected `APP_ENV=testing`, `DB_CONNECTION=sqlite`,
`DB_DATABASE=:memory:` and an unused temporary `APP_CONFIG_CACHE` path. Temporary
MySQL configuration forced the newly created `_test` database and an empty
`DB_URL`; it never targeted the configured application database.
The recorded MySQL review database was removed after the checks. Re-running
these commands requires a new isolated database and regenerated temporary
configuration; do not redirect them to the application database.

| Executed check | Actual result |
| --- | --- |
| `php artisan test --compact --log-junit "$ReviewDir\focused.xml" tests/Feature/Auth tests/Feature/ProfileTest.php` | **82 passed**, 707 assertions; 25.76 s; exit 0 |
| `php artisan test --compact --log-junit "$ReviewDir\full-sqlite.xml"` | **530 passed, 11 skipped**, 6,962 assertions; 278.30 s; exit 0 |
| `php vendor/bin/phpunit --configuration "$ReviewDir\sqlite.phpunit.xml" --log-junit "$ReviewDir\probe-sqlite.xml" "$ReviewDir\ReviewStudentRoleTest.php"` | **4 passed, 1 skipped**, 23 assertions; the collation-specific probe intentionally requires MySQL; exit 0 |
| `php vendor/bin/phpunit --configuration "$ReviewDir\mysql.phpunit.xml" --log-junit "$ReviewDir\existing-mysql.xml" tests/Feature/Auth/StudentRoleTest.php tests/Feature/Auth/StudentAccountLinkTest.php tests/Feature/Database/ScholarshipMigrationTest.php tests/Feature/Database/ScholarshipFoundationTest.php` | **63 passed**, 496 assertions; 81.928 s; exit 0 |
| Native MySQL concurrency command below | **11 passed**, 146 assertions; 94.409 s; exit 0 |
| `php vendor/bin/phpunit --configuration "$ReviewDir\mysql.phpunit.xml" --log-junit "$ReviewDir\probe-mysql.xml" "$ReviewDir\ReviewStudentRoleTest.php"` | **4 passed, 1 failed**, 25 assertions; exit 1; failure is SR-01 |
| `php vendor/bin/pint --test` with the 14 reviewed Student-related PHP/model/controller/command/route/test files | Passed; formatting was checked, not rewritten |
| `php -l` for the reviewed PHP files and all migration files | **35 files checked, 0 failed** |
| `npm run build` | Passed; Vite 7.3.6 compiled 61 modules; 5.23 s |
| `node --test tests/js/data-tables.test.js` | **3 passed**, 0 failed; existing frontend regression checks |
| `php artisan route:list --path=student -v` | Confirmed one GET/HEAD Student route with `web`, `auth` and private/no-store cache middleware |
| `php artisan route:list --path=api` | No matching routes; expected absence, not a failed authorization test |
| Isolated Chrome/HTTP runtime harness | Passed all recorded checks: Student login, own-only data, query-ID isolation, private caching, CSRF, forged-role JSON requests, mobile navigation, logout, unlinked state, Coordinator access; **0 JavaScript exceptions** |
| `git diff --check` | Passed |

The native concurrency invocation used the isolated MySQL configuration:

```powershell
php vendor/bin/phpunit --configuration "$ReviewDir\mysql.phpunit.xml" --log-junit "$ReviewDir\mysql-concurrency.xml" tests/Feature/Database/BatchNumberConcurrencyTest.php tests/Feature/Database/BillingRosterConcurrencyTest.php tests/Feature/Database/AllocationApprovalConcurrencyTest.php tests/Feature/Database/FinancialPostingConcurrencyTest.php tests/Feature/Database/PayrollPreparationConcurrencyTest.php tests/Feature/Database/RosterMembershipConcurrencyTest.php tests/Feature/Database/ScholarshipConcurrencyTest.php tests/Feature/Database/SignedAcknowledgmentConcurrencyTest.php
```

### Failed tests

The only observed failing assertion was the supplementary MySQL test
`test_link_command_success_means_student_profile_access_on_mysql_case_insensitive_data`.
It expected HTTP 200 after the link command returned success, but observed 403.
The normal repository suites had no failing tests in this review.

### Skipped, blocked and unexecuted checks

- The full SQLite suite skipped 11 native MySQL cases. **All 11 were subsequently
  executed on MariaDB and passed**; they are not unresolved environment blocks.
- The supplementary SQLite run skipped its MySQL collation probe. That probe
  was subsequently executed on MariaDB and **failed**, as recorded above.
- No configured TypeScript, PHPStan/Psalm or separate application type-check/lint
  script was found. A formal type-check command was therefore **not executed**;
  PHP syntax, Pint, build and browser checks are not represented as type checking.
- Production/HTTPS deployment, real outbound mail delivery, other browser
  engines/physical devices and the credentials of existing real accounts were
  **not tested**. Runtime accounts were synthetic and isolated.
- A dedicated simultaneous two-operator race test for the new Student link
  command was **not executed**. Existing database race tests passed, but should
  not be mistaken for that specific new-command coverage.
- Student Profile Management write functionality was **not implemented or
  verified**; it remains outside the approved scope pending review.

## 7. Confirmed defect: SR-01

**Severity:** Medium — provisioning correctness and audit accuracy.
**Status:** Reproduced; not fixed.
**Precondition:** An existing account has a noncanonical membership such as
`Student` on a case-insensitive MySQL/MariaDB role column.

### Reproduction and observed result

In a newly created MariaDB review database using `utf8mb4_unicode_ci`:

1. Create a synthetic User with an existing membership whose stored value is
   `Student`, and a separate unlinked synthetic Student record.
2. Run `sms:link-student` for that account and record.
3. Check both the stored membership value and actual profile authorization.

The executed probe recorded:

```json
{
  "driver": "mysql",
  "command_exit": 0,
  "role_values": ["Student"],
  "sql_matches_lowercase_student": true,
  "recognized_student_membership": false,
  "profile_status": 403,
  "link_created": true
}
```

These are test-state observations, not credentials or existing user data.

### Source evidence and root cause

1. `config/database.php:57` defaults MySQL connections to
   `utf8mb4_unicode_ci`. The executed SQL comparison confirmed that stored
   `Student` matched a query for lowercase `student`.
2. `LinkStudentAccount.php:40` calls:

   ```php
   $membership = $user->roles()->firstOrCreate(['role' => User::STUDENT_ROLE]);
   ```

   The SQL lookup returns the existing mixed-case membership. It does not create
   or normalize a canonical membership. `UserRole.php:10` declares fillable role
   data but has no normalization mutator.
3. `User.php:77` deliberately checks roles with strict comparison:

   ```php
   in_array($membership->role, $roles, true)
   ```

   Consequently `StudentPolicy.php:14` rejects this account's portal access.
4. `LinkStudentAccount.php:37` has already associated the record. Lines 41–48
   record the link audit with a hard-coded canonical role value, while lines
   61–63 unconditionally report success. The command does not verify that the
   resulting stored membership is recognized by the authorization layer.

The strict policy is correctly denying a noncanonical role; **the defect is the
provisioner's successful outcome and committed link despite not granting the
promised access**. This is not an observed cross-student access bypass or grant
of staff privileges. Canonical lowercase memberships passed both database runs.

### Recommended fix, not applied

- Define an explicit policy for noncanonical existing Student memberships:
  either reject them with a clear actionable error and roll back the link, or
  canonicalize the Student membership through an approved, audited transaction.
- Before returning success, require the resulting membership to have the exact
  canonical value and verify the Student-access postcondition. Do not leave a
  partial successful link/audit state if that postcondition fails.
- Preserve strict authorization and all staff-role behavior. Do **not** make
  every role comparison case-insensitive or blindly normalize staff roles; doing
  so could grant privileges to memberships currently treated as unrecognized.
- Add a committed MySQL-collation regression test for this case, including the
  existing `Student` value, an unlinked record, command outcome, stored value,
  ownership access and audit outcome. Retain canonical/idempotence/conflict
  tests and run them on SQLite and MySQL. Consider whitespace variants under
  the same explicit policy rather than assuming database equality is identity.

No schema migration is automatically required for the fix. Any existing-data
normalization should be a separate approved correction, not a silent part of
this verification.

## 8. Step-by-step manual acceptance checklist

Use a fresh isolated development/test database and synthetic accounts. Do not
use these steps to reset the configured application database, alter real
credentials or test financial posting on retained school records.

1. Prepare two Student accounts (A and B), their separate student records, an
   unlinked Student account, and the required staff fixtures. Have a trusted
   operator link A/B using the console procedure above. Keep credentials in the
   private testing channel; do not put them in the verification report.
2. Log in as A through the existing login form. Verify the default destination,
   own record, own protected fields, Student role label and own-profile links.
   Verify staff navigation and aggregate dashboard data are absent.
3. Request `/student/profile?student_id=<B-record-id>&user_id=<B-user-id>`.
   A's record must still be selected. Check `/scholars/<B-record-id>` is 403,
   `/student/profile/<B-record-id>` is 404, and no B private fields are returned.
4. Request staff directories, academic verification/evaluations, billing,
   financial records, private evidence and report/export routes directly.
   Student-only requests must be denied even when links are manually typed.
5. Repeat representative requests with JSON and the authenticated session.
   Without CSRF, an unsafe web request must fail CSRF validation. With valid
   CSRF, forged staff requests must still be denied by authorization.
6. Submit forged `role`, `roles`, `user_id`, `student_id` and another User ID to
   account settings while retaining valid own-account fields. Verify only
   permitted own account fields can change; roles, linkage and the other User
   remain unchanged. Official student-record edits must remain unavailable.
7. Log out and request the Student page again. It must require login. Test an
   incorrect password and the existing rate limit on a disposable fixture;
   use the returned retry interval before attempting a successful login again.
8. Log in as the unlinked account. Verify the informational state reveals no
   unrelated record, including when an email happens to match a student record.
   A linked account without Student membership must be denied portal access.
9. Verify the nullable FK, unique user link and unique exact membership using
   isolated fixtures. Conflicting links must fail without reassignment. Repeat
   a canonical link command and confirm no duplicate membership/audit event.
10. Reproduce SR-01 on an isolated case-insensitive MySQL database. After an
    approved fix, require either an explicit safe rejection with rollback or
    approved canonicalization followed by working own-profile access. A success
    message followed by 403 is not acceptable.
11. Log in separately as Coordinator, Registrar and the existing supported test/
    legacy roles. Check their original allowed pages and representative allowed
    operations on synthetic records, plus their existing approval/configuration
    restrictions. Explicit multi-role accounts must retain their documented
    role union; Student membership alone must not reproduce that access.
12. At mobile width, open navigation, follow My profile and inspect field wrapping.
    Review this report and the proposed defect fix before authorizing Student
    Profile Management. Retest ownership and server-owned role/link fields when
    that later feature is designed; do not accept arbitrary profile payloads.

## 9. Final verdict and next action

**FAIL.** The normal Student role paths and executed staff-regression checks
passed, but SR-01 disproves complete and consistent provisioning under the
supported database configuration. No application code was changed to conceal or
repair the failure. No environment block prevented reaching this verdict.

Review SR-01, approve and implement an appropriately scoped fix in a separate
task, then rerun the regression and collation checks. Student Profile Management
remains on hold until these verification results have been reviewed.
