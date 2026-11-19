# Scholarship batches UI improvement

Date: 2026-11-19 (Asia/Manila)

**IMPLEMENTED — PENDING MANUAL VERIFICATION**

## Scope and existing layout

The existing `scholarship-batches` index displayed its creation form permanently
below the roster listing. It repeated the scholarship program/academic period
fields already used by the listing filters, including duplicate control IDs.
Creation errors shared the default validation bag with those filters.

This checkout uses `ScholarshipBatch` and `ManageScholarshipRoster` for semester
scholarship rosters. The Batch and Roster registry links deliberately reach this
existing module. This change moves that module's existing creation form; it does
not create a separate batch type, semester roster workflow or billing submission.
The original heading **Semester scholarship rosters** and description are retained.

## Implemented layout and modal

- Added **Create batch**, with a plus icon and the existing CFCI red primary-button
  class, to the upper-right actions area next to the existing Scholars link.
- The button and modal both use the original `@can('create', ScholarshipBatch::class)`.
- Replaced the permanently displayed creation card with **Create scholarship batch**.
- Opening the dialog does not navigate or submit the listing's filter form.
- Retained the original explanation about rates, enrollment, billing and adding
  students through their scholar records. Added visible required-field indicators.
- Added Cancel and an accessible close-icon button. Escape follows the same guarded
  cancellation path. Clicking the backdrop does not dismiss the dialog.

### Existing pattern reuse

The former Academic Verification Add/Edit Subject Blade modal and editor script
were removed in the earlier module-removal task. They are not recreated here.
The remaining evaluation evidence/activation dialogs still use native `<dialog>`,
`.academic-subject-dialog` and `.academic-dialog-{header,body,footer}`. These existing
styles and focus/scroll-management pattern are reused.

The new anonymous Blade component `components/record-form-dialog.blade.php` owns
the dialog shell, POST form, CSRF field, accessible labels and footer. It accepts
an ID, title, action, submit label, initial error-reopen flag and fresh field defaults.
It uses the new registered Alpine `recordFormDialog` component. There is no new
frontend library, route or parallel batch-creation business implementation.

`components/record-field.blade.php` remains the shared input/select component.
Optional `id` and `errorBag` props allow unique modal IDs and scoped validation;
their defaults preserve existing name-based IDs and the default error bag for
all existing callers. Error lists receive a data marker for fresh-dialog cleanup.

## Retained fields and validation

| Submitted name | Existing input/options | Existing validation retained |
| --- | --- | --- |
| `program_id` | Select program; existing programs ordered by code, displaying code | Required integer; exists in `scholarship_programs,id` |
| `academic_period_id` | Select period; existing academic-year/semester labels and ordering | Required integer; exists in `academic_periods,id` |
| `batch_number` | Roster batch name; text with `maxlength="60"` | Required string; max 60; `regex:/\S/` |

No new business fields or validation requirements were introduced. HTML-required
attributes remain. Fresh modal program/period values follow the current filters,
as in the old inline form. A fresh batch name is blank. Modal field IDs are
`create-batch-program`, `create-batch-period` and `create-batch-name`; submitted
names remain unchanged and no longer duplicate the filter controls' IDs.

## Submission, errors and cancellation

1. Clicking the authorized trigger dispatches a dialog-specific Alpine event.
   The native dialog enters the top layer, locks background body scrolling and
   focuses the first field. Keyboard Tab/Shift+Tab remain within visible enabled
   dialog controls. The native modal also prevents background interaction.
2. The original POST to `scholarship-batches.store` and `@csrf` are retained.
   Native constraint validation runs before submit. Once submission starts, the
   submit/close/Cancel buttons are disabled, the submit label becomes Creating…,
   and subsequent submit events are prevented. Named inputs and CSRF remain enabled.
3. `ScholarshipBatchController::store` still authorizes before validation and calls
   `ManageScholarshipRoster::create` with the same validated data. Its only behavioral
   adjustment is tagging `ValidationException`s as `createBatch`, including the
   action's existing duplicate-name validation exception.
4. Laravel's existing validation redirect and old-input flashing remain responsible
   for returning to the referring listing with its query context. The named error
   bag reopens the modal, preserves submitted values and displays an error summary
   plus field-specific messages/ARIA error references. Listing-filter validation
   continues to use the default bag and cannot itself trigger the creation modal.
5. The dialog focuses the first invalid field, falling back to its error summary.
   Validation failures do not call the successful-close path.
6. **Successful creation preserves the existing redirect to the new batch's detail
   page**, not a new redirect to the listing. The exact existing flash message remains:
   `Semester roster created. No billing or financial amount was recorded.` Returning
   to the listing uses its unchanged newest-ID ordering and existing filters/paging;
   a new record appears only when that scope/page includes it.
