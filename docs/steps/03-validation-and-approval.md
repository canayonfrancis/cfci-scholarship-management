# 3. Verify, evaluate and approve an individual scholarship award

**Responsible roles:** Coordinator configures/reviews the scholarship; Registrar verifies academic records. The provider/authority issues the external decision outside SMS.

**Current limitation:** School grading interpretation is not yet confirmed or implemented. Normal production evaluation returns **Needs review**, so a new award cannot currently complete activation. Follow the data-preparation and review steps below; the activation steps describe the guarded flow once that prerequisite is resolved. Do not manufacture passing criteria or use synthetic test results as approval for a real scholar.

## A. Confirm the validation period

1. Coordinator opens **Academic periods**. Add any missing actual semesters using **Add semester**; reuse an existing year/semester pair rather than creating duplicates.
2. In **Active validation period and grading source**, select the actual **Active academic year / semester for validation**. This is the semester being evaluated. An academic period's Open flag and the scholar's current profile period do not set this global validation context.
3. Select **Source grading semester under school policy** only when the school has confirmed which semester's grades must be used. Enter the approved source-policy reference and confirm the source checkbox. Do not assume first semester, a previous semester or a default academic year.
4. Select **Save validation context**. If the grading source is not confirmed, leave it unconfirmed and expect review. Selecting a source does not implement the still-unconfirmed grading conversion/threshold rules. Changing context invalidates reliance on prior decisions and requires reassessment.

## B. Registrar verifies the academic sources

1. Registrar opens the scholar detail page and selects **Add academic record** under Academic records, or opens **Academic verification**.
2. Select the correct scholar and academic period. Record current target-semester enrollment and the course/year information supported by the Registrar's source.
3. For the configured source grading semester, record the actual reported subject rows: subject identifiers, units, Prelim, Midterm, Semi-Final, Final, Average, Equivalent and remarks, where provided. If the source and target periods differ, prepare the required records for each; do not substitute another semester's grades.
4. Enter the **Registrar source / reference** and verification notes. Attach supporting certificates where appropriate: up to three PDF/JPG/PNG files per save, at most 2 MB each.
5. Use **Save draft** while information is incomplete. After checking the source, choose **Verify record** and confirm the stored revision/status. The form requires enrollment or academic standing to be verified beyond Unverified, plus a source reference; successful academic verification alone does not guarantee scholarship eligibility.
6. If a verified record is wrong, open it and use **Create correction**, give the correction reason and verify the corrected revision. Do not overwrite its historical source. A pending latest correction blocks reuse of an older verified revision for eligibility.

Enter reported grades as provided. SMS does not derive a semester average, convert Average into Equivalent or infer an eligibility threshold from a high number or the manually selected academic standing.

## C. Coordinator opens and evaluates the semester assessment

1. Open **Evaluations → Start assessment**.
2. Check the configured active period shown on **Start direct scholarship validation**. Select the scholarship program and choose **Find assigned scholars**.
3. Select the correct pending/active assignment under **Assigned scholar**, then choose **Open validation**. Existing assessment history for that scholar/program/semester is reused. This is an internal validation record, not a provider application submission.
4. Review the displayed active/source context, **Registrar enrollment source**, and **Configured source grading semester**. Correct missing or outdated sources through the appropriate staff role before relying on a result.
5. Under **Official semester rules**, review the linked applicable rule set. Use **Confirm semester rules** only when an applicable verified rule set is offered and actually reviewed. If none exists for the program/period, leave the case under review; do not borrow another program's rules. This confirmation does not approve a financial rate or resolve missing school grading criteria.
6. Upload the applicable **Private evaluation evidence** using the document types offered by the screen. Use an actual PDF/JPG/PNG up to 2 MB. Check the verification statement only after checking the source; otherwise evidence remains pending and must be reviewed using **Accept evidence** or **Reject evidence** with review notes.
7. In **Evaluate school compliance**, choose the actual beneficiary category and answer only facts supported by source references. Use **Unverified** for unknown facts. Review special-case, duration and exception fields against their documented basis; planning duration is not an automatic cutoff. The EDUKAR/AKLAT view intentionally omits excluded application requirements; use only the controls the selected program exposes.
8. Select **Evaluate and record school decision**. Read the latest result, each blocking check and whether it is current. Every run retains its sources and history.

