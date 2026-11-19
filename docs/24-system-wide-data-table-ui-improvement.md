# System-wide data-table UI improvement

Date: 2026-11-19 (Asia/Manila)

**IMPLEMENTED — PENDING MANUAL VERIFICATION**

## 1. Scope and existing issues

Standardized applicable existing data tables through the shared JavaScript/CSS layer.
The existing table utility already supplied sorting, per-table state, server/client
pagination and page-size options. It placed the page-size selector in its footer.
Most wrappers scrolled only horizontally, so large pages could become very tall.
Headers were not sticky, and the dashboard/record tables had separate wrapper styles.

This change follows the latest requirement to place Rows per page **above** tables,
superseding earlier below-table placement. Existing search/filter forms already sit
above their relevant tables; they retain their controls, field names, selected values,
actions and queries. No new blanket search or per-column text filters were introduced.

No automated tests were created/executed. No manual/browser testing, sample records,
database writes or migrations were performed. Only static inspection, syntax checks,
Blade source compilation and asset compilation were used for verification.

## 2. Inventory of existing table sources

Static inspection found **34 `<table>` declarations across 30 Blade templates**.
These are source declarations, not a runtime table count: shared/recursive partials
can render more than once, and conditional tables may not render at all.

Paths below are relative to `resources/views/`. Standard application tables inherit
the shared enhancement; dedicated print rendering is preserved separately.

| Template | Declarations | Page/module and treatment |
| --- | --- | --- |
| `dashboard.blade.php` | 1 | Recent Validations; existing server table marker/pagination |
| `programs/index.blade.php` | 1 | Scholarship Grant / Program registry |
| `programs/show.blade.php` | 1 | Program scholarship assignments |
| `scholars/index.blade.php` | 1 | Scholar registry/search |
| `scholars/show.blade.php` | 1 | Scholar scholarship history and authorized status actions |
| `scholars/bulk-upload.blade.php` | 1 | Existing upload preview; confirmation remains outside the viewport |
| `periods/index.blade.php` | 1 | Combined Academic Year / Semester registry |
| `periods/show.blade.php` | 1 | Period assignment records |
| `scholarship-batches/index.blade.php` | 1 | Existing Batch / Roster registry |
| `scholarship-batches/show.blade.php` | 1 | Roster member/enrollment/billing table |
| `student-accounts/index.blade.php` | 1 | Student account directory and existing search/status filters |
| `evaluations/index.blade.php` | 1 | Scholarship evaluations directory |
| `evaluations/partials/academic-records.blade.php` | 1 | Retained enrollment/grading source metadata |
| `components/reported-grades-table.blade.php` | 1 | Existing reported/computed academic subject grades |
| `evaluations/partials/supporting-evidence.blade.php` | 1 | Private evidence; row review/download actions |
| `evaluations/partials/external-history.blade.php` | 1 | External decision history, reused in two workspace tabs |
| `components/evaluation-snapshot.blade.php` | 1 | Specialized recursive label/value history; no column header/pager; excluded from generic enhancement |
| `billing/index.blade.php` | 1 | Billing batches; existing filter dependencies retained |
| `billing/show.blade.php` | 2 | Allocation items and independently paginated candidate search |
| `billing/report.blade.php` | 1 | Scholar allocation history/report |
| `components/allocation-summary.blade.php` | 1 | Shared program/period budgets and funding summary |
| `financial/index.blade.php` | 2 | Submitted batches and school receipts; separate server page parameters |
| `financial/funding-status.blade.php` | 1 | Billing/funding stage records |
| `financial/batch.blade.php` | 1 | Student payment records including retained empty-payment rows |
| `financial/receipt.blade.php` | 1 | Receipt allocation history |
| `financial/payroll.blade.php` | 1 | Current payroll source comparisons; also includes prepared register below |
| `financial/payroll-register.blade.php` | 1 | Prepared payroll register, retained totals/footer; shared with dedicated print view |
| `financial/liquidation-summary.blade.php` | 1 | Liquidation payment/settlement lines; shared with web and dedicated print views |
| `scholarship-reports/table.blade.php` | 1 | Main dynamic report columns and source links |
| `scholarship-reports/context.blade.php` | 3 | Roster grouping, funding stages and evidence/history exceptions |

