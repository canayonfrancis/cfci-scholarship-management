# Student Account Management: independent verification

> **Historical snapshot:** After this verification, the user requested removal
> of existing-account linking from the web interface. Its form, controller action
> and POST provisioning route have now been removed. Linking-specific manual
> instructions/results below describe the previously reviewed snapshot. This
> scope change does not claim to resolve SAM-01/SAM-02, approve delivery, apply
> the lifecycle migration or change this report's recorded FAIL verdict. See the
> [interface change note](17-student-account-management-interface.md) for current scope.

**Review date:** 2026-11-18, Asia/Manila  
**Final verdict: FAIL.** Confirmed response-handling and credential-log failures
remain. New-account onboarding and real credential delivery are also **BLOCKED**.
Student Profile Management must remain on hold until review and resolution of
the applicable defects and prerequisites.

## 1. Executive summary

The existing-account interface, strict Student role/link handling, own-profile
authorization and database-backed deactivation/reactivation worked in the tested
environment. Original SR-01 remains resolved. Fresh repository execution passed
**659 tests / 8,188 assertions**, with no failures, errors or skips. Production
build, complete repository Pint check, PHP syntax and table JavaScript tests
also passed.

Passing repository tests did not establish complete acceptance. Eleven new
external adversarial tests produced **nine passes and two failures**:

- **SAM-01, Medium:** direct authorized PATCH requests receive a 302 redirect
  to a GET-only detail route. A real HTTP client follows it as PATCH and receives
  **405**, including when the requested deactivation has already committed.
- **SAM-02, High configuration/security risk, pre-existing:** public recovery
  for a synthetic Registrar followed the retained staff broker path and wrote a
  **usable password-reset bearer token** into the configured log transport.
  The independent probe redirected that transport to a private QA file, verified
  token validity without displaying it, and deleted the file. No real account,
  production log token or external email was used. The Student-specific delivery
  guard did block Student notifications as intended.

Real Chrome browser execution completed **31 positive workflow checks**, while
independently confirming SAM-01 as a failed protocol check. These were automated
browser interactions, not a human UAT sign-off. New User creation, receipt of a
real initial setup email and receipt of a real Student reset email are not
accepted: the approved D02 restriction and fixed delivery-readiness block remain.
The configured application database also lacks the lifecycle migration/columns;
only disposable QA databases were migrated.

**Application code, repository tests, migrations, configuration and prior reports
were not modified.** This report is the only intended repository addition.

## 2. References, method and environment

Reviewed current working source and these references, treating their previous
test counts as history rather than new evidence:

- [Independent Student role reverification](15-student-role-reverification.md)
- [Approved account-management design](16-student-account-management-design.md)
- [Shared provisioning service](16-student-account-provisioning-service.md)
- [Account-management interface](17-student-account-management-interface.md)
- [Student account lifecycle](17-student-account-lifecycle.md)

The design's subsequent approval record governs its historical proposal: exact
Registrar plus retained Test Administrator authority, explicit identity review,
protected staff/mixed-role targets, independent scholarship/account status, and
**no new account creation until real delivery is approved and tested**. The older
interface report predates lifecycle routes; its historical absence-of-lifecycle
statements are not descriptions of the current code.

The starting tree was already dirty/untracked, with HEAD `3ade32a`. The review
inspected the working implementation and took SHA-256 fingerprints of **382**
existing source/config/test/document files. Existing role/credential behavior was
also compared with HEAD where needed to distinguish retained issues from new
regressions.

Environment: PHP **8.5.12**, Laravel **12.69.2**, PHPUnit **11.5.56**, MariaDB
**10.11.16 via Laravel's `mysql` driver**, `utf8mb4_unicode_ci`. The full suite and
external PHP tests ran sequentially on a newly generated
`cfci_student_management_qa_<random>_test` database. The external PHPUnit config
forces testing environment, database, empty `DB_URL`, array cache/session drivers
and a private unused config-cache path. Native race/migration tests create their
own guarded disposable databases.

Browser QA used a different generated
`cfci_student_management_runtime_qa_<random>_test` database, actual database
sessions, active CSRF middleware and a unique cookie/cache prefix. Isolation is
set **before providers boot**, so the rate limiter cannot bind the application's
cache database. Static assets were fulfilled from the local production build to
avoid Windows development-server asset stalls; HTML/forms/CSRF/login/cookies and
mutations went through the real PHP application. Nine synthetic Users and 15
synthetic Students were seeded; the existing Scholars form added a sixteenth
fictional Student without creating a login account.

All private test credentials remained outside the repository. No credentials,
bearer tokens, password hashes or real Student identities are reproduced here.
Source-application inspection was SELECT/schema-only. No real records were used
as fixtures, migrated, reset, remediated or deleted.

## 3. Requirements traceability and functional results

`PASS` below is limited to the executed mechanism. A blocked notification or
existing-account link is not a successful new-account onboarding test.

