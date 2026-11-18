# 17 - Student Account Management interface

> **Student profile update (2026-11-19):** The user has now requested Student self-service information editing. An enabled, correctly linked Student can edit their own allowlisted personal/contact/address/family fields through My student profile. Staff-only record and account permissions remain. See [current implementation and actual test results](24-student-profile-editing.md); earlier read-only/deferred scope below is historical.

> **Initial password setup update (2026-11-19):** The user requested a dedicated Student password form. Authorized Registrar/Test Administrator users can now complete initial setup for an eligible linked pending Student from Manage login, without email. Saving a valid password clears the pending flag and verifies profile access atomically. See [implementation, layout and actual test evidence](22-student-initial-password-form.md). Earlier stage limitations below are retained as history.

> **Latest approved change (2026-11-19):** The user now permits pending account creation without email. Creation no longer requires mail readiness and sends no notification/token. Both password-link buttons are removed. Secure setup remains required before Student login; credential-delivery safeguards remain. See [current behavior and tests](21-student-account-creation-without-email.md). Earlier results below are historical and do not override this change.

> **Current-stage update (2026-11-18):** Create-only Student account provisioning and a dedicated receipt-verification mechanism are now implemented. Mail credentials remain blank at the user's request, so live creation/delivery remain blocked. The lifecycle migration was already applied and verified. See [current implementation and actual test evidence](19-scholar-account-creation-implementation.md). Earlier implementation results below are retained as historical scope. Web existing-account linking remains removed.

> **Subsequent user-requested change (2026-11-18):** Existing-account linking
> was removed from the management page and its POST `/student-accounts/{student}/provision`
> endpoint. Unlinked records now show a read-only No login account state; accounts
> needing review use the technical-review message. Existing associations and
> lifecycle controls are preserved. The trusted console/shared linking action
> and original SR-01 regressions remain; no new browser linking endpoint exists.
> The reused form request is now `ManageStudentAccountRequest`. Linking UI steps
> and test results below describe the earlier implementation. The removal was
> checked against isolated MariaDB: **114 tests / 1,031 assertions passed** across
> interface, lifecycle, shared service, original SR-01 and console-link suites.

**Date:** 2026-11-18, Asia/Manila  
**Final verdict: BLOCKED for the complete new-account/setup objective.** The
existing-account management interface is implemented and passed its applicable
MariaDB and browser checks. New account creation and initial credential delivery
remain deliberately unavailable under the user's approved D02 restriction.

## 1. Reviewed requirements and delivery boundary

Reviewed the approved [account management design](16-student-account-management-design.md),
[shared provisioning service](16-student-account-provisioning-service.md),
[SR-01 fix](15-student-provisioning-fix.md) and
[independent reverification](15-student-role-reverification.md), together with
the current source, navigation, policies, forms and table conventions.

The design approval record takes precedence over its retained historical
proposal: **Registrar Staff plus the retained Test Administrator exception** may
manage accounts; **all new account creation is blocked until real credential
delivery is approved and tested**. This implementation does not interpret the
interface request as approval of mail delivery. The existing service's
`createForActor()` still returns `credential_delivery_not_approved`.

The shared service prerequisite is complete for existing-account linking and
SR-01 compatibility. Its documented new-account prerequisite remains blocked.
Accordingly, the delivered interface supports verified **existing User accounts**
only. It does not generate a User, password, setup token, delivery request or
activation state. Approved future lifecycle fields are not present in the
current schema; none are silently introduced by this interface.

## 2. Implemented features and navigation

**Navigation:** sign in as Registrar Staff or Test Administrator, then select
**Student accounts** in the primary navigation. The same entry appears in the
mobile navigation. Alternatively, open **Scholars → select a student → Student
login account → Manage login account**.