The 33 standard declarations use `records-table` or the dashboard's existing
`recent-validations-table`. No mass rewrite of their individual views is needed.

Additional inspected layouts:

- `academic-programs/index.blade.php` (Course / Program) is an editable list, not a
  table. Its list is now scrollable while its existing **15-record** server pagination,
  create form and authorized status actions remain unchanged. No duplicate client
  pagination or new page-size options are applied to that list.
- `evaluations/show.blade.php` and `evaluations/partials/history.blade.php` compose
  the table partials and specialized history details. Existing tabs/accordions remain.
- `scholarship-reports/index.blade.php`, `financial/liquidation.blade.php` and shared
  financial/report pages include the inventoried partials.
- `financial/payroll-print.blade.php`, `financial/print.blade.php` and
  `scholarship-reports/print.blade.php` use dedicated print styles without the shared
  application script. They keep complete specialized print layouts, not UI pagination.
- Reason for Delisting remains unavailable as previously requested; no table exists.
- The standalone Academic verification module was removed previously and is not
  recreated. Existing academic sources, grades and history remain read-only in
  evaluations according to that removal.
- Forms, timelines, calculation summary grids and non-tabular reports were not
  converted into unnecessary data tables.

## 3. Shared reuse and standard layout

Reused `resources/js/data-tables.js`, existing `.records-table-wrap` / dashboard
`.table-wrap` containers, `components/table-state.blade.php`, `TablePaginator` and
`TablePagination`. No new data-table dependency or parallel implementation was added.

Enhanced tables follow:

1. Existing section heading and authorized primary actions.
2. Existing search/filter form, where one exists.
3. One shared Rows per page toolbar.
4. One scrollable table viewport with its existing header/body/footer.
5. Shared result count and existing server pagination or existing client page controls.

The enhancer reuses the existing wrapper rather than nesting another scroll owner.
Its fallback creates one wrapper only when a table has none. It adds a labeled,
keyboard-focusable region without overriding an existing label/role/tab index.
No heading, filter form, action form or table row is cloned.

## 4. Search/filter and page-size placement

Existing module filters already precede their table wrappers. They remain above the
new page-size toolbar, with responsive wrapping/spacing. This includes scholar/student
search, program/course-related selection where implemented, academic year/semester/
period, status, batch and report scope controls. Existing Filter/Search/Generate/Clear
buttons and billing filter dependency JavaScript remain intact. Table-free pages do
not acquire invented filters.

The existing `pageSizeControl()` now appends to a toolbar **before** the wrapper rather
than the footer. It is still called once per enhanced table. The options remain
**10, 20, 50, 100, 200**, default 10 or the existing selected valid size. It continues
using the **change** event. The existing backend size validation was not modified.

On change, client pagination resets to page 1 and clamps via the existing `pageRange`.
Server navigation removes only the current table's existing `pageName` query parameter,
preserving the other table states and module filters. Sort changes continue using
the same existing reset/navigation behavior. No backend query is changed or loaded
without its existing pagination boundary.

## 5. Independent horizontal/vertical scroll and sticky headers

The shared viewport has `overflow:auto`, width/max-width 100%, min-width 0 and
maximum height `min(36rem, 60vh)`, upgraded to `60dvh` where supported. There is no
new fixed/minimum height: small tables fit their content naturally. The height cap
leaves viewport room for surrounding headers/controls; other page content still
scrolls naturally. Body scrolling is never disabled by these table styles.

