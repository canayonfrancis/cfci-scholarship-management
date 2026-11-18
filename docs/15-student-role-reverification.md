# Student account provisioning: independent reverification

**Review date:** 2026-11-18, Asia/Manila  
**Final verdict: PASS — SR-01 is resolved on the tested MySQL/MariaDB configuration.**

## 1. Scope and method

Reviewed the current working tree, including untracked implementation files,
against all three required references:

- [Student role implementation](15-student-role-implementation.md)
- [Student role verification and original SR-01 failure](15-student-role-verification.md)
- [Student provisioning fix](15-student-provisioning-fix.md)

The repository was already dirty; its HEAD was `3ade32a`. The review therefore
examined the working implementation rather than treating HEAD alone as the fix.
Previous reports' PASS/FAIL counts were not accepted as new execution evidence.
Two new QA harnesses were written outside the repository: one independently
checks provisioning/authorization, and the other invokes the real Artisan
entrypoint in a separate process and tests its committed result through login.
The existing repository suite was also re-executed.

Application code, repository tests, migrations and authentication configuration
were not edited. The requested report is the only intended repository addition.
Student Profile Management and a provisioning interface were not implemented.

### Environment and isolation

- PHP **8.5.12**, PHPUnit **11.5.56**, Node **26.13.0**, npm **11.20.0**.
- Database server: **MariaDB 10.11.16 through Laravel's `mysql` driver**.
  Both the configured application's inspected role column and the isolated
  test column use **`utf8mb4_unicode_ci`**. This verifies the affected deployed
  database family/collation; it is not an Oracle MySQL certification.
- A newly generated `cfci_student_reverify_<random>_test` database was created
  without `IF NOT EXISTS`. All writes, migrations, fixture creation and test
  resets targeted this new database or subordinate databases created by the
  existing guarded race/migration tests. No retained application data was
  used as a test fixture.
- The external PHPUnit configuration uses absolute bootstrap/test paths and
  forces `APP_ENV=testing`, `DB_CONNECTION=mysql`, the generated database name,
  empty `DB_URL`, and the affected collation. A task-specific unused
  `APP_CONFIG_CACHE` path prevents cached application database settings from
  redirecting the tests. Credentials are not copied into this report.
- The real CLI child explicitly receives the isolated connection settings;
  the parent asserts the generated database naming pattern and transaction
  level zero before running it. Synthetic test credentials stay private.
- PHP suites ran sequentially to avoid interference through shared fake
  evidence storage. Source fingerprints and read-only application database
  snapshots were taken before execution; preservation results follow below.

## 2. Code review findings

| Area and source evidence | Review conclusion |
| --- | --- |
| `app/Console/Commands/LinkStudentAccount.php:25` | Account and Student rows are locked inside a transaction with three deadlock attempts. Missing records and conflicting associations are rejected before role changes (`:28`, `:31`). |
| `LinkStudentAccount.php:38`, `:98` | Stored memberships are inspected in PHP after locking the selected account's rows. The predicate is ASCII lowercase plus PHP edge-trim, limited to Student variants; it does not rely on SQL equality or globally normalize staff roles. |
| `LinkStudentAccount.php:40`, `:54` | Multiple Student variants fail without deletion. A single variant is updated in place; a missing membership creates exactly lowercase `student`; an existing canonical membership is reused. |
| `LinkStudentAccount.php:59` | Changed access/linkage is audited. Corrections include the original stored role. A canceled audit is rejected. Valid repeats do not create an audit. |
| `LinkStudentAccount.php:71` | Fresh persisted records must contain exactly one eligible membership with exact canonical bytes, the requested association and permission through the actual ownership Gate. A failed postcondition rolls back and cannot reach the success message (`:93`). |
| `app/Models/User.php:15`, `:73`; `app/Models/UserRole.php:10` | Canonical role remains `student`; authorization still uses strict membership comparison. No global role-normalization mutator was introduced. Git comparison confirms the existing staff-role sets and strict comparison are preserved. |
| `app/Policies/StudentPolicy.php:12`, `:17`; `app/Http/Controllers/StudentProfileController.php:14` | Both membership and ownership are required. The portal selects through the authenticated account's relationship, not request IDs. Staff directory/write checks remain separate. |
| `database/migrations/2026_11_11_000000_create_scholarship_foundation_tables.php:22`, `:38` | Existing composite membership uniqueness and the nullable, unique account-to-Student FK remain. No additional migration or duplicate role catalog is needed for this fix. |
| `app/Http/Requests/Auth/LoginRequest.php:45`; `app/Http/Controllers/Auth/AuthenticatedSessionController.php:27`; `app/Http/Requests/ProfileUpdateRequest.php:17` | Existing credential authentication, session regeneration and intended redirects remain. Account settings accept name/email, not role or association assignment. |

The old `firstOrCreate` lookup described by SR-01 has been removed from the
Student command. Case-insensitive SQL equality is no longer treated as proof
of exact canonical role identity. The strict authorization check was retained.

The new committed `StudentProvisioningRegressionTest` and
`StudentAccountLinkConcurrencyTest` were reviewed, including their failure
injection and two-process locking checks. They were not rewritten for this
review.

