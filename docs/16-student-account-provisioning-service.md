# Student account provisioning service

> **Latest approved change (2026-11-19):** The user now permits pending account creation without email. Creation no longer requires mail readiness and sends no notification/token. Both password-link buttons are removed. Secure setup remains required before Student login; credential-delivery safeguards remain. See [current behavior and tests](21-student-account-creation-without-email.md). Earlier results below are historical and do not override this change.

> **Current-stage update (2026-11-18):** Create-only Student account provisioning and a dedicated receipt-verification mechanism are now implemented. Mail credentials remain blank at the user's request, so live creation/delivery remain blocked. The lifecycle migration was already applied and verified. See [current implementation and actual test evidence](19-scholar-account-creation-implementation.md). Earlier implementation results below are retained as historical scope. Web existing-account linking remains removed.

**Date:** 2026-11-18, Asia/Manila  
**Final verdict: BLOCKED for new-account creation pending approved, tested real
credential delivery. Existing-account linking/refactor: PASS.**

## 1. Approval record and delivered scope

Required references were reviewed:
[SR-01 fix](15-student-provisioning-fix.md),
[independent reverification](15-student-role-reverification.md),
[account management design](16-student-account-management-design.md), the
current console command and `StudentProvisioningRegressionTest`.

The design initially remained marked proposed. During this task the user
explicitly approved:

- **D01:** Registrar plus the retained Test Administrator exception.
- **D02:** **Block all new account creation until real delivery is approved and
  tested.** This supersedes the design's optional pending-account creation while
  delivery is unavailable.
- **D03-D06:** The documented defaults for identity/email review, protection of
  staff/mixed-role targets, Student lifecycle fields, archived-profile access
  independent of scholarship approval, and confirmation/audit safeguards.

The configured mail transport was inspected read-only and is still **`log`**.
Real credential delivery was not configured, approved or tested in this task.
Consequently the service must not create a User, generate a password/setup token,
or offer a log/temporary-password delivery fallback. The creation API returns an
explicit structured **blocked** outcome without writes. Changing `mail.default`
alone does not satisfy approval or testing and cannot enable account creation.

Delivered: centralized existing-account linkage, canonicalization, authorization
for application callers, protected-target checks, safe result objects, console
delegation and regression tests. No web interface, HTTP provisioning endpoint,
public registration, Student Profile Management, credential delivery or account
lifecycle transition was implemented. Approved future lifecycle fields do not
need a migration for this existing-account extraction; none was applied.

## 2. Architecture and reuse

No suitable existing account-linking application action/service was found in
`app/Actions` or `app/Services`. The verified implementation lived entirely in
`app/Console/Commands/LinkStudentAccount.php`. Its transaction/role/link rules
were moved into **`App\Actions\Accounts\LinkStudentAccount`**, following the
project's existing application-action convention.

The implementation reuses:

- `User`, `Student`, `UserRole` and their existing relationships/constraints;
- strict `User::hasAnyRole()`, `User::STUDENT_ROLE` and the existing ownership Gate;
- existing database transactions and row locks, with three deadlock attempts;
- `AuditEvent` with an explicit field allowlist, preserving console audit shape;
- Laravel Gates and validation; no authentication guard/package replacement.

`RecordAudit` is not used to serialize User records: its fillable-field snapshot
would include User's password attribute. No credential fields enter the new
result or audit payloads.

### Public service entry points

| Method | Responsibility and trust boundary |
| --- | --- |
| `fromTrustedConsole(email, studentNumber)` | Existing trusted server-console operation. Preserves intentional explicit staff/Student multi-role linkage and existing console arguments/behavior. Must never be selected from HTTP input or exposed as a controller endpoint. |
| `forActor(actor, email, studentNumber, reason, identityConfirmed)` | Application entry point for an existing login account. Re-loads the persisted actor's roles, enforces the approved Registrar/Administrator Gate, validates identity attestation/reason/input, and checks the locked target. Future controllers must pass the authenticated actor, not an input User ID. |
| `createForActor(actor, studentNumber)` | Rejects unauthorized actors and missing Student records. Otherwise returns `credential_delivery_not_approved` with `blocked = true`. It never creates or modifies any account or issues credentials. |

The actual transaction and canonicalization implementation is private and shared
by both linking entry points. The console no longer owns a second copy. No
public request can switch the service into its trusted-console boundary because
no provisioning HTTP route exists.

