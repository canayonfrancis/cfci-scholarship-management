# 17 - Student account lifecycle and secure credentials

> **Initial password setup update (2026-11-19):** The user requested a dedicated Student password form. Authorized Registrar/Test Administrator users can now complete initial setup for an eligible linked pending Student from Manage login, without email. Saving a valid password clears the pending flag and verifies profile access atomically. See [implementation, layout and actual test evidence](22-student-initial-password-form.md). Earlier stage limitations below are retained as history.

> **Latest approved change (2026-11-19):** The user now permits pending account creation without email. Creation no longer requires mail readiness and sends no notification/token. Both password-link buttons are removed. Secure setup remains required before Student login; credential-delivery safeguards remain. See [current behavior and tests](21-student-account-creation-without-email.md). Earlier results below are historical and do not override this change.

> **Current-stage update (2026-11-18):** Create-only Student account provisioning and a dedicated receipt-verification mechanism are now implemented. Mail credentials remain blank at the user's request, so live creation/delivery remain blocked. The lifecycle migration was already applied and verified. See [current implementation and actual test evidence](19-scholar-account-creation-implementation.md). Earlier implementation results below are retained as historical scope. Web existing-account linking remains removed.

**Date:** 2026-11-18, Asia/Manila  
**Final verdict: BLOCKED for real initial-account setup and credential delivery.**
The Student lifecycle and broker-consumption implementation is available for
existing accounts. Actual outbound Student credential delivery and new User
creation remain blocked by the approved D02 decision; simulated notifications
are not evidence that email works.

## 1. Approved scope and reviewed architecture

Reviewed the [approved design](16-student-account-management-design.md),
[shared provisioning service](16-student-account-provisioning-service.md),
[account interface](17-student-account-management-interface.md) and their current
source. The historical proposals in the design are governed by its subsequent
approval record: Registrar Staff plus the retained Test Administrator exception;
explicit identity/email review; no staff/mixed-role management; two Student-only
lifecycle fields; no scholarship approval gate; recent password confirmation,
rate limits and allowlisted audit. New account creation must remain blocked until
real credential delivery is approved and tested.

This change reuses Laravel/Breeze's existing `web` guard, Eloquent User provider,
hashed password cast, password broker/database token repository, reset form,
notifications/events, password rules, session guard and audit table. It adds no
guard, registration system, independent token table, default password, role
catalog or Student Profile editing. The verified linking action/console command
remain the source of provisioning business rules and were not rewritten.

Current mail transport was inspected as **`log`**. It is not approved Student
credential delivery. User does not implement `MustVerifyEmail`; existing email
verification routes are preserved and password setup does not alter
`email_verified_at` or introduce a new mailbox-approval requirement.

## 2. Account states and migration

Additive migration:
`database/migrations/2026_11_18_000001_add_student_lifecycle_to_users.php`.

| Field | Definition | Existing-account default |
| --- | --- | --- |
| `users.student_setup_required` | Boolean; Student must complete initial password setup. | `false` |
| `users.student_access_disabled_at` | Nullable timestamp; Student access has been suspended. | `null` |

These fields are cast by User but **not mass assignable**. Public settings and
management input cannot set them directly. No existing password, role, Student
link, official record or scholarship state is backfilled or reinterpreted.

| State | Definition / behavior |
| --- | --- |
| No account linked | Existing Student has no User association. Explicit existing-account linking remains available. |
| Needs review | Missing/noncanonical/ambiguous Student membership or invalid association. Requires the existing linking/technical-review path. |
| Active | Canonical linked Student with setup not required and no disabled timestamp. The existing compatibility filter `linked` / **Linked and configured** represents this state. |
| Pending password setup | Canonical linked Student, setup required, not disabled. Login and protected Student access are denied. |
| Inactive | Disabled timestamp is present; takes precedence over pending setup. Login and protected Student access are denied. |
| Management restricted | Staff, unknown or mixed-role target is not manageable through this module; existing staff authority is retained. |

Reactivation clears only the disabled timestamp. It keeps any setup requirement,
password and canonical role/link, revokes obsolete credentials/sessions and
requires a fresh sign-in. It never approves a scholarship or changes enrollment,
roster, academic, billing or financial status. Archived Students retain the
approved independent read-only portal eligibility.

