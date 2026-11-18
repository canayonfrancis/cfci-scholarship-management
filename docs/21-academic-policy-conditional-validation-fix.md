# Academic policy conditional validation fix

Date: 2026-11-19 (Asia/Manila)

**IMPLEMENTED — PENDING MANUAL VERIFICATION**

## 1. Scope and confirmed root cause

Targeted correction to the failed-subject approval condition in Program-specific
academic requirements. Root cause was confirmed by static application and installed
Laravel source inspection. The reported submission was not reproduced at runtime
because this task explicitly prohibits automated and manual functional testing.

Previously `AcademicRequirementRequest::rules()` contained:

```php
'failed_limit_approved' => [
    Rule::requiredIf($this->filled('max_failed_subjects')),
    'nullable',
    'accepted',
],
```

There were two errors:

1. `accepted` was attached unconditionally. Laravel treats it as an implicit required
   rule, so an omitted unchecked checkbox failed even when the maximum was blank and
   the conditional `requiredIf` evaluated to false. `nullable` does not suppress an
   implicit rule. This explains the generic “must be accepted” message.
2. The `requiredIf` condition did not distinguish `draft` from `confirm`, so an entered
   but unapproved proposed limit could not be retained as a draft either.

Installed framework source evidence, inspected without invoking validation scenarios:

- `vendor/laravel/framework/src/Illuminate/Validation/Concerns/ValidatesAttributes.php:44`,
  `validateAccepted()`, explicitly combines `validateRequired()` with the allowed
  accepted values.
- `vendor/laravel/framework/src/Illuminate/Validation/Validator.php:207` lists
  `Accepted` among implicit rules. Its `presentOrRuleIsImplicit()` and
  `isNotNullIfMarkedAsNullable()` methods explain why missing/null values do not
  bypass this rule.
- `vendor/laravel/framework/src/Illuminate/Validation/Rules/RequiredIf.php` returns
  an empty rule string for a false condition; it does not remove other rules.

The Blade checkbox already had `value="1"` and no hidden fallback input. An unchecked
checkbox is omitted by the browser. No hidden zero value caused this failure.
Existing blank-to-null handling and the controller's conditional integer conversion
already distinguished null from zero; no database defect caused this validation error.

## 2. Inspected architecture and permissions

Reviewed the existing authenticated POST route
`/scholarship-programs/{program}/academic-requirements`, named
`scholarship-programs.academic-requirements.store` (`routes/web.php:131`), its
12-per-minute throttle, request/controller, `ScholarshipProgramController::show()`,
`ScholarshipProgramPolicy`/`RecordPolicy`, `ProgramAcademicRequirement`,
`ProgramPeriodTerm`, the academic requirements component, existing schema migration,
`SchoolGradingPolicy`, evaluation currentness and activation checks.

The existing program update permission permits Coordinator and Administrator.
No new role or permission was added. The request still checks that permission and
the controller still rechecks it against a freshly locked actor/program. The
transaction, stale revision check, append-only version creation, confirmed actor/time,
audit recording and audit persistence check are unchanged.

The model stores academic settings in the existing JSON `criteria`; the failed limit
is not a separate required database column. Status, source/version references and
confirmation metadata remain separate version fields. No redundant academic
requirements service was introduced.

## 3. Corrected draft validation

`AcademicRequirementRequest.php:25` now derives:

```php
$failedLimitApprovalRequired = $confirming && $this->filled('max_failed_subjects');
```

Only that condition attaches `accepted`. Otherwise the checkbox is optional with
`nullable` and `boolean` validation. The existing HTML checkbox supplies `1` when
checked; omission, null, false and `0` are valid optional states. Malformed supplied
optional checkbox values are rejected rather than persisted as arbitrary data.

**Save academic policy draft** therefore permits:

- Blank/null maximum failed subjects and an unchecked failed-limit approval.
- A valid zero or positive proposed maximum without its approval checkbox.
- An unchecked general confirmation checkbox.
- Unconfigured academic selections and absent approval source/version references.

All supplied policy values must still satisfy their existing format/range and
consistency rules. For example, negative/fractional/noninteger failed limits are
rejected, and a supplied minimum GWA still needs the “minimum required” selection.
“Incomplete draft” does not mean accepting invalid supplied values.

`AcademicRequirementController.php:35` determines the operation before constructing
criteria. At line 44 it persists `failed_limit_approved=true` only for a confirmed
version with a supplied limit and accepted approval. Every newly saved draft records
that approval as false, even if the checkbox was checked during the draft save.
The proposed numeric limit remains available for subsequent review. Confirmation
requires the user to explicitly acknowledge approval in that confirmation request.

Draft status remains `draft`; `confirmed_by` and `confirmed_at` remain null.
Drafts never acquire confirmation authority from a checkbox.

## 4. Corrected confirmation validation

For **Confirm new academic policy version**:

- Missing, blank or null maximum: no failed-limit acceptance requirement; persisted
  limit remains null, and persisted failed-limit approval is false.
- Zero or a positive integer maximum: explicit accepted failed-limit approval is
  mandatory. Unchecked/omitted/null/false/`0` approval rejects confirmation.
