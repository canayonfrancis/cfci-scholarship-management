# 2. Add a scholar and create a pending scholarship assignment

**Responsible roles:** Registrar or Coordinator registers the profile; Coordinator records the scholarship assignment. Test Administrator can test both.

**Result:** One student profile with a pending assignment in scholarship history. Neither action approves or activates an award.

## A. Find or register the scholar

1. Open **Scholars** and search by Student ID, name or email before adding a record.
2. If the person already exists, open **View** and use **Edit record** as needed. Reuse the same profile for later semesters and scholarship changes.
3. If the person is new, select **Bulk Upload**, then **Download Template**. Follow [the bulk-upload guide](../16-bulk-scholar-upload-implementation.md).
4. Fill the exact template columns: Student ID, Last Name, First Name, Middle Name, EXT, Sex, Course, Year Level, Email, Contact. Middle Name may be blank; EXT is optional. Use existing active course names and preserve IDs/contact numbers as Text.
5. Upload the .xlsx or UTF-8 .csv file and select **Preview**. Correct every invalid or duplicate row, then upload the corrected file. Preview saves no scholar records.
6. Review the valid preview, check the confirmation box and select **Confirm Import**. All new profiles are saved together with active administrative record status. No academic semester, scholarship assignment, enrollment verification or login account is created.
7. Open the imported scholar through **View** and use **Edit record** to complete other known personal, family, address and academic-period fields. Unknown optional information remains blank. Profile completeness itself does not grant eligibility.

The directory's **Academic year** filter matches the scholar's current profile period, not every year in scholarship history. **Active scholarship** filters active assignments only. When searching for a newly added or pending scholar, clear those filters if necessary before assuming the person is missing.

## B. Record the intended scholarship

1. Hand off to the **Coordinator** and open the same scholar detail page.
2. Review **Scholarship history** for an existing active award or a duplicate pending assignment.
3. Select **Assign scholarship**.
4. Select the intended **Scholarship program** and **Starting academic semester**. Programs and periods must already be registered. Use a program available for activation when the school intends to proceed with a new award.
5. Leave **Assignment status** as **Pending** for a new award. Add an exact start date and school/reference notes when available.
6. Select **Save assignment**. Confirm the program and starting semester appear under Scholarship history with **Pending** status.
7. Continue with [academic verification, evaluation and approval](03-validation-and-approval.md).

The assignment form cannot activate a new award directly. Saving it does not create an evaluation, external approval, bill or payment. A duplicate pending/active assignment to the same program and starting semester is rejected. A student may have only one active scholarship across all programs.

## C. Close, relinquish or change an existing award

1. Coordinator opens the scholar's **Scholarship history → Update status** for the relevant pending/active assignment.
2. For a genuinely ended award choose **Ended / historical**; for an actual relinquishment choose **Relinquished**.
3. Enter the **Closing academic semester** and **Reason / school reference**. Enter exact closing dates when known and ensure the closure does not precede the award's start.
4. Save and verify the retained history. Relinquished is displayed distinctly, while the stored assignment is closed. Closed assignments cannot be reopened by overwriting their status.
5. To change programs, keep the original assignment's program and starting semester intact. Record a new pending assignment for the new program and complete its own validation and external-approval checks.

Do not change an active award back to Pending or delete history to make room for another award. Close an active scholarship before archiving its student profile. If a semester assessment is already tied to another assignment, ask the Coordinator to review the retained assessment instead of duplicating or relinking it.

Implementation reference: [scholar profile requirements](../sms/scholar-information.md), [scholar page](../../resources/views/scholars/show.blade.php), [assignment form](../../resources/views/assignments/form.blade.php), [assignment guards](../../app/Actions/Records/SaveScholarshipAssignment.php).
