# 05 - Fund receiving, payroll and actual payments

Reviewed: 2026-11-13 (Asia/Manila). Teacher workflow steps 7-9.

Task 25 amendment: basic internal school payroll preparation is now implemented under the user's confirmed full-batch funding rule. Direct-payment recording is preserved; direct payroll preparation awaits approved funding evidence. See [payroll preparation](../sms/payroll-preparation.md) and [progress](../sms/progress.md).

## Purpose

Track billed grants waiting for cash, verify actual funds received, prepare a payroll register and retain evidence of actual student payments and signed acknowledgments. Funding, payroll preparation and verified payment must remain separately traceable.

## Responsible user role

**Registrar Staff:** records receipt/payment drafts, uploads proof, allocates verified receipts and prepares financial/liquidation records. **School Scholarship Coordinator:** independently reviews proof, posts receipts/payments and authorizes specifically documented deductions. Preserve no-self-review checks and the existing Test Administrator exception. No Finance account or third operational role is required.

## Preconditions

[Submitted and independently approved billing](04-form2-form3-and-billing.md) for payment recording and batch cash allocation. Actual receipts can be recorded at program/semester level independently of a particular batch. School-paid transactions require verified allocated school cash; retained direct-to-student recording is a distinct existing path with no fabricated school receipt.

## Step-by-step procedure

1. Monitor approved billed amounts and batch funding shortfalls. Distinguish awaiting approval, awaiting funds, funded but unpaid, partially paid and fully supported payment.
2. Registrar records a real receipt under **Payments & liquidation**, selecting its program/semester, amount actually received, date, source and reference. Save the receipt draft and upload private receipt evidence.
3. A different Coordinator accepts intact evidence and verifies/posts the actual receipt. Only posted receipts count as received funds. Receiving funds does not create a payout or complete liquidation.
4. Allocate verified same-program/semester school receipts to the approved batch, within both receipt availability and the effective batch amount. Keep allocation identity and an idempotent operation key.
5. In the batch's **Payroll preparation** card, enter an internal reference and prepare the register only once the entire approved batch is funded. SMS snapshots eligible unpaid billing-item identities and remaining gross amounts, preparer/date and verified funding provenance. Review or print this immutable register. Actual student net and deductions remain in the separate payment workflow; preparing or printing never marks a student paid.
6. Actual payment happens outside SMS. Registrar then uses **Record a student payment** for the real date, method/reference, path, gross applied and amount actually received by the student. Save the payment draft against the original billing item.
7. Upload the signed payroll/acknowledgment covering that student and payment. Coordinator checks the signature/coverage and independent proof, then verifies the payment. Payroll preparation alone never authorizes posting.
8. Track partial payments and any missing acknowledgments individually. Pass verified payment and settlement records to [liquidation](06-liquidation.md).

**Current scope:** one retained full-outstanding school payroll per approved billing submission, with planned lines linked to the original bills. A semester roster may support multiple submissions. Existing actual payouts and planned lines share billing-item identity; there is no new direct payout-to-payroll-line FK or typed signature coverage. Direct payroll preparation, installment plans and replacement registers are not implemented. Current payout drafts explicitly record payments already made; do not use them for expected payments.

## Required fields and data

| Record | Required or important data |
| --- | --- |
| Receipt | `program_period_term_id`, positive `amount`, actual `received_on`, source/reference, recorder, duplicate key, evidence and independent posting provenance |
| Cash allocation | Receipt/batch IDs, matching program/period, positive amount, unique operation/posting key, actor/time |
| Prepared payroll | Billing batch/reference, server preparation date/actor, internal format/funding snapshot, frozen billing-item links, student ID/name, effective/prior-posted/planned gross; readiness distinct from payment |
| Actual payout | `billing_item_id`, gross/net, actual `paid_on`, `payment_mode` (`school`/`direct`), method, reference, recorder; student number and beneficiary name snapshotted from the existing student |
| Payment proof | Owner-scoped document, checksum, review status/reviewer/time; target explicit signed-acknowledgment verification and page/line/beneficiary coverage |
| Deduction, only if applicable | Exact amount/reason, program/provider written authority, beneficiary consent/approved basis, destination, authorizer and private evidence; later actual settlement date/reference/evidence |

## Database tables involved

Existing: `funding_receipts`, `funding_allocations`, `payouts`, `deductions`, `documents`, `billing_items`, `billing_batches`, `billing_batch_approvals`, `billing_adjustments`, `program_period_terms`, `students`, `audit_events`; current financial eligibility also reads evaluations, academic sources, assignments and external approvals.

New `payrolls` and `payroll_items` retain only preparation facts; composite FKs constrain each line to its header's billing batch. No financial table is duplicated or historical payout updated. Proposed acknowledgment storage remains described in [08](08-implementation-gap-analysis.md).

