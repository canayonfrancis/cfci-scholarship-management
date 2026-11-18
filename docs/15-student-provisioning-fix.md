# Student provisioning defect fix (SR-01)

**Date:** 2026-11-18, Asia/Manila  
**Final verdict: PASS for SR-01 on the tested MySQL/MariaDB configuration.**

References read in full:
[Student role implementation](15-student-role-implementation.md) and
[Student role verification](15-student-role-verification.md). This report
supersedes their provisioning behavior/results for SR-01; their historical
results remain historical. Student Profile Management and a provisioning UI
remain outside this change.

## 1. Original defect and reproduced root cause

Before editing the command, SR-01 was reproduced twice using synthetic data in
a newly created MySQL-driver database on **MariaDB 10.11.16**, with
`utf8mb4_unicode_ci`. The committed regression first failed with HTTP 403 after
a successful command exit. An independent probe recorded the full state:

| Observation | Before fix | After fix, same executed probe |
| --- | --- | --- |
| Command exit | 0 | 0 |
| Stored role | `Student` | `student` |
| SQL lookup for `student` matches | true | true |
| Strict Student membership recognized | false | true |
| `/student/profile` response | 403 | 200 |
| Intended account/student link exists | true | true |

The root cause is disagreement between SQL equality and application role
identity. The original `LinkStudentAccount.php:40` used
`$user->roles()->firstOrCreate(['role' => User::STUDENT_ROLE])`. On the tested
case-insensitive column, this reused a row containing `Student`. It did not
replace its stored value. The command then committed the link and an audit
claiming lowercase `student`, and returned success without checking access.

Source evidence in the current tree:

- `config/database.php:57` defaults MySQL to `utf8mb4_unicode_ci`. The live
  column inspection and isolated SQL assertions confirmed that collation's
  actual equality behavior, rather than assuming it from configuration.
- `app/Models/User.php:15` defines canonical `student`;
  `User.php:73` uses strict `in_array(..., true)` membership checks.
  `app/Models/UserRole.php:10` has no normalization mutator.
- `app/Policies/StudentPolicy.php:12` requires that exact membership;
  `StudentPolicy.php:17` additionally requires ownership.
  `app/Http/Controllers/StudentProfileController.php:14` authorizes and resolves
  through the authenticated account's relationship.
- `database/migrations/2026_11_11_000000_create_scholarship_foundation_tables.php:22`
  defines the unique `(user_id, role)` membership constraint; line 38 defines
  the nullable, unique `students.user_id` FK. The MySQL unique membership index
  follows column collation, so adding another lowercase membership is not a
  valid repair for an existing case-equivalent row.
- `tests/Feature/Auth/StudentProvisioningRegressionTest.php:32` is the committed
  reproduction: SQL matches `Student`, strict membership rejects it, and a
  successful link must produce an authorized profile and canonical stored row.

Strict authorization was correct and has been preserved. This was a
provisioning/postcondition and audit-accuracy defect, not an observed
cross-student authorization bypass.

## 2. Implemented correction and modified files

| File | Change |
| --- | --- |
| `app/Console/Commands/LinkStudentAccount.php` | Replaces collation-dependent role lookup with inspection of locked membership values; canonicalizes one Student variant in place, rejects ambiguity, audits corrections, checks persisted ownership and the actual policy before commit. |
| `tests/Feature/Auth/StudentProvisioningRegressionTest.php` | Adds 13 regression cases, including MySQL mixed-case and accent-collation behavior, targeted repair, ambiguity, conflicts, canceled writes, and rollback after a failed postcondition. |
| `tests/Feature/Database/StudentAccountLinkConcurrencyTest.php` | Adds three real MySQL two-process races: identical association, competing student, competing account. |
| `docs/15-student-provisioning-fix.md` | This diagnosis, remediation procedure, actual validation and limitations. |

Command behavior:

1. Existing account/student row locks and conflict checks remain first
   (`LinkStudentAccount.php:25`). Missing records or conflicting associations
   fail before membership changes.