`StudentAccountProvisioningPolicy` defines the new named Gates
`student-accounts.provision` and `student-accounts.link` in
`AppServiceProvider`. Existing Student policy mappings, staff permission sets,
auth routes and login handling were preserved.

### Structured results and errors

`StudentAccountProvisioningResult` is immutable and JSON serializable. Its
allowlisted fields are `successful`, `code`, `message`, `user_id`, `student_id`,
`changed`, and `blocked`. It contains no Eloquent models, passwords/hashes,
remember tokens, reset tokens or exception/SQL payloads.

Successful linking returns `linked` or `unchanged` and the verified IDs. Expected
failures return codes including `forbidden`, `invalid_input`, `not_found`,
`email_conflict`, `association_conflict`, `protected_account`,
`ambiguous_memberships`, `duplicate_contact_email`, `audit_failed`,
`postcondition_failed` and `uniqueness_conflict`. Failures expose no account IDs.
Creation is explicitly blocked rather than falsely reported successful.

Unexpected infrastructure/audit exceptions propagate **after transaction
rollback**, retaining the existing console failure behavior. They are not
serialized into service responses. A future web handler must use the normal
generic exception response, not return an exception message/trace to clients.

## 3. Business and security rules

1. Resolve the intended existing User email and institutional Student number.
   Do not create or claim a Student based on contact email alone. Neither side
   can be reassigned from another existing association.
2. Preserve the console's lowercase/trim email and trimmed Student-number lookup.
   The actor path additionally validates email format/length and compares the
   matched User's normalized email **strictly**: MySQL accent equivalence is not
   proof that two login addresses identify the same account.
3. Actor-based linkage requires a fresh exact `registrar` or `administrator`
   membership, a nonblank bounded reason, and affirmative identity confirmation.
   Cached roles cannot restore revoked authority. Coordinator, Finance, Viewer,
   Student, unrecognized and roleless actors are denied.
4. For actor-based linkage, a target may be roleless or carry only eligible
   Student variants. Staff, unknown and mixed-role targets are rejected before
   any mutation. Nothing strips their existing memberships. The trusted
   console's previously supported intentional multi-role operation is retained.
5. Inspect locked role values in PHP. Reuse exact `student`, update one
   case/trim variant such as `Student` **in place**, or add one canonical
   membership if absent. Multiple eligible variants fail without consolidation
   or deletion. Non-ASCII collation aliases fail safely under the retained
   uniqueness constraints. No global role normalization was added.
6. Before a new actor-based association, duplicate normalized Student contact
   addresses require identity review. A later duplicate contact value does not
   break an already-correct repeated association or disable an established login.
   Student contact and User login addresses are not silently synchronized.
7. No scholarship approval, enrollment verification, active award, financial
   record or complete demographic profile is required. Tests include archived
   Student records without scholarship assignments.
8. Preserve User credentials and unrelated role memberships. This service does
   not write password data; the existing hashed cast/authentication remain.
   There are no predictable/default passwords or passwords in results.
9. A changed link or Student membership writes one audit event with link IDs and
   canonical role. Case repair includes the original value. Actor calls record
   the real actor ID/snapshot and normalized reason; console provenance is kept
   only for the trusted-console path. A repeat writes no extra audit.
10. Creation, delivery, pending activation and deactivation are not guessed.
    They remain unavailable until their required implementation/readiness is
    completed under the approved design and D02 restriction.

Authorization is deliberately separate from shared association validation:
the policy owns actor/target access; the action owns transactional invariants.
The action enforces its actor entry point's Gates itself. Future HTTP entry
points must additionally enforce authentication, CSRF, approved recent-password
confirmation, rate limits and input whitelists. Those web entry points have not
been implemented or tested here.

## 4. Transaction and SR-01 compatibility

Within `DB::transaction(..., 3)`:

1. Lock the selected User, then the Student; reject missing/conflicting links.
2. Lock the selected User's memberships. Actor calls re-check current authority
   and reject protected targets using those loaded locked memberships.
3. Validate membership ambiguity and applicable duplicate-contact constraints.
4. Link the Student if unlinked; reuse/create/canonicalize the selected
   membership without changing credentials or unrelated roles.
