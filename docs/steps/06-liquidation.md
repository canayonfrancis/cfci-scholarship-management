# 06 - Liquidation

Reviewed: 2026-11-13 (Asia/Manila). Teacher workflow step 10.

Task 26 amendment: the user confirmed signed acknowledgment for every payout, accepted liquidation support for every report, and application to all future decisions while preserving past acceptance. These requirements are now enforced; see [signed acknowledgment and liquidation](../sms/signed-payroll-and-liquidation.md).

## Purpose

Reconcile a billing's supported actual payments, acknowledgments, authorized deduction settlements and school cash, then record independent liquidation acceptance. Receipt of funds alone must never mean liquidated.

## Responsible user role

**Registrar Staff:** prepares, refreshes and submits the liquidation draft with supporting documents. **School Scholarship Coordinator:** independently reviews and accepts/returns it. Reviewer must differ from both preparer and submitter. Retain the existing Test Administrator exception and the same integrity checks.

## Preconditions

Approved billing from [04](04-form2-form3-and-billing.md), actual payment/funding records from [05](05-fund-receiving-and-payroll.md), required signed acknowledgments and the applicable document checklist. A draft can be prepared while incomplete for follow-up; completion requires verified supporting facts.

## Step-by-step procedure

1. Open the batch under **Payments & liquidation**, review original/effective billing, actual allocated school cash and school/direct payments separately.
2. Prepare/open a liquidation draft with its real reference. The existing action creates lines from posted student net payments and separately settled deductions.
3. Review the listed issues per student/payment: unpaid amounts, missing identity/details, unavailable proof, unsettled deductions, stale line coverage or unreconciled school cash.
4. Resolve issues using their original recording/review workflows. Collect signed payroll evidence and every actually required provider/school document. Do not declare unsupported cash as paid.
5. Refresh the draft after new verified payments/settlements. Ensure each coverage line points to the exact payout or deduction in this batch and matches its supported amount.
6. Registrar submits a complete draft. Coordinator independently checks the required acknowledgment/document checklist and financial reconciliation, then accepts or returns with reasons.
7. A return retains its submitted lines. Prepare a new sequence for correction; never edit the returned report. Accepted records and their covered transactions remain history.
8. Generate the printable summary and include accepted records in [report 6](07-required-reports.md), displaying later evidence exceptions separately.

**Current completion gate:** in addition to existing financial checks, every posted payout needs explicit Coordinator-verified signed acknowledgment for its exact beneficiary, date, student net amount and payment reference. The draft needs an accepted intact `liquidation_support` document owned by this report. Submission retains those source references/checksums; acceptance rechecks them. Historical accepted reports without that snapshot retain their decision and display unconfirmed checklist verification.

## Required fields and data

- `billing_batch_id`, report `sequence`, reference, preparer, submitter/date, reviewer/date, review notes and decision.
- Each `liquidation_items` line targets **one** `payout_id` or `deduction_id`, with same-batch scope and supported amount.
- Source payment identity, actual date/method/reference, posted verifier, accepted intact proof and exact gross/net/deduction reconciliation.
- For deductions: exact authorized basis, separate actual settlement and accepted settlement proof.
- `payment_acknowledgments`: exact payout/document/student, server verifier/time, acknowledgment reference/page-line and retained beneficiary/date/net/reference/checksum.
- `liquidations.compliance_snapshot`: internal confirmed-checklist version, signature verification/document references and required support-document identity. Historical null values are not backfilled.

## Database tables involved

`liquidations`, `liquidation_items`, `payment_acknowledgments`, `payouts`, `deductions`, `documents`, `funding_allocations`, `funding_receipts`, `billing_batches`, `billing_items`, `billing_adjustments`, `billing_batch_approvals`, `audit_events`, `users`. New verification facts connect to these sources; no financial ledger is replaced.

## Business rules and validation

- Existing completeness requires every item's cumulative posted gross to equal its effective allocation, at least one verified positive gross payment, required payment metadata and intact accepted payment proof.
- Every authorized deduction must reconcile with gross/net and be fully settled with evidence; retained money alone is incomplete.
- Liquidation covers student net **plus actual settled deductions**. Do not add gross again and double-count deductions. Direct student net remains separate from school cash.
- Existing cash condition: `posted batch allocations - school student net - settled deductions = 0`. Direct payments create no school receipt or release.
- Submitted coverage must equal the current expected payment/settlement lines. Another accepted report cannot claim the same coverage; the current service allows one complete accepted liquidation per batch, not partial accepted reports.
- Reversal history is surfaced for reviewed correction rather than silently accepted. Operational returns/reallocations/partial settlements are not supplied by this analysis.
- Required signatures and documents are explicit completion gates under the user's Task 26 confirmation. Ordinary evidence acceptance, funding receipt, an upload, a prepared payroll or a submitted liquidation is insufficient. Signature verification requires a posted original unreversed payout, owned intact signed evidence, matching Student ID/name/date/net/reference, and a reviewer different from uploader and payout recorder. Accepted receipt/payment/deduction proof must remain intact.
- A later missing file is a current evidence exception. Keep the original acceptance actor/date and financial history intact while reporting that exception.
- Task 28 QA regression fix: current funding-source checks are shared with the funding monitor. A reversed receipt or globally oversubscribed receipt cannot be certified merely because this batch's nominal allocation equals its payments/settlements. Future submission/acceptance is blocked; past acceptance stays recorded with a current review exception. No new operational reversal/return policy is introduced.

## Status changes

`draft -> submitted -> accepted` or `returned`. Submitted/returned/accepted lines cannot be rewritten; a returned report needs a new sequence. A batch does not get a stored `liquidated` status: acceptance lives in `liquidations`, while the live summary can still detect missing evidence.

## Expected output

A separately reviewed accepted liquidation with traceable payment/settlement coverage, required signed acknowledgments and supporting documents; otherwise an explicit incomplete/returned result. The confirmed checklist is enforced for new decisions. Past acceptance is not automatically certified under the new requirements.

## Error and exception handling

Missing payment/proof, unsettled deduction, cash imbalance or stale lines block submission/acceptance and identify the issue. Reject self-acceptance, duplicate coverage and attempts to change frozen records. Unspent cash cannot be disguised as a student payment; escalate to the reviewed return/reallocation decision. Do not undo existing acceptance or overwrite student data when evidence needs follow-up.

## Dependencies on previous steps

Requires [05](05-fund-receiving-and-payroll.md) and actual program-specific acknowledgment/document requirements. Supplies accepted records for [07](07-required-reports.md).

## Repository evidence

[Liquidation action](../../app/Actions/Billing/ManageLiquidation.php), [completeness service](../../app/Services/Billing/LiquidationSummary.php), [evidence types](../../app/Services/Billing/FinancialEvidence.php), [controller](../../app/Http/Controllers/FinancialRecordController.php), [liquidation view](../../resources/views/financial/liquidation.blade.php), [print view](../../resources/views/financial/print.blade.php), [financial tests](../../tests/Feature/Billing/FinancialRecordingTest.php), [financial migration/history guards](../../database/migrations/2026_11_12_000008_add_financial_recording_workflows.php).