2. All memberships belonging to the selected account are locked and inspected
   in PHP (`:38`), avoiding SQL's case/accent equality for role identity.
   Eligibility is explicitly `strtolower(trim($membership->role)) === 'student'`
   (`:98`): ASCII case variants and PHP's default edge-trim characters only.
   Other role names, including capitalized staff memberships, are unchanged.
3. Zero matching memberships creates exactly `student`; one canonical
   membership is reused; one noncanonical Student variant is updated in place
   (`:54`). Its membership ID and creation timestamp are preserved. There is
   no role catalog to seed and no delete/reinsert operation.
4. Multiple Student variants fail explicitly (`:40`). For example, MySQL can
   store both `student` and a distinct leading-space variant. The command does
   not choose or delete either membership. Non-ASCII collation aliases such as
   an accented spelling are not automatically canonicalized; any resulting
   unique-index collision fails and rolls back.
5. Changed linkage, created membership, or corrected capitalization writes one
   `student_account_linked` audit (`:59`). A correction includes the original
   stored role in `before_state`; `after_state` contains canonical `student`.
   Auditing failure/cancellation aborts the transaction. A valid repeated link
   performs no membership/link write and creates no duplicate audit.
6. After writes and model events, fresh records must show exactly one eligible
   membership, the exact canonical string, the requested one-to-one link, and
   permission through `Gate::forUser(...)->allows('viewOwnProfile', ...)`
   (`:71`). Failure returns a nonzero outcome and rolls back, including the
   audit. Only a committed, verified transaction reaches the success message.
   Unexpected database/audit exceptions also abort rather than report success.

No User, Student or policy model, authentication guard, login handler, navigation,
HTTP route, staff grant command or scholarship workflow was changed. The
command still links **existing** accounts and records; it does not create
credentials or expose public registration. The new-account test scenario
creates a synthetic account through the existing User model first, then links
it through this command.

## 3. Database inspection and migration requirements

The configured application database was inspected read-only. Actual results:

- Role column: `utf8mb4_unicode_ci`; existing membership and Student link unique
  indexes were inspected.
- Exact stored memberships: one `administrator`, one `coordinator`, one
  `registrar`, one `student`.
- Noncanonical Student variants: **0**; accounts with multiple normalized
  Student memberships: **0**; duplicate non-null Student account links: **0**.

No existing-data correction was needed or performed. No schema migration,
global role renaming, collation change, account reset or record deletion is
required. Existing uniqueness and FK constraints remain in force. Fresh
migrations and the existing migration/rollback preservation test were exercised
only in databases created for testing.

After all runs, before/after row counts and SHA-256 snapshots of the configured
application database's `users`, `user_roles`, `students` and `audit_events`
matched exactly. No existing records in those tables were changed. Only the
newly created task database was dropped during final cleanup, guarded by its
saved generated name, strict prefix/suffix check, and inequality with the
configured application database name. Race/migration databases were cleaned by
their owning tests; SQLite storage was in memory.

### Targeted data remediation procedure

Use this procedure only for a verified intended account/student pairing. Merely
having a student record does not authorize a login account or establish identity.

1. Deploy the fixed command and take the normal operational backup. Confirm the
   intended environment/database and its unique constraints. Do not run test
   resets against a retained application database.
2. Inventory exact stored role bytes, rather than grouping by a case-insensitive
   role value. This read-only MySQL diagnostic avoids exposing credentials:

   ```sql
   SELECT HEX(role) AS role_bytes, COUNT(*) AS memberships
   FROM user_roles
   GROUP BY HEX(role);
   SHOW FULL COLUMNS FROM user_roles LIKE 'role';
   SHOW INDEX FROM user_roles;
   SHOW INDEX FROM students;
   ```

3. In a trusted console, inspect the intended User's memberships and its
   `student()` relationship, and the intended Student's `user_id`. Compare
   membership values using the command's exact PHP predicate above. Verify
   identity through authorized school records. An SQL `WHERE role = 'student'`
   or the aggregate access audit alone cannot prove canonical stored bytes.
