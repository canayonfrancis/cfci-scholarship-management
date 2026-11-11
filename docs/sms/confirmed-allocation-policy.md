School clarification supplied by the user on 2026-11-11 (Asia/Manila). This supersedes earlier TES/TDP reference amounts and provisional financial permissions.

# Confirmed Billing Eligibility, Scholarship Amounts, and Submission Authority

The CFCI Scholarship Management System's billing module should focus on recording, allocating, tracking, and managing scholarship funds and budgets for active scholars. It is not intended for tuition billing or student payment collection.

## 1. Scholarship Programs and Amounts

The following scholarship amounts are confirmed and apply **per semester**:

- **TES (Tertiary Education Subsidy):** ₱7,500 per semester
- **TDP (Tulong Dunong Program):** ₱13,500 per semester
- **CoScho:** ₱45,000 per semester

The system must be flexible and allow authorized Administrators to:

- Add or manage scholarship programs.
- Configure and update scholarship amounts.
- Define the applicable academic year and semester for each rate.
- Activate or deactivate scholarship programs.
- Maintain historical scholarship rates without affecting previous approved allocations.

Do not permanently hardcode scholarship amounts into the system.

## 2. One Scholarship Per Scholar

**Each scholar may have only one active scholarship assignment at a time.**

A scholar cannot simultaneously receive TES, TDP, and CoScho, or hold multiple active scholarship assignments.

The system must:

- Prevent overlapping active scholarship assignments.
- Allow authorized scholarship changes when necessary.
- Preserve previous scholarship assignment history.
- Ensure that each scholar receives only one scholarship allocation per semester.
- Prevent duplicate fund allocation records for the same scholar, academic year, and semester.

If a scholar changes scholarship programs, the system must preserve previous records and prevent duplicate allocations for the affected semester. Any correction must be handled through an authorized adjustment process.

## 3. Billing Eligibility Requirements

Only qualified and active scholars should be included in scholarship billing and fund allocation records.

Before generating an allocation, the system must verify:

1. The student is currently marked as Active.
2. The student has one valid and active scholarship assignment.
3. The scholarship assignment applies to the selected academic year and semester.
4. The student has successfully completed the required eligibility validation for that semester.
5. The student's scholarship approval has been verified for the applicable semester.
6. The scholarship program has an approved amount configured for that semester.

Students who do not satisfy these requirements must not be automatically included in fund allocation records.

Previously approved historical records must remain accessible even when a scholar becomes inactive or graduates.

## 4. Purpose of the Billing Module

The billing module is primarily for scholarship fund and budget management.

Its responsibilities include:

- Recording scholarship fund allocations for eligible scholars.
- Tracking scholarship allocations by program, academic year, and semester.
- Automatically calculating total allocations based on the approved semester amount.
- Recording the available scholarship budget.
- Monitoring allocated and remaining funds.
- Generating financial summaries and scholarship allocation reports.
- Maintaining historical scholarship allocation records.

The system must distinguish between allocated scholarship funds and funds actually released or received.

An allocation must not automatically be marked as paid or disbursed.

## 5. Roles and Approval Authority

**Finance Personnel**

- Prepare semester scholarship allocation records.
- Review validated and approved scholars.
- Generate scholarship fund allocation summaries.
- Submit prepared allocations for approval.
- View and monitor scholarship budgets and reports.

**Administrator**

- Configure and approve scholarship programs and their semester amounts.
- Manage academic year and semester configurations.
- Review and approve submitted scholarship allocations.
- Authorize scholarship assignment changes and financial adjustments.
- Manage scholarship budget configurations.

Finance Personnel must not independently approve their own submitted allocations or modify approved scholarship amounts.

## 6. Academic Year and Semester Management

CFCI was established in 2007.

The system should support academic years beginning with 2007–2008 when historical records are available, while allowing future academic years to be created.

However, do not automatically assume that TES, TDP, and CoScho existed at CFCI beginning in 2007.

All scholarship eligibility validations, assignments, rates, and fund allocations must be associated with their respective academic year and semester.

## 7. Implementation Guidelines

Use Laravel and the existing authentication system.

Keep the first implementation simple, accurate, maintainable, and flexible.

The system must:

- Implement role-based permissions for Administrators and Finance Personnel.
- Enforce one active scholarship assignment per scholar.
- Validate eligibility before including a scholar in a semester allocation.
- Use configurable scholarship rates per semester.
- Prevent duplicate semester allocations.
- Calculate financial totals using the approved scholarship rates.
- Preserve historical scholarship amounts and records.
- Track preparation, submission, approval, and financial adjustments.
- Avoid creating allocations without the required validation and approval.
- Avoid implementing tuition payment collection, payment gateways, or unrelated financial functionality.

**Important Business Rules:**

1. One active scholarship per scholar at any given time.
2. One scholarship fund allocation per eligible scholar per semester.
3. Scholarship amounts are configured per semester.
4. TES = ₱7,500, TDP = ₱13,500, and CoScho = ₱45,000 per semester as initial configured values.
5. Only active and validated scholars with verified semester approval are eligible for new allocations.
6. Scholarship amounts and programs must remain configurable.
7. Finance Personnel prepare and submit allocations; Administrators approve them.
8. Historical financial records must remain accurate when scholarship amounts or student statuses change.
9. Billing is for scholarship fund and budget tracking, not tuition billing or collecting payments from students.

Before implementing, inspect the existing Laravel models, migrations, authentication, roles, and scholarship modules. Reuse existing structures where appropriate, avoid duplicating functionality, and ensure the billing module integrates correctly with the current system.