| Requirement | Actual evidence | Result |
| --- | --- | --- |
| 1. Authorized Registrar login | Real Chrome login B01; existing operational-role tests. | PASS |
| 2. Student Account Management access | Registrar and Administrator navigation/details B02/B20; unauthorized roles denied B12/B27. | PASS |
| 3. Search an existing Student | Exact fictional Student number returned one row B06; repository search/sort/filter tests. | PASS |
| 4. Correct account status | Active-compatible `linked`, pending setup, inactive and restricted filters B05; null link displayed separately; actual flags inspected. | PASS |
| 5a. Provision access through an existing account | Nia's unlinked record explicitly linked after operator identity/password review B07–B09. No new User created. | PASS |
| 5b. Create a new Student login User | `createForActor()` returns `credential_delivery_not_approved`; Create new account is disabled. No successful creation path executed. | BLOCKED |
| 6. Correct User-to-Student relationship | Browser own-profile check plus independent DB check; conflicts in both directions rejected. | PASS |
| 7. Canonical lowercase `student` / SR-01 | Independently recreated SQL case-insensitive `Student` match, strict initial denial, same-row repair, correct profile 200 and unchanged repeat. Original 13 SR-01 tests also passed. | PASS |
| 8a. Initial token/password mechanism | Isolated synthetic pending account, hashed broker token, password policy, single use, expiry and activation tested through Laravel HTTP kernel; fake notification/transport cases ran. | PASS, adapter only |
| 8b. Student receives real setup instructions and completes onboarding | No approved/tested delivery channel, no new User creation, no real Student setup email. | BLOCKED |
| 9. Student login | Linked Nia and existing Enzo authenticated with their original privately generated passwords B12/B13. Pending/inactive accounts denied B28. | PASS |
| 10. Own profile only | Own number/address present; foreign address absent; query tampering cannot switch records; foreign staff detail/admin access 403 B12. | PASS |
| 11. Deactivate | Native form confirmation worked B14; sessions/tokens revoked; official status retained. Direct PATCH can commit but report 405: SAM-01. | PASS for native form; FAIL for direct PATCH response |
| 12. Inactive login denied | Correct-password attempt rejected B16. | PASS |
| 13. Existing session/protected actions denied | Remembered session denied B15; direct Student administrative PATCH 403; another Student's session stayed usable B17. | PASS |
| 14. Reactivate | Native form worked B18; pending flag remains independent in backend tests; no school-status update. Direct PATCH response issue also applies to this handler. | PASS for native form; SAM-01 applies |
| 15. Login after reactivation | Old cookie replay remained denied; fresh login with unchanged credentials succeeded B19. | PASS |
| 16a. Authorized Student reset request/readiness guard | Direct eligible request clearly blocked; no token/notification produced; blocked request audited B21 and DB postcheck. | PASS for safe block |
| 16b. Actual Student password-reset delivery | Disabled button and fixed readiness false; no verified outbound transport or mailbox receipt. | BLOCKED |
| 17. Management audit | One changed link, two deactivations/two reactivations including protocol reproduction, one blocked reset and the separate Scholar creation recorded; no credential fields found. | PASS for executed events |

Design A01/A03/A04/A07/A08/A11/A12 have substantial executed authorization,
canonicalization, lifecycle, migration and regression coverage. A02 (new pending
User creation) is blocked. A05/A06 cover broker behavior, two real lifecycle
locking races and fake delivery, **not** real outbound delivery or all conceivable
resend/email-change races. A10 fails for the retained staff log-mail path even
though the new Student UI/audit/delivery guards passed their checks.

## 4. Source-code findings and recommended fixes

### SAM-01 — PATCH redirect produces 405 after success or validation rejection

**Severity:** Medium. **Confirmed defect in current lifecycle HTTP responses.**

Source evidence:

- `routes/web.php:36` declares the detail URL as GET-only; `:40` declares PATCH
  lifecycle actions.
- `app/Http/Controllers/StudentAccountController.php:109`, `:115`, `:118` return
  default-status `to_route(...)` redirects from validation, unexpected-error and
  success branches of the PATCH handler. The returned status is **302**.

Fresh observed sequence, with no token/credential values exposed:

```text
PATCH /student-accounts/{fictional-student}/deactivate
  -> 302 Location: /student-accounts/{fictional-student}
  -> client follows as PATCH /student-accounts/{fictional-student}
  -> 405 Method Not Allowed
```

A valid mutation had actually committed the disabled flag before the final 405.
A protected target correctly failed validation, but its redirect also ended in
405 instead of displaying the intended error. A subsequent **GET** displayed the
appropriate state/error. Native forms use POST plus `_method=PATCH`, and the
browser followed their redirect as GET; that happy path passed. This is not an
authorization bypass or evidence that the protected staff account was disabled.

Root cause: the new PATCH actions reuse POST-style 302 redirects to a route that
does not accept PATCH. Recommend **303 See Other** for HTML mutation redirects,
or explicit JSON success/validation responses when JSON is requested. Keep
authorization, CSRF, identity checks, audit and transactions unchanged. Add a
real-client redirect-following test; a Laravel assertion that merely sees the
initial redirect does not catch this failure. External acceptance test
`test_direct_patch_mutation_must_redirect_with_get_semantics` remains **failed**
against the unchanged application.

