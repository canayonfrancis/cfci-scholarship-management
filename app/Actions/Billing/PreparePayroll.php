<?php

namespace App\Actions\Billing;

use App\Actions\Records\RecordAudit;
use App\Models\AcademicValidationContext;
use App\Models\BillingAdjustment;
use App\Models\BillingBatch;
use App\Models\FundingAllocation;
use App\Models\FundingReceipt;
use App\Models\Payroll;
use App\Models\ProgramPeriodTerm;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\BillingEligibility;
use App\Services\Billing\Money;
use App\Services\Eligibility\PrivateEvidence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PreparePayroll
{
    public function __construct(private RecordAudit $audit, private BillingEligibility $eligibility, private PrivateEvidence $evidence) {}

    public function handle(BillingBatch $batch, User $actor, string $reference): Payroll
    {
        Gate::forUser($actor)->authorize('financial-prepare');
        $reference = trim($reference);
        if ($reference === '' || mb_strlen($reference) > 255) {
            $this->fail('Enter a payroll reference of up to 255 characters.');
        }
        $scopeId = $batch->program_period_term_id;
        try {
            return DB::transaction(function () use ($batch, $actor, $reference, $scopeId) {
                // Acquire the same financial scope lock as receipts, payouts and allocations
                // before any consistent read can establish a repeatable-read snapshot.
                $terms = ProgramPeriodTerm::whereKey($scopeId)->lockForUpdate()->firstOrFail();
                $batch = BillingBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
                $approval = $batch->approval()->lockForUpdate()->first();
                $approved = ($batch->status === 'submitted' && $batch->submitted_at && $approval)
                    || ($batch->status === 'finalized' && $batch->finalized_at && $batch->finalized_by);
                if (! $approved) {
                    $this->fail('Payroll preparation requires a submitted, independently approved billing batch.');
                }
                $existing = Payroll::where('billing_batch_id', $batch->id)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->status === 'prepared' && mb_strtolower($existing->reference) === mb_strtolower($reference)) {
                        return $existing;
                    }
                    $this->fail('This batch already has a retained payroll register. Open it instead of creating another plan.');
                }
                $items = $batch->items()->orderBy('student_id')->lockForUpdate()->get();
                if ($items->isEmpty() || $items->contains(fn ($i) => $i->status !== 'finalized' || $i->gross_amount === null)) {
                    $this->fail('All payroll sources must be finalized billing items with known amounts.');
                }
                $students = Student::whereIn('id', $items->pluck('student_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                AcademicValidationContext::whereKey(1)->lockForUpdate()->firstOrFail();
                ScholarshipProgram::whereKey($batch->program_id)->lockForUpdate()->firstOrFail();
                $adjustments = BillingAdjustment::whereIn('billing_item_id', $items->modelKeys())->lockForUpdate()->get()->groupBy('billing_item_id');
                $payments = $batch->payouts()->where('status', 'posted')->lockForUpdate()->get();
                if ($payments->contains(fn ($p) => $p->gross_amount === null || ! in_array($p->payment_mode, ['school', 'direct'], true))) {
                    $this->fail('Review incomplete historical payment amounts or payment paths before preparing payroll.');
                }
                $allocations = $batch->fundingAllocations()->where('status', 'posted')->lockForUpdate()->get();
                $receipts = FundingReceipt::whereIn('id', $allocations->pluck('funding_receipt_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $receiptAllocations = FundingAllocation::whereIn('funding_receipt_id', $receipts->modelKeys())->where('status', 'posted')->lockForUpdate()->get()->groupBy('funding_receipt_id');
                $receiptReversals = FundingReceipt::whereIn('reversal_of_id', $receipts->modelKeys())->where('status', 'posted')->lockForUpdate()->get();
                $fundingSources = [];
                foreach ($receipts as $receipt) {
                    $proof = $receipt->documents()->where('document_type', 'receipt_evidence')->get()->filter(fn ($d) => $this->evidence->usable($d));
                    $allocated = Money::signedTotal($receiptAllocations->get($receipt->id, collect()));
                    if ($receipt->status !== 'posted' || $receipt->entry_type !== 'original' || ! $receipt->received_on ||
                        $receipt->received_on->toDateString() > now('Asia/Manila')->toDateString() ||
                        $receipt->program_period_term_id !== $terms->id || $receipt->amount === null || $allocated < 0 || $allocated > Money::cents($receipt->amount) ||
                        $receiptReversals->contains('reversal_of_id', $receipt->id) || $proof->isEmpty()) {
                        $this->fail('Each allocated school receipt must be dated, verified, unreversed, within its cash capacity and supported by intact accepted proof.');
                    }
                    $fundingSources[] = ['receipt_id' => $receipt->id, 'received_on' => $receipt->received_on->toDateString(), 'reference' => $receipt->reference, 'documents' => $proof->map(fn ($d) => $this->evidence->snapshot($d))->values()->all()];
                }
                $effective = 0;
                $lines = [];
                foreach ($items as $item) {
                    $amount = Money::cents($item->gross_amount) + Money::signedTotal($adjustments->get($item->id, collect()));
                    $posted = Money::signedTotal($payments->where('billing_item_id', $item->id), 'gross_amount');
                    if ($amount < 0 || $posted < 0 || $posted > $amount) {
                        $this->fail('Billing adjustments and recorded payments require reconciliation before payroll preparation.');
                    }
                    $effective += $amount;
                    if ($amount === $posted) {
                        continue;
                    }
                    $student = $students->get($item->student_id);
                    if (! filled($student->student_number) || ! filled($student->full_name)) {
                        $this->fail('Each planned beneficiary requires an existing institutional Student ID and name.');
                    }
                    $lines[] = [
                        'billing_batch_id' => $batch->id, 'billing_item_id' => $item->id,
                        'institutional_student_id' => $student->student_number, 'beneficiary_name' => $student->full_name,
                        'effective_amount_snapshot' => Money::decimal($amount), 'posted_gross_snapshot' => Money::decimal($posted), 'planned_gross_amount' => Money::decimal($amount - $posted),
                        'basis_snapshot' => $this->eligibility->basis($item->application, $terms, $batch->appliedRate()),
                    ];
                }
                if ($lines === []) {
                    $this->fail('No unpaid benefit remains to prepare for a school-held payroll. Direct payments remain recorded separately.');
                }
                $funded = Money::signedTotal($allocations);
                $direct = Money::signedTotal($payments->where('payment_mode', 'direct'), 'gross_amount');
                // Existing direct payments cover their own benefit; they never become school cash.
                if ($funded < 0 || $funded + $direct !== $effective) {
                    $this->fail('Wait until the entire approved billing batch is funded. Budgets, expected funds and partial school allocations are insufficient; overallocated cash needs reconciliation.');
                }
                $payroll = Payroll::create([
                    'billing_batch_id' => $batch->id, 'reference' => $reference, 'prepared_by' => $actor->id,
                    'funding_snapshot' => [
                        'format' => 'internal-school-register-v1', 'payment_path' => 'school', 'funding_rule' => 'full_batch_coverage',
                        'effective_batch_gross' => Money::decimal($effective), 'allocated_school_cash' => Money::decimal($funded), 'previous_direct_gross' => Money::decimal($direct),
                        'previous_school_gross' => Money::decimal(Money::signedTotal($payments->where('payment_mode', 'school'), 'gross_amount')),
                        'sources' => $fundingSources, 'allocations' => $allocations->map(fn ($a) => $a->only(['id', 'funding_receipt_id', 'amount', 'entry_type', 'reversal_of_id']))->all(),
                    ],
                ]);
                $payroll->items()->createMany($lines);
                $payroll->update(['status' => 'prepared', 'prepared_at' => now()]);
                $this->audit->record($actor, $payroll, 'school_payroll_prepared');

                return $payroll;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $this->fail('A payroll for this batch was prepared concurrently. Reload its retained register.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['payroll' => $message]);
    }
}