- Invalid supplied maximum: the unchanged integer/nonnegative validation rejects it.
  A supplied invalid nonblank maximum can also produce an approval error; accepting
  the checkbox cannot make the invalid maximum valid.

All other confirmation requirements remain: explicit Boolean policy selections,
valid required minimum percentage GWA where applicable, INC/dropped review choices,
approved source and version references, and accepted `confirm_authority`.
Authorization and stale-version checks still apply independently to both operations.

## 5. Null versus zero

The existing web middleware trims text and converts empty strings to null. Installed
`InteractsWithData::filled()` uses a trimmed string comparison, so `0` and `"0"`
are filled, whereas missing/null/blank/whitespace are not. No truthiness or `empty()`
check is used to decide whether zero requires approval.

The controller retains:

```php
'max_failed_subjects' => isset($data['max_failed_subjects'])
    ? (int) $data['max_failed_subjects']
    : null,
```

Thus unconfigured criteria retain JSON `null`; configured zero retains integer `0`.
The view uses null coalescing rather than a truthy fallback, so zero remains visible.
No defaults, backfills or conversions of existing records were performed.

## 6. Failed-subject rules and draft safety

Completed failing numeric subjects still participate in provisional GWA. Failed
subjects still append an academic review reason; no limit is compared to a failed
count to automatically disqualify a scholar.

The only change to `SchoolGradingPolicy.php:42` is descriptive text: a recorded
maximum is called “approved” only if the policy is confirmed and its approval flag
is true. A draft/unapproved proposed value is described as unconfirmed. The existing
review branch, review precedence and final pass/fail/review aggregation are unchanged.

The existing policy guard requires confirmed status, confirmed actor/time and source/
version references. `EvaluationRun::isCurrent()` still rejects a latest draft policy
or a policy version different from the pinned snapshot. A new draft continues to
require reassessment before reliance; it cannot authorize activation. Historical
decisions, grades, policy versions, certificates and active scholarship records are
not rewritten. Unrelated minimum-GWA, standing, enrollment, INC/dropped, external
approval and activation safeguards remain unchanged.

## 7. Messages and interface

The relevant confirmation error now reads:

> Confirm the approved failed-subject limit before confirming this academic policy.

It is defined in `AcademicRequirementRequest::messages()` and appears beside the
approval checkbox through `x-input-error`. The general authority checkbox also has
a clear confirmation-specific error. Both retain page-level validation reporting,
have associated labels/error descriptions and recover checked state from old input.

Help text now states that:

- The maximum is an optional manual-review reference; blank is not zero.
- Drafts may retain an unapproved proposed limit.
- Failed-limit approval is needed only when confirming a policy containing a limit,
  including zero.
- General confirmation, complete selections and approved references are required for
  confirmation, while drafts can remain incomplete.

A proposed maximum on the current draft is visibly labeled unconfirmed. No controls,
workflow, navigation or page layout outside this section was redesigned.

## 8. Files changed by this task

- `app/Http/Requests/AcademicRequirementRequest.php`: operation-specific checkbox
  validation and understandable messages.
- `app/Http/Controllers/AcademicRequirementController.php`: prevent draft approval
  metadata from representing an approved failed limit.
- `app/Services/Eligibility/SchoolGradingPolicy.php`: distinguish approved versus
  unconfirmed limits in the existing manual-review explanation only.
- `resources/views/components/program-academic-requirements.blade.php`: adjacent
  checkbox errors, contextual help, old checked state and unconfirmed value label.
- `docs/21-academic-policy-conditional-validation-fix.md`: this report.

The repository contained earlier changes before this task. A task-start source hash
comparison identified exactly the four application/template files above as changed
during this fix, with zero test, route or schema files changed.

## 9. Database changes and developer commands

No database schema change or new migration is required. No existing records were
updated, deleted, reset, seeded, imported or recertified. All normal future saves
continue appending a policy version through the existing action.

There are no new setup commands or dependencies. Existing REC-01 migration/readiness
requirements still apply; this task did not execute any migration. No frontend asset
code or new style class was added, so an asset rebuild is not required by this fix.
Use the application's normal deployment procedure if Blade views are cached.

## 10. Actual static checks and findings

Performed source-only review of missing/null/blank/zero/positive values, unchecked
and explicit false checkbox states, draft versus confirmation branches, storage
casting/status metadata, versioning, authorization, audit, review and activation guards.
No validation scenario or functional form submission was executed.

Commands actually executed:

```powershell
php vendor/bin/pint app/Http/Requests/AcademicRequirementRequest.php app/Http/Controllers/AcademicRequirementController.php app/Services/Eligibility/SchoolGradingPolicy.php
php -l app/Http/Requests/AcademicRequirementRequest.php
php -l app/Http/Controllers/AcademicRequirementController.php
php -l app/Services/Eligibility/SchoolGradingPolicy.php
git diff --check
```

Results: Pint completed successfully; all three PHP sources had no syntax errors;
no whitespace errors were reported. Git emitted CRLF-to-LF notices for existing
working-tree files. A temporary source-compilation helper called Blade
`compileString()` for the modified component, then `php -l` checked the resulting
temporary source: no syntax errors. No template was rendered, HTTP request simulated,
validator case invoked or database write action run.

