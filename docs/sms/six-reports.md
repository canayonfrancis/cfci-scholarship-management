# Six scholarship reports

Implemented 2026-11-13 (Asia/Manila). Open **Scholarship reports** in the existing Cronasia navigation. The six modes share read-only reporting logic, filters, whole-result totals, CSV export and a complete printable view. See Task 27 in [progress](progress.md) for verification.

## Counting rules and approved workflow

The implementation follows the user's confirmed definitions: one semester roster supports several separate billing submissions; **submitted billing is billed and draft billing is not**; school payroll preparation waits for full applicable batch funding; signed evidence means explicit Coordinator verification of the exact actual payout; future liquidation decisions require signed acknowledgments and accepted support while past acceptance is retained. The primary workflow remains [program through liquidation](../steps/README.md).

| Report | Main row / counting unit | Amount and stage rules |
| --- | --- | --- |
| Grants by Program and Batch | One actual roster membership, grouped by scholarship program, roster and benefit period | Include pending assignments, never-billed members, non-enrolled and unverified members. Membership is not a bill, award activation or payable entitlement. |
| Billed Scholars | One finalized billing item in a submitted batch with submission provenance, or supported legacy finalized batch | Exclude drafts/voids. Sum original billing once; show signed adjustments and effective billing separately. Independent approval is a stage, not an additional amount. |
| Not Billed Scholars | Roster membership without a submitted bill for that student/program/period in any submission | Draft reservations remain not billed. Show reservations in other submissions/rosters/programs; do not suggest duplicate billing. |
| Grants Awaiting Funds for Payroll | Unpaid items under approved submissions with usable batch cash shortfall | Reuse funding status checks. Show batch shortfall once in batch totals, never assign/repeat it as an individual student's own share. Funded-but-unpaid, approval-pending, draft, fully applied and source-review stages appear separately. |
| Paid Grants with Verified Signed Payroll | One billing item aggregating qualifying actual positive student-net payments | Require posted original unreversed payout, intact ordinary payment proof, explicit usable signed acknowledgment, actual payment metadata and reconciled authorized deductions. Aggregate each payout once even with several documents/acknowledgments. Show signed portions, gross applied, student net, deductions and school/direct paths separately. |
| Liquidated Billings | One independently accepted billing with retained confirmed checklist and currently complete reconciliation/evidence | Reuse the full liquidation completeness check. Sum covered student net plus settled deductions, never gross plus net. Show recorded accepted billing histories separately from currently verified liquidation. Preserve legacy acceptance and later evidence failures as exceptions. |

Every mode displays **listed rows**, **distinct scholars by Student ID record**, **distinct student/program/period combinations**, and **distinct financial submissions** as separate counts. One person may appear in several roster groups or benefit periods. Whole-report distinct counts are calculated independently and must not be obtained by adding group counts. Financial submissions and membership rows are not interchangeable grant/person counts.

All amounts use exact integer centavos. Totals cover all matching rows before pagination. Original billing, effective benefit, allocated cash, school/direct student net and settled deductions are different measures. Unknown source amounts stay unconfirmed; an empty known result can have zero totals without inventing an amount for an unknown record.

## Roster and historical scope

Reports 1/3 start from `scholarship_batch_memberships`, including members with no evaluation or billing. Enrollment uses the latest Registrar revision for the exact membership benefit period; unknown/pending corrections cannot fall back to an older favorable source. Form 2/Form 3 remains **pending approved templates**, never inferred from enrollment, payment or academic outcome.

Financial reports use the **recorded** nullable `billing_batches.scholarship_batch_id`. Without it, the financial submission is shown as **Unlinked historical submission**. Selecting a roster restricts financial reports to its explicit links; there is no inferred or backfilled relationship.

Not-billed checks the matching student/program/period across all submissions, even if an old bill has no saved roster link. Consequently a member can have a matching historical bill, correctly disappear from the unbilled list, and still have that bill shown as unlinked in financial grouping. The roster report displays that reservation/history explicitly. A bill from another program is a reservation fact, not submitted billing for the selected grant.

