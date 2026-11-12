<?php

namespace App\Actions\Billing;

use App\Actions\Records\RecordAudit;
use App\Models\Document;
use App\Models\PaymentAcknowledgment;
use App\Models\Payout;
use App\Models\ProgramPeriodTerm;
use App\Models\User;
use App\Services\Billing\FinancialEvidence;
use App\Services\Billing\Money;
use App\Services\Billing\SignedAcknowledgment;
use App\Services\Eligibility\PrivateEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class VerifyPaymentAcknowledgment
{
    public function __construct(private FinancialEvidence $files, private PrivateEvidence $evidence, private RecordAudit $audit) {}

    public function handle(Payout $payout, User $actor, array $input): PaymentAcknowledgment
    {
        Gate::forUser($actor)->authorize('financial-verify');
        $data = Validator::make($input, [
            'document_id' => ['required', 'integer'],
            'document_reference' => ['required', 'string', 'max:255', 'regex:/\S/'],
            'page_line' => ['required', 'string', 'max:255', 'regex:/\S/'],
            'institutional_student_id' => ['required', 'string', 'max:50'],
            'beneficiary_name' => ['required', 'string', 'max:1100'],
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Manila')->toDateString()],
            'amount_received' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'regex:/^\d+(?:\.\d{1,2})?$/D'],
            'payment_reference' => ['required', 'string', 'max:255'], 'signature_confirmed' => ['accepted'],
            'verified_by' => ['prohibited'], 'verified_at' => ['prohibited'], 'payout_id' => ['prohibited'], 'student_id' => ['prohibited'],
        ])->validate();
        $scope = $payout->batch->program_period_term_id;

        return DB::transaction(function () use ($payout, $actor, $data, $scope) {
            ProgramPeriodTerm::whereKey($scope)->lockForUpdate()->firstOrFail();
            $payout = Payout::whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if ($payout->status !== 'posted' || $payout->entry_type !== 'original' || $payout->reversals()->where('status', 'posted')->lockForUpdate()->first()) {
                $this->fail('Verify a posted, unreversed actual payment; a payroll plan or payment draft is insufficient.');
            }
            $document = Document::whereKey($data['document_id'])->where('payout_id', $payout->id)->where('document_type', 'signed_acknowledgment')->lockForUpdate()->first();
            if (! $document || $document->uploaded_by === $actor->id || $payout->recorded_by === $actor->id) {
                $this->fail('A different authorized Coordinator must verify signed evidence owned by this exact payout. Ordinary payment proof is not signed verification.');
            }
            $expected = ['institutional_student_id' => $payout->institutional_student_id, 'beneficiary_name' => $payout->payee,
                'paid_on' => $payout->paid_on?->toDateString(), 'amount_received' => $payout->net_amount, 'payment_reference' => $payout->reference];
            foreach ($expected as $field => $value) {
                $actual = $field === 'amount_received' ? Money::decimal(Money::cents((string) $data[$field])) : trim($data[$field]);
                if ($value === null || $actual !== $value) {
                    $this->fail('Acknowledgment '.$field.' must match the actual payout, including the student net amount rather than gross benefit.');
                }
            }
            if ($document->review_status !== 'accepted') {
                $this->files->review($document, $actor, 'accepted', 'Beneficiary signature and exact payment coverage verified; '.$data['document_reference'].'; '.$data['page_line']);
            }
            // Preserve current reads even when a competing request began with an older snapshot.
            $document = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if (! $this->evidence->usable($document)) {
                $this->fail('Signed evidence is unavailable, unaccepted or its checksum changed.');
            }
            $existing = PaymentAcknowledgment::where('document_id', $document->id)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->document_reference !== trim($data['document_reference']) || $existing->page_line !== trim($data['page_line'])) {
                    $this->fail('This document already has immutable signature verification. Upload a new document for a corrected verification.');
                }

                return $existing;
            }
            $ack = PaymentAcknowledgment::create($expected + [
                'payout_id' => $payout->id, 'student_id' => $payout->billingItem->student_id, 'document_id' => $document->id,
                'verified_by' => $actor->id, 'verified_at' => now(), 'document_reference' => trim($data['document_reference']), 'page_line' => trim($data['page_line']),
                'document_checksum' => $document->checksum, 'policy_reference' => SignedAcknowledgment::POLICY, 'evidence_snapshot' => $this->evidence->snapshot($document),
            ]);
            $this->audit->record($actor, $ack, 'signed_payment_acknowledgment_verified');

            return $ack;
        }, 3);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['acknowledgment' => $message]);
    }
}