**Deployment requirement:** apply the additive migration before serving the new
directory/lifecycle code. The configured application database was **not migrated**
by this task. Migration and rollback were exercised only on isolated databases.
Do not roll back a live lifecycle migration casually: removing the flags removes
its access restrictions. A live rollback requires a separate operational review.

```powershell
# Run only against the intended deployment database after review/backup.
php artisan migrate --force
```

## 3. Authentication, protected sessions and transitions

`StudentAccess` centralizes lifecycle decisions and canonical association checks.
The existing login uses `Auth::attemptWhen` with that helper, retaining password
verification, generic login errors, five-attempt throttling, remember behavior,
session regeneration and intended redirects. Known staff memberships retain
their independent existing authentication even if Student capability flags are
set. A pending/disabled account without recognized staff authority remains denied
even when its Student membership or link has disappeared.

`EnsureStudentAccess` is appended to the existing **web** middleware group. It
reloads the authenticated User/roles on protected requests, including dashboard,
settings/password routes and existing private record/download routes. Denied
sessions are logged out locally and invalidated; browser requests go to login,
JSON requests receive 403. Logout remains usable. `StudentPolicy` additionally
requires enabled lifecycle for own-profile permission, retaining exact lowercase
membership and ownership checks. A staff capability is not globally disabled by
an unrelated Student flag.

`StudentAccountLifecycle::transition()`:

1. Rechecks the actor's approved Gate and validates the identity attestation,
   current login email and bounded reason.
2. Locks **User → Student → memberships**, rereads the intended association and
   requires exactly one literal lowercase `student` membership. Protected,
   ambiguous, stale-email and conflicting targets fail without a transition.
3. Saves the disabled timestamp or clears it for reactivation, preserving setup
   required. Repeats do not create another transition audit.
4. Rotates the affected User's remember token, deletes its broker token and
   deletes database sessions **only where `user_id` matches the target**.
5. Saves an allowlisted actor/reason/state audit and verifies fresh association,
   flags and actual profile Gate before commit. Failed writes, canceled audits
   and failed postconditions roll back.

Both transitions use transactions with three deadlock attempts. Database-backed
sessions in the account database are required for actual session revocation.
Unsupported session drivers fail safely; the in-memory array driver is allowed
only under the automated `testing` environment. An already-running request may
finish; subsequent protected requests recheck state. Reactivation cannot revive
deleted sessions or rotated remembered cookies. Other users' sessions are not
removed.

The existing console link command still requires actual enabled profile access
before reporting success. It cannot silently reactivate a disabled account or
clear pending setup. Original SR-01 single-variant repair, strict lowercase
postconditions, conflict rejection, idempotence and mixed-role console behavior
are retained.

## 4. Secure setup/reset and delivery boundary

`StudentCredentials` adapts the existing broker for Student accounts:

- Issuance locks the intended User/Student/memberships, checks actual association,
  exact role, current email and inactive state, and reauthorizes management
  actors. Management requests require the route's Student ID, verified identity
  and reason; public recovery is recorded as a public request, not impersonated
  Student approval.
- Readiness is checked **before generating a token**. Current
  `StudentCredentialDelivery::available()` explicitly returns false. A mail
  configuration toggle alone cannot enable new Student credentials. New account
  creation remains blocked in the existing provisioning action.
- The prepared issuance path retains the broker repository's **60-second** issue
  throttle and **60-minute** token expiry. Tokens use the framework's secure
  random generation and hashed database storage; raw tokens are never returned
  to the Registrar, put in audit/flash data or logged by application code.
- When issuance is available in isolated simulations, the request/token audit is
  atomic, resending replaces the old token, and notification dispatch happens
  after commit. A transport acceptance is recorded separately from a request;
  it is never described as a delivery receipt. Transport exceptions are replaced
  with a generic, credential-free report and a safe failure-result audit where
  that audit can be stored. Pending setup remains pending after failure.
- The delivery adapter independently rejects log, array and failover transports
  and invalid/disabled targets, even if readiness is overridden in a test.
  Student notifications cannot fall through to the default log mailer via User's
  password-reset notification hook.
- Consumption reuses the existing reset form and `Password::broker()->reset()`.
  It validates the existing password policy, confirmed password, email and token
  type, then locks the User **before token validation**, rereads association,
  role/current address/disabled state, hashes the chosen password, clears setup
  required, rotates remember credentials, revokes sessions/tokens and audits the
  activation/reset in one transaction. Fresh password/access/token postconditions
  must pass; model-event cancellation cannot masquerade as success.