### SAM-02 — retained staff password recovery exposes a live token to log mail

**Severity:** High configuration/security risk. **Pre-existing retained path;
not a new Student-role authorization regression.**

Source evidence:

- `app/Http/Controllers/Auth/PasswordResetLinkController.php:41–44` uses the
  guarded Student action for Student targets, but calls the ordinary broker for
  the other branch.
- `app/Models/User.php:73–80` guards Student credential delivery; its non-Student
  branch directly notifies `ResetPassword`.
- `config/mail.php:17`, `:73–75` support/default to `log`; read-only inspection
  confirmed the configured transport is currently `log`.
- Installed `vendor/laravel/framework/src/Illuminate/Mail/Transport/LogTransport.php:54`
  writes the rendered message through the logger. The standard broker message
  contains the bearer reset URL.

Independent reproduction created only a new fictional Registrar in the isolated
database and redirected the log mailer to a private QA file. The public existing
forgot-password request generated a message containing a token that
`Password::tokenExists()` confirmed was usable for that fixture. The file was
deleted immediately; no token value was printed or retained in this report.
No real mailbox delivery or real-account takeover was attempted.

HEAD comparison confirms the generic broker/log behavior predates this feature.
Student-specific readiness checks did prevent Student notification leakage in
the tested path. Nevertheless, the broad credential-exposure acceptance check
cannot pass while privileged recovery writes usable bearer credentials to logs.

Recommend preventing credential-bearing notifications through log/array/unsafe
fallback transports for **all affected recovery paths**, while preserving staff
authentication and authorization. Establish an approved real channel and retain
generic public responses. Have the authorized security owner assess actual log
access/retention and any historically exposed live tokens; this QA task neither
claims that real tokens previously leaked nor modifies/revokes real data.
External test
`test_public_staff_recovery_must_not_write_a_live_bearer_token_into_log_transport`
remains **failed**.

### OBS-01 — retained own-password update keeps an older reset token valid

**Confirmed behavior; Medium hardening observation for policy review.**
`app/Http/Controllers/Auth/PasswordController.php:16–25` verifies the current
password and writes the new hash, without the Student credential adapter's token
revocation. A synthetic active Student's password changed successfully, and its
earlier fixture reset token still passed `tokenExists()` afterward. The file is
unchanged from HEAD; this is **not** asserted as a newly introduced regression
or an independently specified acceptance failure.

Before enabling real Student delivery, review whether this existing self-service
path should revoke outstanding recovery tokens and other sessions under the
same locked Student credential policy. The observation test records the fact;
its passing assertion is **not** security approval of token retention. Broker
reset, deactivation and reactivation revocation did pass their separate tests.

### B01–B03 — unresolved completion/deployment blockers

- **B01: New User provisioning is unavailable.**
  `app/Actions/Accounts/LinkStudentAccount.php:47–58` returns a blocked result;
  current `LinkStudentAccountRequest` prohibits creation mode and supplied
  passwords. Existing account linking is not a substitute for a new User.
- **B02: Real Student credential delivery is unapproved/unverified.**
  `app/Services/Accounts/StudentCredentialDelivery.php:10` returns false;
  `StudentCredentials.php:39–42` blocks before token generation; account detail
  buttons are disabled at `resources/views/student-accounts/show.blade.php:80`.
  Changing SMTP configuration alone does not complete approval, implement the
  blocked creation path, or make hardcoded disabled controls operational.
- **B03: Configured application schema is not ready for rollout.** Read-only
  metadata found both lifecycle columns absent and the lifecycle migration not
  recorded. `StudentAccountDirectory` queries those fields; the migration must
  be reviewed/applied before accepting this installation for use. No existing
  account was used to probe the live UI. Only isolated schemas were migrated.

These blockers honor or expose the approved prerequisites; they are not reasons
to silently enable mail, create pending accounts or alter production records.

### Positive source review

The shared linking action remains the only linking implementation. Its locked
PHP membership checks and fresh ownership Gate reject false SR-01 success.
`StudentAccountProvisioningPolicy` allows exact `registrar`/`administrator`
actors and protects staff/unknown/mixed targets. `StudentAccess`, the login
callback, web middleware and Student policy share lifecycle checks. Lifecycle
flags are cast but not client-mass-assignable. User, Student, membership and
credential operations use the intended locks and transactional audits; token
consumption locks before validation. Navigation alone does not authorize writes.
No new public registration, arbitrary role editor, duplicate role catalog,
Student Profile editing or scholarship-approval gate was found.

## 5. Negative/security results

