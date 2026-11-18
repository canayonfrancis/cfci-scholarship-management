# Scholar login account creation flow

**Current status (2026-11-19):** New Student accounts can be created without email. The account is linked with **Pending password setup** status. No notification is sent, and the Student cannot sign in until secure password setup is completed. The setup/reset email buttons have been removed.

## Registrar steps

1. Sign in with your existing Registrar Staff account. The retained Test Administrator is also authorized.
2. Open **Scholars**, search first and register the official record through **Bulk Upload** only if it does not already exist. Download the template, complete its required identity/course/contact fields, select **Preview**, review every row and choose **Confirm Import**. See [the bulk upload guide](16-bulk-scholar-upload-implementation.md). Importing a Scholar record alone does not create a login.
3. Open **Student accounts** and search for the Student ID.
4. Select **Manage login** for the intended Student. Confirm the identity and **No account linked** state.
5. Correct a missing/invalid contact email through the existing Scholar record controls. The login email must be valid, distinct and reviewed even though no email is being sent.
6. Choose **Confirm password to continue** if shown. Enter your own operator password; confirmation lasts 15 minutes.
7. In **Create Student login account**, review **Confirmed login email**, enter an **Identity review / management reason** and check the identity-review box.
8. Select **Create new account**. The system creates one pending User, assigns only lowercase `student`, links the Student and records the actor/reason. No email or password-reset token is generated.
9. Review **Pending password setup**. Repeating the same valid request preserves the existing association and credentials. Occupied login emails, shared Student contacts and conflicting/privileged associations require authorized review.

## Student access

Both initial Student password fields default to **CRONASIA** at the user's request. The operator can replace it with a unique password before saving. The shared default is not applied to existing saved passwords, and saving through the form is still required.

A newly created account remains pending until its initial password is saved. In **Student accounts > Manage login**, use **Set Student password**, confirm your own operator password if requested, enter and confirm the new Student password, provide the identity-review reason, check the identity confirmation and choose **Save Student password**. The status then changes to **Linked and configured** and the Student can sign in with the linked email and saved password. This approved manual setup sends no email and does not require a reset token. See [the password form and test evidence](22-student-initial-password-form.md).

Existing enabled Students continue signing in through the existing login page with their own credentials and can open **My student profile**. Pending or inactive accounts remain denied. Reactivation does not clear pending setup or change scholarship eligibility.

Account management does not approve scholarships, change enrollment, or delete Student, roster, billing or historical records. Existing-account linking remains removed from the web interface.

See [the current implementation and test evidence](21-student-account-creation-without-email.md). [SMTP verification](20-student-smtp-verification.md) is historical evidence of the separate mail blocker; verified delivery remains required whenever credential email is sent.

Students can now choose **My student profile > Edit my information** to update their own personal/contact/address/family details. Official identifiers, academics and scholarship fields remain staff-controlled. See [Student profile editing](24-student-profile-editing.md).