4. Stop on missing records, an association with another person, multiple
   Student variants, or an unrecognized collation alias. Resolve identity or
   membership ambiguity through a separate reviewed correction with retained
   audit evidence. This fix deliberately performs no automatic consolidation
   or deletion of ambiguous rows.
5. For a single eligible variant or a missing canonical membership with a
   valid intended pairing, run the existing trusted command:

   ```sh
   php artisan sms:link-student "<verified-existing-email>" "<verified-student-number>"
   ```

   The same operation repairs an already-linked `Student` membership, or
   establishes the link and canonical membership together. It changes only
   that selected account's Student membership and the intended link, inside one
   transaction. Do not execute a global `UPDATE user_roles` normalization.
6. Require exit 0, exactly `student` in the stored row, the intended unique FK,
   and the corresponding audit with original role when repaired. Log in
   privately with the account's existing credentials and check own-profile
   access. Repeat the command: the membership ID and audit count must remain
   unchanged. A nonzero outcome must be investigated, never reported as access
   successfully provisioned.

## 4. Regression coverage and permissions

Every scenario below executed on the isolated MySQL-driver database:

| Required scenario | Result and evidence |
| --- | --- |
| Provision a newly created Student login account | PASS; fresh User/Student fixtures, canonical membership, correct link and authorized profile in `StudentAccountLinkTest::test_trusted_link_is_idempotent_and_audited_without_changing_credentials`. |
| Link an existing account to a valid record | PASS; canonical reuse and existing-link/missing-role cases in `StudentAccountLinkTest`. |
| Existing `Student` membership | PASS; original reproduction now authorizes HTTP 200; same membership ID, original role audited, credentials unchanged, in `StudentProvisioningRegressionTest:32`. |
| Existing lowercase `student` membership | PASS; reused without duplication in `StudentAccountLinkTest`; valid repeats do not add audits. |
| Repeated provisioning | PASS; repeated canonical and repaired links, plus simultaneous identical commands. |
| Conflicting account/student associations | PASS; both directions denied without reassignment/canonicalization, including two-process competing requests. |
| Student authentication | PASS; repaired account logs in through the existing `/login` endpoint; existing invalid-password/throttling/session/logout tests also pass. |
| Correct student's profile | PASS; own protected information and private/no-store response; existing ownership tests and new repaired-membership test. |
| Another student's private profile | PASS; ownership Gate rejects it; direct staff detail URLs are 403; query IDs cannot change selected profile in `StudentRoleTest`. |
| Administrative access and privilege changes | PASS; staff routes and 44 mutation routes denied, forged roles/links ignored, registration unavailable in `StudentRoleTest`. |
| Existing roles | PASS; Coordinator, Registrar, Administrator, Finance and Viewer login/permissions, staff restrictions, and explicit multi-role union in existing role/security tests. Capitalized `Administrator` remains unrecognized after repair. |
| Failure atomicity | PASS; missing/conflicting links, ambiguous variants, MySQL collation collisions, failed/canceled audit or required model writes, and changed postconditions roll back. |

Additional cases exercise `STUDENT`, leading/trailing spaces, repair of an
already-linked account, preservation of unrelated users' `Student` rows, and
preservation of all other memberships. The three concurrency tests use separate
PHP processes and hold an uncommitted winning transaction until the competitor
is observed waiting; final links, memberships and single audit are asserted.

## 5. Actual commands and results

All checks below were executed for this task. `$FixDir` denotes the private
temporary directory `%TEMP%\cfci-student-fix-20261118-2cfea3ec`. It contains
JUnit/log evidence and the isolated configuration, not committed credentials.
Runs overlap in coverage; counts must not be added as unique coverage.

MySQL used a newly generated `cfci_student_fix_<random>_test` database, created
without `IF NOT EXISTS`, with the same role collation as the application. The
external PHPUnit configuration copies the repository suites with absolute
bootstrap/test paths, forces `APP_ENV=testing`, `DB_CONNECTION=mysql`, the new
database name, and empty `DB_URL`. It uses existing connection configuration
without copying credentials into the report. An unused `APP_CONFIG_CACHE` path
prevents cached application configuration from redirecting test queries.
Race/migration tests create and clean their own guarded `_test` databases.