| Scenario | Executed result |
| --- | --- |
| Repeated existing-account provisioning | PASS: unchanged response; one membership/link/change audit. |
| Duplicate login email | PASS for actual MySQL unique constraint; uppercase duplicate insert rejected without row changes. New User creation UI/race remains BLOCKED. |
| Duplicate Student contact email | PASS: linking refused with `duplicate_contact_email`; no global contact-email migration/merge. |
| Missing/invalid Student or email | PASS: route-binding 404, missing service record failure, malformed email 422 and unchanged data. |
| Association conflicts in both directions | PASS in independent service probes and repository regressions; browser conflict error visible. |
| Invalid/expired/reused/inactive setup token | PASS in fresh MySQL HTTP-kernel probes; no unauthorized activation/password writes. Real email handoff remains BLOCKED. |
| Unauthorized interface/direct requests | PASS: Student, Coordinator, Finance and Viewer GET/PATCH rejected; guest/unknown-role coverage in repository suite. |
| CSRF | PASS: real missing-token PATCH received 419. |
| Student privilege escalation | PASS: injected role/link/lifecycle fields in own settings did not modify roles or associations; management payload role/password fields rejected. |
| Foreign private information | PASS: own-profile query tampering retained own record; foreign staff detail/admin access 403; foreign private address absent. |
| Unauthorized/privileged-target deactivation | PASS for authorization/state protection. Protected validation response has SAM-01's separate redirect failure. |
| Rollback/partial records | PASS: independent canceled-audit fault preserved User/Student/role/token/audit snapshots; full suite covered additional failure/postcondition cases. |
| Student UI/result/audit credential secrecy | PASS for tested paths: no password, hash, remember/reset token in management audit/view; Student issue guard produced no token. |
| Whole retained recovery log secrecy | **FAIL: SAM-02** for synthetic privileged staff recovery with log transport. |
| Direct authorized PATCH response | **FAIL: SAM-01**; mutation may commit before client sees 405. |
| Own-password change recovery-token policy | OBS-01 observed; retained legacy behavior, not misrepresented as accepted security. |

## 6. Fresh test commands and regression evidence

`$QADir` is `%TEMP%\cfci-student-management-qa-20261118-2394b9ce`. All following
results were executed in this review. Overlapping results are not summed into
unique coverage. The external harness/config/evidence remain outside the repo.

| Executed command/check | Actual result |
| --- | --- |
| `php vendor/bin/phpunit --configuration "$QADir\mysql.phpunit.xml" --log-junit "$QADir\full-mysql.xml"` | **659 passed, 8,188 assertions**, 0 failures/errors/skips, exit 0; **10 min 22.317 s**. |
| `php vendor/bin/phpunit --configuration "$QADir\mysql.phpunit.xml" --log-junit "$QADir\independent-mysql.xml" "$QADir\IndependentStudentManagementTest.php"` | **11 tests: 9 passed, 2 failed**, 96 assertions, 0 errors/skips, exit 1; JUnit case time 14.068 s. The two failures are SAM-01/SAM-02, not hidden or fixed. |
| `node "$QADir\browser-qa.cjs"` | 24 positive checks completed; exit 1 on protected PATCH response verification, whose final status was 405 (SAM-01). |
| `node "$QADir\browser-followup.cjs"` | Initial diagnostic also observed 405; final follow-up completed seven remaining workflow checks, exit 0, and separately recorded the **failed** PATCH protocol condition. Combined: 31 positive workflow checks, one confirmed protocol defect, zero JS page errors. |
| `php "$QADir\runtime-postcheck.php"` | 15 integrity/audit/credential checks passed: intended link, canonical role, unchanged passwords, final restored state, archived record retained, protected Coordinator untouched, no new User/token, expected audits and no credentials in audit JSON. |
| `php vendor/bin/pint --test` | Complete repository style check passed, exit 0; no formatter edits performed. |
| `php -l` over PHP files in app/bootstrap/config/database/routes/tests, excluding generated bootstrap cache | **241 checked, zero syntax failures**. |
| `npm run build` | Passed, exit 0; production Vite build **3.13 s**. |
| `node --test tests/js/data-tables.test.js` | Three passed, zero failures/skips, exit 0. |
| `git diff --check` | Passed, exit 0. |

No configured PHPStan/Psalm/TypeScript or separate frontend lint command was
found. Formal type checking was **not run/not supported by project scripts**;
syntax/Pint/build checks are not substitutes described as a type-check PASS.
No test was marked skipped in either executed PHP suite. Real email, Oracle
MySQL, all browser engines, production HTTPS/access-log redaction and school
human UAT were not certified.

Selected suites from the full JUnit file, all with zero failures/errors/skips:

| Existing area/suite | Tests | Assertions |
| --- | ---: | ---: |
| AuthenticationTest | 4 | 8 |
| PasswordResetTest / PasswordUpdateTest / EmailVerificationTest / ProfileTest | 4 / 2 / 3 / 5 | 8 / 8 / 6 / 21 |
| OperationalRoleSecurityTest / StaffAccessAuditTest | 11 / 19 | 252 / 66 |
| StudentRoleTest / StudentProvisioningRegressionTest | 20 / 13 | 277 / 117 |
| StudentAccountProvisioningServiceTest | 31 | 322 |
| StudentAccountInterfaceTest / StudentAccountLifecycleTest | 32 / 29 | 266 / 247 |
| StudentCredentialDeliveryTest | 5 | 34 |
| Console linking / actor linking / lifecycle concurrency | 3 / 3 / 2 | 36 / 39 / 18 |
| ScholarRecordsTest / ScholarInformationTest | 36 / 41 | 344 / 305 |
| ScholarshipRosterTest | 18 | 91 |
| ScholarshipLifecycleTest | 6 | 1,119 |
| ScholarshipReportsTest | 30 | 489 |