- The broker consumes the valid token in that transaction. Expired, invalid,
  previously used, noncanonical, unlinked or inactive targets cannot change the
  password or activate. Password-reset events are deferred until commit. Success
  redirects to existing login and does not automatically authenticate or verify
  email.
- Student login-email editing still uses existing account settings. Its targeted
  adapter locks the User and invalidates tokens for both the old address and the
  newly adopted address in the same transaction, preventing a former address
  owner's obsolete broker token from following an email reassignment.

The public forgot-password entry point uses the Student service for Student
targets and preserves the existing staff broker path. All valid-format public
requests get the same eligibility/delivery-qualified message regardless of
known/unknown/inactive account. Format validation still applies. No claim of
constant-time response or real delivery follows from that presentation change.
Public recovery/consumption routes are limited to six requests per IP per minute.
Reset pages use private/no-store and **Referrer-Policy: no-referrer**; raw tokens
are excluded from validation old input.

Standard broker links still carry a bearer token in the recipient's reset URL.
Before real delivery is enabled, verify HTTPS, approved URL origin, trusted proxy
configuration and access-log/error-monitoring redaction for these existing auth
routes. This task does not certify infrastructure logging or delivery. Never
copy setup links from logs or return them through the management interface.

## 5. Interface, permissions and routes

Navigation remains **Student accounts → select Student → account detail**.
Eligible canonical accounts show a confirmed-email/reason/identity form for
**Deactivate Student access** or **Reactivate Student access**. Existing recent
password confirmation and duplicate-submission prevention are reused. Inactive
and Pending password setup filters are added. Directory search, chevron sorting,
one onchange footer page-size select and minimal projected data are preserved.
Setup/reset/create buttons clearly remain disabled while delivery is blocked.

| Route | Gate and safeguards |
| --- | --- |
| PATCH `/student-accounts/{student}/deactivate` | Existing authenticated web session; `student-accounts.provision`; eligible target; CSRF; identity/email/reason; password confirmation within 900 seconds; existing actor/target limiter. |
| PATCH `/student-accounts/{student}/reactivate` | Same safeguards; canonical association; retain pending setup. |
| POST `/student-accounts/{student}/setup-link` | Same safeguards; broker throttle and delivery readiness; currently returns a clear blocked error for eligible accounts. |
| POST `/student-accounts/{student}/password-reset` | Same safeguards; no password retrieval; currently delivery-blocked. |
| Existing POST `/forgot-password` | Generic public recovery; Student eligibility/readiness/broker rules; six-per-minute public limiter. |
| Existing GET/POST reset-password routes | Existing token form and broker; Student transactional lifecycle adapter; POST throttling and secret-safe validation. |

Only **Registrar Staff** and **Test Administrator** can invoke management.
Coordinator, Finance, Viewer, Student, unknown/roleless and guest accounts gain
no management authority. Staff/mixed/ambiguous targets require technical review;
even Test Administrator cannot disable them through this Student module.
Existing unrelated staff authorization remains unchanged. No new public
registration, arbitrary role assignment, account deletion or Student editing
route was added.

## 6. Audit events

Use `AuditEvent` with explicit allowlists, never generic User snapshots:

| Event | Meaning |
| --- | --- |
| Existing `student_account_linked` | Existing centralized provisioning/link/role repair audit retained. |
| `student_access_deactivated` | Authorized timestamp transition plus credential/session revocation. |
| `student_access_reactivated` | Authorized disabled-flag clearing; pending state retained. |
| `student_account_activated` | Initial password successfully established using a valid token. |
| `student_password_reset_completed` | Existing active Student successfully consumed a recovery token. |
| `student_setup_requested` / `student_password_reset_requested` | Token request recorded when readiness is available; not a delivery receipt. |
| `student_credential_transport_accepted` | Notification transport returned; delivery remains unconfirmed. |
| `student_credential_delivery_failed` | Post-commit delivery outcome unconfirmed; no raw exception/token recorded. |
| `student_credential_request_blocked` | Eligible request could not issue a token because delivery is not approved. |