7. Cancel, close icon and Escape ask for confirmation when values differ from the
   initial form or when recovering a failed submission. Declining leaves values/errors
   intact. Accepting discards inputs, clears server error markup/ARIA error state,
   restores current filter-based defaults, closes the modal, restores prior body
   overflow and returns focus to Create batch. Cancelling an untouched fresh form
   closes without confirmation and never submits.
8. Navigating away with unsaved modal data uses the browser's native unload warning
   where supported. A genuine form submission bypasses that warning. The pageshow
   handler clears a stale busy state on browser-history restoration.

Unexpected non-validation failures remain handled by existing Laravel error
handling. They are not converted into successful creation messages or hidden by
the modal controller. No AJAX submission or secret error details were introduced.

## Authorization and data protection

Source evidence:

- `routes/web.php`: existing authenticated resource routes are still limited to
  index/store/show, with private/no-store cache middleware.
- `ScholarshipBatchPolicy::viewAny`: Coordinator, Registrar and Administrator may
  view. Inherited `RecordPolicy::create` permits Coordinator and Administrator;
  Registrar viewing permission does not grant creation permission.
- `ScholarshipBatchController::store`: original server-side creation Gate remains.
- `ManageScholarshipRoster::create`: independent actor authorization, transaction,
  related-record locks, trimmed name, existing creator ID and
  `scholarship_roster_created` audit event remain untouched.
- `2026_11_13_000009_add_independent_scholarship_rosters.php`: existing unique
  `(program_id, academic_period_id, batch_number)` constraint remains. Its exception
  still becomes the original field validation message, now shown inside the dialog.

No database/schema changes, migration execution, sample batch creation or existing
record changes were performed. Scholarship eligibility, academic sources, assignment
and roster membership, billing, liquidation, payments and historical records are
unmodified. This task does not add editing or status-management operations that
the existing batch routes do not support.

## Data table and navigation preservation

The listing section itself is unchanged: original GET filters, scoped options,
rows, links, empty state, `<x-table-state>`, table classes and Laravel pager remain.
The shared implementation documented in
`docs/24-system-wide-data-table-ui-improvement.md` is reused without changes to
`resources/js/data-tables.js` or its table CSS rules:

- Search/filter controls remain above the listing, outside the modal. This page
  originally has two select filters; no new text-search requirement is invented.
- One onchange Rows per page selector remains above the table, with existing
  10/20/50/100/200 options and per-table query state.
- Independent bounded vertical/horizontal scrolling and sticky headers remain.
- Existing sort controls, result count and pagination below the viewport remain.
- Existing batch detail links and empty-state colspan/message remain.

The existing horizontal navigation and registry aliases are unchanged. The dialog
is outside the table's overflow wrapper and enters the native top layer, preventing
table/header clipping or a page-layout shift from its opening.

## Responsive and accessibility behavior

The shared dialog shell supplies a white surface, rounded corners, border, shadow,
subtle backdrop and viewport-based height cap. Scoped `.record-form-dialog` rules
limit width to 680px or viewport width minus 32px. The existing two-column form
becomes one column on small screens; the name spans the form width. The form can
shrink, its body scrolls internally, and the header/footer remain outside that body.
Footer buttons wrap on narrow screens. No document-wide horizontal overflow or
navigation styling is added.

Dialog title/description are referenced through `aria-labelledby`/`aria-describedby`;
the trigger has `aria-haspopup="dialog"` and `aria-controls`. The close icon has an
accessible label. Labels target unique input IDs. Native required validation,
`aria-invalid`, field error references, an alert summary and form `aria-busy` are
used. Focus returns to the trigger after deliberate closing.

## Files created or modified

| File | Change |
| --- | --- |
| `resources/views/scholarship-batches/index.blade.php` | Authorized header action; inline form moved into dialog; original listing retained |
| `resources/views/components/record-form-dialog.blade.php` | New reusable native dialog/form shell |
| `resources/js/record-form-dialog.js` | New reusable Alpine lifecycle, focus, scroll, cancellation and submit guard |
| `resources/js/app.js` | Import/register the new Alpine component |
| `resources/views/components/record-field.blade.php` | Optional unique ID/named-error-bag support with compatible defaults |
| `resources/css/app.css` | Scoped form-dialog sizing, scrolling layout and responsive styles |
| `app/Http/Controllers/ScholarshipBatchController.php` | Scope create validation/duplicate errors to the modal; retain rules/action/redirect |
| `docs/25-scholarship-batches-ui-improvement.md` | Implementation/static-review report and unexecuted manual guide |

## Static checks and actual results

Only source inspection and permitted compilation/syntax/format checks were used.

| Command/check | Actual result |
| --- | --- |
| `node --check resources/js/record-form-dialog.js` | Exit 0; valid syntax |
| `node --check resources/js/app.js` | Exit 0; valid syntax |
| `php -l app/Http/Controllers/ScholarshipBatchController.php` | Exit 0; no syntax errors |
| Blade `compileString` of the modified index, shared record-field and new record-form-dialog | Three source templates compiled without rendering or invoking routes |
| `php -l` on each of those three compiled templates | All three valid PHP |
| `git diff --check` | No whitespace errors; Git only noted CRLF/LF normalization |
| `npm run build` | Exit 0; Vite 7.3.6, 64 modules transformed |