All 52 class suites, including billing, funding/payments/evidence, migration and
financial/academic/report workflows, executed in the complete run. Their passing
results establish supported repository regression coverage, not human acceptance
of every workflow or closure of newly found gaps outside existing assertions.

## 7. Beginner-friendly manual testing guide

### Safe preparation and execution labels

Use a fresh **isolated** QA database with reviewed migrations, never the retained
school database. An authorized QA operator must prepare existing test User
fixtures and provide their credentials privately. There is no new general
account-creation/role-management UI to invent here. Public registration is absent.
The review's temporary credentials and databases are removed after testing;
these examples are **not accounts usable on the live system**.

All Student data below was newly fictional for this review. `.test` emails are
deliberately non-deliverable and cannot prove email receipt. A future real-mail
test needs a school-approved controlled QA mailbox supplied by its owner.

| Fictional fixture | Student ID | Name | Contact/existing test login email |
| --- | --- | --- | --- |
| ALPHA | `SAM-QA-20261118-ALPHA-2394B9CE` | Nia Test Velasco | `sam-alpha-2394b9ce@example.test` |
| BETA | `SAM-QA-20261118-BETA-2394B9CE` | Enzo Test Duarte | `sam-beta-2394b9ce@example.test` |
| GAMMA | `SAM-QA-20261118-GAMMA-2394B9CE` | Mara Test Ibarra | `sam-gamma-2394b9ce@example.test` |
| DELTA | `SAM-QA-20261118-DELTA-2394B9CE` | Leo Test Santos | `sam-delta-2394b9ce@example.test` |
| EPSILON | `SAM-QA-20261118-EPSILON-2394B9CE` | Ada Test Moreno | `sam-epsilon-2394b9ce@example.test` |

ALPHA started with no Student/User link and an existing User whose membership
was literal `Student`. BETA was canonical/active but its **official Student
record was archived**. GAMMA required setup; DELTA was inactive. A separate Rhea
Test Navarro Student record was linked to a fictional Coordinator to test target
protection. EPSILON initially did not exist. Ten additional fictional unlinked
Students exercised pagination. No awards/approvals were required for these logins.

The following actual results identify their execution mechanism. **PASS means
the stated browser automation or technical check actually ran; no separate human
UAT session was completed.** Human repetition remains outstanding. A guide case
not exercised through the stated manual/browser steps is explicitly NOT TESTED
or BLOCKED, even if related backend tests passed. Numeric route IDs must be
copied from the selected fictional record's URL, not guessed/hardcoded.

### M01 — Registrar signs in

- **Role:** Registrar Staff.
- **Preconditions:** Existing isolated Registrar fixture; privately supplied credentials.
- **Menu/page:** `/login`.
- **Sample data:** QA Registrar login details supplied by the authorized operator; no password in this report.
- **Exact action:** Enter its email/password and submit the actual login form.
- **Expected:** Authenticated staff page with permitted navigation.
- **Actual:** Real Chrome login completed (B01).
- **Status:** **PASS — automated browser; human repetition not performed.**

### M02 — Find and inspect the account directory

- **Role:** Registrar.
- **Preconditions:** M01 and seeded fictional Student records.
- **Menu/page:** Primary navigation → **Student accounts**.
- **Sample data:** ALPHA Student ID above.
- **Exact action:** Enter the ID in **Search students**, click **Search**, inspect the one result; clear and test Account status filters, footer size 20 and header chevrons.
- **Expected:** Correct Student/status; one 10/20/50/100/200 selector below the table changes on selection; both sort directions work.
- **Actual:** Search/status/sort/pagination and single footer control verified (B02–B06).
- **Status:** **PASS — automated browser.**

### M03 — Link an existing login without creating a new User

- **Role:** Registrar.
- **Preconditions:** ALPHA unlinked; its existing test User has literal `Student`; account ownership reviewed.
- **Menu/page:** Student accounts → ALPHA → **Manage login**.
- **Sample data:** ALPHA existing email; reason `QA checked the fictional Student and existing account identity`.
- **Exact action:** Choose **Confirm password to continue** if shown, confirm your own operator password, enter **Existing login email** and review reason, check the ownership attestation, then **Link existing account**.
- **Expected:** Intended link, canonical lowercase role, unchanged credentials and accurate confirmation.
- **Actual:** Browser link succeeded; independent DB check found one canonical membership and correct link, with unchanged password (B07–B09).
- **Status:** **PASS — browser plus DB verification.**

### M04 — Reopen/repeat an already linked association

- **Role:** Registrar.
- **Preconditions:** M03; remain within confirmation window and respect retry limits.
- **Menu/page:** Student accounts → ALPHA → Manage login.
- **Sample data:** Same ALPHA association.
- **Exact action:** Reopen the page; observe **No account change needed**. For the duplicate-request check, have the QA operator replay the earlier authorized request; do not invent another visible link button.
- **Expected:** No duplicate User, membership or changed-link audit; explicit unchanged result.
- **Actual:** Authorized replay returned unchanged, one changed-link audit remained, fourth target attempt returned 429 (B10/B11 and DB check).
- **Status:** **PASS — technical replay and browser state.**