5. Save an allowlisted audit when state changed; canceled audit writes fail.
6. Reload User, membership collection and Student; require exactly one eligible
   membership whose stored string is exactly `student`, the requested one-to-one
   relationship and the actual `viewOwnProfile` Gate allowed.
7. Return success only when the transaction completes. Expected validation or
   unique-index failure returns a structured error after rollback. Other errors
   also roll back and propagate.

Existing unique email, Student number, Student User-link, role membership and FK
constraints remain unchanged. No application database migration/reset was run.
No account-creation transaction exists to leave a partially created User: that
operation is blocked before writes.

The command retains `sms:link-student {email} {student_number}`, identical success
text and failure exit semantics. The original SR-01 tests now exercise the shared
action through this thin command. Existing Role/ownership authorization was not
made case-insensitive or weakened.

## 5. Files modified or added

| File | Change |
| --- | --- |
| `app/Actions/Accounts/LinkStudentAccount.php` | New centralized action with trusted console, actor-authorized linking and blocked creation entry points. |
| `app/Actions/Accounts/StudentAccountProvisioningResult.php` | Immutable, allowlisted success/failure/blocked results. |
| `app/Policies/StudentAccountProvisioningPolicy.php` | Approved actor authority and protected-target restrictions. |
| `app/Console/Commands/LinkStudentAccount.php` | Delegates linking to the action; preserves signature/output/exit behavior. |
| `app/Providers/AppServiceProvider.php` | Registers two named provisioning Gates without replacing existing policies. |
| `tests/Feature/Auth/StudentAccountProvisioningServiceTest.php` | Adds 31 cases for direct service results, canonical roles, actor/target matrices, identity, missing/conflicting records, duplicate contact email, blocked creation, safe serialization and rollback. |
| `tests/Feature/Database/StudentAccountProvisioningConcurrencyTest.php` | Adds three isolated real MySQL two-process actor-entry races. |
| `docs/16-student-account-management-design.md` | Records the user's approval, D01-B choice and D02 override, retaining the original proposal as history. |
| `docs/16-student-account-provisioning-service.md` | This approval/scope, implementation and actual verification record. |

No existing regression test was removed or rewritten. No route, view, User model,
Student model, auth controller, credential-delivery configuration or schema file
was changed. Previously dirty table-control and Student implementation files are
preserved apart from the explicitly listed command/provider changes.

## 6. Tests and actual results

All PHP tests target a newly created
`cfci_student_service_<random>_test` database, **MariaDB 10.11.16 through Laravel's
MySQL driver**, with `utf8mb4_unicode_ci`. An external PHPUnit configuration
forces testing environment, that database, empty `DB_URL` and an unused config
cache path. The configured application database is not a test target.

`$ServiceDir` is `%TEMP%\cfci-student-service-20261118-2118e030`. Logs and JUnit
evidence remain outside the repository. Results overlap; do not add them as
distinct coverage. No actual delivery or existing real-user credentials were used.

| Executed check | Actual result |
| --- | --- |
| `php vendor/bin/phpunit --configuration "$ServiceDir\mysql.phpunit.xml" --log-junit "$ServiceDir\focused-mysql.xml" tests/Feature/Auth/StudentAccountProvisioningServiceTest.php tests/Feature/Auth/StudentProvisioningRegressionTest.php tests/Feature/Auth/StudentAccountLinkTest.php` | **53 passed**, 486 assertions, exit 0; 12.890 s. This first focused run preceded expanding the blocked-creation test to include both unused and existing contact addresses. |
| `php vendor/bin/phpunit --configuration "$ServiceDir\mysql.phpunit.xml" --log-junit "$ServiceDir\full-mysql.xml"` | **591 passed**, **7,623 assertions**, **0 failures, 0 errors, 0 skips**, exit 0; **6 min 36.533 s**. |
| `php vendor/bin/pint --test` on the seven changed/added PHP files | Passed, exit 0. |
| `php -l` on the same seven PHP files | 7 checked; no syntax failures. |
| `npm run build` | Passed, exit 0; production build, 1.74 s. |
| `node --test tests/js/data-tables.test.js` | 3 passed; no failures/skips. |
| `git diff --check` | Passed, exit 0. |

Selected suites within the full MySQL result (all zero failures/errors/skips):

