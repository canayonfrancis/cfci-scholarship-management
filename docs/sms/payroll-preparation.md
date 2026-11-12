# Payroll preparation

Implemented 2026-11-13. This is a basic internal school-held payroll register, separate from actual payments. See Task 25 in [progress](progress.md) for validation and local migration evidence.

Subsequent Task 26 implements [explicit signed acknowledgment and required liquidation evidence](signed-payroll-and-liquidation.md) under the user's confirmed requirements. It supersedes the signature/checklist deferrals below for that specific scope; preparing this register still proves neither payment nor signature.

## Confirmed scope

The user confirmed **“Basic internal register is sufficient”** and **“Wait until the entire approved billing batch is funded.”** For direct-to-student funding, the answer was **“preserve”**: existing direct-payment recording remains available; preparing a direct payroll is deferred until the provider-funding evidence is defined. No official provider template, payment priority, installment planning, deduction permission or additional reviewer role is inferred.

The earlier roster decision remains unchanged: one semester roster can have several financial submissions. A payroll belongs to one existing **billing submission**, not to the entire semester roster. The minimal implementation retains one full-outstanding register per billing batch; it does not introduce multiple installment plans, replacement/cancellation workflows or a new payment ledger.

## Staff procedure

1. Open **Payments & liquidation**, then the relevant approved billing batch. Existing submission, independent approval, rate, eligibility and budget requirements still apply.
2. Record real school receipts, upload evidence, have another authorized reviewer accept/post them and allocate the cash to this batch through the existing controls.
3. Wait for full batch coverage. A budget, expected remittance, draft receipt, unallocated receipt or partial cash allocation is insufficient.
4. Under **Payroll preparation**, enter a reference and select **Validate funding and retain payroll**. The server supplies the preparer/time and all beneficiaries/amounts. Staff cannot submit a paid flag, actual payment date, deduction or arbitrary payment line through this action.
5. Review or print the retained register. It contains existing institutional student IDs and beneficiary names, billing-item IDs, effective gross benefit, previously posted gross and planned remaining gross, with an exact total. The printout says **PREPARED ONLY** and is not a signed acknowledgment.
6. Record real payments separately using the existing payment action, real date/reference, applicable authorized deductions and independently accepted proof. Preparation creates no actual payment and does not waive any posting check.

Registrar and Coordinator use existing financial preparation/view permissions; the Test Administrator exception and legacy financial access are retained. Read-only users may view/print an existing register but cannot prepare one. Routes remain authenticated and private/no-store. No new role or account is required.

## Funding and amount rules

All calculations use exact integer centavos. Define:

- **B:** sum of finalized billing-item gross plus signed authorized billing adjustments.
- **D:** signed posted direct-to-student gross already applied to these bills.
- **F:** signed posted school cash allocations backed by usable receipts for this batch.

Preparation requires **F + D = B** and nonnegative reconciled amounts. Posted direct payments cover their own benefit; they are never reported as school cash. Existing posted school payments consume cash from F but are not deducted twice from the full-batch funding target. Overallocated cash requires reconciliation, rather than silently expanding the plan.

For each unpaid billing item, **planned gross = effective gross − signed posted gross**. Fully applied items are omitted. Every planned item must still satisfy the existing `BillingEligibility` chain, including current academic/external decisions and the pinned approved rate. A pending correction blocks reliance on an old favorable decision. A fully paid batch cannot generate another plan. Previously posted partial actual payments remain supported; partial funding does not permit preparing a partial new register.

Allocated receipts must be posted originals in the exact terms scope, dated no later than today, unreversed, within their total receipt allocation capacity, and supported by currently accepted checksum-intact receipt evidence. The retained snapshot includes allocation IDs, receipt references/dates and evidence provenance. Budgets do not count as funds.

The plan records **gross benefit only**. Actual student net and specifically authorized deductions continue to be recorded and checked in the existing payout/deduction workflow. Planning does not authorize withholding or settle a deduction.

## Smallest retained structure

Migration `2026_11_13_000010_add_payroll_preparation` creates only:

| Table | Retained facts and constraints |
| --- | --- |
| `payrolls` | Unique restrictive billing-batch FK, reference, `draft/prepared` assembly state, preparer FK, server preparation timestamp and funding snapshot |
| `payroll_items` | Payroll and billing-item FKs constrained to the same batch; unique item per payroll; frozen student ID/name, effective/prior-applied/planned gross and eligibility basis |

Existing payout drafts describe payments already made and require an actual payment date. They cannot safely store plans. These two small tables retain the independently dated header and its bill-specific lines without duplicating funding or payment transactions. There is no new balance table, `payouts` column or historical backfill. Planned and actual payments share the original billing-item identity; an explicit payment-to-payroll-line or signed-document link remains future work if required by the approved acknowledgment rules.

The draft assembly state is internal to one atomic transaction, not another staff financial stage. Final preparation requires nonempty finalized sources and approved submitted billing (or the preserved valid legacy-finalized path). Prepared headers and lines are protected against update, delete and appended/moved lines by database triggers on SQLite and MariaDB/MySQL. Composite foreign keys prevent cross-batch bill lines. Rollback refuses populated payroll tables. Preserve the retained facts rather than dropping them during a later application rollback.

## Integrity and source changes

Preparation locks the existing financial terms scope first, then takes current locks on batch/approval, prior payroll, billing items, students, academic context, program and relevant financial sources. This serializes competing preparation/payment/allocation operations and avoids relying on an older MariaDB repeatable-read snapshot. A database unique batch key is the final duplicate guard. The audit event and entire register commit together; audit failure rolls everything back.

Repeating a prepared reference, ignoring surrounding whitespace and case, returns the existing immutable register. A different reference for that batch is rejected. No second plan or payout is generated by retries.

The view separates frozen preparation values from current effective/applied/remaining bill amounts and flags changed amounts. It is not a current eligibility/funding certification after preparation. Changes never rewrite the snapshot. Actual payment posting rechecks current eligibility, usable school cash on the actual payment date, bill limits, intact accepted proof, independent verification, and `gross = net + authorized deductions`.

Existing allocations remain the batch cash commitments. There is **no new cash reservation or cash movement** when a payroll is prepared, and no addition of planned amounts to received, paid, budget-consumed or liquidated totals. No payment priority, cross-payroll reservation policy, edit/replacement or direct-funding readiness rule is invented.

## Exceptions and remaining boundaries

Missing approval, zero unpaid benefit, partial/unallocated funding, invalid scope, stale eligibility, unknown historical amounts, missing identity, corrupt receipt proof, duplicate references for an existing batch and unreconciled totals block preparation with validation errors. No partial payroll survives failure. Forbidden actors receive authorization errors; nonprepared headers cannot be viewed as retained registers.

Approved Form 2/Form 3 templates and final classification-gated billing remain unresolved from Task 24. This task preserves existing financial authorization without inventing form classifications or enabling payable Form 3 cases. Ordinary production grading still returns review until school criteria are approved; synthetic downstream tests are not school policy.

Explicit signed acknowledgment coverage, representative/shared-sheet rules, direct-provider funding evidence, replacement payroll handling and any future multi-register reservation policy require separate confirmed requirements. A prepared or printed register does not prove payment, a signature or liquidation. Existing direct-payment recording, posting and liquidation actions remain unchanged.

## Implementation and tests

[Preparation action](../../app/Actions/Billing/PreparePayroll.php), [controller](../../app/Http/Controllers/PayrollController.php), [header](../../app/Models/Payroll.php), [line](../../app/Models/PayrollItem.php), [migration](../../database/migrations/2026_11_13_000010_add_payroll_preparation.php), [feature tests](../../tests/Feature/Billing/PayrollPreparationTest.php) and [MariaDB concurrency tests](../../tests/Feature/Database/PayrollPreparationConcurrencyTest.php). See [funding/payroll workflow](../steps/05-fund-receiving-and-payroll.md) and the [gap analysis](../steps/08-implementation-gap-analysis.md) for the broader process.