## Business rules and validation

- Budget, approved allocation, actual receipt, allocated cash, payroll plan, posted gross applied and posted student net are different amounts.
- Preparation requires verified posted batch cash allocations plus already-posted direct gross to equal the entire effective approved batch amount. Unallocated receipts and partial funding do not qualify. Direct gross is benefit coverage, never school cash. Existing school payments are not deducted twice from that funding target.
- Planned gross equals effective bill minus posted gross; fully applied bills are omitted. Current eligibility is rechecked for every planned line. Only server-derived amounts and existing identities enter the register. Preparation creates no cash reservation ledger, payout, deduction or liquidation and does not change financial totals.
- A repeated same reference returns the original register; a different reference for the same batch is rejected. Prepared lines/header are immutable. Source changes remain visible separately, and actual payment checks still run at posting.
- Receipt posting requires accepted intact `receipt_evidence`, actual source/reference/date and a verifier different from the recorder. Batch allocation is capped and rejects cross-scope receipts.
- Payment posting rechecks current scholarship eligibility, approved billing, actual date, existing student identity and accepted intact `payment_evidence`; verifier differs from recorder. School payments additionally require enough usable cash allocated by the payment date.
- Sum of posted gross applications cannot exceed the effective billing item. School gross commitments cannot exceed allocated school funding. Transactions/locks protect competing payments.
- `gross = student net + explicitly authorized deductions`; direct payments have no school deductions. Withholding does not equal settled cash. No tuition offset, deduction percentage or provider permission is inferred.
- **Explicit signature verification (Task 26):** `payment_evidence` remains generic proof. Separate owned `signed_acknowledgment` documents and immutable `payment_acknowledgments` retain the exact student/payout, human verifier/time, document reference/page-line and matching actual net/date/reference. A filename or generic accepted review is insufficient. See [verification procedure](../sms/signed-payroll-and-liquidation.md).
- A verified direct payment must never be presented as school cash received/released. The confirmed signed-acknowledgment and liquidation-support requirements apply to direct and school payouts alike. Direct payroll preparation still awaits provider-funding evidence.
- Preserve historical posted records. Missing evidence later is an exception requiring follow-up; do not silently erase the payment or pretend a new upload reexecutes it.

## Status changes

Receipts/payments: `draft -> posted` after independent verification. Allocations are separate posted records. Evidence: `pending -> accepted/rejected`; accepted metadata is immutable. Deductions: authorized retained amount, then separately `pending -> settled` after full settlement evidence.

Awaiting funds/funded/unpaid/partially paid are derived monitoring conditions. Payroll is assembled as `draft -> prepared` within one transaction; only the immutable prepared register is exposed. It has no manually toggled paid status. Actual payment and signed-proof completeness remain separate.

## Expected output

Traceable actual receipts, batch cash allocations and a retained/printable internal payroll with exact planned gross totals. Actual payments retain separate gross/net/deduction, ordinary proof and explicit human signature-verification records. Signature coverage is never inferred from preparation.

## Error and exception handling

Reject future dates, unknown/invalid amounts, duplicate source/payment references, repeated mismatched operation keys, self-review, corrupt/missing proof, over-allocation and excess cumulative payouts. Keep unfunded/unacknowledged cases pending. Uploaded files are private and owner scoped; invalid MIME/extensions, oversized files and checksum changes fail. Reviewed cash reversals, refunds, returns/transfers and partial deduction settlements remain separate unresolved workflows; never use a negative ordinary receipt or rewrite posted history.

## Dependencies on previous steps

Requires [04](04-form2-form3-and-billing.md) and existing approval/eligibility safeguards; unresolved approved form classification remains a separate integration boundary. Feeds [06](06-liquidation.md) and reports 4-5 in [07](07-required-reports.md). The user approved a basic internal register, waiting for full batch funding and exact per-payout signature coverage. Direct-provider funding evidence remains unresolved for direct payroll preparation.

## Repository evidence

[Financial action](../../app/Actions/Billing/RecordFinancialActivity.php), [financial controller](../../app/Http/Controllers/FinancialRecordController.php), [request](../../app/Http/Requests/FinancialRecordRequest.php), [evidence service](../../app/Services/Billing/FinancialEvidence.php), [financial policy](../../app/Policies/FinancialRecordPolicy.php), [batch/payment view](../../resources/views/financial/batch.blade.php), [payment detail](../../resources/views/financial/payout.blade.php), [financial tests](../../tests/Feature/Billing/FinancialRecordingTest.php), [two-role lifecycle tests](../../tests/Feature/Integration/ScholarshipLifecycleTest.php).
