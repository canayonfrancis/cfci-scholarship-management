# 02 - Scholar registration and assignment

Reviewed: 2026-11-13 (Asia/Manila). Teacher workflow step 3.

## Purpose

Identify the student once, record their scholarship program assignment and place them in the selected semester batch **before enrollment validation and billing**. Retain students who later prove non-enrolled or otherwise nonpayable.

## Responsible user role

**Registrar Staff or School Scholarship Coordinator:** register/select student profiles. **Coordinator:** creates/maintains scholarship assignments. Registrar profile-edit permission does not grant scholarship-assignment authority. Proposed batch membership should follow the same Coordinator authority, with Registrar roster assistance only through explicitly authorized preparation actions. Preserve the Test Administrator exception without changing existing memberships.

## Preconditions

[Program and batch selected](01-scholarship-program-and-batch.md); registered starting period; student identified by institutional Student ID. Enrollment verification, qualified evaluation, funding and a billing record are **not** prerequisites for a pending assignment or target roster membership.

## Step-by-step procedure

1. Search **Scholars** by Student ID/name before adding a person. Select the existing profile when found.
2. For new students, choose **Bulk Upload**, download the template and complete its required identity/course/contact columns. Preview and correct all errors, then explicitly confirm the import. Follow [the bulk-upload instructions](../16-bulk-scholar-upload-implementation.md). Complete other known profile details through Edit record; leave unknown optional information unset.
3. Coordinator opens **Assign scholarship** on the scholar detail page, chooses the scholarship program and starting academic semester, and saves a **Pending** assignment.
4. On the scholar detail page, open **Semester batch memberships**, select the pending/active assignment and matching program/semester roster, then **Assign to roster without billing**. The membership is displayed on the scholar record and roster.
5. Hand the batch roster to Registrar for [enrollment validation](03-enrollment-validation.md). Membership must survive a not-enrolled result.
6. Separately, when required and actually supported, complete school evaluation, record the external authority's decision and explicitly activate the award. These existing gates continue to govern financial eligibility; they must not block initial roster recording.

**Current capability:** steps 1-4 work without enrollment validation or a billing rate. The independent membership control is on the scholar detail page, after saving the program assignment. The **Add scholar** action inside Billing still adds a financial item under its existing guards; it is not roster assignment. Classification-gated integration of that financial action remains pending approved templates.

## Required fields and data

| Record | Data |
| --- | --- |
| Bulk profile registration | Unique `student_number`, first/last names, sex, existing active academic course, configured year level, contact email and phone; middle name may be blank and suffix is optional. `record_status` is active administrative profile status. |
| Other profile details | Middle/suffix names remain optional in the upload. Known academic period, family/address, birthdate and supported indicators/identifiers can be maintained through Edit record. |
| Assignment | `student_id` from scoped scholar route, `program_id`, `start_period_id`, `status = pending`; known start date/reference only |
| Proposed membership | Batch ID, student ID, assignment ID, matching program/benefit-period scope, assigning actor/time; retained reason/provenance for a later correction |

The student's `academic_program_id` is their course, not their scholarship program. Their profile `academic_period_id` is administrative metadata, not Registrar-certified enrollment. Profile completeness is separate from saveability and eligibility.

## Database tables involved

Existing: `students`, `academic_programs`, `academic_periods`, `scholarship_programs`, `scholarship_assignments`, `audit_events`, `users`, `user_roles`. Downstream relations use `scholarship_applications` as internal semester-validation context, not an intake service.

Implemented: `scholarship_batch_memberships -> scholarship_batches / scholarship_assignments / students`, with composite scope FKs and unique roster/student membership. `billing_batches.scholarship_batch_id` supports multiple separate financial submissions per roster. Existing `billing_items` remain financial records; there is no duplicate student/enrollment identity table or inferred legacy membership.

## Business rules and validation

- Unique Student ID is the person key; a matching name/email alone is insufficient to merge identities.
- One active scholarship across all programs is enforced by `sms_one_active_assignment_uq` and the student lock. Multiple pending records do not establish multiple active awards.
- New active awards cannot be created through the assignment form. Activation requires the existing current qualified evaluation and verified exact-semester external approval.
- Program and known starting semester remain immutable on an existing assignment. A program change uses retained closure/new-assignment history, not overwritten financial history.
- Proposed membership must match the assignment's student/program and cover the selected batch period. Duplicate membership in the same batch is rejected. Cross-batch transfers and multi-batch membership need the clarification in [08](08-implementation-gap-analysis.md).
- Do not create a billing item, assign an amount, close an award or archive a student merely because they joined a batch or were not enrolled.
- Do not alter existing student profiles as part of migration/backfill planning. Existing institutional-ID snapshots on payments remain historical identities.

## Status changes

Profile: existing `active`/`archived` administrative status. Assignment: new `pending`; guarded activation changes it to `active`; reviewed closure becomes `ended`, with `closure_reason = relinquished` where applicable. Suspended historical records remain read only under current rules.

Proposed membership records participation independently; a missing enrollment result means **awaiting validation**, not paid/eligible/ended. Any displayed membership lifecycle beyond this is a proposed design requiring review, not an existing status enum.

## Expected output

One retained student identity, a pending/existing scholarship assignment, and a saved independent batch membership ready for Registrar checking. All three are implemented; membership creates no billing or funding records.

## Error and exception handling

Reject duplicate Student IDs, cross-scholar nested URLs, wrong-program batches and stale/unauthorized changes. Existing closed/suspended history cannot be overwritten. Archiving a profile with an active assignment is rejected. Missing roster functionality must be reported as a gap, not bypassed using a financial draft. Unknown information remains unknown.

## Dependencies on previous steps

Requires [01](01-scholarship-program-and-batch.md); supplies the denominator for [03](03-enrollment-validation.md) and the complete grants/not-billed reports in [07](07-required-reports.md).

## Repository evidence

[Scholar controller](../../app/Http/Controllers/ScholarController.php), [profile request](../../app/Http/Requests/ScholarRequest.php), [assignment action](../../app/Actions/Records/SaveScholarshipAssignment.php), [assignment policy](../../app/Policies/ScholarshipAssignmentPolicy.php), [assignment form](../../resources/views/assignments/form.blade.php), [Student relationships](../../app/Models/Student.php), [record tests](../../tests/Feature/Records/ScholarRecordsTest.php), [profile tests](../../tests/Feature/Records/ScholarInformationTest.php).