| Suite | Passed cases | Assertions |
| --- | ---: | ---: |
| New `StudentAccountProvisioningServiceTest` | 31 | 322 |
| New `StudentAccountProvisioningConcurrencyTest` | 3 | 39 |
| Original `StudentProvisioningRegressionTest` | 13 | 117 |
| Existing `StudentAccountLinkTest` | 9 | 54 |
| Existing `StudentAccountLinkConcurrencyTest` | 3 | 36 |
| Existing `StudentRoleTest` | 20 | 277 |
| Existing `ScholarshipLifecycleTest` | 6 | 1,119 |

The original MySQL capitalization test executed successfully rather than being
skipped: the same stored `Student` membership becomes `student`, command success
corresponds to profile HTTP 200, repeat execution remains idempotent, and real
login still succeeds with unchanged credentials. Original denied-access, all
five existing staff-role branches, operational role restrictions, billing,
academic/financial/evidence/report workflows and migration/locking cases also
passed through the full repository run. No observed test failure remains.

### Covered behavior

- Both approved actor roles, roleless/existing lowercase/capitalized/trimmed
  Student targets, same membership IDs, exact lowercase result and private
  own-profile HTTP 200; foreign detail/administrative pages remain 403.
- All unauthorized actor and staff/unknown/mixed-target cases, stale cached actor
  roles, missing identity attestation/reason, malformed input and missing records.
- Conflict in each association direction; contact-email duplicates; MySQL's
  accent-equivalent email identity; credential preservation and safe result JSON.
- Idempotent repeated service/command calls, unchanged fixture snapshots,
  audit provenance, policy-postcondition rejection and audit-exception rollback.
- New-account requests are tested to return **blocked**, including for distinct
  and already-used contact addresses. No new User, membership/link, token or
  notification is produced. Merely selecting SMTP in configuration changes none
  of this.
- New actor-entry races cover identical association, another target Student and
  another target account. Existing three console races/regressions remain.

### Blocked or unexecuted work

- **Successful new User provisioning is BLOCKED**, not passed: real credential
  delivery has not been approved/tested, and the user explicitly prohibited new
  creation until then. Its eventual happy-path/password/setup-token/concurrent
  creation tests cannot be claimed satisfied by current blocked-result tests.
- Lifecycle migrations, pending setup, activation/reset delivery, deactivation,
  reactivation, web confirmation/CSRF/rate-limit integration and actual mail
  tests are not implemented in this service extraction.
- Oracle MySQL and other collations/platforms are not certified by the MariaDB
  run. No new browser walkthrough or production rollout was performed.
- No standalone project type-check command is configured; PHP syntax/style and
  build checks are not represented as formal type checking.

## 7. Existing functionality, preservation and final review

Existing SR-01 mixed-case behavior, canonical reuse, unrelated memberships,
console arguments, exact policy checks, ownership, login and business workflow
coverage passed in the repository suite.

Before/after row counts and sorted-row SHA-256 fingerprints matched for **all 41
configured application tables**, including existing accounts/credentials,
Student records, memberships, reset tokens, sessions and audit/business history.
No application database records were provisioned, reset, deleted or remediated.
Only the newly created task database was dropped after tests, guarded by its saved
generated name, exact prefix/suffix/hex validation and exclusion of the configured
application database name. Race/migration databases were removed by their owning
tests. User application servers and the database service were not stopped.

The starting inventory covered **353 pre-existing files**. Only the intended
console command, provider and design approval record changed; the other **350
pre-existing files** matched their original SHA-256 values, including `.env`,
all pre-existing regression tests and table-control source. Six new files are
the action, result, policy, two test suites and this report. No dependencies or
credential configuration were changed.

Reviewed the shared transaction against the previous command, the result's
serialization allowlist, both authorization boundaries, the approved creation
block and all regression additions. The action never invokes a provisioning
command or chooses a privileged role from caller input. No generic User audit
snapshot or credential-return path was introduced.

## 8. Final verdict and remaining prerequisite

**BLOCKED for the complete new-account provisioning objective.** The approved
existing-account centralization is implemented and **passed** its direct service,
original SR-01, authorization, rollback, six console/service concurrency cases
and complete isolated MariaDB regression run.
New User creation remains deliberately unavailable under the user's D02 decision.
Before enabling it, establish and explicitly approve/test a real credential
delivery channel, then implement the approved pending-state/broker foundation
and its isolated happy-path/security/concurrency tests. A mail configuration
change alone is not that approval or evidence.