| Latest result | Staff action |
| --- | --- |
| Needs review (`needs_review` / `needs_information`) | Resolve missing facts, evidence or policy. Leave the assignment pending. This is the normal result while school grading rules are unconfirmed. |
| Ineligible (`failed` / `not_qualified`) | Review the recorded failed checks. Correct genuine source errors through the proper workflow and reevaluate; do not force an approval. |
| Eligible (`passed` / `qualified`) and current | School checks have passed; proceed to the separate external-decision/activation gates. This is conditional on the missing school grading prerequisite being resolved. |
| Requires reassessment / retained history | Reopen current sources and run a new evaluation. An old eligible display is insufficient when its inputs/context have changed. |

## D. Record the actual external decision

1. Coordinator opens the same assessment's **External program decisions** section and checks the existing decision history. This action records an authority decision; SMS does not issue the provider's approval or submit an application to obtain one.
2. Select the real decision: **Approved**, **Rejected** or **Revoked**. Do not record Approved while waiting for the provider.
3. Enter the authority, decision reference, decision date, applicable validity dates and **How the external decision was verified**. The decision date cannot be in the future. Use the program's required authority where enforced by the form.
4. Upload the authority evidence (PDF/JPG/PNG, at most 2 MB), confirm the identity/program/semester verification statement and select **Record external decision**.
5. Confirm the latest decision belongs to the exact assignment and semester. Prior decisions remain in history; an older approval cannot override a newer rejection/revocation. Rejected, revoked, expired or not-yet-valid decisions cannot satisfy the activation gate.

External decisions can be recorded before school evaluation is eligible, but they never bypass it. Evidence acceptance for an evaluation is also separate from external award approval. Recording a rejection/revocation does not erase an existing award's history; the Coordinator must review any required status closure separately.

## E. Activate only when every gate passes

1. Coordinator verifies that the profile is Active, the program is available, the assignment is Pending, and no other scholarship assignment is Active for the scholar.
2. Verify a **current Eligible** school decision based on the latest verified sources and applicable rules. Until the school grading-policy limitation is resolved, stop here.
3. Verify the latest external decision is Approved, current/valid and backed by available verified evidence for this exact assignment and semester.
4. In **Award activation**, select **Activate award**. If the record changed or a check fails, read the error and reload/reassess; do not repeatedly submit an outdated page or edit stored statuses directly. The button's presence does not mean the prerequisites already pass.
5. Open **Scholars → View → Scholarship history** and confirm the assignment now shows **Active**. The activation retains the responsible actor, time and supporting evaluation/approval references. No billing, receipt or payout is created by activation.

## F. Continuing scholars and billing handoff

1. For a new semester, reuse the student, program and existing active assignment if it covers that semester. Do not register the student again or create a second active award just to renew semester validation.
2. Configure the actual target/source period, verify the appropriate academic revisions, open that semester's assessment, reevaluate and record its exact-semester external decision. An existing Active status alone does not authorize a new bill. The activation action is for Pending assignments; do not reactivate an assignment already Active.
3. When all current checks can pass, proceed to the [separate semester billing workflow](../sms/billing-batches.md), including explicit approved semester rate and duplicate-billing checks. Billed amounts are not received funds or documented student payments.

For missing or stale records, return to the responsible staff member and the relevant step above. If the assessment is tied to another assignment, review retained history with the Coordinator instead of creating duplicate records or changing its ownership.

Implementation reference: [current eligibility scope and limitations](../sms/eligibility-rules.md), [academic verification request](../../app/Http/Requests/AcademicVerificationRequest.php), [evaluation action](../../app/Actions/Eligibility/EvaluateApplication.php), [external-decision recording](../../app/Actions/Eligibility/RecordExternalApproval.php), [award activation guards](../../app/Actions/Eligibility/ActivateAward.php).
