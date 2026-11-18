# Scholarship Management System users

Updated on 2026-11-17 (Asia/Manila). Account counts and roles checked against the currently configured local database, `cfci_scholarship`. Existing staff credential entries are retained from the earlier verified inventory; the new Student credentials and access were verified through the existing login system.

## Accounts and credentials

| Role | User / login email | Password | Current status |
| --- | --- | --- | --- |
| Test Admin (`administrator`) | `test@example.com` | `admin123` | Existing local test account; password verified against its stored hash |
| School Scholarship Coordinator (`coordinator`) | `uat-coordinator@example.test` | `Cfci!5017a93cc6e4cf0a4ec1` | UAT Scholarship Coordinator; created 2026-11-13; credentials and role permissions verified |
| Registrar Staff (`registrar`) | `uat-registrar@example.test` | `Cfci!954aee6b930374887f05` | UAT Registrar Staff; created 2026-11-13; credentials and role permissions verified |
| Student (`student`) | `uat-student@example.test` | `CfciStudent!c7cc7ba80c86` | UAT Student; created 2026-11-17; Student-only account linked to `UAT-STUDENT-001`; login and own-profile access verified |

The configured database currently contains **four accounts**: one Test Admin, one Coordinator, one Registrar and one Student. The Student account and its dedicated synthetic profile were created on 2026-11-17. Existing accounts, credentials, role memberships and student profiles were preserved. No public registration or staff permissions were changed.

Local login: [http://127.0.0.1:8000/login](http://127.0.0.1:8000/login). Use separate browser profiles to test Student and staff sessions simultaneously; ordinary windows in the same profile share login cookies.

## Student test account

- **Account name:** UAT Student.
- **Linked Student ID:** `UAT-STUDENT-001` (dedicated synthetic test profile).
- **Login:** use the Student email and password in the table above at [the local login page](http://127.0.0.1:8000/login). Successful login opens [My student profile](http://127.0.0.1:8000/student/profile).
- **Access:** read-only access to this account's linked student profile and access to its own account settings. Staff pages and other students' records are denied. This account has only the `student` role.
- **Profile data:** basic test identification and email are provided; remaining school profile fields are intentionally unfilled. No scholarship assignments, enrollment verifications, awards, billing items or financial records were created for this test account.
- **Verified on 2026-11-17:** actual login, private own-profile response, account settings, ignored foreign `student_id` query parameters, denied staff/other-record URLs, and logout. Account audit reports one Student account and zero Student accounts without a linked profile.

## Who uses the system

- **Student:** logs in to view their own linked student profile and manage their own account settings. Official student records remain read-only, and staff features and other student records are restricted.
- **Coordinator:** manages scholarship programs and periods, scholarship assignments, evaluation, external-decision recording, award activation, and approval/verification of billing and financial records.
- **Registrar:** registers/updates scholar profiles, verifies academic records, and records/submits billing, actual funding/payment records, evidence and liquidation. These recording permissions do not grant approval authority.
- **Test Admin:** retained for testing the existing features. Eligibility, history, evidence and independent-review requirements still apply.

One Coordinator and one Registrar are the required operational staff roles. Separate Finance or Viewer accounts are not required for the approved workflow. Students can now have login accounts linked to their own student records. Student access requires both the explicit `student` membership and the verified account-to-record link; student records without login accounts remain supported.

See the [Student role implementation and setup](../15-student-role-implementation.md), [step-by-step workflow guides](../steps/README.md) and [current role permissions](../sms/auth-access-review.md).