The directory shows existing Student records with Student ID, name, contact
email, permitted linked login email, association/access status and a **Manage
login** action. It excludes addresses, protected identifiers, password hashes,
remember/reset tokens and full role-management controls. A restricted account's
login email is withheld and cannot be discovered through directory search.

- Search matches Student number, name, contact email and visible login email.
  Multiple search words must all match, without case sensitivity.
- Account-status filters use the same computed exact-role/ownership semantics as
  the displayed status, including under case-insensitive MySQL collation.
- Existing table components provide server sorting/pagination, header up/down
  chevrons and **one** rows-per-page selector **below** the table. Options are
  **10, 20, 50, 100, 200**; changing the select triggers navigation on `change`.
  No duplicate record toolbar or page-size control was introduced above it.
- Empty results explain how to clear filters. Validation and service errors use
  existing error summaries/field messages; success uses the existing status
  notification. Generic infrastructure failures never display internal errors.
- The detail page separates official Student identity/record status from login
  access, shows the current association and offers only appropriate actions.
- A form requires explicit identity attestation, confirmed existing login email
  and an audit reason. Linking preserves the account's existing credentials.
- Recently confirmed operators can submit once; Alpine disables the submit
  button while submitting. Server-side idempotence remains authoritative.

The page uses the current Blade/Alpine architecture, cards, fields, buttons,
badges, notifications, typography, colours and responsive table wrapper. No new
frontend dependency, stylesheet or framework was introduced. On narrow screens,
the table scrolls inside its wrapper rather than widening the document.

### Supported statuses

| Filter value / displayed label | Actual meaning |
| --- | --- |
| `not_linked` / No account linked | Student has no `user_id` association. An explicitly reviewed existing account can be submitted. |
| `linked` / Linked and configured | Exactly one eligible membership is stored as literal lowercase `student`; ownership authorization succeeds and the account is manageable. No change is necessary. |
| `needs_review` / Access needs review | Linked account needs canonical-role/ownership review, for example literal `Student`, no membership, or ambiguous variants. A single eligible variant or roleless target can be configured; ambiguity requires technical review. |
| `restricted` / Management restricted | A staff, unknown or mixed-role target cannot be managed by this web module. Its memberships and credentials are preserved. |

**Configured** describes the actual Student profile Gate, not successful password
entry or mailbox delivery. A restricted mixed-role account may already have
Student profile permission; management remains restricted. Official
`record_status` active/archived does not activate/deactivate a login. No pending,
active or inactive **account** state is invented. Hidden details of a linked
ambiguous account say **Unavailable for review**, rather than implying it is
unlinked.

## 3. Routes, responsible roles and security

| Method / route | Name | Required permission and safeguards |
| --- | --- | --- |
| GET `/student-accounts` | `student-accounts.index` | Existing authenticated web session; `student-accounts.provision` Gate; private/no-store response. |
| GET `/student-accounts/{student}` | `student-accounts.show` | Same Gate and response policy; existing Student route binding. |
| GET `/student-accounts/{student}/confirm` | `student-accounts.confirm` | Same Gate; existing password confirmation; redirects to the safe detail GET. No account mutation. |
| POST `/student-accounts/{student}/provision` | `student-accounts.provision` | Same Gate, CSRF, password confirmed within 900 seconds, validation, rate limiting, actor/target checks in the shared action and transactional postconditions. |

`StudentAccountProvisioningPolicy` continues to allow exact `registrar` and
`administrator` memberships. Coordinator, Finance, Viewer, Student, uppercase
unrecognized memberships, roleless users and guests receive no new authority.
Authenticated unauthorized actors receive 403 even with valid CSRF and password
confirmation. Guests use the existing login redirect. Nonexistent Students use
Laravel route-binding 404 responses.

The POST limiter permits six attempts per actor per minute and three per target
Student per minute. Authorized repeated requests still consume attempts; excess
requests return 429. Unauthorized actors cannot consume a target's management
quota. Password confirmation reuses Breeze and changes no global timeout. An
expired browser POST returns to a safe detail GET after confirmation, requiring
review/resubmission instead of replaying the mutation as GET; stale JSON requests
receive the existing confirmation-required 423 response.

