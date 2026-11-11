# Scholarship Funds, Semester Allocations and Budgets

Updated 2026-11-11 (Asia/Manila), following the user's [confirmed allocation policy](confirmed-allocation-policy.md). This replaces the earlier provisional billing policy and reversed TES/TDP reference figures. Existing source records and approved amounts are retained.

## Operation

1. Administrators maintain programs under **Scholarship programs**. Codes are configurable and stable once created; reference amounts and availability can be updated with an audited school source. Initial references are TES PHP 7,500, TDP PHP 13,500 and CoScho PHP 45,000 per semester. EDUKAR remains available for retained history; its unconfirmed amount is not replaced with another program's amount.
2. Administrators create actual academic years/semesters under **Academic periods**. Years must be consecutive and start in 2007 or later; future years are allowed. No historical program rates or activity are inferred from the school's establishment date.
3. Under **Billing batches**, approve a rate for the actual program and semester. The initial form can populate its amount from the program's configurable reference. Each approval records a source, actor, time and revision. Later changes use the existing scope's rate revision form. No rate is authorized for every semester automatically.
4. Configure that scope's available scholarship budget, with an approval source. Budget revisions retain prior values and cannot reduce capacity below existing approved allocations. A configured budget is not received funding.
5. Finance creates a draft batch, searches existing semester validations and adds individual permitted scholars. Each new batch pins its approved rate. Only active students with one active assignment covering that semester, a current eligible validation and verified exact-semester program approval can be included. Draft edits use a version token. Removing a draft item releases its reservation and retains audit history.
6. Finance submits a validated batch for Administrator approval. All scholar, amount and source checks run again. Submission freezes the source batch/items but does not approve, receive, pay or release funds.
7. A different Administrator reviews and approves the submission. Eligibility is rechecked and the entire batch must fit within the latest approved budget. A unique approval record retains the approver, reference, time, total and budget revision. Finance cannot approve, including a Finance user with an Administrator role who prepared or submitted that batch.
8. Administrators make financial corrections using a separate adjustment on the approved item's history. Enter the correct effective amount, authorization reference and reason. Original amounts remain unchanged. Increases require current eligibility, a supporting current semester rate and sufficient budget; reductions cannot fall below posted gross releases. A stale adjustment sequence is rejected, and adjustment rows cannot be edited or deleted.
9. Generate **Allocation report**, filter program/year/semester and export CSV. Historical inactive/archived scholars remain included. Reports distinguish approved budget, original/effective allocations, signed adjustments, remaining budget, pending approvals, posted actual receipts and posted net cash releases. They aggregate sources separately to prevent joined rows from multiplying amounts.

## Permissions

| Responsibility | Finance | Administrator | Coordinator / Viewer | Registrar / no role |
| --- | --- | --- | --- | --- |
| Read allocation batches and financial summaries | Yes | Yes | Yes | No |
| Prepare/edit drafts and submit | Yes | Requires explicit Finance membership | No | No |
| Approve rates/budgets | No | Yes | No | No |
| Approve submitted allocations | No | Yes, if neither preparer nor submitter | No | No |
| Authorize financial adjustments | No | Yes | No | No |
| Create/close/change scholarship assignments | No | Yes | No | No |

Existing account roles are not changed. The existing trusted `sms:grant-role` command remains available when an operator has explicitly assigned responsibility. Coordinator academic evaluation, verified external-decision recording and guarded award activation remain separate from Administrator authorization of assignment records.

## Data and integrity

Existing BillingBatch, BillingItem, ProgramPeriodTerm, FundingReceipt and Payout relationships are reused. A scholarship allocation is not a receipt-backed FundingAllocation row: that existing model represents allocation of actually received cash to a batch and is not repurposed as a student's scholarship entitlement.

Migration `000006` adds append-only semester rate/budget revisions, batch approvals and item adjustments, a nullable pinned rate on batches, and a unique student/semester allocation key. Existing assignment/semester/application unique keys remain. A preflight check stops before schema changes if legacy student/semester duplicates need authorized reconciliation; it does not delete records. Existing confirmed legacy rates are copied to revision history without changing old terms or batch amounts.

Approval uses the existing immutable submitted batch plus a separate approval record; the original submission is never reopened or rewritten. Display status becomes Approved when an approval exists. Retained finalized legacy batches are displayed/count as approved historical allocations, and documented legacy finalization provenance is required for adjustments. Void history remains retained; no uncontrolled reopen, deletion or replacement-allocation endpoint is introduced.

Mutations use server-side policies, current row locks, transactions, stale rate/budget/adjustment tokens and atomic audits. Program/semester locking serializes competing approvals against the same remaining budget. Money is calculated in integer centavos; null legacy amounts are shown as unconfirmed. Database guards enforce immutable revisions/approvals/adjustments, adjustment chains, foreign-key scope, self-approval restrictions and duplicate scholar/semester allocation across programs.

`ScholarshipProgramSeeder` provides corrected initial reference defaults without overwriting local changes. The explicitly invoked, idempotent `ConfirmedScholarshipProgramSeeder` adopts this user's confirmed TES/TDP/CoScho references once, audits previous/current reference values and preserves later administrator edits. It changes no approved semester rate, allocation, budget or academic year.

## Financial recording extension (2026-11-12)

[Funding, payments, deductions and liquidation](financial-records.md) now records actual school receipts, scoped cash allocations, independently verified school/direct student payments, individually authorized deductions, evidenced full settlements, incomplete/complete batch liquidation and printable summaries. These reuse the existing billing item and approved-batch history. Allocation summaries/CSV distinguish school-paid student net from direct-to-student net; neither billing nor budget approval manufactures a receipt or payment. Ordinary allocation adjustments are blocked after accepted liquidation pending a reviewed correction process.

The scope boundary below describes the earlier allocation-only delivery. Task 10 in `progress.md` is current for the implemented basic recording workflows. Pooling, cash returns/transfers, partial deduction settlements, production reversals, automatic disbursement and official provider templates remain outside the delivered scope.

## Remaining policy and scope boundaries

Required CFCI numeric grading rules remain unconfirmed. No CoScho/custom-program academic profile is invented. Such missing rules continue to produce pending review and block allocations even when their rate/budget is configured. Tests use clearly identified synthetic resolved prerequisites only to exercise downstream financial safeguards.

Historical records can be read for years from 2007 onward; making a new allocation still requires the applicable completed validation and verified approval, not an assumed historical program presence. A scholarship change preserves its previous allocation for that semester and cannot obtain a second allocation, even after a zero-value correction. No implicit transfer of committed funds across programs is performed.

This scope provides scholarship budgeting, preparation, approval, adjustments and allocation reports. It does not add tuition collection, payment gateways, receipt/payout posting, deduction settlement, liquidation or general dashboard analytics. Approval never manufactures a receipt or a release. Posted actual cash records, when present from an authorized source, are read separately for summaries.

Run database suites sequentially or with separate `LARAVEL_STORAGE_PATH` directories: fake evidence disks clean their test directories. Use an isolated MySQL database ending in `_test`; never run RefreshDatabase tests against the application database. Verification is recorded in [progress.md](progress.md).
