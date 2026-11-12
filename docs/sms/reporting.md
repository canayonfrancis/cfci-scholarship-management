# Dashboard analytics and counting rules

Implemented 2026-11-12. Per the latest request, analytics are presented through **Dashboard** indicators and graphs only. The separate Reports pages, printable report views and navigation links have been removed. The existing Cronasia hero, red accents, cards, chart geometry, responsive layouts and scrolling navigation are reused.

## Filters and scope

Scholarship program, academic year and semester are optional, conjunctive filters drawn from registered records. Blank means all; there is no default active year or guessed semester. Semester-only filtering includes that semester code across registered years. Invalid choices are rejected.

Unfiltered scholar counts include every registered Student identity, including archived and unassigned students. A filtered cohort is the union of matching assignments, non-withdrawn validation contexts and billing items. Without a scholarship-program filter, matching current academic profiles or Registrar records also establish membership. Each Student ID counts once, regardless of the number of semesters, assignments, evaluations or billing rows.

Assignment coverage uses existing start/end academic periods. Codes `1` and `2` are compared within the stored academic-year range; other semester codes match explicit endpoints only because their ordering is unconfirmed. Unknown boundaries do not acquire invented calendar dates. Closed assignments remain cohort history but never count as active awards.

The dashboard describes **current recorded state for selected benefit periods**, not a reconstructed historical snapshot. Selecting an earlier year does not claim a student had today's status in that year.

## Indicator counting rules

| Indicator | Source and counting unit |
| --- | --- |
| Total scholars | Distinct Student identities in the cohort above |
| Active scholarship awards | Currently active ScholarshipAssignment rows for active students, covering any selected period. Existing database uniqueness permits one active assignment per student |
| Pending evaluations | Non-withdrawn student/program/semester contexts without a run, or whose latest run has no reusable completed school decision. Old runs never add counts |
| School-eligible scholars | Distinct active students whose latest run is current according to `EvaluationRun::isCurrent()`, automatically passed and school-qualified. This does not constitute external approval |
| Pending external approvals | Contexts with no exact-semester approval, a latest pending decision, or a latest approved decision whose assignment, provenance, authority, validity or private evidence needs verification. Rejected/revoked decisions are excluded from this pending count; it is independent of school qualification |
| Scholars by program | Distinct active-award students in each registered scholarship program, with the same active-award denominator. Zero-count programs remain visible. Academic courses are separate |
| Billing totals | Original BillingItem gross amounts in submitted/finalized batches, once per item. Approved batches retain their submitted status with a separate approval record. Draft/void amounts do not enter this indicator |
| Recorded school funding | Posted FundingReceipt amounts, subtracting posted reversals; never budgets, billed amounts or cash allocations repeated through batches |
| Documented student payouts | Posted student **net** Payout amounts from school and verified direct paths, subtracting reversals. Existing financial records retain the separate paths. School cash and deductions are not added to student net |
| Incomplete liquidation records | Approved batches lacking an accepted report whose existing `LiquidationSummary` is complete now. Includes batches without a draft and accepted reports with missing/corrupt evidence; multiple draft/returned reports do not multiply the batch count |

Financial calculations use the existing integer-centavo `Money` service and effective-allocation relationship. A missing original billing amount remains **Unconfirmed**, not zero. Approved effective allocations include authorized adjustments and retain original submitted amounts separately. Existing posted ledger history remains counted if evidence later becomes unavailable; liquidation completeness identifies that evidence problem instead of erasing a real payment.

The line chart groups each context's **latest completed run only** by its completion month in Asia/Manila, over the last 12 calendar months. Its qualified series counts the currently reusable qualified contexts in each group. It does not sum historical reruns, claim growth percentages or project future applications. Axes scale to actual counts; empty data produces zero series and a neutral donut. The donut counts latest context states, so its denominator is contexts rather than distinct scholars.

## Recent validations and source records

Recent Validations shows the five most recently recorded contexts in the selected scope, with institutional ID, name, scholarship program, current school status and recorded date. Historical reruns do not add rows. Each row opens its existing evaluation detail; **View All** opens the established evaluation queue, retaining the scholarship program and exact academic period when both year and semester are selected. The program graph links to the existing Scholar directory.

The table retains a normal header and six columns on every screen size. Its card grows to contain the rows; long IDs/names/statuses wrap within cells, and constrained widths scroll the table horizontally inside its card. A direct view link avoids dropdowns being clipped by the scroll container. Loading error panels are cloaked until their actual Alpine state is known, preventing a temporary overlap during initialization.

Receipts, payouts, assignments and evaluation contexts are queried separately. There is no multi-child aggregate join that can multiply a receipt by the number of batch items, documents or payments. The `DashboardAnalytics` service builds each snapshot inside a read-only database transaction. Existing eligibility/evidence/completeness services remain authoritative; no parallel eligibility or accounting engine is introduced. Report-only row builders and search/printing code are removed.

## Authorization and preservation

Existing staff roles can view aggregate dashboard indicators. Recent evaluation identities require the existing evaluation policy (Administrator, Coordinator or Registrar). Financial cards require existing `financial-view` permission (Administrator, Coordinator, Finance or Viewer); Registrar does not receive financial details. Roleless accounts see their existing dashboard greeting plus an access notice. Dashboard responses use private/no-store cache headers. Former `/reports` and `/reports/{type}` URLs return 404.

The dashboard exposes institutional IDs only to authorized evaluation staff. It does not include encrypted national identifiers, LRN, 4Ps identifiers, private evidence paths or downloads, addresses, birthdates or unnecessary contact information. Existing evidence download authorization remains unchanged. This module adds no tables, migration, seed data, financial mutations, eligibility rules or credential changes.

The current implementation materializes a scoped snapshot so freshness and evidence checks use the established PHP services. Very large datasets may need a separately reviewed aggregation strategy; cached eligibility flags must not replace the authoritative freshness checks merely to optimize queries.

Verification results and stopping boundary are recorded in [progress.md](progress.md), Task 11.