### M05 — Student signs in and sees only their own profile

- **Role:** Student ALPHA.
- **Preconditions:** M03; private existing credentials from QA operator.
- **Menu/page:** `/login` → **My profile** / `/student/profile`.
- **Sample data:** ALPHA login email and private password.
- **Exact action:** Sign in in a separate browser session; inspect own Student number/details. Try another Student's copied Scholars detail URL and a forged profile query ID.
- **Expected:** Own profile only; foreign private details absent; staff detail/admin access denied.
- **Actual:** Own profile worked, foreign address absent, foreign/admin requests 403 and query tampering did not switch records (B12).
- **Status:** **PASS — automated browser.**

### M06 — Deactivate an established Student account

- **Role:** Registrar.
- **Preconditions:** BETA logged in separately with Remember me; BETA canonical/active and official record archived.
- **Menu/page:** Student accounts → BETA → Manage login.
- **Sample data:** BETA current email; reason `QA suspend fictional login access only`.
- **Exact action:** Confirm your own password if prompted, review readonly login email, enter reason, check identity/review attestation and click **Deactivate Student access**.
- **Expected:** Inactive access, preserved official record/link/history, only target sessions/tokens revoked.
- **Actual:** Native form succeeded; archived record and credentials retained (B13/B14, DB check, nonempty financial-preservation repository tests).
- **Status:** **PASS — native browser form and backend checks.**

### M07 — Deny old sessions and correct-password login

- **Role:** Student BETA; comparison Student ALPHA.
- **Preconditions:** M06; keep BETA's previously authenticated/remembered context and ALPHA's independent context.
- **Menu/page:** BETA `/student/profile` and `/login`; ALPHA own profile.
- **Sample data:** Existing BETA credentials; no password reset.
- **Exact action:** Refresh BETA's old profile and attempt correct-password login. Refresh ALPHA's own profile separately.
- **Expected:** BETA cannot continue or log in; ALPHA remains unaffected.
- **Actual:** BETA old session and fresh correct-password login denied; ALPHA remained authorized (B15–B17).
- **Status:** **PASS — automated browser.**

### M08 — Reactivate without reviving old cookies

- **Role:** Registrar, then Student BETA.
- **Preconditions:** M06; existing credentials known privately.
- **Menu/page:** Student accounts → BETA → Manage login, then `/login`.
- **Sample data:** Reason `QA restore established fictional login`.
- **Exact action:** Review identity/email, enter reason, check attestation, click **Reactivate Student access**, then sign in afresh. QA operator also replays pre-deactivation cookies.
- **Expected:** Fresh login works; old session/remembered cookie does not revive; official archived state remains.
- **Actual:** Native reactivation and fresh unchanged-password login worked; old-cookie replay failed (B18/B19).
- **Status:** **PASS — automated browser.**

### M09 — Pending setup stays pending after reactivation

- **Role:** Registrar and Student GAMMA.
- **Preconditions:** Operator-seeded canonical GAMMA with setup required; no assumed working account-creation screen.
- **Menu/page:** Student accounts → GAMMA → Manage login.
- **Sample data:** GAMMA fixture and reviewed reason.
- **Exact action:** Deactivate/reactivate via the same forms, then try GAMMA login.
- **Expected:** Setup requirement retained; login still denied.
- **Actual:** Initial pending login denial was browser-tested; the full deactivate/reactivate pending sequence ran in backend tests, not in this manual/browser sequence.
- **Status:** **NOT TESTED — complete browser sequence; related automated backend tests passed.**

### M10 — Create only a fictional official Student record

- **Role:** Registrar.
- **Preconditions:** EPSILON absent; no required scholarship approval.
- **Menu/page:** **Scholars → Add scholar**.
- **Sample data:** EPSILON ID/name/email above; Record status **Active**; optional fields blank.
- **Exact action:** Fill Student ID, given/last name, optional middle name/email and record status, then **Add scholar**.
- **Expected:** Official Student record added; no login User automatically created.
- **Actual:** Ada's record was added; Student count became 16, User count stayed nine and its account association remained null (B22/DB check).
- **Status:** **PASS — automated browser.**

### M11 — New login User provisioning

- **Role:** Registrar.
- **Preconditions:** Existing EPSILON Student, no matching login User; approved delivery not available.
- **Menu/page:** Student accounts → EPSILON → Manage login.
- **Sample data:** EPSILON.
- **Exact action:** Inspect **Create new account** availability; do not substitute public registration or make a temporary password.
- **Expected:** Future completed onboarding creates an authorized pending account with secure setup. Current approved behavior is a clear block.
- **Actual:** Creation control is disabled; independent creation service returns blocked without writes. No successful new User creation was tested.
- **Status:** **BLOCKED — safe guard verified, happy path unavailable.**

### M12 — Real initial password setup