Current student profile names/IDs are labeled as current profile fields. Signed-payment detail separately includes the immutable actual payout identity, date, method/reference and signature verifier/reference/page-line. Archived students and unavailable programs are not removed from historical financial reports merely because their current administrative status changed.

## Funding and signed-payment interpretation

Funding reuses `BatchFundingStatus`: **potential school gap = max(effective batch gross − posted direct gross − usable posted school cash allocations, 0)**. Budget amounts, expected cash, draft receipts and unallocated posted receipts do not become batch funds. Existing school payments are not subtracted twice from the full funding target. Receipt scope/date/capacity/reversal/evidence exceptions remain review cases. Direct paid benefit is not school cash. No payment priority or intended direct-funding path is invented for unpaid grants.

The signed-payment report selects a usable explicit acknowledgment once per payout. More accepted documents or signature records do not multiply amounts. Ordinary accepted `payment_evidence`, a filename containing “signed,” a prepared payroll or an accepted signed file without explicit human verification is insufficient. Signed partial payments remain visible with a partial/evidence coverage label. Zero student-net applications, unknown amounts, reversals, missing proof and authorization exceptions remain separate from verified student cash totals. Authorized deductions are disclosed independently of actual settlement; signed payment is not liquidation certification.

The liquidation report accepts only `accepted` decisions with independent reviewer/submission metadata, a retained confirmed checklist and a currently complete `LiquidationSummary`. Draft, submitted and returned reports do not count. Recorded accepted histories have their own distinct billing count; unconfirmed legacy checklist provenance and missing/corrupt evidence are disclosed separately and do not rewrite the original decision or payment amount. Repeated coverage/report history does not inflate verified amounts.

## Filters and output

Common filters are scholarship program, **semester roster batch**, academic year and semester. Financial modes additionally offer **financial billing submission**. The optional `academic_period_id` query filter identifies an exact registered period. Mismatched program, roster, submission or year/semester scope produces a validation error; a roster filter does not silently claim unlinked history. Inactive/historical programs and periods remain selectable.

Financial submission filtering is prohibited for roster/unbilled modes so it cannot redefine the roster denominator. Existing working allocation-history and funding screens remain available; the dashboard's removed filter controls are not restored.

The HTML list uses 25 rows per page. **Export CSV** and **Print complete report** use the same complete filtered result, definition, generation timestamp, counts/totals and exceptions. CSV also includes program/roster grouping and funding-stage context where applicable. CSV text is escaped against spreadsheet formula injection, and only approved report columns are exported. Private file paths, raw evidence checksums, credentials and protected national identifiers are not exported. Outputs are internal operational lists, not invented official provider forms.

Generation time is shown in Asia/Manila. These are **current recorded-state** reports, not reconstructed historical as-of balances. Official form formats, historical cutoffs and missing source classifications remain separate requirements.

## Authorization and preservation

Coordinator, Registrar and the retained Test Administrator can use all six reports through existing permissions. Legacy Finance/Viewer financial access is retained for the four financial modes; it does not gain roster-directory access. Unknown/unauthenticated users are rejected. HTML, CSV and print use the same authorization and private/no-store responses. Existing private evidence download permissions are unchanged.

Reporting invokes only read queries and integrity checks within a read transaction. No migrations, report tables, persisted totals, status writes, audits, inferred membership, signature backfill or business-record changes are needed. Existing billing/payment/liquidation actions, financial constraints, authentication and Cronasia components remain intact.

## Implementation references

[Reusable report service](../../app/Services/Reporting/ScholarshipReports.php), [controller/filter/export logic](../../app/Http/Controllers/ScholarshipReportController.php), [views](../../resources/views/scholarship-reports/index.blade.php), [funding service](../../app/Services/Billing/BatchFundingStatus.php), [signed evidence](../../app/Services/Billing/SignedAcknowledgment.php), [liquidation completeness](../../app/Services/Billing/LiquidationSummary.php), [report tests](../../tests/Feature/Reporting/ScholarshipReportsTest.php), [workflow report definitions](../steps/07-required-reports.md).