Wide columns retain their existing table minimum widths and scroll horizontally
inside their card. Longer rendered pages scroll vertically in the same container.
Subtle visible scrollbar styling is applied; no data-table scrollbar is explicitly
hidden. Mouse/trackpad/touch native scrolling remains, and focusable regions permit
keyboard scrolling. Existing dashboard program-list scrolling is untouched.

The existing `thead` is sticky at top 0 within the viewport, with opaque header/cell
backgrounds and local stacking above ordinary rows. The whole header group sticks,
so multi-row headers can move together without invented per-row offsets. There are
no cloned headers or split header/body tables. Existing first-header-row sorting
logic and chevron controls are unchanged. Separate borders with zero spacing avoid
collapsed-border paint issues while preserving column alignment and existing widths.

Application print rules remove viewport height caps, sticky positioning and interactive
toolbars/footers. Existing client `beforeprint`/`afterprint` row handling is unchanged.
Dedicated complete-print/export routes remain the authoritative existing full-output
operations; printing an ordinary server-paginated screen does not fetch extra pages.

## 6. Pagination and counts outside the scroll area

The table footer is a sibling following the viewport. It contains a single visible
Showing X to Y of Z results count and pagination. Counts are not placed above the table.

For server tables, first/last/total come from the existing Blade JSON metadata. Laravel's
existing pagination node/links are moved intact into the footer; URLs and controls
are not rebuilt. Its existing desktop-only count paragraph is hidden only after the
shared responsive count is provided, preventing duplicate counts. Empty metadata
uses zero for unavailable first/last values rather than inventing rows.

For client tables, the count uses the already computed matching rows and clamped
page range. Previous/Next and Page N of M stay in the footer. No record is fetched,
added or removed by changing the footer presentation. Original empty placeholders,
table totals and native row actions remain.

## 7. Multiple tables and retained behavior

Existing `tables[table_id][size|filters|sort|page]` state prefixes are unchanged.
Server marker IDs and paginator page names stay exactly as before; client-only
tables retain their existing `local_${index}` IDs and initialization order.

Examples inspected include billing batches versus budget scopes (`page`/`scopes`),
billing items versus candidates (`page`/`candidates`), financial batches versus receipts
(`batch_page`/`receipt_page`), and the several independent report/evaluation tables.
Every table has its own viewport, toolbar, closure state and footer. URL updates modify
only that table's prefix/page parameter and preserve other URL parameters, including
evaluation `tab` selection. No IDs or request field names were renamed.

Existing filters, sorting comparisons/indicators, row nodes, view/edit/removal actions,
status changes, checkbox values, bulk upload confirmation and validation messages remain.
No table re-creation or new form nesting was introduced. Native in-row details/review
forms expand within the scroll area. Their prior error-recovery UI remains intact,
including failed evidence reviews rendered above the table so paging cannot hide them.

Static inspection found no currently instantiated custom absolute-positioned action
popover inside these data rows: actions are links/buttons, native controls/details
or existing external/native dialogs. Existing primary dropdowns remain teleported
outside overflow containers. Header sorting tooltips use their original native titles.
No modal/tooltip/navigation library was changed.

## 8. Responsive styling and navigation preservation

Retained the existing red palette, typography, table row borders/spacing, card shape,
status badges and action links. Filter controls wrap to available width and become
full-width on narrow screens; the page-size selector and footer wrap independently.
No financial/academic column was hidden to fit a device.

New sticky-header selectors preserve visible table headers against the legacy mobile
global table rules. The original horizontal/mobile scroll table layouts remain.
Course / Program's list gets the same height/scroll treatment without becoming a
different workflow. Specialized recursive evaluation snapshots keep their existing
label/value layout instead of acquiring column sorting or page controls.

The horizontal CFCI navigation template, `primary-navigation.js`, app entry point,
five primary menu labels, registry visibility/unavailable item, desktop hover behavior,
mobile tap controls, active highlighting and account/logout are unchanged. No sidebar
or body-scroll lock was introduced.

