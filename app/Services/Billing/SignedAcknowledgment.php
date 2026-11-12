<?php

namespace App\Services\Billing;

use App\Models\PaymentAcknowledgment;
use App\Models\Payout;
use App\Services\Eligibility\PrivateEvidence;

class SignedAcknowledgment
{
    public const POLICY = 'confirmed-payment-acknowledgment-v1';

    public function __construct(private PrivateEvidence $evidence) {}

    public function usable(PaymentAcknowledgment $ack, Payout $payout): bool
    {
        $document = $ack->document;

        return $payout->status === 'posted' && $payout->entry_type === 'original'
            && $ack->payout_id === $payout->id && $ack->student_id === $payout->billingItem->student_id
            && $ack->policy_reference === self::POLICY && $ack->verified_by && $ack->verified_at
            && $ack->institutional_student_id === $payout->institutional_student_id && filled($ack->institutional_student_id)
            && $ack->beneficiary_name === $payout->payee && filled($ack->beneficiary_name)
            && $ack->paid_on->toDateString() === $payout->paid_on?->toDateString()
            && $ack->amount_received === $payout->net_amount && $ack->payment_reference === $payout->reference
            && filled($ack->document_reference) && filled($ack->page_line)
            && $document && $document->payout_id === $payout->id && $document->document_type === 'signed_acknowledgment'
            && $document->checksum === $ack->document_checksum && $this->evidence->usable($document);
    }

    public function snapshot(PaymentAcknowledgment $ack): array
    {
        return ['id' => $ack->id, 'payout_id' => $ack->payout_id, 'student_id' => $ack->student_id, 'verified_by' => $ack->verified_by,
            'verified_at' => $ack->verified_at->toIso8601String(), 'document_reference' => $ack->document_reference, 'page_line' => $ack->page_line,
            'document' => $this->evidence->snapshot($ack->document)];
    }
}
