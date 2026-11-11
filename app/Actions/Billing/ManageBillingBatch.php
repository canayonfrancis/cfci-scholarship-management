<?php

namespace App\Actions\Billing;

use App\Actions\Records\RecordAudit;
use App\Models\AcademicPeriod;
use App\Models\AcademicValidationContext;
use App\Models\BillingBatch;
use App\Models\BillingItem;
use App\Models\ProgramPeriodTerm;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\BillingEligibility;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageBillingBatch
{
    public function __construct(private BillingEligibility $eligibility, private RecordAudit $audit) {}

    public function configure(User $actor, array $data): ProgramPeriodTerm
    {
        Gate::forUser($actor)->authorize('configure', BillingBatch::class);

        return DB::transaction(function () use ($actor, $data) {
            ScholarshipProgram::whereKey($data['program_id'])->lockForUpdate()->firstOrFail();
            $terms = ProgramPeriodTerm::firstOrCreate(['program_id' => $data['program_id'], 'academic_period_id' => $data['academic_period_id']]);
            $terms = ProgramPeriodTerm::whereKey($terms->id)->lockForUpdate()->firstOrFail();
            $latest = $terms->rates()->orderByDesc('revision')->first();
            if ((int) ($data['previous_rate'] ?? 0) !== (int) ($latest?->id ?? 0)) {
                $this->fail('The semester rate changed. Reload its current revision before confirming a new rate.');
            }
            // Preserve the original financial and academic confirmations for legacy history.
            if (! $terms->billing_confirmed_at) {
                $before = $terms->only($terms->getFillable());
                $terms->update([
                    'semester_gross_amount' => $data['semester_gross_amount'], 'billing_policy' => BillingEligibility::POLICY,
                    'billing_source_reference' => trim($data['billing_source_reference']),
                    'billing_confirmed_by' => $actor->id, 'billing_confirmed_at' => now(),
                ]);
                $this->audit->record($actor, $terms, 'semester_billing_terms_confirmed', $before);
            }
            $rate = $terms->rates()->create([
                'revision' => ($latest?->revision ?? 0) + 1, 'amount' => $data['semester_gross_amount'],
                'source_reference' => trim($data['billing_source_reference']), 'approved_by' => $actor->id, 'approved_at' => now(),
            ]);
            $this->audit->record($actor, $rate, 'scholarship_rate_approved');

            return $terms;
        }, 3);
    }

    public function create(User $actor, array $data): BillingBatch
    {
        Gate::forUser($actor)->authorize('create', BillingBatch::class);

        return DB::transaction(function () use ($actor, $data) {
            $program = ScholarshipProgram::whereKey($data['program_id'])->lockForUpdate()->firstOrFail();
            $period = AcademicPeriod::whereKey($data['academic_period_id'])->lockForUpdate()->firstOrFail();
            $terms = ProgramPeriodTerm::where('program_id', $program->id)->where('academic_period_id', $period->id)->lockForUpdate()->first();
            $rate = $terms?->rates()->orderByDesc('revision')->first();
            if (! $program->is_active || ! $rate) {
                $this->fail('Select an available program and explicitly approved semester billing terms.');
            }
            $numbers = $terms->billingBatches()->pluck('batch_number')->map(fn ($number) => preg_match('/^Batch (\d+)$/', $number, $matches) ? (int) $matches[1] : 0);
            $batch = $terms->billingBatches()->create([
                'program_id' => $program->id, 'academic_period_id' => $period->id, 'scholarship_rate_id' => $rate->id,
                'batch_number' => 'Batch '.(($numbers->max() ?? 0) + 1),
                'reference' => $data['reference'] ?? null, 'prepared_by' => $actor->id,
            ]);
            $this->audit->record($actor, $batch, 'billing_batch_created');

            return $batch;
        }, 3);
    }

    public function change(BillingBatch $batch, User $actor, int $version, string $operation, array $data = []): BillingBatch
    {
        try {
            return DB::transaction(function () use ($batch, $actor, $version, $operation, $data) {
                $ids = $batch->items()->orderBy('student_id')->lockForUpdate()->pluck('student_id')->all();
                if ($operation === 'add') {
                    $candidate = ScholarshipApplication::whereKey($data['application_id'])->lockForUpdate()->firstOrFail();
                    $ids[] = $candidate->student_id;
                }
                Student::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                AcademicValidationContext::whereKey(1)->lockForUpdate()->firstOrFail();
                ScholarshipProgram::whereKey($batch->program_id)->lockForUpdate()->firstOrFail();
                AcademicPeriod::whereKey($batch->academic_period_id)->lockForUpdate()->firstOrFail();
                $terms = ProgramPeriodTerm::whereKey($batch->program_period_term_id)->lockForUpdate()->firstOrFail();
                $batch = BillingBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize($operation === 'submit' ? 'submit' : 'update', $batch);
                if ($batch->lock_version !== $version) {
                    $this->fail('This batch changed since the form was opened. Reload before saving.');
                }
                $before = $batch->only($batch->getFillable());
                if ($operation === 'edit') {
                    $batch->fill(['batch_number' => trim($data['batch_number']), 'reference' => $data['reference'] ?? null]);
                } elseif ($operation === 'add') {
                    $application = ScholarshipApplication::whereKey($data['application_id'])->lockForUpdate()->firstOrFail();
                    $application->assignment()->lockForUpdate()->first();
                    if (BillingItem::where('student_id', $application->student_id)->where('academic_period_id', $application->academic_period_id)->exists()) {
                        $this->fail('This scholar already has a semester allocation or draft reservation, including under a previous scholarship program. Use an authorized adjustment for approved records.');
                    }
                    $item = $batch->items()->create([
                        'application_id' => $application->id, 'assignment_id' => $application->assignment_id,
                        'student_id' => $application->student_id, 'program_id' => $batch->program_id, 'academic_period_id' => $batch->academic_period_id,
                        ...$this->eligibility->basis($application, $terms, $batch->appliedRate()),
                    ]);
                    $this->audit->record($actor, $item, 'billing_item_added');
                } elseif ($operation === 'remove') {
                    $item = $batch->items()->whereKey($data['item_id'])->lockForUpdate()->firstOrFail();
                    if ($item->status !== 'draft') {
                        $this->fail('Only draft billing items can be removed.');
                    }
                    $this->audit->record($actor, $item, 'draft_billing_item_removed', $item->only($item->getFillable()));
                    $item->delete();
                } elseif ($operation === 'submit') {
                    $reference = trim($data['reference']);
                    $items = $batch->items()->orderBy('student_id')->lockForUpdate()->get();
                    if ($items->isEmpty() || $reference === '') {
                        $this->fail('Submission requires at least one valid scholar and a submission reference.');
                    }
                    foreach ($items as $item) {
                        $application = $item->application()->lockForUpdate()->firstOrFail();
                        $application->assignment()->lockForUpdate()->first();
                        $basis = $this->eligibility->basis($application, $terms, $batch->appliedRate());
                        if ($item->status !== 'draft' || $item->gross_amount !== $basis['gross_amount'] || $item->entitlement_snapshot !== $basis['entitlement_snapshot'] ||
                            $item->evaluation_run_id !== $basis['evaluation_run_id'] || $item->external_approval_id !== $basis['external_approval_id']) {
                            $this->fail('A billing item changed or its validation/approval was replaced. Remove and add it again before submission.');
                        }
                        $item->update([...$basis, 'status' => 'finalized', 'finalized_by' => $actor->id, 'finalized_at' => now()]);
                        $this->audit->record($actor, $item, 'billing_item_submitted');
                    }
                    $batch->fill(['reference' => $reference, 'status' => 'submitted', 'submitted_by' => $actor->id, 'submitted_at' => now()]);
                } else {
                    throw new \LogicException('Unknown billing operation.');
                }
                $batch->lock_version++;
                $batch->save();
                $this->audit->record($actor, $batch, 'billing_batch_'.$operation, $before);

                return $batch;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $this->fail('The batch name or scholar semester bill already exists. Reload the batch before retrying.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['billing' => $message]);
    }
}