Records include the actual management actor or explicit public-request/token-holder
provenance, affected Student/User IDs, timestamp, reason where required, state and
safe result. Public requests have no invented authenticated actor ID. Passwords,
password hashes, bearer/reset/remember tokens, session IDs and raw transport
exceptions are excluded. Failed transactional transitions do not leave success
audits or partial state. Existing school/administrative history is preserved.

## 7. Modified files

| Files | Purpose |
| --- | --- |
| `database/migrations/2026_11_18_000001_add_student_lifecycle_to_users.php` | Add/default/drop the two lifecycle columns. |
| `app/Models/User.php` | Cast lifecycle fields and guard Student credential notification delivery. |
| `app/Services/Accounts/StudentAccess.php` | Shared state/eligibility decisions and targeted revocation. |
| `app/Services/Accounts/StudentCredentialDelivery.php` | Explicit unapproved-readiness boundary and safe transport/target guard. |
| `app/Actions/Accounts/StudentAccountLifecycle.php` | Authorized transactional transitions and allowlisted audit. |
| `app/Actions/Accounts/StudentCredentials.php` | Broker issuance/consumption, email-token revocation and credential audit. |
| `app/Http/Middleware/EnsureStudentAccess.php`, `bootstrap/app.php` | Fresh web-session checks; exclude token from flashed validation input. |
| `app/Http/Middleware/ProtectCredentialResponse.php` | Reset-form no-store/no-referrer headers. |
| `app/Http/Requests/Auth/LoginRequest.php`, `app/Policies/StudentPolicy.php` | Existing login and own-profile lifecycle enforcement. |
| `app/Http/Controllers/Auth/NewPasswordController.php` | Student consumption adapter; existing staff branch retained. |
| `app/Http/Controllers/Auth/PasswordResetLinkController.php` | Student/public readiness checks and approved generic recovery message. |
| `app/Http/Controllers/ProfileController.php` | Student-only transactional email/token update. |
| `app/Http/Controllers/StudentAccountController.php`, `routes/web.php` | Protected transition and credential-request endpoints. |
| `routes/auth.php` | Existing auth route rate limits and reset-form headers. |
| `app/Services/Accounts/StudentAccountDirectory.php` | Project actual lifecycle flags and supported filters. |
| `resources/views/student-accounts/index.blade.php`, `show.blade.php` | State explanations, eligible access controls and disabled delivery actions. |
| `tests/Feature/Auth/StudentAccountLifecycleTest.php` | Lifecycle, security, rollback, password validation, preservation and email-token cases. |
| `tests/Feature/Auth/StudentCredentialDeliveryTest.php` | Simulated broker/notification/throttle/events, failed delivery, audit rollback and migration round-trip. |
| `tests/Feature/Database/StudentLifecycleConcurrencyTest.php` | Two real two-process MySQL lock/token/state races. |
| `tests/Feature/Auth/StudentAccountInterfaceTest.php` | Retain interface regressions; update obsolete inactive-filter/absent-route expectations for the newly approved lifecycle. |
| `docs/screenshots/17-student-lifecycle-inactive.png` | Reviewed browser capture with synthetic identities only. |
| `docs/17-student-account-lifecycle.md` | This implementation and verification record. |

Original provisioning/SR-01 tests were not removed. No dependency, `.env`, mail
configuration, scholarship workflow, Student data model or official-record
editing implementation was changed.

## 8. Actual verification evidence

All PHP evidence uses generated isolated **MariaDB 10.11.16** databases through
Laravel's **MySQL driver**, `utf8mb4_unicode_ci`. The external PHPUnit config forces
the generated database, testing environment, empty `DB_URL`, array cache/session
drivers and a unique unused config-cache path. Session-revocation unit fixtures
explicitly select database sessions; browser verification uses actual database
sessions and remembered cookies. Concurrency tests use their own generated
databases and independent PHP processes. No SQLite result substitutes for these
MySQL-specific checks.

`$LifeDir` is `%TEMP%\cfci-student-lifecycle-20261118-dd9d4d9a`. Logs/JUnit/browser
evidence remain outside the repository. Runs overlap and must not be added as
independent test totals.