## 9. Files created/modified and backend scope

Modified:

- `resources/js/data-tables.js`: reuse existing wrappers; accessible scroll region;
  top page-size toolbar; below-table responsive counts and existing pagination.
- `resources/css/app.css`: shared viewport/sticky headers, responsive filters/footer,
  scrollbars, focus indication and print overrides.
- `resources/views/academic-programs/index.blade.php`: scroll wrapper around the
  existing editable course/program list, leaving pagination/actions outside.

Created:

- `docs/24-system-wide-data-table-ui-improvement.md`

All inventoried standard table modules inherit the shared changes without duplicating
their templates. The shared state component and server pagination helpers were reviewed
and reused unchanged. No controller, business service/action, request, policy, model,
database schema, route, historical data or existing test file changed. No migration or
dependency was added. Local ignored Vite output was rebuilt.

## 10. Actual static review and checks

Reviewed all inventoried table/partial sources and callers, existing filter placement,
marker IDs, server page names, existing local sorting/filtering/paging functions,
header groups, empty/colspan/total rows, row forms/actions, evaluation tabs, print/export
routes, native dialogs, Course / Program list pagination and navigation source.

Actual source/compilation results:

| Command/check | Actual result |
| --- | --- |
| `node --check resources/js/data-tables.js` | Exit 0; no syntax errors |
| Blade `compileString()` plus `php -l` for Course / Program and existing table-state source | Two sources compiled with no PHP syntax errors; no view rendered |
| `npm run build` | Exit 0; Vite transformed 63 modules and compiled production assets |
| `git diff --check` | No whitespace errors; line-ending normalization notices only |
| Source inventory | 30 table templates / 34 declarations identified; includes one specialized recursive snapshot declaration |
| Task-start hash comparison | Zero changes to backend, routes, schema, configuration or tests; navigation template/component/app entry unchanged |

No PHPUnit, Pest, Artisan test, browser, HTTP scenario, database fixture, migration
or manual functional check was executed. Existing test assertions for previous
page-size placement may need developer review; they were neither changed nor run.
Static checks do not establish runtime functionality or visual correctness.

## 11. Required developer commands

Deploy/rebuild assets with the existing command:

```powershell
npm run build
```

The build was already executed locally. No installation or database command is needed.
Use the normal deployment procedure for cached views if applicable.

## 12. Beginner manual verification — ALL NOT TESTED

Use existing authorized development accounts and isolated existing fictional data.
Do not edit real records simply to verify table appearance. No fixtures/credentials
were created or supplied by this implementation.

1. Sign in with an authorized Coordinator/Registrar/Administrator development account.
   Open **Information Registry → Scholars**. Expect existing search/filter/action
   controls first, one Rows per page selector above the table, and result count/
   pagination below its viewport.
2. Use an existing search term/program/status or other available filter on that page.
   Submit its existing Search/Filter button. Expect the same filtered data/selected
   values. Sort with a header chevron, change page and refresh. Existing module filters
   should remain where currently supported; no new per-column text controls are expected.
3. Select 10, 20, 50, 100 and 200 using the page-size control. Expect onchange updates,
   the existing valid size selected on refresh, and reset/clamped current page. Confirm
   only one page-size control exists and the result count matches displayed pagination.
4. With enough already-existing fictional results, select a larger page size and scroll
   vertically inside the table. Expect its header/sort controls to remain visible,
   page height bounded and pagination immediately outside the scroll area.
5. At a narrow viewport, scroll horizontally to the final action/financial columns,
   then vertically. Expect column alignment, opaque sticky headers, reachable actions
   and no horizontal overflow of the whole page. Small tables should not gain an
   artificial full-height blank area.
6. Focus the labeled table region and use keyboard scrolling. Repeat with wheel,
   trackpad and touchscreen where available. Expect native scrollbars when needed,
   normal surrounding page scrolling, and no body-wide scroll lock.
