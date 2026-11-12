# 07 - Required reports

Reviewed: 2026-11-13 (Asia/Manila). Teacher workflow step 11.

Task 27 delivery: all six operational modes are available in **Scholarship reports**, with shared filters, whole-result counts/totals, CSV and complete printing. [Operation and counting rules](../sms/six-reports.md) describe current behavior. The user-confirmed submitted-billing, multi-submission roster, full-funding and exact signed-acknowledgment definitions are preserved. Unknown classifications/history are not invented.

Task 24 update: independent rosters, the Registrar enrollment queue, an unbilled-member view and batch funding monitoring now exist. The user confirmed that one roster can support several submissions and that billed means submitted, excluding drafts. Remaining final report/form limitations below must be read with that implementation update; see [progress](../sms/progress.md).

## Purpose

Produce the six required operational lists from authoritative records, with clear scope and totals. Task 27 implements the lists separately from existing dashboard analytics and allocation-history output.

## Responsible user role

**School Scholarship Coordinator:** reviews grants, billed/unbilled lists, funding readiness, supported payments and accepted liquidation. **Registrar Staff:** generates authorized operational lists and follows up missing verification/evidence. Current policies allow both roles to view financial records; retain protected identity/evidence permissions. Preserve the existing Test Administrator exception. Reports are read only.

## Preconditions

Data from [01](01-scholarship-program-and-batch.md)-[06](06-liquidation.md), including recorded rosters for reports 1/3 and explicit signed-payroll coverage for report 5. The user confirmed **billed means submitted; drafts are not billed**. Independent approval remains a separate stage. Missing form classifications remain unknown and do not prevent listing recorded facts.

## Step-by-step procedure

1. Choose the report, scholarship program, exact academic year/semester and batch. Identify whether a batch means the roster or financial submission; use stable IDs and display both when they differ.
2. State the counting unit and stage. Generate each list using the definitions below, including unresolved/excluded reasons.
3. Reconcile all-result totals independently of pagination. Separate original billed amounts, adjustments, effective approved amounts, received cash, applied gross, student net and supported liquidation amounts.
4. Review incomplete or ambiguous legacy scope/evidence before publishing conclusions. Unknown historical membership/signatures must be labeled unknown, not fabricated.
5. Use **Export CSV** or **Print complete report** to retain the complete filtered result, generation time, definitions and exceptions. The Cronasia dashboard and existing module styling are preserved.

## Required fields and data

Common fields: program code/name and ID, batch ID/number, academic period, institutional Student ID, scholar name, relevant record ID/status/reference, amount columns with PHP units, date/actor where applicable, evidence-completeness exceptions, filters and generation timestamp. Export only authorized identifiers; omit private national IDs, storage paths and credentials.

### 1. List of grants by Scholarship Program and Batch

- **Unit:** retained batch membership linked to its student and scholarship assignment; group by program and batch/benefit period. Enrollment/classification and bill/payment states are separate columns.
- **Include:** enrolled, non-enrolled and unresolved members. Do not restrict the roster to current active awards or billed students. Identify any reviewed membership withdrawal/transfer separately.
- **Current sources:** `students`, `scholarship_assignments`, `scholarship_programs`, `academic_periods`; `billing_batches` identifies only financial scope.
- **Delivered:** consolidated roster-membership report, grouped by program/roster/period with independent whole-result distinct scholar counts. Includes unresolved/non-enrolled members and never-billed students. Historical absent memberships are not reconstructed from bills. CSV/print are internal operational outputs, not assumed official forms.

### 2. List of billed scholars, individual amounts and total billing