Authorization is enforced in routes, controller/Form Request, and the shared
actor entry point. Hiding navigation is only a convenience. The controller never
calls the trusted-console entry point and cannot accept an actor or target User
ID, privileged role assignment or creation mode from the browser.

### Validation and writes

- `login_email`: required string, valid email, at most 255 characters, trimmed
  and lowercased; must identify an eligible existing account through the shared
  service's identity checks. No new account is automatically created.
- `reason`: required trimmed string, at most 1,000 characters.
- `identity_confirmed`: accepted explicit attestation, never automatically
  restored as checked after an error.
- Role arrays, User/Student identifiers, caller actor/source/mode, creation
  flags, password and token fields are prohibited. The controller uses only its
  validated allowlist and the route-bound Student's stored number.
- Only email/reason are flashed after errors or password confirmation. Arbitrary
  credential/role payloads and internal exception details are not returned.
- Missing login accounts, duplicate normalized Student contact addresses,
  protected targets and conflicts in either association direction fail safely.
  Student contact and User login email remain separate; neither is silently
  synchronized. Identity confirmation is required even when emails match.
- Linking, single-variant `Student` repair, uniqueness guards, lock order,
  conflict handling, audit provenance, rollback and fresh canonical-role/profile
  postconditions are delegated to the existing
  [`LinkStudentAccount::forActor()`](../app/Actions/Accounts/LinkStudentAccount.php).
  Provisioning business logic was not copied into the controller or directory.
- A changed success writes the existing allowlisted audit with actor/reason;
  repeats produce no duplicate membership or audit. Unexpected errors show
  **We could not confirm the account link. Review the current status before
  trying again.** No ambiguous commit is represented as confirmed success.

There is no new registration, role editor, unlink/delete, setup/reset delivery,
deactivate/reactivate or Student profile editing endpoint. Create/setup buttons
are disabled with a visible explanation. Existing authentication, console
arguments and Student own-profile authorization are unchanged.

## 4. Files added or modified

| File | Change |
| --- | --- |
| `app/Http/Controllers/StudentAccountController.php` | New directory/detail/confirmation/link endpoints delegating to the shared action. |
| `app/Http/Requests/LinkStudentAccountRequest.php` | New actor authorization, normalization, allowlist and safe validation response. |
| `app/Http/Middleware/ConfirmStudentAccountPassword.php` | New 15-minute confirmation wrapper preserving a safe GET return path. |
| `app/Services/Accounts/StudentAccountDirectory.php` | New minimal read projection with exact role/ownership status computation; performs no provisioning writes. |
| `app/Providers/AppServiceProvider.php` | Registers the named management rate limiter, retaining all existing Gates/policy mappings. |
| `app/Support/TableColumns.php` | Adds the Student account directory's sortable/filterable column projection. |
| `routes/web.php` | Adds the four protected management routes. |
| `resources/views/layouts/navigation.blade.php` | Adds the approved-role-gated desktop/mobile entry. |
| `resources/views/scholars/show.blade.php` | Adds the gated account-management link to the existing Student detail page. |
| `resources/views/student-accounts/index.blade.php` | New directory, search, supported-status filter and shared table controls. |
| `resources/views/student-accounts/show.blade.php` | New identity/access review, eligible linking form and unavailable-action explanation. |
| `tests/Feature/Auth/StudentAccountInterfaceTest.php` | Adds 32 parameterized feature cases for UI access, validation, linking, rollback, postconditions, confirmation and throttling. |
| `docs/screenshots/17-student-accounts-*.png` | Three browser captures containing synthetic QA identities only. |
| `docs/17-student-account-management-interface.md` | This implementation and verification record. |