7. Repeat on **Scholarship Grant / Program**, **Batch/Roster**, **Academic Year/Semester**
   and a scholar's scholarship-history table. Check existing authorized view/status
   actions, empty-state messages and source links. Reason for Delisting must remain
   unavailable and the removed Academic verification module must remain removed.
8. Open **Course / Program**. Expect the existing registration form, scrollable program
   list, authorized Deactivate/Reactivate actions and original 15-record pagination.
   It must not acquire duplicate client-side pagination or a new table-only selector.
9. Under **Billing and Validation → Billing batches**, check batch and budget/scope
   tables independently. Change one table's size/sort/page, then the other. Open an
   existing development billing detail and repeat for allocation items versus Add an
   individual scholar candidate search. Expect separate state/page parameters and
   unchanged eligibility explanations/actions.
10. Open an existing fictional evaluation. Check Academic records/subject grades,
    Supporting evidence and External approval/History tables. Sort/change size in
    one instance, switch tabs and return. Expect preserved tab/form state and separate
    tables. Review native details/dialog controls without submitting real decisions.
    Failed-review recovery, if separately prepared by the developer, must stay visible
    above the evidence table rather than becoming hidden by its scroll/paging state.
11. Open **Student accounts** with an existing authorized provisioning role. Search
    and filter account status, change size/page and follow an authorized Manage login
    link. Table changes must not alter account state or expose restricted records.
12. Under **Liquidation and Payment**, inspect submitted batches and receipts separately,
    funding status, existing payment records, receipt allocations, prepared payroll
    and liquidation lines. Check retained totals/footer rows and empty-payment colspan
    rows. Do not post, approve or change real financial records to perform UI checks.
13. Open existing **Reports**. Use current program/year/semester/batch filters, page
    the main result and change grouping/stage/exception table state independently.
    Verify the existing CSV and complete-print links still use their original full
    output behavior. Interactive pagination must not become a server-wide unlimited load.
14. Open Dashboard Recent Validations; verify its top selector, sticky header, local
    viewport and outside footer. Confirm the earlier Scholars by Program scroll card
    and quote removal are unaffected.
15. Check dedicated payroll/liquidation/report print screens. Expect their original
    complete print layout without interactive controls/height clipping. If printing
    a normal client-side table, verify existing before/after print row restoration;
    printing a server page alone should not fetch additional data.
16. Confirm the same horizontal CFCI navigation, desktop hover menus, touch toggles,
    active parent highlighting and account/logout controls. Check role-hidden module
    links/actions remain hidden and direct endpoint authorization is unchanged.

All steps have actual status **NOT TESTED**. Expected outcomes are source-derived
instructions for the developer, not completed scenarios or test-pass claims.

## 13. Known limitations and final status

- Runtime scrolling, sticky-header painting, pagination/filter actions, sorting,
  accessibility, mobile devices, modals and cross-browser behavior remain unverified
  under the user's explicit restrictions. No screenshots were taken.
- Existing JavaScript remains necessary for generated page-size controls, interactive
  sorting/client pagination and shared footer counts. CSS-only scrolling still applies
  to existing wrappers; no new no-JavaScript table control implementation was added.
- Scrollbar visibility details depend on OS/browser settings. The new table styles
  never explicitly hide an overflowing table's scrollbars.
- Table query parsing/filtering/sorting algorithms, large-result iteration behavior
  and server page-name conventions are existing behavior and were not redesigned.
- Specialized recursive history snapshots, list/timeline layouts and dedicated print
  screens intentionally keep their corresponding presentation rather than receiving
  unsuitable generic controls. Course / Program keeps its pre-existing 15-row page size.
- Unsupported/missing modules are not created and academic/financial/scholarship
  decisions/calculations are not changed by these table presentation improvements.

**Final status: IMPLEMENTED — PENDING MANUAL VERIFICATION.**