## 3. Original MySQL failure scenario: fresh execution evidence

The independent probe recreated the original preconditions: an existing
synthetic account with one literal **`Student`** membership and a separate,
unlinked Student record on the case-insensitive role column.

Observed during this review:

```json
{
  "driver": "mysql",
  "server": "10.11.16-MariaDB",
  "role_collation": "utf8mb4_unicode_ci",
  "initial_role": "Student",
  "initial_sql_matches_lowercase": true,
  "initial_strict_student_role": false,
  "initial_profile_status": 403,
  "command_exit": 0,
  "stored_role_values": ["student"],
  "recognized_student_membership": true,
  "profile_status": 200,
  "correct_link": true,
  "membership_id_preserved": true,
  "membership_created_at_preserved": true,
  "credentials_unchanged_by_command": true
}
```

The single audit recorded `before_state.role = Student`, the original null
link, and `after_state.role = student` with the intended User ID. Repeating
provisioning preserved hashes of all fixture User, membership, Student and
audit rows. Existing credentials then authenticated through the login endpoint
and selected the correct profile; foreign profile/administrative requests
remained denied.

The original precondition was successfully retested; the old outcome of
**success followed by a stored `Student` role and profile 403 did not recur**.
This conclusion comes from the new execution, not the fix report's assertion.

### Separate-process commit and login check

An additional QA test used no outer test transaction and ran the actual command
in a child process:

```sh
php artisan sms:link-student "<synthetic-existing-email>" "<synthetic-student-number>"
```

It observed exit **0**, persisted lowercase membership and the intended link
after reconnecting the parent's database connection. Login subsequently
authenticated and `/student/profile` returned **200** with the correct record.
A repeated real CLI call returned **0** without row changes. A conflicting real
CLI call returned **1** without row changes or a success message. Requests to
another student's staff profile and the financial administration page returned
**403**. The test explicitly asserted transaction level **0**, so this check
establishes visibility of the committed child-process transaction.

## 4. Scenario results

| Requirement / scenario | Actual result and evidence |
| --- | --- |
| Canonical lowercase handling | PASS; new membership and existing lowercase membership both produce one exact `student` row and the intended authorized profile. Independent valid-association provider and committed link tests. |
| Existing capitalized membership | PASS; original `Student` row is canonicalized in place and audited; prelinked `Student` and mixed-case/trim variants also pass. Independent original-case test and valid-association provider. |
| Account-to-Student association | PASS; exact intended FK/profile checked; neither another account's nor another student's existing association can be reassigned. |
| Success corresponds to authorized access | PASS; exact role, relationship, actual login and HTTP 200 verified. An independent Gate-denial injection forced exit 1 and rollback despite otherwise valid input, confirming that policy denial cannot be reported as success. |
| Repeated provisioning | PASS; both in-process and real CLI repeats leave the complete fixture User/role/Student/audit state unchanged. |
| Invalid provisioning | PASS; missing account, missing Student, Student owned elsewhere, account linked elsewhere, ambiguous variants and accent-equivalent SQL alias each return 1, omit the success message and preserve all four fixture table snapshots. |
| Missing role / missing link | PASS; linked account without Student membership is 403; canonical unlinked account shows the safe empty state without claiming an email-matching record. |
| Other private profiles | PASS; forged query IDs still select the current user's profile; foreign name, Student number and protected identifier are absent; ID path is 404; direct foreign staff detail route is 403. |
| Administrative access / privilege injection | PASS; direct JSON reads and writes remain 403; account-settings payloads cannot grant roles, transfer links or modify another User. Student dashboard redirects to the own-profile page. |
| Other memberships / users | PASS; explicit Coordinator membership and unrelated users' `Student` rows remain unchanged. Capitalized `Administrator` remains unrecognized. |
| Existing roles and scholarship workflows | PASS; the full MySQL suite passed all 557 tests. This includes all five staff-role login/permission branches, operational-role restrictions, scholarship lifecycle and financial evidence consistency tests. |

The independent QA suite comprises **14 cases**: original SR-01; four valid
associations; six invalid associations; actual-policy-denial rollback; missing
role/link isolation; and repaired-account ownership/privilege-injection checks.
The separate-process boundary suite adds **one** committed-transaction case.

## 5. Actual commands and results

`$QADir` below is the private evidence directory
`%TEMP%\cfci-student-reverification-20261118-51adfb02`. All results in this table
are new executions for this review. Overlapping suite results are not summed as
distinct coverage.

