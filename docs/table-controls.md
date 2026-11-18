# Table controls

Interactive tables provide up/down sorting chevrons beside each data-column heading and one
rows-per-page selector below each table (10, 20, 50, 100, 200; default 10).
The selector updates immediately on change. Click a column's chevrons to sort
ascending, then click again to sort descending. The active chevron is highlighted
and the header exposes its sort direction to assistive technology. Numbers and
amounts sort numerically, dates chronologically, and text uses natural ordering
(for example, Scholar 2 before Scholar 10). Unknown values remain last. Action and
source-link columns are excluded. Controls appear in the column headings and
below the table, without an extra toolbar or record-count status above it.
Changing the sort direction or page size starts that table at page one.

Existing page-level search and scope filters continue to apply. Table state uses
`tables[table_id][size]`, `tables[table_id][sort][column]`, and
`tables[table_id][sort][direction]` query parameters. Existing column filter URLs
(`tables[table_id][filters][column_index]`) remain supported. Each server table
retains its own pagination parameter. Local detail
table state is also retained in the URL. Report summaries, CSV exports, and
dedicated print documents continue to cover their complete page-level scope;
table sorting and column filters only affect the interactive table's displayed rows.

## Implementation

- `TablePagination` and the Eloquent `tablePaginate()` macro paginate existing
  authorized queries. Without column filters or sorting, pagination runs in SQL. Filtered
  queries scan eager-loaded batches of 200 so computed labels (for example,
  funding readiness, latest revisions, and profile completeness) can be searched
  across all pages. Sorting collects matching rows before pagination so the order
  covers the entire result, with stable ties preserving the source order. These
  scans and sorts may cost more time and memory on large datasets; SQL-backed
  sorting for individual columns can be added if needed.
- `TableColumns` defines the searchable text in visible column order. Keep these
  projections aligned when changing a server table's columns or labels.
- `<x-table-state :paginator="$rows" />` belongs inside the table's scroll wrapper
  before the table. Its JSON marker connects shared browser controls to server
  state. Collections already built by reports use `TablePagination::collection()`
  with an optional column projection.
- `resources/js/data-tables.js` enhances application tables automatically. Tables
  without a server marker sort, filter, and paginate all their already-rendered rows.
  Before browser printing, local tables show every matching row across pages;
  dedicated print views render complete records without interactive controls.

Run `php artisan test --compact tests/Feature/TableControlsTest.php` and
`node --test tests/js/data-tables.test.js` for focused checks. `npm run build`
builds the shared controls with the existing application assets.
