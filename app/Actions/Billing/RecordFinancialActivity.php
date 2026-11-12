<?php

namespace App\Actions\Billing;

use App\Actions\Records\RecordAudit;
use App\Models\AcademicValidationContext;
use App\Models\BillingBatch;
use App\Models\BillingItem;
use App\Models\Deduction;
use App\Models\FundingAllocation;
use App\Models\FundingReceipt;
use App\Models\Payout;
use App\Models\ProgramPeriodTerm;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\BillingEligibility;
use App\Services\Billing\FinancialEvidence;
use App\Services\Billing\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class RecordFinancialActivity
{
    public function __construct(private RecordAudit $audit, private FinancialEvidence $evidence, private BillingEligibility $eligibility) {}

    public static function approved(BillingBatch $batch): bool
    {
        return ($batch->status === 'submitted' && $batch->submitted_at && $batch->approval()->exists())
            || ($batch->status === 'finalized' && $batch->finalized_at && $batch->finalized_by);
    }

    public function receipt(User $actor, array $data): FundingReceipt
    {
        Gate::forUser($actor)->authorize('financial-prepare');

        return DB::transaction(function () use ($actor, $data) {
            $terms = ProgramPeriodTerm::whereKey($data['program_period_term_id'])->lockForUpdate()->firstOrFail();
            if (Money::cents($data['amount']) <= 0) {
                $this->fail('A receipt must record a positive actual amount.');
            }
            if ($terms->fundingReceipts()->whereRaw('LOWER(TRIM(source)) = ?', [mb_strtolower(trim($data['source']))])->whereRaw('LOWER(TRIM(reference)) = ?', [mb_strtolower(trim($data['reference']))])->exists()) {
                $this->fail('This source/reference is already recorded for the semester.');
            }
            $receipt = FundingReceipt::create([
                'program_period_term_id' => $terms->id, 'program_id' => $terms->program_id, 'academic_period_id' => $terms->academic_period_id,
                'amount' => Money::decimal(Money::cents($data['amount'])), 'received_on' => $data['received_on'], 'source' => trim($data['source']), 'reference' => trim($data['reference']),
                'recorded_by' => $actor->id, 'record_key' => hash('sha256', $terms->id.'|'.mb_strtolower(trim($data['source'])).'|'.mb_strtolower(trim($data['reference']))),
            ]);
            $this->audit->record($actor, $receipt, 'actual_receipt_recorded_draft');

            return $receipt;
        }, 3);
    }

    public function postReceipt(FundingReceipt $receipt, User $actor): void
    {
        Gate::forUser($actor)->authorize('financial-verify');
        DB::transaction(function () use ($receipt, $actor) {
            ProgramPeriodTerm::whereKey($receipt->program_period_term_id)->lockForUpdate()->firstOrFail();
            $receipt = FundingReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($receipt->status === 'posted') {
                return;
            }
            if (! $receipt->recorded_by || $receipt->recorded_by === $actor->id || ! $receipt->received_on || $receipt->received_on->toDateString() > now('Asia/Manila')->toDateString() || Money::cents($receipt->amount) <= 0 || ! filled($receipt->reference) || ! filled($receipt->source)) {
                $this->fail('A different Coordinator must verify a dated, identified actual receipt. Legacy placeholders are not actual receipt records.');
            }
            if (! $this->evidence->approved($receipt, 'receipt_evidence')) {
                $this->fail('Accepted intact receipt evidence is required before funds are counted as received.');
            }
            $before = $receipt->only($receipt->getFillable());
            $receipt->update(['status' => 'posted', 'posting_key' => 'receipt:'.$receipt->id, 'posted_by' => $actor->id, 'posted_at' => now()]);
            $this->audit->record($actor, $receipt, 'actual_school_funds_verified', $before);
        }, 3);
    }

    public function allocate(BillingBatch $batch, User $actor, array $data): FundingAllocation
    {
        Gate::forUser($actor)->authorize('financial-prepare');

        return DB::transaction(function () use ($batch, $actor, $data) {
            ProgramPeriodTerm::whereKey($batch->program_period_term_id)->lockForUpdate()->firstOrFail();
            $batch = BillingBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $receipt = FundingReceipt::whereKey($data['funding_receipt_id'])->lockForUpdate()->firstOrFail();
            $key = 'funding:'.$data['operation_key'];
            $previous = FundingAllocation::where('posting_key', $key)->first();
            if ($previous) {
                if ($previous->billing_batch_id !== $batch->id || $previous->funding_receipt_id !== $receipt->id || Money::cents($previous->amount) !== Money::cents($data['amount'])) {
                    $this->fail('This operation key was already used for a different cash allocation.');
                }

                return $previous;
            }
            if (! self::approved($batch) || $receipt->status !== 'posted' || $receipt->entry_type !== 'original' || $receipt->reversals()->where('status', 'posted')->exists() || $receipt->program_period_term_id !== $batch->program_period_term_id || ! $this->evidence->approved($receipt, 'receipt_evidence')) {
                $this->fail('Allocate only unreversed verified school receipts to an approved batch in the same program and semester.');
            }
            $amount = Money::cents($data['amount']);
            $remainingReceipt = Money::cents($receipt->amount) + Money::signedTotal($receipt->reversals()->where('status', 'posted')->lockForUpdate()->get()) - Money::signedTotal($receipt->allocations()->where('status', 'posted')->lockForUpdate()->get());
            $batchTotal = $batch->items()->get()->sum(fn ($item) => Money::cents($item->effectiveAmount()));
            $remainingBatch = $batchTotal - Money::signedTotal($batch->fundingAllocations()->where('status', 'posted')->lockForUpdate()->get());
            if ($amount <= 0 || $amount > $remainingReceipt || $amount > $remainingBatch) {
                $this->fail('The allocation exceeds unallocated actual receipts or the approved batch amount.');
            }
            $allocation = FundingAllocation::create(['funding_receipt_id' => $receipt->id, 'billing_batch_id' => $batch->id, 'program_id' => $batch->program_id, 'academic_period_id' => $batch->academic_period_id, 'amount' => Money::decimal($amount), 'status' => 'posted', 'posting_key' => $key, 'posted_by' => $actor->id, 'posted_at' => now()]);
            $this->audit->record($actor, $allocation, 'received_cash_allocated_to_batch');

            return $allocation;
        }, 3);
    }

    public function payout(User $actor, array $data): Payout
    {
        Gate::forUser($actor)->authorize('financial-prepare');
        $scopeId = BillingItem::findOrFail($data['billing_item_id'])->batch->program_period_term_id;

        return DB::transaction(function () use ($actor, $data, $scopeId) {
            ProgramPeriodTerm::whereKey($scopeId)->lockForUpdate()->firstOrFail();
            $item = BillingItem::whereKey($data['billing_item_id'])->lockForUpdate()->firstOrFail();
            if (! self::approved($item->batch) || $item->status !== 'finalized') {
                $this->fail('Payment records require an approved, submitted allocation item.');
            }
            $gross = Money::cents($data['gross_amount']);
            $net = Money::cents($data['net_amount']);
            if ($gross <= 0 || $net > $gross || ($data['payment_mode'] === 'direct' && $net !== $gross)) {
                $this->fail('Direct payments have no school deductions; the actual student amount must equal the benefit recorded.');
            }
            if ($gross + Money::signedTotal($item->payouts()->where('status', 'posted')->lockForUpdate()->get(), 'gross_amount') > Money::cents($item->effectiveAmount())) {
                $this->fail('This payment exceeds the remaining student allocation.');
            }
            if ($item->payouts()->where('method', $data['method'])->whereRaw('LOWER(TRIM(reference)) = ?', [mb_strtolower(trim($data['reference']))])->exists()) {
                $this->fail('This payment method/reference is already recorded for this student allocation.');
            }
            $payout = Payout::create([
                'billing_item_id' => $item->id, 'billing_batch_id' => $item->billing_batch_id, 'gross_amount' => Money::decimal($gross), 'net_amount' => Money::decimal($net),
                'paid_on' => $data['paid_on'], 'payee' => $item->student->full_name, 'institutional_student_id' => $item->student->student_number,
                'payment_mode' => $data['payment_mode'], 'method' => $data['method'], 'reference' => trim($data['reference']), 'recorded_by' => $actor->id,
                'record_key' => hash('sha256', $item->id.'|'.$data['method'].'|'.mb_strtolower(trim($data['reference']))),
            ]);
            $this->audit->record($actor, $payout, 'student_payment_recorded_draft');

            return $payout;
        }, 3);
    }

    public function deduct(Payout $payout, User $actor, array $data, UploadedFile $file): Deduction
    {
        Gate::forUser($actor)->authorize('financial-verify');
        $scopeId = $payout->batch->program_period_term_id;
        $document = null;
        try {
            return DB::transaction(function () use ($payout, $actor, $data, $file, $scopeId, &$document) {
                ProgramPeriodTerm::whereKey($scopeId)->lockForUpdate()->firstOrFail();
                $payout = Payout::whereKey($payout->id)->lockForUpdate()->firstOrFail();
                if ($payout->status !== 'draft' || $payout->payment_mode !== 'school' || $payout->recorded_by === $actor->id || empty($data['permitted_by_program'])) {
                    $this->fail('A separate Coordinator may authorize a documented deduction only on a school-payment draft.');
                }
                $amount = Money::cents($data['amount']);
                if ($amount <= 0 || $amount + Money::signedTotal($payout->deductions()->lockForUpdate()->get()) > Money::cents($payout->gross_amount) - Money::cents($payout->net_amount)) {
                    $this->fail('Authorized deductions cannot exceed the stated gross-to-student-payment difference.');
                }
                foreach (['basis', 'authority_reference', 'consent_reference', 'destination'] as $field) {
                    if (! filled($data[$field] ?? null)) {
                        $this->fail('Exact program authority, beneficiary consent/approved basis, reason and destination are required.');
                    }
                }
                $deduction = $payout->deductions()->create(['billing_batch_id' => $payout->billing_batch_id, 'type' => $data['type'], 'amount' => Money::decimal($amount), 'basis' => $data['basis'], 'destination' => $data['destination'], 'authority_reference' => $data['authority_reference'], 'consent_reference' => $data['consent_reference'], 'authorized_by' => $actor->id, 'authorized_at' => now()]);
                $document = $this->evidence->upload($deduction, $actor, $file, 'deduction_authority');
                $document->update(['review_status' => 'accepted', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_notes' => 'Coordinator verified the cited program-specific authority and beneficiary basis for this exact deduction.']);
                $this->audit->record($actor, $deduction, 'specific_benefit_deduction_authorized');

                return $deduction;
            });
        } catch (Throwable $error) {
            if ($document) {
                Storage::disk($document->disk)->delete($document->storage_path);
            }
            throw $error;
        }
    }

    public function postPayout(Payout $payout, User $actor): void
    {
        Gate::forUser($actor)->authorize('financial-verify');
        // Resolve immutable scope metadata before starting a repeatable-read transaction.
        // No consistent read may establish a stale snapshot before the serializing lock.
        $scopeId = $payout->batch->program_period_term_id;
        DB::transaction(function () use ($payout, $actor, $scopeId) {
            $terms = ProgramPeriodTerm::whereKey($scopeId)->lockForUpdate()->firstOrFail();
            $batch = BillingBatch::whereKey($payout->billing_batch_id)->lockForUpdate()->firstOrFail();
            $item = BillingItem::whereKey($payout->billing_item_id)->lockForUpdate()->firstOrFail();
            $payout = Payout::whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if ($payout->status === 'posted') {
                return;
            }
            Student::whereKey($item->student_id)->lockForUpdate()->firstOrFail();
            AcademicValidationContext::whereKey(1)->lockForUpdate()->firstOrFail();
            ScholarshipProgram::whereKey($item->program_id)->lockForUpdate()->firstOrFail();
            if (! self::approved($batch) || ! $payout->recorded_by || $payout->recorded_by === $actor->id || ! $payout->institutional_student_id || ! $payout->paid_on || $payout->paid_on->toDateString() > now('Asia/Manila')->toDateString()) {
                $this->fail('A separate Coordinator must verify an identified, dated payment against an approved batch.');
            }
            $basis = $this->eligibility->basis($item->application, $terms, $batch->appliedRate());
            if (! $this->evidence->approved($payout, 'payment_evidence')) {
                $this->fail('Signed acknowledgment or other accepted intact payment proof is required.');
            }
            $gross = Money::cents($payout->gross_amount);
            $net = Money::cents($payout->net_amount);
            $deductions = $payout->deductions()->lockForUpdate()->get();
            foreach ($deductions as $deduction) {
                if (! $deduction->authorized_by || ! $deduction->authorized_at || ! filled($deduction->authority_reference) || ! filled($deduction->consent_reference) || ! $this->evidence->approved($deduction, 'deduction_authority')) {
                    $this->fail('Each deduction requires specific verified authority and evidence.');
                }
            }
            if ($gross <= 0 || $net + Money::signedTotal($deductions) !== $gross || ($payout->payment_mode === 'direct' && $deductions->isNotEmpty())) {
                $this->fail('Gross benefit must equal the actual student amount plus explicitly authorized deductions.');
            }
            $already = Money::signedTotal($item->payouts()->where('status', 'posted')->lockForUpdate()->get(), 'gross_amount');
            if ($already + $gross > Money::cents($item->effectiveAmount())) {
                $this->fail('Concurrent or earlier payments already consumed this student allocation.');
            }
            if ($payout->payment_mode === 'school') {
                $allocations = $batch->fundingAllocations()->where('status', 'posted')->with('receipt')->lockForUpdate()->get();
                $usable = $allocations->filter(fn ($a) => $a->receipt->status === 'posted' && ! $a->receipt->reversals()->where('status', 'posted')->exists() && $a->receipt->received_on && $a->receipt->received_on->lte($payout->paid_on) && $this->evidence->approved($a->receipt, 'receipt_evidence'));
                $used = Money::signedTotal($batch->payouts()->where('status', 'posted')->where(fn ($q) => $q->where('payment_mode', 'school')->orWhereNull('payment_mode'))->lockForUpdate()->get(), 'gross_amount');
                if (Money::signedTotal($usable) < $used + $gross) {
                    $this->fail('Insufficient verified school receipts allocated to this batch on the payment date. Billing or a budget is not received cash.');
                }
            } elseif ($payout->payment_mode !== 'direct') {
                $this->fail('Select school-paid or verified direct-to-student payment.');
            }
            $before = $payout->only($payout->getFillable());
            $payout->update(['status' => 'posted', 'posting_key' => 'payment:'.$payout->id, 'posted_by' => $actor->id, 'posted_at' => now(), 'basis_snapshot' => $basis + ['effective_allocation' => $item->effectiveAmount(), 'batch_approval_id' => $batch->approval?->id]]);
            $this->audit->record($actor, $payout, 'student_payment_verified', $before);
        }, 3);
    }

    public function settle(Deduction $deduction, User $actor, array $data): void
    {
        Gate::forUser($actor)->authorize('financial-verify');
        $scopeId = $deduction->batch->program_period_term_id;
        DB::transaction(function () use ($deduction, $actor, $data, $scopeId) {
            ProgramPeriodTerm::whereKey($scopeId)->lockForUpdate()->firstOrFail();
            $deduction = Deduction::whereKey($deduction->id)->lockForUpdate()->firstOrFail();
            if ($deduction->settlement_status === 'settled') {
                return;
            }
            if ($deduction->payout->status !== 'posted' || $deduction->payout->payment_mode !== 'school' || $deduction->payout->recorded_by === $actor->id || ! $deduction->authorized_by || $data['settled_on'] < $deduction->payout->paid_on->toDateString()) {
                $this->fail('Verify settlement only after the authorized school payment, with a separate Coordinator.');
            }
            if (! $this->evidence->approved($deduction, 'deduction_settlement')) {
                $this->fail('Accepted proof of actual full deduction settlement is required; withholding alone is not settlement.');
            }
            $before = $deduction->only($deduction->getFillable());
            $deduction->update(['settlement_status' => 'settled', 'settled_on' => $data['settled_on'], 'settlement_reference' => $data['settlement_reference'], 'settled_by' => $actor->id]);
            $this->audit->record($actor, $deduction, 'deduction_settlement_verified', $before);
        }, 3);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['finance' => $message]);
    }
}