No migration is required for the delivered existing-link interface. No User,
Student, membership model, existing authorization policy, shared provisioning
action/command, authentication controller or dependency manifest was modified.

## 5. Executed tests and results

PHP tests used a newly created **MariaDB 10.11.16** database through Laravel's
`mysql` driver with **`utf8mb4_unicode_ci`**. An external PHPUnit configuration
forces testing environment, generated test database, empty `DB_URL`, array
cache/session drivers and a task-owned config-cache path. Race and migration
tests retain their own isolated-database safeguards. No SQLite result is used to
substitute for SR-01 verification.

`$UiDir` below is `%TEMP%\cfci-student-ui-20261118-27f2d83e`. Test evidence/logs
remain there, outside the repository. These overlapping runs are not separate
coverage totals.

| Actual command / check | Actual result |
| --- | --- |
| `php vendor/bin/phpunit --configuration "$UiDir\mysql.phpunit.xml" --log-junit "$UiDir\interface-mysql.xml" tests/Feature/Auth/StudentAccountInterfaceTest.php` | Initial run: 32 cases, 237 assertions, two test failures. Both incorrectly searched HTML for the `no-store` header; the response header was correct. Corrected those assertions to inspect response headers. |
| Same focused command with `interface-final-mysql.xml` | 32 passed, 259 assertions, exit 0; 13.218 s. |
| `php vendor/bin/phpunit --configuration "$UiDir\mysql.phpunit.xml" --log-junit "$UiDir\full-mysql.xml"` | 623 passed, 7,882 assertions, zero failures/errors/skips, exit 0; 7 min 13.868 s. |
| Same full command with `full-final-mysql.xml` after ambiguous-account display/test review | **623 passed, 7,887 assertions, zero failures/errors/skips**, exit 0; **7 min 33.291 s**. |
| Focused MySQL command below, after aligning the directory column projection with the reviewed ambiguity label | **87 passed, 846 assertions, zero failures/errors/skips**, exit 0; **24.594 s**. Includes all 32 interface cases (266 assertions), original SR-01, provisioning service and shared table controls. |
| `php vendor/bin/pint --test` on the eight changed/added PHP files | Passed, exit 0. |
| `php -l` on those eight PHP files | Eight checked; no syntax errors. |
| `npm run build` | Passed twice, exit 0; final Vite production build, **1.80 s**. |
| `node --test tests/js/data-tables.test.js` | Three passed; zero failures/skips, exit 0. |
| `git diff --check` | Passed, exit 0. |
| `node "$UiDir\browser-checks.cjs"` | Final fresh-fixture run: 13 checks passed in installed Google Chrome via Playwright; zero JavaScript page errors; three screenshots. See harness correction below. |
| `php "$UiDir\runtime-postcheck.php"` | Final fresh-fixture check: exact one-to-one association, one literal `student` membership, actual profile Gate allowed, unchanged password usable, foreign link preserved, four original fixture Users retained, no setup tokens and exactly one changed-link audit. |

No standalone type-check or frontend lint script is configured. PHP syntax/style
and Vite checks are not represented as formal type checking.

The final focused command was:

```powershell
$cases = @(
    'tests/Feature/Auth/StudentAccountInterfaceTest.php',
    'tests/Feature/Auth/StudentProvisioningRegressionTest.php',
    'tests/Feature/Auth/StudentAccountProvisioningServiceTest.php',
    'tests/Feature/TableControlsTest.php'
)
php vendor/bin/phpunit --configuration "$UiDir\mysql.phpunit.xml" --log-junit "$UiDir\final-focused-mysql.xml" @cases
```

The last full run preceded only the small column-label projection alignment and
its filter assertion; all affected suites were rerun by that focused command.
Within the full run, original SR-01 had **13 passed / 117 assertions**, shared
service **31 / 322**, existing console linking **9 / 54**, Student role **20 /
277**, the two Student linking concurrency suites **3 / 36** and **3 / 39**, and
the existing scholarship lifecycle suite **6 / 1,119**. None failed or skipped.