- **Unit:** original submitted `billing_items` in a `submitted` batch with submission provenance, or supported legacy `finalized` batch. Exclude drafts and voids from billed totals; expose approved/unapproved state separately.
- **Amounts:** original billed total is the exact sum of included `gross_amount`; show signed adjustment and effective amount in separate columns. Approval snapshot is another reference, not a value to add again.
- **Current sources:** `billing_items -> billing_batches`, student/program/period; `billing_batch_approvals`, `billing_adjustments`.
- **Delivered:** submitted-only billed mode with roster/submission/year/semester/program filters and exact whole-result original/adjustment/effective totals. Existing `/billing-batches/report` remains allocation history; its broader draft/history inclusion does not redefine this billed report.

### 3. List of scholars not billed

- **Unit:** batch members with **no submitted bill** for the same student/assignment/program/period scope. A draft item means reserved/prepared, not submitted billing. A bill in another batch must be shown explicitly so the cross-batch view does not suggest billing again.
- **Include reasons:** verified non-enrollment/Form 3, awaiting Registrar verification, missing applicable template, pending academic policy/evaluation, external approval pending, inactive assignment, missing approved rate, draft only, or eligible but not prepared. Show all applicable facts rather than inventing an exclusion policy.
- **Query shape:** roster as the left side, `NOT EXISTS` a matching submitted bill; separately inspect draft reservations and existing other-batch bills. Do not start with billing items or the evaluation queue, as both omit students without those records.
- **Delivered:** consolidated membership-first unbilled mode includes members with no evaluation/bill and draft reservations; a matching submitted bill in any submission removes that grant from the unbilled list. Other-program and unlinked/other-roster reservations remain visible facts. Financial submission filtering is prohibited for this roster denominator. No form classification or missing historical membership is inferred.

### 4. List of billed grants awaiting funds for payroll processing

- **Unit:** approved billed items listed under their batch, with unpaid benefit and **batch-level** school-funding shortfall. Submitted billings awaiting approval are a separate pre-funding queue.
- **Sources:** effective approved `billing_items`, posted `funding_allocations` linked to usable posted same-scope receipts, posted original/reversal `payouts`, plus any future documented payroll payment path.
- **Existing funding reconciliation reused:** let `B` be approved effective gross, `D` posted direct-to-student gross and `F` usable posted school allocations for the batch. Display potential school shortfall `max(B - D - F, 0)` and separately unpaid benefit `B - all posted applied gross`. Do not subtract school payments again from the full-funding target: their funding already forms part of `F`. Surface source exceptions separately.
- Task 25 confirms waiting until the entire approved batch is funded before school payroll preparation. This report monitors that existing rule; it invents no partial-payment priority or intended direct-funding readiness. Current records store actual payment path on payouts, not planned paths on unpaid grants.
- Batch cash is not assigned to individual students in the current schema. Do not distribute it arbitrarily or repeat the batch shortfall as every student's own shortfall. Program-level posted receipts are not proof that this batch is funded.
- **Delivered:** consolidated unpaid grant rows for approved awaiting-funds batches, plus a separate one-row-per-batch stage table. Whole-result shortfall totals sum each batch once. Funded-but-unpaid and approval-pending records never enter the awaiting-funds grant count.

### 5. List of paid grants supported by signed payroll evidence

- **Unit:** grant/billing item with its actual verified payment lines and explicit signed-payroll coverage. List partial payments separately from fully covered grants.
- **Sources:** `payouts -> billing_items -> students/billing_batches`, applicable deductions and accepted intact evidence. Aggregate payments before joining multiple documents. Reversal entries reduce amounts once and must not appear as ordinary paid grants.
- Show actual date/method/reference, beneficiary ID snapshot, gross applied, student net, authorized deductions, signature verification/reference/page/line and reviewer. Gross is not the student's cash received; unsettled deductions remain separate obligations.
- Full benefit application reconciles cumulative posted gross with effective entitlement and signed student net/authorized deductions. Task 26 requires exact signed acknowledgment for every actual payout, including direct payouts and each recorded installment. Signed portions, zero-net applications and exceptions remain distinct; a payroll plan or payout draft is not paid.
- **Delivered:** consolidated billing-item rows with qualifying signed payouts aggregated once, exact identity/date/method/reference/verifier/page-line detail and separate signed gross, student net, authorized deductions and school/direct totals. Multiple documents/acknowledgments do not inflate amounts. Generic proof, reversals and current evidence/authorization exceptions are disclosed separately and excluded from verified totals.

