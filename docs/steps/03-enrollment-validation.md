# 03 - Enrollment validation

Reviewed: 2026-11-13 (Asia/Manila). Teacher workflow step 4.

## Purpose

Establish the student's Registrar-verified enrollment for the **batch benefit semester**, retaining the exact academic revision used. Enrollment is a fact; school scholarship qualification and external approval are separate decisions.

## Responsible user role

**Registrar Staff:** records, verifies and corrects academic revisions. **School Scholarship Coordinator:** reads verified facts, maintains the active/source-period configuration and performs scholarship evaluation. Coordinator permission does not permit Registrar verification. The existing Test Administrator can exercise these actions under the same guards.

## Preconditions

Student, program assignment and [batch membership](02-scholar-registration-and-assignment.md) exist. The existing academic-record workflow itself only requires a student and registered period. Open the semester roster's **Awaiting Registrar enrollment verification** view to include members with no record or unresolved latest revisions. Use an authoritative Registrar source; do not infer enrollment from an Active profile, course selection or scholarship assignment.

## Step-by-step procedure

1. Review the batch's exact year/semester and find the student's latest record for that period under **Academic verification/Academic records**.
2. Registrar creates the initial record, or edits only its latest pending draft. Select `enrolled`, `not_enrolled` or `unverified` according to the real source.
3. Record course/year level, source reference and any known academic standing. Reported subject grades belong to the confirmed grade-source semester when that differs from the benefit semester.
4. Save a draft while facts are unresolved. Use the explicit verification action after checking the source. Actor and verification time are assigned by the server.
5. Preserve all previous verified revisions. A correction requires a reason and creates a successor revision; it must not edit the original.
6. For the target roster, expose the latest verified enrollment result and its revision for [Form 2/Form 3 classification](04-form2-form3-and-billing.md). If the latest revision is pending, use **awaiting verification**; do not fall back to an older favorable revision.
7. Coordinator separately runs the existing semester scholarship validation where applicable, using the configured active target period and confirmed grading-source period. Record missing policy/evidence as review; never turn roster classification into an eligibility override.

## Required fields and data

- `student_id`, `academic_period_id`, latest revision and edit `lock_version` where required.
- `enrollment_status`: currently `unverified`, `enrolled`, `not_enrolled`.
- `academic_standing`: separately `unverified`, `passing`, `failing`.
- `source_reference` is required on verification; verification notes, course/year, reported subjects and certificates as actually available.
- Correction reason for a successor; server `verified_by`, `verified_at`, `revision`, `supersedes_id`.
- Evaluation additionally uses the active/source context revision, confirmed source-policy reference, applicable program rule profile and evidence.

No LOA, graduated, transferred, deceased or other special-status code is implemented in the enrollment form. Confirm the teacher/provider mappings before adding statuses or assigning them to Form 3.

## Database tables involved

`academic_records`, `academic_record_grades`, `academic_periods`, `academic_validation_context`, `students`, `documents`, `audit_events`, `users`, `user_roles`. School evaluation also uses `scholarship_applications`, `evaluation_runs`, `program_period_terms`, `external_approvals` and assignments. Proposed roster/classification references must reuse the existing academic revisions.

## Business rules and validation

- Current verification accepts a record when **either** enrollment or standing is known. Thus `verification_status = verified` alone does not prove enrollment; explicitly inspect `enrollment_status` as well.
- A usable enrollment basis must be the latest revision for the same student and benefit period, with known enrollment and Registrar verification provenance. A newer pending correction blocks reuse.
- Grade-source enrollment is not a substitute for current-benefit enrollment. Profile period/course fields are not certified sources.
- Preserve as-reported grades; no conversion, passing threshold, units requirement or duration cutoff is introduced.
- `SchoolGradingPolicy::check()` currently always returns review. Normal new eligible evaluation/activation/billing remains blocked by that unresolved production prerequisite. Successful downstream tests use explicitly synthetic prerequisites.
- `EvaluationRun::isCurrent()` invalidates decisions after source/context changes; submitted financial snapshots remain history rather than being recalculated.
- Non-enrollment retains the student, assignment history and proposed membership. It does not authorize a payable item.

## Status changes

Academic draft: `verification_status = pending`; explicit verification sets `verified` with actor/time. Correction appends a new pending/verified revision. Enrollment status and standing remain independent fields. Evaluation outcomes map to `passed/failed/needs_review` and `qualified/not_qualified/needs_information`; these are not Form 2/Form 3 codes.

## Expected output

A traceable, semester-specific enrollment finding, including non-enrolled and unresolved students. Task 24 adds the batch verification queue with source revision links and latest-correction freshness. It does not issue official Form 2/Form 3 classification: that output and its retained stale-classification tracking remain pending the approved templates.

## Error and exception handling

Reject missing verification sources, duplicate initial student/period records, stale versions and changes to superseded/verified revisions. Missing or corrupted private certificates are unavailable through downloads. Pending/unknown enrollment must remain visible for follow-up. Do not repair the problem by changing the student profile or reusing an older verified revision. Reevaluate affected financial prerequisites after a correction; preserve posted historical records for reviewed reconciliation.

## Dependencies on previous steps

Requires [02](02-scholar-registration-and-assignment.md) for the target batch workflow. Supplies the authoritative enrollment basis for [04](04-form2-form3-and-billing.md). Grade interpretation is a separate unresolved dependency for existing automated scholarship eligibility, not a reason to erase enrollment facts.

## Repository evidence

[Verification request](../../app/Http/Requests/AcademicVerificationRequest.php), [verification action](../../app/Actions/Records/SaveAcademicVerification.php), [academic model](../../app/Models/AcademicRecord.php), [evaluation action](../../app/Actions/Eligibility/EvaluateApplication.php), [decision freshness](../../app/Models/EvaluationRun.php), [grading policy](../../app/Services/Eligibility/SchoolGradingPolicy.php), [academic tests](../../tests/Feature/Records/AcademicVerificationTest.php), [scope tests](../../tests/Feature/Eligibility/DirectValidationScopeTest.php).