| Executed check | Actual result |
| --- | --- |
| Focused run `focused.xml` | 74 tests, 572 assertions; two helper snapshot failures and one missing fixture-variable error. The initial schema-list helper included qualified/all-schema names instead of the intended isolated schema/exclusions; it performed reads only. Corrected explicit schema scope and the fixture variable. |
| Expanded focused run `focused-2.xml` | 77 tests, 627 assertions; one migration-helper assertion compared Carbon object identity instead of stored values. Corrected the helper to compare raw database values. |
| Expanded focused run `focused-3.xml` | 82 tests, 659 assertions; two test comparisons used in-memory insertion order rather than fresh stored array order. Corrected the pre-change snapshots to read from the database. Other cases, including both lifecycle concurrency cases, passed. |
| Preflight `preflight.xml` | Four previously affected/new failure-path cases passed; 22 assertions, exit 0; 51.912 s. |
| Full repository command below, `full-mysql.xml` | **658 passed, 8,175 assertions, zero failures/errors/skips**, exit 0; **9 min 59.390 s**. |
| Final authentication command below, `final-auth-mysql.xml`, after old/new email-token and delivery-target hardening and the active-recovery session test | **192 passed, 1,693 assertions, zero failures/errors/skips**, exit 0; **1 min 50.950 s**. |
| `php vendor/bin/pint --test` on 22 changed/new PHP files | Passed, exit 0. |
| `php -l` on those 22 PHP files | Passed, no syntax failures. |
| `npm run build` | Passed, exit 0; production Vite build, 2.81 s. |
| `node --test tests/js/data-tables.test.js` | Three passed; zero failures/skips, exit 0. |
| `node "$LifeDir\browser-lifecycle.cjs"` | **Nine passed** in installed Google Chrome/Playwright; zero JavaScript page errors; one screenshot. |
| `git diff --check` | Passed, exit 0. |

```powershell
php vendor/bin/phpunit --configuration "$LifeDir\mysql.phpunit.xml" --log-junit "$LifeDir\full-mysql.xml"
php vendor/bin/phpunit --configuration "$LifeDir\mysql.phpunit.xml" --log-junit "$LifeDir\final-auth-mysql.xml" tests/Feature/Auth tests/Feature/ProfileTest.php
```

The full run preceded only the final email-reassignment/transport-target
hardening and added active-recovery test. Every authentication suite and existing
account-settings suite was rerun against the final source by the second command.
Within the full run, original SR-01 was **13 passed / 117 assertions**, original
Student role **20 / 277**, provisioning service **31 / 322**, interface **32 /
266**, new lifecycle tests **28 / 234**, new delivery/migration tests **5 / 34**,
new lifecycle concurrency **2 / 18**, existing console/service concurrency **3 /
36** and **3 / 39**, and scholarship lifecycle **6 / 1,119**. All passed without
skips. The final authentication run also includes the added active Student
password-recovery/session case and strengthened email-reassignment assertion.

Broker notification success was tested with **Notification::fake()**, test-only
readiness overrides and synthetic accounts. No SMTP message was sent and no real
delivery readiness was granted. Tests exercised token hashing/expiry, resend
invalidation, 60-second issuance throttling, single use, password policy, setup
activation, preserved verification state, correct login/profile, invalid foreign
records, inactive login/session rejection, actor/target denial, audit rollback,
canceled password writes, safe delivery failure, migration defaults/round-trip
and preservation of nonempty academic, billing, payout and historical fixtures.

The browser independently checked real Registrar password confirmation and
identity forms; deactivation; existing remembered-session denial; correct-password
inactive login denial; another Student's unaffected session; reactivation; failed
replay of old session/remember cookies; successful fresh login with unchanged
credentials; denied foreign profile and administrative PATCH; disabled delivery
controls. Static assets were fulfilled from the local production build to avoid
Windows PHP development-server parallel-asset stalls; HTML, cookies, CSRF, login
and management requests used the actual PHP application.

## 9. Preservation, configuration and deployment limits

The configured application database was inspected read-only before/after. No
production/application account, Student, role, scholarship, token or audit record
was provisioned, reset, deleted or migrated. The browser bootstrap forces the
generated database and unique cache prefix **before providers boot**, avoiding
the prior interface task's cache-isolation issue. Current cache and business-table
fingerprints match. The application session table changed during this task;
its cause is not certified and existing sessions were not restored/deleted.
Do not claim a completely unchanged 41-table snapshot.