### 6. List of liquidated billings

- **Unit:** billing batch with its accepted `liquidations` record/sequence, supported `liquidation_items`, acceptance reference/date/reviewer and monetary coverage. Do not count one batch again for every payment or report sequence.
- Separate **recorded accepted history** from **currently complete evidence**. Reuse live completeness checks and display later missing/corrupted proof without rewriting acceptance history.
- **Sources:** `liquidations -> billing_batches`, coverage lines to payouts/settled deductions, documents and batch financial sources.
- **Delivered:** consolidated accepted/currently supported billing mode using retained `compliance_snapshot`, independent decision provenance and live `LiquidationSummary`. Recorded accepted billing histories and currently verified liquidated counts are distinct. Legacy null snapshots and later missing evidence remain exceptions without altering acceptance. Draft/submitted/returned reports do not count as liquidated. No stored billing status is added.

## Database tables involved

All sources reuse existing identity, roster, assignment, academic, billing, receipt, payout, deduction, document, acknowledgment and liquidation tables. Task 27 needs **no migration or report table**. Classification remains unknown pending approved templates, rather than being backfilled for reporting.

## Business rules and validation

- Aggregate money by its owning billing item/batch/receipt before joining one-to-many payouts, deductions, evidence and liquidation lines. Otherwise row multiplication inflates totals.
- Use integer centavos or exact decimal arithmetic, as existing `Money` does. Unknown amounts remain unknown and flagged.
- Apply program, exact period and batch filters together. Include historical inactive/archived students with actual financial records; current profile year is not historical grant membership.
- Report current source status or retained submitted snapshots explicitly. General historical “as of” reconstruction is not implemented; a generation timestamp alone is not such reconstruction.
- Preserve separate original/reversal entry semantics, school/direct cash paths and original/effective allocation meanings. Do not sum original and effective values together.
- Enforce server-side permissions on screens, exports, prints and evidence links; CSV strings retain existing formula-injection protection.

## Status changes

None. Reports read existing statuses and evidence; generating a list never changes enrollment, submits a bill, receives cash, pays a student or accepts liquidation.

## Expected output

Six separately scoped internal lists with row-level traceability, whole-result totals, CSV/complete print and visible exceptions. Official provider forms, final Form 2/Form 3 output and historical as-of reconstruction remain outside this delivery. Existing overview/CSV/print functionality is retained.

## Error and exception handling

Reject invalid/mismatched filters and unauthorized exports. Render empty results and zero totals only for genuinely empty known amounts; distinguish unavailable evidence and unknown amounts. Do not suppress unbilled/non-enrolled members. Preserve source records and expose legacy scope gaps rather than assigning invented historical memberships or signatures.

## Dependencies on previous steps

Requires the recorded source steps and confirmed billing/funding/signature/liquidation definitions. These lists can report actual recorded facts while form classifications remain unknown. Official provider formats and historical cut-off reconstruction require separate approved requirements.

## Repository evidence

[Routes](../../routes/web.php), [allocation report/controller/CSV](../../app/Http/Controllers/ScholarshipFundsController.php), [report view](../../resources/views/billing/report.blade.php), [financial summary](../../app/Services/Billing/AllocationSummary.php), [dashboard analytics](../../app/Services/DashboardAnalytics.php), [dashboard scope](../../app/Http/Controllers/DashboardController.php), [financial controller/printing](../../app/Http/Controllers/FinancialRecordController.php), [reporting tests](../../tests/Feature/DashboardAnalyticsTest.php), [financial report tests](../../tests/Feature/Billing/ScholarshipFundsTest.php).