No PHPUnit, Pest, Artisan tests, automated test cases, manual/browser functional
tests, fixture records or migration commands were created/executed. Existing tests
were left intact. Static findings support the corrected branch logic; runtime
behavior remains pending developer verification.

## 11. Manual verification instructions — NOT EXECUTED

Use a controlled development environment, an existing authorized Administrator or
Coordinator, and a designated fictional development program/period. Do not modify
real policies to perform these checks. No login credentials or fixtures are supplied
or created by this task.

1. Open **Scholarship programs → View → Program-specific academic requirements**.
2. Select the intended academic year/semester and click **Load academic requirements**.
   Resolve any existing pending-upgrade notice through the separately reviewed REC-01
   setup before testing saves.
3. For a minimal draft, leave maximum failed subjects blank, both checkboxes unchecked,
   optional references blank and unresolved selections unconfigured. Click **Save
   academic policy draft**. Expect a new Draft version with no failed-limit approval
   error, null maximum, false failed-limit approval and no confirmer/date.
4. Reload the selected period after each successful save so `previous_version` is
   current. Repeat the draft check with zero, then a positive integer such as `2`.
   Keep approval unchecked. Expect each proposed value retained without confirmation.
   Use `2` only as fictional test data, not as an actual program rule.
5. For confirmation, complete all four Yes/No policy selections using the fictional
   source, choose **Academic review required** for INC/dropped handling, and provide
   fictional source/version references. If the fixture has a minimum GWA requirement,
   supply its valid percentage. Otherwise explicitly select No for that requirement
   and leave its threshold blank. Check general policy authority.
6. Leave maximum failed subjects blank and its approval unchecked. Click **Confirm
   new academic policy version**. Expect successful confirmation if all other required
   data is valid, with maximum null and no failed-limit approval requirement.
7. Repeat confirmation with maximum `0` and approval unchecked. Expect the new clear
   error beside its checkbox and no new persisted version. Check its approval and
   resubmit with the other confirmation requirements satisfied; expect integer zero
   retained in a new confirmed version.
8. Repeat with proposed maximum `2`. Expect the same conditional approval behavior.
   Verify an unchecked general authority checkbox or missing source/version still
   blocks confirmation, even when failed-limit approval is checked.
9. Try negative, fractional or text limits in controlled requests. Expect rejection
   by existing integer/range rules for both operations. Browser number controls may
   prevent entering text; use developer tools only on the isolated fictional form
   if inspecting backend rejection. Never substitute production records.
10. Inspect retained versions and audit history after successful saves. Expect older
    versions unchanged, sequential appended revisions and existing actor/event data.
    A stale second-tab save should still receive the existing reload/version error.
11. With fictional failing numeric grades, inspect the existing evaluation workflow.
    Expect failed subjects to remain included in provisional GWA when otherwise
    eligible, and failed cases to require academic review. A draft must remain
    unconfirmed and cannot authorize a final award. No failed-count limit should
    automatically disqualify the scholar or alter previous decisions/active awards.

Expected boundary outcomes, derived from source inspection only:

| Operation | Maximum | Failed-limit checkbox | Expected result, assuming all other required input is valid | Actual functional status |
| --- | --- | --- | --- | --- |
| Draft | Missing / null / blank / whitespace | Unchecked | Save draft; maximum null; approval false | NOT TESTED |
| Draft | `0` | Unchecked | Save draft; integer zero; approval false | NOT TESTED |
| Draft | `2` | Unchecked | Save draft; integer two; approval false | NOT TESTED |
| Draft | `2` | Checked | Save draft; still not an approved policy/limit | NOT TESTED |
| Confirm | Missing / null / blank / whitespace | Unchecked | No failed-limit acceptance error; maximum null | NOT TESTED |
| Confirm | `0` or `2` | Missing / null / false / `0` | Reject with conditional approval error; no version created | NOT TESTED |
| Confirm | `0` or `2` | Checked | Retain configured integer and approval; confirm only with all other requirements | NOT TESTED |
| Confirm | Any valid maximum | General authority unchecked | Reject general policy confirmation | NOT TESTED |
| Either | Negative / fractional / malformed | Either | Reject invalid maximum; checkbox cannot legitimize it | NOT TESTED |
| Either | Valid data | Unauthorized actor / stale revision | Existing denial/stale-version safeguard | NOT TESTED |

## 12. Remaining limitations and final status

- Functional form saves, SQL transactions, version/audit persistence, concurrency,
  eligibility/activation, browser error placement and regression behavior were not
  executed; the developer must verify them.
- No existing policy/decision is repaired or rewritten. New draft saves correctly
  store unapproved proposed limits; historical versions remain immutable.
- Approval flags record an authorized user's attestation against a policy reference;
  this fix cannot independently establish whether the cited school approval is real.
- Official GWA formula/rounding approval and actual program thresholds remain outside
  this conditional validation fix.

**Final status: IMPLEMENTED — PENDING MANUAL VERIFICATION.**