| Executed command/check | Actual result |
| --- | --- |
| `php vendor/bin/phpunit --configuration "$FixDir\mysql.phpunit.xml" --filter test_mysql_capitalized_student_membership_is_repaired_before_reporting_success --log-junit "$FixDir\before-mysql.xml" tests/Feature/Auth/StudentProvisioningRegressionTest.php` **before application fix** | **1 failed**, 4 assertions, exit 1; 9.292 s. Command returned 0, expected profile 200 but observed 403. This is the intentional defect reproduction. |
| Independent original-case probe **before fix** | **1 failed**, 2 assertions, exit 1; saved the before-state observations in section 1. |
| `php vendor/bin/phpunit --configuration "$FixDir\mysql.phpunit.xml" --log-junit "$FixDir\link-mysql.xml" tests/Feature/Auth/StudentProvisioningRegressionTest.php tests/Feature/Auth/StudentAccountLinkTest.php` | **21 passed**, 164 assertions, exit 0; 10.151 s. This first run preceded the additional accent-alias regression. |
| MySQL authorization/migration/concurrency command below | **131 passed**, 1,018 assertions, exit 0; 78.003 s. Includes all three Student command races; preceded the additional accent-alias test. |
| `php vendor/bin/phpunit --configuration "$FixDir\mysql.phpunit.xml" --log-junit "$FixDir\final-provisioning-mysql.xml" tests/Feature/Auth/StudentProvisioningRegressionTest.php "$FixDir\ReviewStudentRoleTest.php"` | **18 passed**, 142 assertions, exit 0; 10.939 s. Includes all 13 final committed regression cases plus 5 independent probes. Original probe now observes canonical role and HTTP 200. |
| `php artisan test --compact --log-junit "$FixDir\full-sqlite.xml"` with forced SQLite `:memory:` configuration | **541 passed, 16 skipped**, 7,049 assertions, exit 0; 133.06 s. The skipped tests require MySQL (14 locking/concurrency cases and 2 collation cases). |
| `php vendor/bin/phpunit --configuration "$FixDir\mysql.phpunit.xml" --log-junit "$FixDir\full-mysql.xml"` | **557 passed**, 7,262 assertions, **0 failures, 0 errors, 0 skips**, exit 0; 6 min 27.075 s. Includes all 16 cases skipped on SQLite, the original-defect regression, all Student link races and existing scholarship workflow/security tests. |
| `php vendor/bin/pint --test app/Console/Commands/LinkStudentAccount.php tests/Feature/Auth/StudentProvisioningRegressionTest.php tests/Feature/Database/StudentAccountLinkConcurrencyTest.php` | Passed. |
| `php -l` on the three changed/added PHP files | 3 checked, 0 syntax failures. |
| `npm run build` | Passed; production Vite build, 1.63 s. |
| `node --test tests/js/data-tables.test.js` | 3 passed, 0 failed. |
| `git diff --check` | Passed; existing CRLF conversion notices only. |

The broader MySQL command was:

```powershell
php vendor/bin/phpunit --configuration "$FixDir\mysql.phpunit.xml" --log-junit "$FixDir\regression-mysql.xml" tests/Feature/Auth tests/Feature/ProfileTest.php tests/Feature/Database/ScholarshipMigrationTest.php tests/Feature/Database/ScholarshipFoundationTest.php tests/Feature/Database/StudentAccountLinkConcurrencyTest.php
```

The independent probe was copied into the task's temporary directory from the
previous verification harness and re-executed; previous test results were not
reused. Its five cases cover JSON role/link denial, exact-role rejection,
ownership/output escaping, FK constraints and the original collation failure.

Only the two intentional **pre-fix** reproductions failed. All post-fix runs
above passed. The 16 SQLite skips were subsequently executed successfully on
the full MySQL-driver run; none remains blocked. There is no configured separate
type-check command; unexecuted platform/browser checks are listed below.