### Coverage and browser observations

The full repository run includes the original SR-01 regression suite, console
link tests, Student role/login/ownership tests, service tests, all six existing
console/actor Student linking concurrency cases, existing operational-role
security and scholarship/academic/billing/financial/report workflows. Existing
test files were preserved. The new interface cases verify:

- Both approved actors, denied roles/guests and direct GET/POST authorization.
- Safe directory/details, exact canonical/capitalized/roleless/ambiguous and
  protected-account status, search, pagination/sorting and empty results.
- Real form POST, in-place capitalized membership repair, preserved credentials,
  archived Student access without awards, audit actor/reason and repeat safety.
- Expired password confirmation, safe return routing, invalid email/attestation/
  reason and prohibited credential/privilege payloads without unsafe old input.
- Missing login accounts, duplicate contacts, conflicts in both directions,
  protected targets, audit-exception rollback and forced postcondition failure.
- Rate-limit enforcement and unavailable creation/setup/lifecycle/public
  registration paths without notifications or record changes.

The real HTTP browser run additionally executed Registrar login/navigation,
14-fixture directory pagination, onchange page-size navigation, both chevron sort
directions, status filtering/search, existing password confirmation and actual
link submission. It tested missing-CSRF **419**, repeated-link unchanged feedback,
390-pixel mobile navigation and absence of document overflow. The linked Student
logged in using the unchanged password, saw only their own private profile, and
received **403** for another Student's record and account-management GET/POST.
Coordinator login and Scholars access remained available while the new
management navigation and direct GET/POST were denied. HTTP forms/CSRF/session
handling used the PHP preview; static assets were fulfilled from the generated
local build to avoid the Windows development server's parallel-asset stalls.

### Test-harness correction and preservation limits

The initial browser bootstrap selected the test session/database connections
after application boot. Registering the rate limiter had already resolved its
database cache store, so four temporary management rate-limit entries landed in
the configured application cache table. This was an external QA-bootstrap
isolation defect, not a bypass of application authorization. Corrected the
bootstrap to force the generated database/cache connection and a unique cache
prefix **before** providers boot, then reran browser checks against a newly
created runtime database.

The four task cache entries were identified by the exact new limiter keys.
Preflight proved that excluding only those four restored the original cache
row-count and sorted-row SHA-256 fingerprint, and that all four had expired.
A guarded transaction removed only these newly introduced expired QA entries
and verified the original cache fingerprint; pre-existing cache rows were
untouched. A preliminary fresh-fixture rerun also hit the bootstrap's
already-initialized guard and then an invalid doubled-fixture row-count
assertion. Those failed harness attempts are not counted as successful product
tests. The corrected fresh database run passed all 13 browser checks and the
postconditions above.

The configured application's account, membership, Student, token, audit and
business-table fingerprints match the starting snapshot. Its session table
changed during the task; preview session IDs are disjoint from application
session IDs. Existing application sessions were neither reset nor restored, and
their cause is not certified by this test. Therefore this report does **not**
claim all 41 tables stayed byte-for-byte unchanged throughout verification.

Final preservation check: **40 of 41** configured application tables matched the
starting row-count and sorted-row fingerprint, including the restored cache;
only `sessions` differed. Stopped only the PHP process identified by the saved
task preview PID and unique router path. Removed the private synthetic credential
fixture file. Dropped only the three saved, generated task test/runtime databases
after exact name-pattern and configured-database exclusion checks. Existing
records, live application servers and the database service were not reset.

Starting source inventory: **359 pre-existing files**. Only five intended
pre-existing files changed: provider, table columns, navigation, Student detail
view and web routes. The other **354** matched their original SHA-256 values,
including existing tests, `.env`, shared provisioning action/command, table
JavaScript/CSS, models, policies and dependency manifests.

## 6. Screenshots