Final preservation: **40 of 41** configured application tables match their
starting row-count and sorted-row SHA-256 fingerprints; only `sessions` differs.
All **370** pre-existing inventoried source/config/document/test files remain;
only the **14** intended files listed above changed, and the other **356** match
their original hashes. Existing SR-01/provisioning/business regression files,
`.env`, dependency manifests, table JS/CSS and scholarship controllers are
preserved. Twelve new files comprise the services/actions/middleware, migration,
three test files, screenshot and this report.

Stopped only the preview process verified against its saved PID/unique router
path, and removed its private synthetic credential fixture file. Dropped and
verified cleanup of only the two saved generated task databases, under exact
name-pattern checks and exclusion of the configured application database.
The user's application and database service were not
stopped. Saved XML/logs remain evidence; reruns require a fresh isolated database
and corresponding config rather than pointing tests at existing records.

Required before deployment/use:

- Reviewed migration on the intended environment, backup/recovery readiness and
  database session storage in the account database.
- Normal HTTPS/secure-cookie/trusted proxy configuration and credential-route
  access-log/error-monitoring redaction. Application code does not log raw tokens,
  but infrastructure logging was not certified.
- Explicit approval and actual delivery testing of a real outbound channel,
  configured recipient/origin, failed-delivery handling and pending-account
  provisioning before enabling new User creation or setup/reset delivery. The
  current fixed readiness block and disabled buttons must not be bypassed by an
  environment mailer change alone.

Oracle MySQL, alternative session drivers, other mail services, all browser engines
and production rollout are not certified. No standalone type-check/lint script
exists beyond the executed PHP style/syntax and frontend build checks. Existing
non-Student password reset and account-setting lifecycle remains on its original
path, except the explicitly approved generic public recovery presentation/rate
limits and auth-page header protections.

## 10. Manual verification

Use isolated development/test databases and synthetic accounts. Do not create
predictable passwords, reset existing records or issue real credential links
from logs.

1. Migrate the isolated database. Confirm existing Users default to setup false
   and disabled null, without password/link/role changes.
2. Sign in as Registrar or Test Administrator. Open **Student accounts**, choose
   a canonical linked Student and confirm your own password when prompted.
3. Review identity/current login email, enter a reason and attest the identity.
   Deactivate. Confirm Inactive, unchanged Student/scholarship/billing history,
   target sessions/tokens removed and a safe actor/reason audit.
4. Keep another synthetic Student logged in separately. Verify their session
   still works, while the disabled Student's existing/remembered session and
   correct-password login cannot reach protected pages/settings or downloads.
5. Reactivate after the same review. Confirm old cookies do not resume access;
   a fresh login with existing credentials succeeds for an established account.
   Pending setup must stay pending and unable to log in.
6. Test Coordinator/Finance/Viewer/Student/guest direct PATCH/POST requests and
   staff/mixed/ambiguous targets. Expect authorization/validation errors and no
   partial transitions. Test stale password confirmation, missing reason/email/
   identity and rate limits.
7. Confirm setup/reset/create controls explain unavailable delivery. Direct
   eligible management requests must return a blocked message without a token
   or notification. Public recovery for unknown, active and inactive accounts
   must give the same eligibility-qualified message.
8. Run the isolated automated broker tests for setup, invalid/expired/reused
   tokens, resend throttling, rollback and concurrent use. Their fake transport
   is not a manual delivery procedure. Real initial setup remains BLOCKED until
   its approved channel and provisioning prerequisite are complete.
9. Run existing staff, Student, console-link/SR-01 and business workflows. Check
   own profile access and foreign/admin denial, nonempty financial/audit history
   preservation and unchanged scholarship/enrollment status.

[Reviewed inactive-account screenshot](screenshots/17-student-lifecycle-inactive.png).

## 11. Final review and verdict

Reviewed lock order, exact canonical association, disabled/pending precedence,
fresh auth/profile enforcement, actor/target boundaries, revocation isolation,
broker single-use semantics, after-commit notification/event handling, safe
results/audits, email reassignment tokens and migration defaults. No public
registration or Student Profile editing was implemented.

**BLOCKED for complete real Student initial setup and credential delivery.**
**PASS for the tested lifecycle controls and isolated secure broker adapter.**
The implementation must not be represented as a working email/onboarding system.
Real outbound delivery and new-account creation remain explicitly
unapproved/unverified. No automated test failure remains after the documented
test-helper corrections; deployment migration and real credential-delivery
acceptance remain outstanding.