| Command | Actual result |
| --- | --- |
| `php vendor/bin/phpunit --configuration "$QADir\mysql.phpunit.xml" --log-junit "$QADir\independent-mysql.xml" "$QADir\IndependentStudentProvisioningTest.php"` | **14 passed**, 135 assertions, 0 failures/errors/skips, exit 0; 11.205 s. |
| `php vendor/bin/phpunit --configuration "$QADir\mysql.phpunit.xml" --log-junit "$QADir\cli-boundary.xml" "$QADir\StudentProvisioningCliBoundaryTest.php"` | **1 passed**, 25 assertions, 0 failures/errors/skips, exit 0; 2.802 s. Runs real Artisan child processes for success, repeat and conflict. |
| `php vendor/bin/phpunit --configuration "$QADir\mysql.phpunit.xml" --log-junit "$QADir\full-mysql.xml"` | **557 passed**, 7,262 assertions, **0 failures, 0 errors, 0 skips**, exit 0; **7 min 7.935 s**. |
| `php vendor/bin/pint --test app/Console/Commands/LinkStudentAccount.php tests/Feature/Auth/StudentProvisioningRegressionTest.php tests/Feature/Database/StudentAccountLinkConcurrencyTest.php` | PASS, exit 0; formatting checked without rewriting files. |
| `php -l` on the same three PHP files | 3 checked, 0 syntax failures. |
| `npm run build` | PASS, exit 0; production build completed in 2.00 s. |
| `node --test tests/js/data-tables.test.js` | 3 passed, 0 failed/skipped, exit 0. |
| `git diff --check` | PASS, exit 0. |

Selected JUnit evidence within that full MySQL run, all with zero failures,
errors or skips:

| Existing repository suite | Passed cases | Assertions |
| --- | ---: | ---: |
| `StudentAccountLinkTest` | 9 | 54 |
| `StudentProvisioningRegressionTest` | 13 | 117 |
| `StudentRoleTest` | 20 | 277 |
| `StudentAccountLinkConcurrencyTest` | 3 | 36 |
| `OperationalRoleSecurityTest` | 11 | 252 |
| `ScholarshipLifecycleTest` | 6 | 1,119 |
| `FinancialEvidenceConsistencyTest` | 6 | 80 |
| `ScholarshipMigrationTest` | 1 | 22 |

The three Student link races cover the same association, a competing Student
record and a competing account. The original MySQL mixed-case regression and
the accent-collation case both executed successfully; neither was skipped.
Existing migration/rollback preservation, billing, academic, financial, report
and other native concurrency cases also ran in the full suite.

Evidence retained outside the repository includes the two QA harnesses, forced
test configuration, JUnit XML, private command logs,
`original-case-evidence.json`, `cli-boundary-evidence.json`, initial application
table snapshots and source fingerprints. No existing account credentials or
private production profile fields are reproduced here.

The isolated test database is disposable; before repeating these commands,
create a new `_test` database and regenerate the external configuration. Do not
reuse a removed database name or redirect the suite to retained application
records. The full suite's test resets affect only data created for this review.

## 6. Unresolved defects, limitations and preservation

**Unresolved confirmed defects: none within this review's scope. Failed tests:
none. Blocked required tests: none.** All independent QA checks and the complete
repository MySQL suite passed. The previously confirmed SR-01 condition no
longer produces false success followed by denied profile access.

Final preservation checks:

- All **354 pre-existing files** in the starting source/configuration/test/
  migration/documentation inventory matched their original SHA-256 values,
  including `.env` and the already-dirty implementation/table-control files.
  No existing application or repository test code was changed. The only new
  repository source/documentation file is this report. Build output and private
  temporary QA artifacts are separate from application source edits.
- All **41 existing application tables** matched their original row counts and
  sorted-row SHA-256 snapshots, including accounts, memberships, students,
  audits and retained business records. No application data remediation,
  provisioning, reset or deletion was performed.
- The newly created review database was dropped only after the test processes
  completed, using its saved generated name, a strict naming-pattern check and
  exclusion of the configured application database. Subordinate race/migration
  databases were cleaned by their owning tests. No user application server or
  existing database service was stopped.

Intentional boundaries and checks not executed:

- Multiple Student variants are rejected rather than consolidated. Non-ASCII
  spellings equated by the database are not automatically granted Student
  access. These are documented safe failure paths, not unresolved SR-01 cases.
- No new global data correction or schema migration was applied. Trusted
  operators must still verify the intended account/person association before
  linking. The command cannot infer identity from a matching email.
- Oracle MySQL, other collations, production deployment, real-user credentials,
  live browser/CSRF behavior, outbound mail and HTTPS were not retested.
  Authentication/authorization requests executed through Laravel's HTTP test
  kernel on the isolated MySQL-driver connection. The real console process
  boundary was separately exercised.
- SQLite was not rerun for this MySQL-specific reverification. No prior SQLite
  result is presented as a new PASS.
- No standalone TypeScript/PHPStan/Psalm type-check command is configured in the
  inspected manifests. None was executed or claimed as passing.
- Student Profile Management remains outside scope and on hold for separate
  review; this verification does not authorize implementing it.

## 7. Final verdict

**PASS.** The original case-insensitive MySQL failure scenario was freshly
recreated and successfully retested. A literal `Student` membership is corrected
to exact lowercase `student` in the same row, linked to the intended Student,
accurately audited and usable through actual authenticated profile requests.
Real CLI execution also demonstrated a committed, independently visible result.
Repeat and invalid provisioning preserve consistent state; unauthorized access
and privilege changes remain denied. Existing roles and workflows passed the
executed regressions.

This verdict closes **SR-01 for the tested application configuration**. It does
not extend to the unexecuted platforms/checks listed above or authorize Student
Profile Management work.
