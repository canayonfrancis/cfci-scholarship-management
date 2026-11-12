# Scholarship Management System users

Verified on 2026-11-12 (Asia/Manila) against the currently configured local database, `cfci_scholarship`.

## Accounts and credentials

| Role | User / login email | Password | Current status |
| --- | --- | --- | --- |
| Test Admin (`administrator`) | `test@example.com` | `admin123` | Existing local test account; password verified against its stored hash |
| School Scholarship Coordinator (`coordinator`) | Not assigned | Not set | Required operational account; not yet created |
| Registrar Staff (`registrar`) | Not assigned | Not set | Required operational account; not yet created |

The configured database currently contains **one account**: the Test Admin above. Coordinator and Registrar credentials are unavailable until those staff accounts are provisioned. This document does not create accounts or change existing passwords, roles or authentication.

## Who uses the system

- **Coordinator:** manages scholarship programs and periods, scholarship assignments, evaluation, external-decision recording, award activation, and approval/verification of billing and financial records.
- **Registrar:** registers/updates scholar profiles, verifies academic records, and records/submits billing, actual funding/payment records, evidence and liquidation. These recording permissions do not grant approval authority.
- **Test Admin:** retained for testing the existing features. Eligibility, history, evidence and independent-review requirements still apply.

One Coordinator and one Registrar are the required operational staff roles. Separate Finance or Viewer accounts are not required for the approved workflow. Scholars are managed as student records; this workflow does not require student login accounts.

See the [step-by-step workflow guides](../steps/README.md) and [current role permissions](../sms/auth-access-review.md).
