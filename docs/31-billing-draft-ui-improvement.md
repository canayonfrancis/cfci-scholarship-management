# Billing draft detail UI improvement

2026-11-20 (Asia/Manila)  
**IMPLEMENTED — PENDING MANUAL VERIFICATION**

## Changes and reused components

Open **Billing and Validation → Billing batches → an existing billing batch**.

- The existing page heading displays the batch name, **Edit details** (authorized
  editable drafts only) and **Back to billing**. A compact summary shows program,
  academic year/semester, linked roster, billing status, approved rate and prepared
  total. **Additional billing details** retains preparer, submission reference,
  submitter/date, policy reference and historical guidance in a disclosure.
- Five compact responsive cards use the existing whole-roster readiness counts:
  total, eligible/available, pending review, excluded and billed/reserved. They
  remain visible outside the tabs; no status or count calculation was introduced.
- Exactly three tabs: **Roster Validation**, **Billing Items**, **Submission**.
  The existing Evaluation workspace tab styling and interaction pattern are reused
  with a small billing-specific Alpine component, rather than its assessment logic.
  A new draft defaults to Roster Validation. Other records default to Billing Items;
  valid `?tab=roster|items|submission` links restore the requested panel.
- Tab links support keyboard arrows, Home/End, focus, selected states and labelled
  panels. URL/history updates preserve search and table state. `x-show` keeps all
  panel contents mounted: switching tabs retains scholar selections, preview totals
  and review state without submitting anything. Normal full-page search/sort/page
  navigation still clears unsaved page-local selection as previously documented.
- Roster search, selection controls and review action remain outside the table's
  scroll area. Scoped styling gives names/courses wider columns, reduces cell
  padding and uses compact status labels. Long reasons show a short explanation
  with native expandable **full existing text**, never a substituted validation
  result. Authorized scholar/evaluation links remain. Shared sticky headers,
  independent two-axis scrolling, sorting, size controls and pagination are retained.
- Billing Items retains recorded amounts, validation/approval IDs, statuses, authorized
  removals, prepared total and approved adjustment history/actions. Its empty view
  links back to Roster Validation and explains that filters may hide saved items.
- Submission retains Coordinator approval/history, submission reference, requirements
  and disabled final submission. The Form 2/Form 3 blocker is also visible in the
  main summary with a link to Submission. The backend classifier is unchanged.
- **Edit details** uses the existing `record-form-dialog`, PATCH endpoint, fields,
  lock version and naming validation. Validation is returned in the `editBilling`
  bag, reopening the modal with entered values and field errors. JSON error behavior
  is preserved. Hidden UI-only `billing_tab` values restore the relevant panel after
  validation failures; pagination explicitly targets the table's own tab. Successful
  forms retain the current tab through the existing back redirect and updated URL.

## Scope and files

Changed:

- `resources/views/billing/show.blade.php`
- `app/Http/Controllers/BillingBatchController.php` — pagination tab links and
  edit-modal validation recovery only in this task.
- `resources/js/app.js` — registers the small tab component.
- `resources/css/app.css` — styles scoped to the billing detail layout.

Added:

- `resources/js/billing-workspace.js`
- `resources/views/billing/partials/roster-validation.blade.php`
- `resources/views/billing/partials/items.blade.php`
- `resources/views/billing/partials/submission.blade.php`
- This report.

Existing policies, endpoints, CSRF protection, audit and financial history, assignment
statuses, enrollment/evaluation/external approval rules, roster membership, rates,
calculations, reservations, duplicate prevention and financial approvals are reused
without business-rule changes. Shared selection/modal/table logic and horizontal
navigation are unchanged. No database table, migration, service or dependency added;
no database records were accessed or changed during this implementation.

## Static verification and developer commands

Only static source review and syntax/whitespace checks are permitted. No automated
tests were created/executed; no browser/manual test, application bootstrap, Blade
render, build, sample record, database operation or migration was performed.
Runtime appearance and interactions remain pending developer verification.

Actual static results:

- `php -l app/Http/Controllers/BillingBatchController.php` — passed.
- `node --check resources/js/billing-workspace.js` — passed.
- `node --check resources/js/app.js` — passed.
- `git diff --check` scoped to the tracked files changed in this task — passed;
  Git printed line-ending normalization notices only. New partials and this report
  were inspected directly; Blade rendering and CSS appearance were not executed.

Before manual verification, the developer should run **`npm run build`**, or use
the existing **`npm run dev`** server, to load the updated CSS and Alpine registration.
Use **`php artisan view:clear`** only if cached views need clearing. No migration is
required for this UI change. These developer commands were not executed here.

## Manual verification — all NOT TESTED

Use isolated development records and existing authorized test accounts.

1. Open a newly created draft. Confirm Roster Validation is selected and only its
   panel is visible. Check compact metadata/counts and expand Additional billing
   details to compare all original references, names, dates and links.
2. Select eligible scholars and open their review. Switch through all three tabs
   and return: checkboxes, count, amount preview and review state should remain.
   Test tab arrows, Home/End, browser Back/Forward and refresh of each tab URL.
3. In Roster Validation, search, sort, change rows per page and paginate. Check the
   roster tab remains selected. Inspect long names/courses, compact statuses and
   expandable full reasons, plus sticky headers and both scrollbars on narrow screens.
4. Review and save a valid group using isolated data. Confirm the existing bulk
   workflow, saved amounts and totals are unchanged. Try invalid/stale submissions;
   errors must be visible and the Roster Validation tab restored without partial saves.
5. Open Billing Items. Check amounts, IDs, statuses, sorting, page sizes and pagination;
   paging must stay on Billing Items. Exercise an authorized draft removal in isolated
   data. Check an empty/filtered view provides navigation back to Roster Validation.
6. Open Edit details from each tab. Try Cancel, Escape, dirty-form dismissal, valid
   changes, invalid names and a stale version. The shared modal must close normally;
   server validation must reopen it with values/errors and preserve the originating
   tab. Batch naming and role restrictions must match the previous behavior.
7. Open Submission and follow the summary's blocker link. Check the reference field,
   approval requirements and history. Final submission remains unavailable; an
   authorized direct request must still encounter existing server-side safeguards.
8. On retained submitted/approved test records, inspect authorized Coordinator
   approval in Submission and adjustment/history in Billing Items. Exercise existing
   validation paths only in isolated data; errors must restore the correct panel.
9. As a read-only or unauthorized user, verify existing page/action restrictions
   and hidden edit/remove/approval controls. Check the horizontal navigation and
   existing rate-confirmation modal remain operational. Record actual outcomes
   separately; no functional pass is claimed in this report.