The temporary MySQL database has been removed. Re-running a recorded MySQL
command requires creating a new isolated database and regenerating the external
configuration with its name. Do not redirect these tests to the application
database. SQLite execution explicitly selected `APP_ENV=testing`,
`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` and the unused configuration-cache
path. Test suites were run sequentially to avoid interference from shared fake
evidence storage.

## 6. Manual verification instructions

Use a fresh isolated MySQL/MariaDB development database and synthetic records.
Keep passwords and identifiers in the private testing channel.

1. Create two existing synthetic login accounts A/B through the existing trusted
   setup, with distinct Student records. Give A one literal `Student` membership
   and leave its Student record unlinked. Also prepare a canonical Student
   account and the existing staff-role fixtures. Do not enable registration.
2. Confirm A's SQL lookup for lowercase `student` matches but its strict Student
   permission does not. Run the fixed link command for A's verified record.
   Require exit 0, stored lowercase role in the original membership row, correct
   FK and one audit containing the original `Student` role.
3. Log in as A using existing credentials. Confirm `/student/profile` is 200 and
   shows only A's official information. Query B's IDs on that URL: selection
   must remain A. `/scholars/<B-id>` must be 403; a Student-profile ID path must
   be 404. Confirm staff directories/reports and writes remain denied.
4. Repeat the command with exactly A's association; require no extra membership,
   audit or link changes. Repeat for a prelinked `Student` membership and an
   existing lowercase membership. Verify unrelated roles and credentials.
5. Attempt A-to-B-record and B-to-A-record links. Require failure, original links
   retained, no membership correction and no additional success audit.
6. On an isolated fixture only, create both `student` and a leading-space
   ` Student` membership. Require the explicit ambiguity failure and no deletion
   or link creation. Use automated tests for injected write/audit failures.
7. Verify all existing staff fixtures can log in and perform their original
   authorized actions on synthetic records. A capitalized staff role must not
   gain permissions just because Student provisioning ran.
8. Run the committed MySQL regression and concurrency suites using a configuration
   that forces a newly created `_test` database. Review JUnit results and audit
   evidence before accepting the repair. Never run `migrate:fresh` against the
   configured application database.

## 7. Remaining limitations and final review

- MySQL verification uses the installed **MariaDB 10.11.16 through Laravel's
  MySQL driver** and the affected collation. Oracle MySQL itself, other
  collations, production deployment and real-user credentials were not tested.
- No TypeScript, PHPStan/Psalm or other standalone type-check command is
  configured in the project manifests. None was claimed executed; PHP syntax,
  formatting and production build are separate checks.
- No new browser walkthrough or outbound mail/HTTPS test was run for this
  console-only fix. HTTP authentication, profile and authorization requests
  executed through Laravel's test kernel on the isolated MySQL connection.
- Multiple variants and non-ASCII SQL-equivalent names are intentionally rejected
  rather than merged or globally renamed. Identity verification and trusted
  console access remain operational requirements.
- Student Profile Management and account-provisioning UI work remain on hold
  and were not implemented.

Final review covered the entire modified command, both new test files, the
persisted-state/authorization checks, transaction and audit failure paths,
case-sensitive role boundaries, and test database guards. A SHA-256 comparison
against the task's 333-file starting inventory found only the intended command
modified; the other **332 pre-existing files were unchanged**, including the
existing dirty table-control/Student implementation files and `.env`. The only
new source/documentation files are the two regression suites and this report.
Final Pint, PHP syntax and whitespace checks passed. Existing application
database preservation was verified separately as described in section 3.

**PASS:** SR-01 was reproduced before the fix and no longer reproduces after it
on the affected MySQL-driver/collation configuration. Successful provisioning
now establishes canonical membership, the intended association, an accurate
audit when changed, and actual own-profile permission within one transaction.
No unresolved defect was observed in the executed post-fix checks. This verdict
closes this provisioning defect within the tested environment; Student Profile
Management remains subject to separate review and authorization.