- **Role:** New Student, supported by Registrar.
- **Preconditions:** Approved real channel, authorized new pending User, controlled deliverable QA mailbox; currently unmet.
- **Menu/page:** Student account detail → **Send password setup link**; recipient's existing reset form.
- **Sample data:** A newly fictional Student and owner-supplied approved QA mailbox; `.test` fixtures cannot receive email.
- **Exact action:** Once approved/implemented, request instructions and use the privately received link to choose a password. Never copy links from logs.
- **Expected:** Time-limited single-use setup, no plaintext credentials, fresh login afterward.
- **Actual:** Button/readiness/new-User prerequisites blocked; no real setup email or browser mailbox handoff occurred. Isolated broker tests do not replace this step.
- **Status:** **BLOCKED.**

### M13 — Invalid, expired or reused setup link

- **Role:** Student.
- **Preconditions:** Approved isolated token fixture or private controlled-mail link prepared by QA; no log copying.
- **Menu/page:** Existing `/reset-password/{token}` form.
- **Sample data:** Fictional pending Student; invalid/expired/consumed QA token.
- **Exact action:** Submit a policy-compliant confirmed password with each invalid token case.
- **Expected:** Error; no activation, password change or privilege grant.
- **Actual:** Fresh independent HTTP-kernel and repository tests passed; these cases were not manually exercised in Chrome/mail.
- **Status:** **NOT TESTED — manual/browser; automated token checks passed.**

### M14 — Unauthorized account-management access

- **Role:** Student, Coordinator, Finance or Viewer.
- **Preconditions:** Separate existing isolated role fixtures, privately supplied credentials.
- **Menu/page:** Login, then direct `/student-accounts`; advanced operator tests its mutation endpoint with valid CSRF.
- **Sample data:** Fictional BETA/EPSILON record URL, copied by authorized tester.
- **Exact action:** Look for Student accounts navigation, try the direct page and direct deactivate request.
- **Expected:** No management navigation/authority; 403 for authenticated unauthorized requests.
- **Actual:** Student and all three staff-role branches were denied; their normal login remained usable (B12/B27).
- **Status:** **PASS — automated browser and direct HTTP.**

### M15 — Authorized Student password-reset initiation

- **Role:** Registrar or Test Administrator.
- **Preconditions:** Eligible linked active Student and recent password confirmation; real delivery still unavailable.
- **Menu/page:** Student accounts → BETA → Manage login → **Send password reset link**.
- **Sample data:** BETA confirmed login email/review reason.
- **Exact action:** Inspect disabled control; QA operator submits an authorized direct request to verify the server-side block.
- **Expected:** Completed delivery sends approved secure instructions; current behavior accurately reports unavailable delivery with no secret fallback.
- **Actual:** Direct request clearly blocked, no token generated, blocked request audited; actual delivery not tested (B21).
- **Status:** **BLOCKED — successful reset delivery; guard passed.**

### M16 — Protect privileged targets

- **Role:** Test Administrator.
- **Preconditions:** Fictional Rhea Student linked to the QA Coordinator.
- **Menu/page:** Student accounts → Rhea → Manage login.
- **Sample data:** Rhea fixture and current Coordinator account reference supplied only to authorized technical tester.
- **Exact action:** Inspect technical-review state; QA operator attempts direct deactivation and then loads the detail page with GET.
- **Expected:** Cannot disable staff through the Student module; credentials and staff authority remain intact.
- **Actual:** Coordinator remained enabled and logged in later; GET showed validation/technical review. Direct PATCH's final 405 is separately failed in M19.
- **Status:** **PASS — target protection only; the response defect is failed under M19.**

### M17 — Invalid email and conflicting associations

- **Role:** Registrar/Administrator.
- **Preconditions:** An unlinked fixture and an account already linked to ALPHA.
- **Menu/page:** Student accounts → select unlinked fixture → Manage login.
- **Sample data:** `invalid-email`, or ALPHA's existing linked email against EPSILON.
- **Exact action:** Have the authorized QA operator submit the invalid/conflicting identity as a CSRF-valid request from the selected form's test context. The native browser tooltip is outside this technical case.
- **Expected:** Validation/conflict error, no account transfer or partial writes.
- **Actual:** Direct malformed email returned 422, and conflicting account association returned the correct error. The native HTML invalid-email tooltip itself was not inspected (B08/B23).
- **Status:** **PASS — direct validation/conflict only. Native tooltip not tested.**

### M18 — Review management audit history

- **Role:** Authorized technical reviewer/Test Administrator.
- **Preconditions:** Earlier fictional transitions/link and read-only audit access approved.
- **Menu/page:** Student accounts detail shows state; **there is no new audit-history UI/menu**. Use approved read-only database tooling for `audit_events`.
- **Sample data:** ALPHA/BETA fixture associations and recorded reasons.
- **Exact action:** Match each changed transition to actor/time/reason/result; check repeat link has no extra changed-link event and no credential values.
- **Expected:** Accurate, minimal, credential-free audit; retained school history preserved.
- **Actual:** Independent DB checks passed, including the extra deactivate/reactivate used to reproduce SAM-01; one link, two of each transition and one blocked reset request were expected.
- **Status:** **PASS — technical read-only verification, not an invented UI test.**

