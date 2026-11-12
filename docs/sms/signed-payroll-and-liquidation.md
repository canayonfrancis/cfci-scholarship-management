# Signed payment acknowledgment and liquidation

Implemented against the user's confirmations on 2026-11-13 (Asia/Manila). Test and local rollout evidence is recorded in Task 26 in [progress](progress.md).

## Confirmed requirements

An acknowledgment must show Student ID/name, the actual payment date, the amount received and the payment reference. A Coordinator records the acknowledgment document reference and page/line and explicitly verifies the beneficiary signature and coverage. **Every future liquidation submission and acceptance requires verified signed acknowledgment for each payout and an accepted liquidation-support document.** Existing payment proof, financial reconciliation and applicable deduction authority/settlement proof remain necessary. This covers school and direct payouts and older payouts considered in future decisions.

Previously accepted liquidations keep their original acceptance. An absent historical checklist snapshot or unavailable evidence is displayed as an exception; no generic proof is automatically classified as signed and no past acceptance is retroactively certified.

`confirmed-payment-acknowledgment-v1` is an internal version identifier for these user-confirmed checks. It is not an invented official provider form or policy reference. Actual acknowledgment reference and page/line are supplied by the human reviewer.

## Staff procedure

1. Registrar records the actual payout and ordinary payment proof; a separate Coordinator reviews/posts it using the existing workflow. Payroll preparation remains a plan, not proof of payment.
2. On **Payments & liquidation -> batch -> Student payment record**, use **Private evidence** to upload **Signed acknowledgment**, as a private PDF/JPG/PNG within the existing 2 MB limit. The document belongs to this exact payout. Ordinary `payment_evidence` remains distinct.
3. A different Coordinator opens the private evidence and checks the beneficiary signature. In **Signed payment acknowledgment**, select that document and transcribe Student ID, beneficiary name, actual payment date, amount received and payment reference. Enter the real acknowledgment reference and page/line, check the explicit signature confirmation and submit.
4. SMS compares the transcribed coverage with the retained actual payout. The amount must equal **student net received**, including when gross benefit includes separately authorized deductions. The actor cannot be the document uploader or payout recorder. The normal Registrar/Coordinator roles and retained Test Administrator exception continue; no new role is required.
5. Prepare/refresh the batch's liquidation draft. Upload **Liquidation support** to this draft and have another Coordinator accept the intact document. A support file owned by another report does not qualify. A pending upload alone is insufficient.
6. Submit the draft. SMS rechecks full financial reconciliation, all payment signatures, supporting evidence and existing source-document integrity. It retains the specific signature verification IDs, document references/checksums and required support-document identity in a frozen checklist snapshot.
7. A Coordinator different from preparer/submitter accepts or returns the submitted liquidation. Acceptance rechecks the same retained evidence and all current completeness conditions. A missing or changed source blocks acceptance. A returned report keeps its history; create a new sequence for correction using the existing flow.

A submitted historical report with no checklist snapshot must satisfy the current requirements before future acceptance. If it lacks a required support upload, return it for a new draft; do not unfreeze its old lines or silently attach documents to an accepted report.

## Minimal additive storage

Migration `2026_11_13_000011_add_signed_payment_acknowledgments` introduces:

| Change | Why it is needed |
| --- | --- |
| `payment_acknowledgments` | Append-only human verification linked to an existing posted payout, its existing owned signed document and the bill's student; verifier/time, document reference/page-line, frozen identity/date/net/reference, checksum and evidence-review snapshot |
| Nullable `liquidations.compliance_snapshot` | Retains the exact confirmed checklist/version and evidence checked at submission, or at a future acceptance of an older submitted report; historical rows stay null |

There is no second payments ledger, mutable balance, new financial amount, new document-owner type, payout-column change or historical backfill. Signature verification does not create/post a payout or change cash, deductions, billing, payroll or student records. Both the new verification row and document acceptance/audit commit atomically.

Restrictive FKs preserve the payout/document/student/verifier sources. Unique document identity prevents duplicate verification. Database insertion guards enforce posted original payout scope, exact beneficiary/payment amounts and the owned accepted signed document; verified records cannot be updated/deleted. New liquidation transitions require signature/support metadata and a confirmed checklist snapshot. A stored snapshot cannot be rewritten. File bytes/checksums and authorization are additionally verified in the application. Populated rollback refuses to discard the new history.

## Integrity and historical interpretation

- The action acquires the existing financial terms lock before current payout/document/verification locks. Transactions and the unique document key prevent competing confirmations. Identical retries return the retained valid verification; conflicting document reference/page-line is rejected. An unavailable signed file is rejected even on a retry.
- Generic evidence acceptance verifies ordinary evidence integrity. Even a `signed_acknowledgment` document marked accepted through ordinary review is insufficient until a separate explicit signature-verification record exists. Filenames, free-text notes and prepared payrolls never imply a signature.
- Private download/review routes still enforce the exact financial owner and existing role/checksum checks. References and human verifier/time are visible in payment detail. Signed evidence uses the existing one-owner document model; no shared-file ownership bridge, representative-signature workflow or electronic-signature authentication is inferred.
- Posted transactions, accepted document metadata and verified acknowledgment facts stay immutable. Later file loss causes a current evidence exception without erasing actual payments or changing an accepted decision. A replacement document receives its own separate review/verification; a frozen submitted checklist continues to identify its original evidence.
- Receipt proof, payment proof and deduction authority/settlement proof must remain intact. Liquidation retains the existing exact gross/net/deduction, full coverage, independent review and zero unspent school-cash requirements. Direct payments never become school cash. Missing required evidence blocks new decisions rather than changing historical monetary totals.
- A historical accepted liquidation without a retained checklist is explicitly **unconfirmed under the new requirements**, even if new evidence is later collected. The system does not infer what was checked at its past acceptance.

## Remaining boundaries

The basic internal [payroll register](payroll-preparation.md) and actual payout remain separate. This task does not implement direct-provider funding readiness, Form 2/Form 3 templates/classification, new installment schedules, provider-specific official forms or the six complete report outputs. School grading criteria remain unconfirmed; downstream test fixtures use explicitly synthetic qualified sources. No missing academic policy is bypassed by signature verification.

## Implementation references

[Verification action](../../app/Actions/Billing/VerifyPaymentAcknowledgment.php), [verification model](../../app/Models/PaymentAcknowledgment.php), [evidence service](../../app/Services/Billing/SignedAcknowledgment.php), [liquidation completeness](../../app/Services/Billing/LiquidationSummary.php), [liquidation transitions](../../app/Actions/Billing/ManageLiquidation.php), [feature tests](../../tests/Feature/Billing/SignedAcknowledgmentTest.php) and [MariaDB concurrency tests](../../tests/Feature/Database/SignedAcknowledgmentConcurrencyTest.php).
