<?php

namespace App\Actions\Billing;

use App\Actions\Records\RecordAudit;
use App\Models\AcademicValidationContext;
use App\Models\BillingBatch;
use App\Models\BillingItem;
use App\Models\ProgramPeriodTerm;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\AllocationSummary;
use App\Services\Billing\BillingEligibility;
use App\Services\Billing\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageScholarshipFunds
{
    public function __construct(private BillingEligibility $eligibility, private AllocationSummary $summary, private RecordAudit $audit) {}

    public function budget(User $actor, array $data): void
    {
        Gate::forUser($actor)->authorize('configure', BillingBatch::class);
        DB::transaction(function () use ($actor, $data) {
            ScholarshipProgram::whereKey($data['program_id'])->lockForUpdate()->firstOrFail();
            $terms = ProgramPeriodTerm::firstOrCreate(['program_id' => $data['program_id'], 'academic_period_id' => $data['academic_period_id']]);
            $terms = ProgramPeriodTerm::whereKey($terms->id)->lockForUpdate()->firstOrFail();
            $previous = $terms->budgets()->orderByDesc('revision')->first();
            if ((int) ($previous?->id ?? 0) !== (int) $data['previous_budget']) {
                $this->fail('The budget changed. Reload before saving a new revision.');
            }
            if (Money::cents($data['amount']) < Money::cents($this->summary->forTerms($terms)['allocated'])) {
                $this->fail('The scholarship budget cannot be reduced below approved allocations.');
            }
            $budget = $terms->budgets()->create([
                'revision' => ($previous?->revision ?? 0) + 1, 'amount' => $data['amount'],
                'source_reference' => trim($data['source_reference']), 'approved_by' => $actor->id, 'approved_at' => now(),
            ]);
            $this->audit->record($actor, $budget, 'scholarship_budget_approved');
        }, 3);
    }

    public function approve(BillingBatch $batch, User $actor, array $data): void
    {
        DB::transaction(function () use ($batch, $actor, $data) {
            $studentIds = $batch->items()->orderBy('student_id')->lockForUpdate()->pluck('student_id');
            Student::whereIn('id', $studentIds)->orderBy('id')->lockForUpdate()->get();
            AcademicValidationContext::whereKey(1)->lockForUpdate()->firstOrFail();
            ScholarshipProgram::whereKey($batch->program_id)->lockForUpdate()->firstOrFail();
            $terms = ProgramPeriodTerm::whereKey($batch->program_period_term_id)->lockForUpdate()->firstOrFail();
            $batch = BillingBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('approve', $batch);
            $items = $batch->items()->lockForUpdate()->get();
            if ($items->isEmpty()) {
                $this->fail('An empty allocation batch cannot be approved.');
            }
            foreach ($items as $item) {
                $application = $item->application()->lockForUpdate()->firstOrFail();
                $application->assignment()->lockForUpdate()->first();
                $rate = $batch->appliedRate();
                $basis = $this->eligibility->basis($application, $terms, $rate);
                if ($item->status !== 'finalized' || $item->gross_amount !== $basis['gross_amount'] || $item->entitlement_snapshot !== $basis['entitlement_snapshot'] ||
                    $item->evaluation_run_id !== $basis['evaluation_run_id'] || $item->external_approval_id !== $basis['external_approval_id']) {
                    $this->fail('A submitted scholar, validation, approval or amount is no longer valid. A changed submission cannot be silently approved.');
                }
            }
            $summary = $this->summary->forTerms($terms);
            $total = $batch->totalAmount();
            if (! $summary['budget'] || $total === null || Money::cents($total) <= 0 || Money::cents($summary['remaining']) < Money::cents($total)) {
                $this->fail('Configure sufficient approved scholarship budget before approving this allocation.');
            }
            $approval = $batch->approval()->create([
                'approved_by' => $actor->id, 'approved_at' => now(), 'reference' => trim($data['reference']),
                'amount_snapshot' => $total, 'scholarship_budget_id' => $summary['budget']->id,
            ]);
            $this->audit->record($actor, $approval, 'scholarship_allocation_approved');
        }, 3);
    }

    public function adjust(BillingItem $item, User $actor, array $data): void
    {
        DB::transaction(function () use ($item, $actor, $data) {
            $item = BillingItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            Student::whereKey($item->student_id)->lockForUpdate()->firstOrFail();
            AcademicValidationContext::whereKey(1)->lockForUpdate()->firstOrFail();
            ScholarshipProgram::whereKey($item->program_id)->lockForUpdate()->firstOrFail();
            $terms = ProgramPeriodTerm::whereKey($item->batch->program_period_term_id)->lockForUpdate()->firstOrFail();
            $batch = $item->batch()->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('adjust', $batch);
            $last = $item->adjustments()->orderByDesc('sequence')->first();
            if ((int) ($last?->sequence ?? 0) !== (int) $data['previous_adjustment']) {
                $this->fail('This allocation has a newer adjustment. Reload its history.');
            }
            $before = Money::cents($item->effectiveAmount());
            $after = Money::cents($data['new_amount']);
            if ($before === $after) {
                $this->fail('The adjustment must change the effective allocation amount.');
            }
            $released = Money::signedTotal($item->payouts()->where('status', 'posted')->get(), 'gross_amount');
            if ($after < $released) {
                $this->fail('An allocation cannot be reduced below funds already released. Reconcile the release through its authorized correction workflow first.');
            }
            $basis = $item->basis_snapshot;
            $rate = null;
            if ($after > $before) {
                $rate = $terms->rates()->orderByDesc('revision')->first();
                $application = $item->application()->lockForUpdate()->firstOrFail();
                $basis = $this->eligibility->basis($application, $terms, $rate);
                if ($after > Money::cents($rate->amount)) {
                    $this->fail('An increased allocation must stay within the approved semester rate.');
                }
                $summary = $this->summary->forTerms($terms);
                if (! $summary['budget'] || Money::cents($summary['remaining']) < $after - $before) {
                    $this->fail('The increased allocation exceeds the remaining scholarship budget.');
                }
            }
            $adjustment = $item->adjustments()->create([
                'sequence' => ($last?->sequence ?? 0) + 1, 'direction' => $after > $before ? 'increase' : 'decrease',
                'amount' => Money::decimal(abs($after - $before)), 'amount_before' => Money::decimal($before), 'amount_after' => Money::decimal($after),
                'reason' => trim($data['reason']), 'reference' => trim($data['reference']),
                'authorized_by' => $actor->id, 'authorized_at' => now(), 'scholarship_rate_id' => $rate?->id, 'basis_snapshot' => $basis,
            ]);
            $this->audit->record($actor, $adjustment, 'scholarship_allocation_adjusted');
        }, 3);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['billing' => $message]);
    }
}
