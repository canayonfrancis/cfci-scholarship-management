# 01 - Scholarship program and batch

Reviewed: 2026-11-13 (Asia/Manila). Teacher workflow steps 1-2.

## Purpose

Select or create the scholarship program, then establish its academic-year/semester batch **before** student enrollment validation or billing. A batch is the roster context; belonging to it must not itself create a payable billing item.

Implementation update: independent semester rosters and memberships are delivered in Task 24 of [progress](../sms/progress.md). The user confirmed one roster can support multiple financial submissions; financial headers are separate. Final Form 2/Form 3 classification and classification-gated billing remain blocked on approved templates. See [gap analysis](08-implementation-gap-analysis.md).

## Responsible user role

- **School Scholarship Coordinator:** maintains programs, periods and approved semester configuration; oversees batch setup.
- **Registrar Staff:** can currently prepare billing batch drafts, but cannot configure programs, rates or budgets. Proposed roster preparation should reuse this recorder/Coordinator handoff.
- Preserve the existing Test Administrator exception and its record safeguards. No additional operational role is required.

## Preconditions

Authenticated staff with the relevant policy permission; an existing program or sufficient authorized catalog information; a registered academic period. A roster must not require student eligibility, a financial rate, a budget or received cash merely to exist.

## Step-by-step procedure

1. Search **Scholarship programs** to avoid duplicate program identities. Select the correct existing program or use **Add Scholarship Program**.
2. Record its code, name, availability and only known reference information. Save the program first.
3. Select the exact registered academic year and semester. Create/select a uniquely identified batch within that program and period.
4. Review the saved batch's actual program and period, then proceed to [student assignment](02-scholar-registration-and-assignment.md).
5. Maintain semester rate/budget approval as a separate financial preparation task before billing. Program reference amounts never constitute an approved semester entitlement.

**Current screen limitation:** Add/Edit Program displays a Batch Number dropdown containing existing `billing_batches.id` values. Its reference program and period are validated, but the controller deliberately excludes those fields from saving. It creates no batch, membership or persistent program-to-batch selection. A new program cannot take ownership of another program's existing batch.

**Current roster setup:** Scholars -> **Semester rosters** -> **Create semester roster** saves a `scholarship_batches` header without a rate, validation, financial terms or billing item. Billing's separate **Create draft allocation batch** retains its approved-rate requirement. Neither action automatically assigns a student.

## Required fields and data

| Record | Required or relevant information |
| --- | --- |
| Program | Unique stable `code`, `name`, `is_active`; optional known `school_stated_semester_amount`, `planning_duration_years`, `source_notes` |
| Period | `academic_year`, `semester_code`; known dates only; maintenance availability is distinct from the active validation context |
| Batch | `program_id`, `academic_period_id`, scoped `batch_number`, creator/time; optional reference |
| Later financial configuration | Exact-semester rate, revision, approving actor/time and real source reference; budget revision before allocation approval |

Unknown amounts stay null. Retain the documented TES/TDP/CoScho reference configuration and historical EDUKAR records; this analysis sets no rates or duration policies.

## Database tables involved

Existing: `scholarship_programs`, `academic_periods`, `program_period_terms`, `billing_batches`, `scholarship_rates`, `scholarship_budgets`, `audit_events`, `users`, `user_roles`.

Implemented: independent `scholarship_batches` plus `scholarship_batch_memberships`. Multiple existing `billing_batches` can reference one roster through nullable `scholarship_batch_id`; scope is guarded and legacy rows remain null. No financial ledger is duplicated. Linking the actual billing preparation flow to approved form classification remains future work, pending templates.

## Business rules and validation

- Match program and period on every batch selection and write. Do not trust dropdown labels or client filtering.
- Current header uniqueness is `(program_period_term_id, batch_number)`; creation also checks semantic numeric labels and uses locks to prevent competing duplicate numbers. Existing labels remain unchanged.
- Keep a batch's program/period identity stable. Do not move submitted records to another program or semester.
- Membership, financial authorization and payment are separate facts. Never create `billing_items` to simulate roster membership.
- Planning duration is descriptive; it does not automatically terminate a grant.
- No enrollment or payment is inferred from program availability, period availability or profile completeness.

## Status changes

Current program: available/unavailable through `is_active`. Current batch: `draft -> submitted`, with independent approval stored in `billing_batch_approvals`; legacy `finalized` and `void` values remain supported. These are financial states, not roster-member enrollment states. Proposed roster creation leaves the header draft and creates no financial item or receipt.

## Expected output

A saved program and identifiable independent semester roster ready to receive students before validation or billing. Roster creation does not set a financial status or create a payable item.

## Error and exception handling

Reject duplicate codes/numbers, mismatched IDs, unavailable financial programs and unauthorized requests. Existing creation fails without a rate; do not work around that by entering an unapproved amount. Preserve old labels and immutable submissions. Reload after a concurrency/stale-form error; audit failures must roll back the associated write.

## Dependencies on previous steps

None within this workflow. Existing authentication, staff permissions, program catalog and academic periods are reused. Next: [02 - Registration and assignment](02-scholar-registration-and-assignment.md).

## Repository evidence

[Program controller](../../app/Http/Controllers/ScholarshipProgramController.php), [program request](../../app/Http/Requests/ScholarshipProgramRequest.php), [program form](../../resources/views/programs/form.blade.php), [batch action](../../app/Actions/Billing/ManageBillingBatch.php), [batch model](../../app/Models/BillingBatch.php), [program-context tests](../../tests/Feature/Billing/ProgramBatchNumberTest.php), [batch-number concurrency tests](../../tests/Feature/Database/BatchNumberConcurrencyTest.php).
