# 04 - Form 2, Form 3 and billing

Reviewed: 2026-11-13 (Asia/Manila). Teacher workflow steps 5-7.

## Purpose

Classify retained batch grantees using Registrar-verified enrollment and the **applicable program template**, then prepare actual billing only for eligible enrolled grantees. Distinguish roster membership, form classification, draft reservation, submitted billing, allocation approval and waiting for cash.

## Responsible user role

**Registrar Staff:** supplies verified enrollment and can prepare/submit financial drafts. **School Scholarship Coordinator:** reviews program applicability, eligibility and external decisions, configures authorized rates/budgets, and independently approves submitted allocations. The same person cannot approve a batch they prepared or submitted. Preserve the Test Administrator's existing access without bypassing this rule.

## Preconditions

[Batch membership](02-scholar-registration-and-assignment.md) and [verified enrollment](03-enrollment-validation.md). Obtain the real program/provider Form 2/Form 3 samples, version and applicability before implementing official output. For billing, retain the current qualified evaluation, active assignment, verified exact-semester external approval, approved rate and budget gates; do not assume the teacher waived them.

## Step-by-step procedure

1. Review each member's latest benefit-semester enrollment revision. Pending/unknown results stay in a visible **awaiting classification** queue.
2. Under a confirmed applicable template, classify enrolled grantees under Form 2 and non-enrolled/applicable other statuses under Form 3. Retain reason, enrollment source revision, program/period, template version and classifying actor/time.
3. Retain all Form 3 members on the roster. They receive no payable billing item from this workflow. Enrollment classification is distinct from a failed academic evaluation; one does not automatically determine the other.
4. Review Form 2 members against the existing financial prerequisites. Being enrolled or on Form 2 alone does not establish the approved amount or payability.
5. In **Billing**, prepare the financial draft for that program/period. Bind the approved rate before adding financial items. Add only qualifying enrolled members; show excluded members and explicit reasons.
6. Verify per-student amounts and the whole-result total. Submit with a reference; submission rechecks sources and freezes items/batch. A separate Coordinator reviews current eligibility and budget capacity and appends approval.
7. Track submitted/approved billings awaiting funds separately from submissions awaiting approval. Show approved amount, posted usable receipts allocated to the batch and funding shortfall. Continue to [fund receiving and payroll](05-fund-receiving-and-payroll.md).

**Current status after Task 24:** independent rosters, a Registrar queue, an unbilled-member view and batch funding monitoring exist. No Form 2/Form 3 classifier or approved template output exists. Current financial candidate selection still uses semester-validation contexts; connecting it to roster/classification gates remains blocked on those templates. The allocation-status filter's **Awaiting Coordinator approval** is distinct from the new funding-readiness view. The user confirmed submitted billing is billed and drafts are not.

## Required fields and data

| Stage | Data |
| --- | --- |
| Target classification | Membership/student/assignment/batch scope; exact `academic_record_id` and revision; known verified enrollment; Form 2/Form 3 code; actual template reference/version, reason, actor/time |
| Existing financial item | `billing_batch_id`, `application_id`, `assignment_id`, `student_id`, `program_id`, `academic_period_id`, evaluation/approval FKs, `gross_amount`, `entitlement_snapshot`, `basis_snapshot` |
| Submission/approval | Submission reference, actor/time and version; independent approval reference, actor/time, approved amount snapshot and budget revision |
| Funding monitor | Original/effective approved totals, posted usable batch cash allocations, payment path and separate posted school/direct payments |

Exact official form columns and special-status reasons remain unconfirmed. Do not fill them from guessed provider policy or copy one program's layout to all programs.

## Database tables involved

Existing: `billing_batches`, `billing_items`, `billing_batch_approvals`, `billing_adjustments`, `scholarship_rates`, `scholarship_budgets`, `program_period_terms`, `scholarship_assignments`, `scholarship_applications`, `evaluation_runs`, `external_approvals`, `academic_records`, `documents`, `funding_receipts`, `funding_allocations`, `audit_events` and identity/period tables.

Implemented: independent `scholarship_batches` and `scholarship_batch_memberships`, with a nullable scope-checked roster reference on existing billing headers. Retained classification revisions remain proposed pending templates (see [08](08-implementation-gap-analysis.md)). Existing billing tables are reused; no student or financial table is recreated.

## Business rules and validation

- Require exact-period known verified enrollment; never classify from a Student profile or an unverified standing-only record. Do not map missing data automatically to Form 3.
- Form 3 is a retained reporting category, **not a payable class**. No Form 3-to-payment path is authorized here.
- Current ordinary evaluator treats not-enrolled records as failure/review, so they cannot produce the passed qualified basis required by billing. Billing relies on that evaluation chain, not a dedicated form/enrollment check. Add an explicit current enrolled/member/classification check at financial transitions in a later change.
- Current `BillingEligibility::basis()` also requires active student/program/assignment covering the period, current completed qualified evaluation with confirmed applicable rules, verified current exact-semester external approval/evidence and a positive approved rate. Preserve these guards pending an explicit school decision about their applicability.
- Existing uniqueness is one allocation/reservation per **student and semester across programs**, plus assignment/semester and application uniqueness. Draft reservations count; a void record does not automatically free its key. Partial payments use the original bill.
- `gross_amount` is the original submitted amount. Effective amount adds signed authorized adjustments. Neither received cash nor net student payment equals that amount automatically.
- Drafts are preparations, not submitted bills. Submit atomically; never allow an invalid member to partially finalize a batch.

## Status changes

Target classification revisions: Form 2/Form 3 only after confirmed mapping; unresolved records remain pending classification. No such fields currently exist.

Current billing: header `draft -> submitted`; items `draft -> finalized`. Independent approval is a `billing_batch_approvals` row and computed `allocationStatus() = approved`; the submitted header itself stays submitted. Legacy finalized/void history is retained. Waiting for funds is a derived financial condition, not a new stored `paid` flag.

## Expected output

Separate Form 2 and Form 3 lists, a billed-student list with accurate amounts/totals, retained not-billed members/reasons and a funding-wait queue. Currently only guarded financial preparation/submission/approval and general financial summaries are implemented.

## Error and exception handling

Missing template mapping, unknown enrollment, new pending academic corrections, stale evaluations, revoked/expired approval or unknown rates block the affected transition and remain review cases. Reject mismatched program/semester IDs and duplicate billing. Reload stale forms. Submitted records cannot be reopened/rewritten; retain adjustment/correction history. A post-submission enrollment change requires reviewed follow-up, not deletion of the original financial evidence.

## Dependencies on previous steps

Requires [01](01-scholarship-program-and-batch.md)-[03](03-enrollment-validation.md), applicable templates and the existing financial authorization prerequisites. Supplies actual bills for [05](05-fund-receiving-and-payroll.md) and reports 2-4 in [07](07-required-reports.md).

## Repository evidence

[Billing controller](../../app/Http/Controllers/BillingBatchController.php), [eligibility service](../../app/Services/Billing/BillingEligibility.php), [evaluator](../../app/Services/Eligibility/EligibilityEvaluator.php), [batch action](../../app/Actions/Billing/ManageBillingBatch.php), [approval/adjustment action](../../app/Actions/Billing/ManageScholarshipFunds.php), [billing view](../../resources/views/billing/show.blade.php), [billing tests](../../tests/Feature/Billing/BillingBatchTest.php), [fund tests](../../tests/Feature/Billing/ScholarshipFundsTest.php).