Screenshots contain generated QA records, not existing Student identities or
credentials. Reviewed the rendered desktop directory, identity form and mobile
table layout:

- [Desktop directory](screenshots/17-student-accounts-directory.png)
- [Identity and existing-account linking form](screenshots/17-student-accounts-link.png)
- [Mobile directory](screenshots/17-student-accounts-mobile.png)

## 7. Manual testing procedure

Use an isolated migrated MariaDB database and synthetic Users/Students. Do not
run database-reset commands or these fixture operations against existing data.

1. Use an authorized existing Registrar test account. Open **Student accounts**;
   confirm the same link exists from a Student's Scholars detail page.
2. Search by Student number/name/email. Check each supported account status and
   clear filters. Change the single footer select to 20/50/100/200 without an
   additional click. Test both header sort directions and next/previous pages.
3. Open an unlinked synthetic Student. Review identity and contact email. Click
   **Confirm password to continue**, enter your own operator password and verify
   return to the Student detail page. Never enter the Student's password here.
4. Enter an eligible existing synthetic account's verified email, record the
   review reason, check the ownership attestation and submit **Link existing
   account**. Confirm configured status and preserved credentials. Inspect the
   allowlisted actor/reason audit through authorized technical tooling.
5. Repeat the same request after confirmation; verify unchanged feedback, one
   association/membership and no extra changed-link audit. More than three
   attempts for a Student within a minute must return 429; wait before retrying.
6. With isolated fixtures, test an existing literal `Student` membership; it
   should be repaired in place to `student`. Canonical linked targets need no
   change. Ambiguous memberships require technical review without consolidation.
7. Try invalid/missing email, missing reason/attestation, a nonexistent login,
   duplicate Student contact email, protected staff account and both association
   conflicts. Expect clear errors and no partial account/link/role/audit writes.
8. Sign in as the linked synthetic Student using their existing password. Open
   **My profile**; verify the correct record. Try another Student's Scholars URL
   and management GET/POST with valid CSRF; expect 403. Query parameters must not
   switch the own-profile record.
9. Check Coordinator/Finance/Viewer/roleless accounts cannot access management.
   Confirm existing authorized Registrar/Coordinator Scholars operations still
   work. Check Test Administrator retains the approved management exception.
10. Repeat on a narrow viewport. Confirm readable fields, mobile navigation,
    scrollable table, one footer page-size select and no document overflow.
11. Confirm **Create new account** and **Send password setup link** stay disabled
    with the delivery explanation. No new-account setup success can be tested
    until its approved delivery foundation is implemented and verified.

## 8. Remaining limitations and verdict

- **BLOCKED:** successful new User creation and initial secure password setup.
  No real delivery channel has been approved/tested, no initial setup token has
  been issued, and no mail delivery was exercised. Existing-account login is
  verified; it is not evidence of a new-account setup flow.
- Future lifecycle fields/enforcement and activation/deactivation/reset delivery
  remain unimplemented; the UI describes actual supported states instead.
- The read projection loads minimal directory records into a collection before
  filtering/pagination, matching the existing table helper. This preserves exact
  PHP role semantics but is not a large-directory performance certification.
- MariaDB/MySQL-driver behavior is verified. Oracle MySQL, other collations,
  multiple browser engines, SMTP delivery and a production rollout are not
  certified by this task.
- The external preview cache-isolation issue was corrected and its four expired
  QA entries removed under exact preflight checks. The application session-table
  change remains a preservation limitation; no existing session was deleted.

Reviewed routes, form whitelists, minimal data projection, actor/target guards,
safe confirmation redirects, error/old-input handling, exact SR-01 semantics and
all changed application files. No duplicate provisioning implementation or
authorization weakening was introduced. **Existing-account management: PASS.**
**Complete creation/setup objective: BLOCKED**, as required by the approved
credential-delivery restriction. No Student Profile editing was implemented.
