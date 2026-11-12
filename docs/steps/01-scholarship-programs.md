# 1. Add or maintain a scholarship program

**Responsible role:** Coordinator; Test Administrator for testing.

**Result:** A scholarship program reference record. Saving it does not register a scholar, approve an award, create a billing batch or record received funds.

## Step by step

1. Sign in and open **Scholarship programs**.
2. Search by program code or name. If the program already exists, use **View** or **Edit**. Reuse the same program across scholars, semesters and billing batches; do not create another program for each batch or academic year.
3. If it does not exist, select **Add program**. The page title is **Add scholarship program**.
4. Enter the program's reference information:

   | Field | How to complete it |
   | --- | --- |
   | Program code | Use the actual unique code. It becomes the record's stable identity and cannot be renamed through Edit. |
   | Program name | Enter the actual program name. |
   | School-stated semester amount | Use a confirmed reference amount; leave blank when unconfirmed. This does not approve a semester rate or payout. |
   | Scholarship Duration | Optional planning duration in years. It is not an automatic eligibility cutoff. |
   | Availability for new active assignments | Choose Active when the program is available for award activation; Inactive prevents new activation. |
   | School source / reference notes | Identify the supporting school reference when available. A source/reason is required when editing the reference amount. |

5. Handle the **Batch Number** dropdown inside this form as an optional reference:
   - For an ordinary new program, leave the reference fields blank. A new program does not need an existing billing batch. **No matching billing batches** is normal when no relevant records exist.
   - To inspect/reference an existing batch, choose **Billing batch reference program**, then **Batch academic year / semester**, then **Batch Number**.
   - Options come from existing records, with labels such as `Batch 1 - TES` and actual batch IDs as values. Repeated labels include their academic period where necessary.
   - The reference program is the batch's existing owner, separate from the new program code. The selected batch must match that reference owner and semester.
   - This context is validated but **is not stored as a program relationship**. It never moves a batch, creates a new batch or copies another program's award information. After a successful save, do not expect this temporary selection to be restored on a later visit. Valid context is retained when form validation fails.
6. Select **Save program**. Correct any errors and resubmit; do not keep creating new codes to work around an existing-program error.
7. On the program detail page, verify its code, name, reference amount and availability. Its scholarship-assignment list can remain empty until a Coordinator assigns a scholar.
8. Continue with [registering a scholar and pending assignment](02-scholars-and-assignments.md). Making the program Active does not approve that scholar.

## Editing an existing program

1. Open **Scholarship programs → View → Edit program**, or use **Edit** in the list.
2. Keep the original program code. Update the permitted reference fields and record the reason/source when changing the amount.
3. If using Batch Number, select only a batch already belonging to this program and the selected academic period. The shared form restricts the reference program accordingly.
4. Save and review the program detail page. Existing assignment history and previously recorded semester rates, batch amounts and submissions are not rewritten by changing a program reference amount.

## Where billing belongs

Use **Billing batches** separately when an award satisfies the current eligibility and approval checks. A Coordinator must explicitly confirm the program's actual semester rate before batch creation can proceed. Creating a draft is a separate action; selecting Batch Number on Add Program never creates one. Multiple batches can belong to the same program and academic period. A bill, an approved planning budget and actual funds received are separate records.

Implementation reference: [program form](../../resources/views/programs/form.blade.php), [program validation](../../app/Http/Requests/ScholarshipProgramRequest.php), [billing workflow](../sms/billing-batches.md).