Blade compilation used a temporary PHP helper outside the repository with the
existing application Blade compiler; it did not render templates, invoke HTTP
scenarios or create fixtures. Syntax/build results do not establish runtime or
visual correctness. No automated tests were created/executed. No PHPUnit, Pest,
Artisan test, browser/manual testing, database records or migrations were run.

Static diff review confirms the original creation rules, action, model relationships,
transaction/locks/constraint/audit, policies, route definitions, table utility and
navigation templates were not redesigned. The backend diff is limited to preserving
the creation flow while tagging validation failures for modal recovery.

## Required developer commands

Build the frontend assets through the existing deployment command:

```powershell
npm run build
```

This was executed locally. No dependency installation or database command is needed.
Follow the existing deployment procedure for view-cache refresh if applicable.

## Beginner manual verification guide — NOT EXECUTED

All following scenarios are **NOT TESTED**. Use authorized local development
accounts and isolated fictional data prepared by the developer. No credentials,
test accounts or sample batches were created during this implementation.

1. Sign in as an existing authorized Coordinator or Administrator. Open
   **Information Registry → Batch** (or **Roster**). Expect the same heading,
   description and listing, with Create batch at the top and no inline creation card.
2. Click Create batch. Expect a centered dialog titled Create scholarship batch,
   three existing fields, helper text, required indicators, Cancel and Create batch.
   The listing URL must not change merely from opening the dialog.
3. Cancel the untouched form, then reopen and try the close icon and Escape. Expect
   no record creation, the modal closed and focus restored to Create batch.
4. Change a field. Try each cancellation method. Decline the discard confirmation;
   expect the dialog/values retained. Accept it; reopen and expect a blank name,
   current filter-based program/period defaults and no stale errors.
5. Change listing filters, page size, sort and page. Open/cancel the dialog. Expect
   those table states untouched. Filter defaults should be reflected in a fresh form.
6. On isolated fictional scope, submit an empty form and an overlong name. Expect
   native required/length checks where applicable and no premature busy lock. To
   exercise server-only validation, use the developer's normal controlled request
   tools to submit whitespace-only or invalid IDs while retaining valid auth/CSRF.
   Expect a validation redirect to the listing, reopened modal, entered values and
   field-specific errors. Do not alter real records for this scenario.
7. In the isolated development scope, enter a unique fictional batch name using real
   existing development option values, such as `UI25-MODAL-REVIEW-01`. Submit once.
   Expect the existing detail redirect and exact success notification. Return to the
   listing; confirm the batch appears under matching filters/newest sorting.
8. Repeat the same program, period and name. Expect no duplicate, the original
   duplicate-name message on the name field, and the modal reopened with values.
   Cancel and reopen to confirm that the stale error is cleared.
9. Submit a fresh isolated creation and attempt rapid repeated clicks/Enter. Expect
   a single active UI submission, disabled busy controls and unchanged backend
   duplicate protection. Confirm normal interaction when returning with browser Back.
10. As Registrar, expect the existing view permission with no Create batch button
    or dialog. As Student/other unauthorized roles, expect existing page restrictions.
    Direct unauthorized POST requests must still be denied by server authorization.
11. Use Tab and Shift+Tab through the dialog. Expect no focus escape; initial errors
    should focus their field, and deliberate close should return to the trigger.
    Verify labels, required indicators and busy/error messages with assistive tools.
12. At a narrow viewport or short screen, expect a one-column form and internal modal
    body scrolling, with reachable close/footer buttons and no horizontal page overflow.
    Background scrolling must be locked only while open and restored on close.
13. Verify table page-size changes, sorting, counts, pagination, vertical/horizontal
    scrolling, sticky headers, empty state and detail links as described in report 24.
    Confirm the horizontal navigation and desktop hover/touch fallback are unchanged.

Expected outcomes above come from source review, not executed functional scenarios.

## Known limitations and final status

- Opening/closing, focus, scrolling, native confirmation behavior, actual form POSTs,
  validation redirects, role scenarios and layout remain pending developer verification.
- No browser testing or screenshots were performed. Native `<dialog>` and the existing
  Alpine/Vite bundle are required; no legacy-browser or no-JavaScript fallback was added.
- Button locking prevents accidental repeated UI submissions; existing database
  uniqueness remains the protection against concurrent/independent requests. No new
  idempotency scheme or schema was introduced.
- Session expiry, CSRF failures, unexpected server failures and external navigation
  retain existing framework behavior; modal recovery specifically handles existing
  validation failures. The browser controls whether unload warnings can be displayed.
- Existing successful navigation remains the detail page. Matching listing filters
  and paging determine where a created record is visible on returning to the list.

**Final status: IMPLEMENTED — PENDING MANUAL VERIFICATION.**