### M19 — Direct PATCH client follows mutation redirect correctly

- **Role:** Authorized Registrar/Administrator; advanced QA operator.
- **Preconditions:** Disposable canonical linked fixture, recent confirmation and valid CSRF.
- **Menu/page:** Selected Student account detail plus direct PATCH action URL.
- **Sample data:** BETA test account and reviewed reason.
- **Exact action:** Submit a real PATCH with a redirect-following HTTP client, then inspect status independently through GET. Restore the fixture through its native form.
- **Expected:** Correct GET-following redirect or appropriate JSON; no misleading error after a committed change.
- **Actual:** Initial 302 followed as PATCH ended in 405; deactivation had committed. Native form restoration worked. External acceptance test remains red.
- **Status:** **FAIL — SAM-01.**

### M20 — Recovery log secrecy

- **Role:** Authorized QA/security operator using only a new fictional staff fixture.
- **Preconditions:** Separate isolated log target/database; no real account/log mutation.
- **Menu/page:** Existing `/forgot-password` form.
- **Sample data:** Newly created fictional QA Registrar, with its fixture email supplied by the authorized test operator.
- **Exact action:** Security operator submits recovery with output confined to a private log and checks for usable bearer credentials without exposing or copying them into a report.
- **Expected:** No usable credential in logs.
- **Actual:** Isolated recovery log contained a valid token and was deleted; no real email sent (SAM-02). This was an HTTP-kernel/security probe, not human UAT.
- **Status:** **FAIL — SAM-02.**

### M21 — Human scholarship, roster, billing and reporting regression

- **Role:** Test Administrator or the existing role authorized for each operation.
- **Preconditions:** Separate approved fictional academic/financial fixtures and their documented school rules; no real Student records or invented eligibility/amounts.
- **Menu/page:** Actual permitted navigation: **Scholarship programs**, **Scholars → Semester rosters**, **Billing batches**, **Scholarship reports**.
- **Sample data:** Fresh fictional Nia/Enzo records with appropriately prepared synthetic business fixtures supplied by the QA lead.
- **Exact action:** Follow the established program/roster/billing/report workflows and compare authorized outputs/history; do not create a new approval policy for this test.
- **Expected:** Existing permissions and scholarship/roster/billing/report behavior remain intact after account access changes.
- **Actual:** Full automated business regression suites passed. This complete human browser walkthrough was not performed.
- **Status:** **NOT TESTED — human UAT.**

## 8. Failed, blocked and unexecuted work

The two independent acceptance failures and initial browser failure are retained
as evidence; application code was not changed to turn them green. The follow-up
browser script records the observed 405 as a defect while checking remaining
workflows; observing the expected defect does not close it. Repository tests
remain green because they do not assert these two acceptance conditions.

New User creation, its concurrent creation/duplicate-email happy path, real
initial setup receipt, real Student reset receipt, infrastructure credential-URL
logging/HTTPS certification, configured-database rollout and school human UAT
remain blocked or not tested as described above. No SMTP success is claimed.
Private test credentials and bearer values are not a manual delivery workaround.

## 9. Preservation and cleanup

Final before/after verification matched **all 41 configured application
tables**, including sessions/cache, accounts/credentials, Students, roles,
password-reset tokens, audits and business history. This is fresh evidence for
this review; the earlier lifecycle report's session-table caveat is historical
and is not silently reused as this review's result.

All **382 pre-existing inventoried files matched** their starting hashes; this
report is the only repository addition. The review used
only `--test` formatting and read-only syntax inspection. Test/build/generated
artifacts and private external QA harnesses are separate from source changes.
Stopped only the QA preview process verified by its saved PID/unique router
path, removed the private synthetic credential fixture file, and dropped only
the two saved generated QA databases after exact name-pattern and configured-
database exclusion checks. The private credential-log probe file had already
been deleted. Cleanup and final fingerprint postconditions passed. No retained
records or user application/database services were reset or stopped.

Evidence outside the repo includes full/adversarial JUnit XML and logs,
`independent-observations.json`, `patch-redirect-finding.json`, browser result
files, synthetic fixture catalog, mobile capture, runtime audit/integrity checks,
configuration metadata and source/database fingerprints. Database reruns require
a fresh generated QA database/config, not pointing destructive test setup at
retained records.

## 10. Final verdict and review gate

**FAIL for complete Student Account Management acceptance.** SAM-01 is a
confirmed functional response defect; SAM-02 is a confirmed retained credential
exposure under current log-mail configuration. Their narrow scope, provenance
and reproduction are documented rather than described as a Student ownership or
role-escalation failure. OBS-01 requires explicit hardening/policy review.

Original SR-01, existing-account linking, actual own-profile boundaries, native
form lifecycle controls and supported repository regressions passed the executed
checks. That does not satisfy blocked new-account creation, approved real
credential delivery, the unapplied installation migration or unperformed human
acceptance. Fix/retest the confirmed failures, resolve the approved delivery and
provisioning prerequisites, review safe migration/deployment and assess OBS-01
before claiming full PASS. **Do not begin Student Profile Management until this
verification is reviewed and all blocking issues are resolved.**
