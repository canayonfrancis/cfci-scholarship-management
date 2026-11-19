<?php

namespace App\Actions\Billing;

use App\Actions\Records\RecordAudit;
use App\Models\AcademicPeriod;
use App\Models\AcademicValidationContext;
use App\Models\BillingBatch;
use App\Models\BillingItem;
use App\Models\ProgramPeriodTerm;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipBatch;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Models\User;
use App\Rules\PositiveBatchNumber;
use App\Services\Billing\BillingEligibility;
use App\Services\Billing\BillingRoster;
use App\Services\Billing\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageBillingBatch
{
    public function __construct(private BillingEligibility $eligibility, private RecordAudit $audit, private BillingRoster $roster) {}

    public function configure(User $actor, array $data): ProgramPeriodTerm
    {
        Gate::forUser($actor)->authorize('configure', BillingBatch::class);

        return DB::transaction(function () use ($actor, $data) {
            $fromReference = ! empty($data['use_program_reference']);
            if ($fromReference) {
                $selected = ScholarshipBatch::findOrFail($data['scholarship_batch_id']);
                $data['program_id'] = $selected->program_id;
                $data['academic_period_id'] = $selected->academic_period_id;
            }
            $program = ScholarshipProgram::whereKey($data['program_id'])->lockForUpdate()->firstOrFail();
            if ($fromReference) {
                $period = AcademicPeriod::whereKey($data['academic_period_id'])->lockForUpdate()->firstOrFail();
                $this->roster->selection((int) $selected->id, $program->id, $period->id);
                if (! $program->is_active || ! $program->usesFixedSemesterRate() || ! $period->is_open) {
                    $this->fail('Reference rate confirmation requires an available fixed-amount program and an open roster period.');
                }
                Validator::make($data, [
                    'expected_reference_amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/', 'numeric', 'gt:0', 'max:9999999999.99'],
                    'previous_rate' => ['required', 'integer', 'min:0'],
                    'billing_source_reference' => ['required', 'string', 'max:3000', 'regex:/\S/'],
                    'confirm_billing_policy' => ['accepted'],
                ])->validate();
                $amount = $program->school_stated_semester_amount;
                if ($amount === null || Money::cents($amount) <= 0) {
                    $this->fail('Configure a positive school-stated semester amount in the scholarship program before confirming its rate.');
                }
                if (Money::cents((string) $data['expected_reference_amount']) !== Money::cents($amount)) {
                    $this->fail('The program reference amount changed. Reload and review the current amount before confirming.');
                }
                // The browser's displayed value is a stale-preview check, never the amount authority.
                $data['semester_gross_amount'] = $amount;
            }
            $terms = ProgramPeriodTerm::firstOrCreate(['program_id' => $data['program_id'], 'academic_period_id' => $data['academic_period_id']]);
            $terms = ProgramPeriodTerm::whereKey($terms->id)->lockForUpdate()->firstOrFail();
            $latest = $terms->rates()->orderByDesc('revision')->first();
            if ($fromReference && $terms->rates()->whereNotNull('approved_by')->whereNotNull('approved_at')->where('amount', '>', 0)->exists()) {
                $this->fail('An approved semester rate already exists. Reload the roster and use that rate; no additional confirmation is required.');
            }
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

        try {
            return DB::transaction(function () use ($actor, $data) {
                Validator::make($data, ['scholarship_batch_id' => ['required', 'integer', 'min:1']])->validate();
                $selected = ScholarshipBatch::findOrFail($data['scholarship_batch_id']);
                // Identity comes from the existing immutable roster, never from display text.
                if ((isset($data['program_id']) && (int) $data['program_id'] !== $selected->program_id) ||
                    (isset($data['academic_period_id']) && (int) $data['academic_period_id'] !== $selected->academic_period_id)) {
                    $this->fail('The submitted program and period must match the selected roster.');
                }
                $program = ScholarshipProgram::whereKey($selected->program_id)->lockForUpdate()->firstOrFail();
                $period = AcademicPeriod::whereKey($selected->academic_period_id)->lockForUpdate()->firstOrFail();
                if (! $period->is_open) {
                    throw ValidationException::withMessages(['academic_period_id' => 'Select an open academic period to create a new billing batch. Closed periods remain available in history and reports.']);
                }
                $roster = $this->roster->selection((int) $data['scholarship_batch_id'], $program->id, $period->id);
                if (strtoupper(trim($program->code)) === 'EDUKAR') {
                    $this->fail('EDUKAR billing needs an approved variable entitlement workflow. A fixed-rate draft cannot be prepared for this program.');
                }
                $terms = ProgramPeriodTerm::where('program_id', $program->id)->where('academic_period_id', $period->id)->lockForUpdate()->first();
                $rate = $terms?->rates()->whereNotNull('approved_by')->whereNotNull('approved_at')->where('amount', '>', 0)->orderByDesc('revision')->lockForUpdate()->first();
                if (! $program->is_active || ! $rate || ! $rate->approved_by || ! $rate->approved_at || Money::cents($rate->amount) <= 0) {
                    $this->fail('Select an available program and explicitly approved semester billing terms.');
                }
                $numbers = $terms->billingBatches()->lockForUpdate()->pluck('batch_number')->map(fn ($label) => $this->numberFromLabel($label))->filter(fn ($number) => $number !== null);
                if (isset($data['batch_number'])) {
                    Validator::make($data, ['batch_number' => ['required', new PositiveBatchNumber]])->validate();
                    $number = (int) $data['batch_number'];
                    if ($numbers->contains($number)) {
                        throw ValidationException::withMessages(['batch_number' => 'This Batch Number already exists for the selected scholarship program, academic year and semester.']);
                    }
                } else {
                    if (($numbers->max() ?? 0) >= PositiveBatchNumber::MAX) {
                        throw ValidationException::withMessages(['batch_number' => 'Automatic numbering has reached its supported limit. Enter an unused positive Batch Number.']);
                    }
                    $number = ($numbers->max() ?? 0) + 1;
                }
                $batch = $terms->billingBatches()->create([
                    'program_id' => $program->id, 'academic_period_id' => $period->id, 'scholarship_rate_id' => $rate->id,
                    'scholarship_batch_id' => $roster->id,
                    'batch_number' => 'Batch '.$number,
                    'reference' => $data['reference'] ?? null, 'prepared_by' => $actor->id,
                ]);
                $this->audit->record($actor, $batch, 'billing_batch_created');

                return $batch;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['batch_number' => 'This Batch Number already exists for the selected scholarship program, academic year and semester. Reload before retrying.']);
        }
    }

    public function change(BillingBatch $batch, User $actor, int $version, string $operation, array $data = []): BillingBatch
    {
        Gate::forUser($actor)->authorize($operation === 'submit' ? 'submit' : 'update', $batch);
        if ($operation === 'add_many') {
            Validator::make($data, [
                'application_ids' => ['required', 'array', 'min:1', 'max:200'],
                'application_ids.*' => ['required', 'integer', 'distinct', 'exists:scholarship_applications,id'],
            ])->validate();
        }
        try {
            return DB::transaction(function () use ($batch, $actor, $version, $operation, $data) {
                $ids = $batch->items()->orderBy('student_id')->lockForUpdate()->pluck('student_id')->all();
                $applications = collect();
                if (in_array($operation, ['add', 'add_many'], true)) {
                    $applicationIds = $operation === 'add' ? [$data['application_id']] : $data['application_ids'];
                    $applications = ScholarshipApplication::whereIn('id', $applicationIds)->orderBy('student_id')->lockForUpdate()->get();
                    if ($applications->count() !== count($applicationIds)) {
                        $this->fail('A selected semester evaluation is missing. Reload and review the roster.');
                    }
                    $ids = array_merge($ids, $applications->pluck('student_id')->all());
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
                    $number = $this->numberFromLabel(trim($data['batch_number']));
                    if ($number !== null && $terms->billingBatches()->whereKeyNot($batch->id)->lockForUpdate()->pluck('batch_number')->contains(fn ($label) => $this->numberFromLabel($label) === $number)) {
                        throw ValidationException::withMessages(['batch_number' => 'This Batch Number already exists for the selected scholarship program, academic year and semester.']);
                    }
                    $batch->fill(['batch_number' => trim($data['batch_number']), 'reference' => $data['reference'] ?? null]);
                } elseif (in_array($operation, ['add', 'add_many'], true)) {
                    foreach ($applications as $application) {
                        $application->assignment()->lockForUpdate()->first();
                        $this->roster->member($batch, $application);
                        if (BillingItem::where('student_id', $application->student_id)->where('academic_period_id', $application->academic_period_id)->exists()) {
                            $this->fail('This scholar already has a semester allocation or draft reservation, including under a previous scholarship program. Use an authorized adjustment for approved records.');
                        }
                        $item = $batch->items()->create([
                            'application_id' => $application->id, 'assignment_id' => $application->assignment_id,
                            'student_id' => $application->student_id, 'program_id' => $batch->program_id, 'academic_period_id' => $batch->academic_period_id,
                            ...$this->eligibility->basis($application, $terms, $batch->appliedRate()),
                        ]);
                        $this->audit->record($actor, $item, 'billing_item_added');
                    }
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
                    $classification = $this->roster->demonstrationClassification($batch);
                    foreach ($items as $item) {
                        $application = $item->application()->lockForUpdate()->firstOrFail();
                        $application->assignment()->lockForUpdate()->first();
                        $this->roster->member($batch, $application);
                        if ($item->student_id !== $application->student_id || $item->assignment_id !== $application->assignment_id ||
                            $item->program_id !== $batch->program_id || $item->academic_period_id !== $batch->academic_period_id) {
                            $this->fail('The billing item no longer matches its roster assignment, student, program and academic period.');
                        }
                        $basis = $this->eligibility->basis($application, $terms, $batch->appliedRate());
                        if ($item->status !== 'draft' || $item->gross_amount !== $basis['gross_amount'] || $item->entitlement_snapshot !== $basis['entitlement_snapshot'] ||
                            $item->evaluation_run_id !== $basis['evaluation_run_id'] || $item->external_approval_id !== $basis['external_approval_id']) {
                            $this->fail('A billing item changed or its validation/approval was replaced. Remove and add it again before submission.');
                        }
                        if ($classification !== null) {
                            $basis['basis_snapshot']['uat_classification'] = $classification;
                        }
                        $item->update([...$basis, 'status' => 'finalized', 'finalized_by' => $actor->id, 'finalized_at' => now()]);
                        $this->audit->record($actor, $item, 'billing_item_submitted');
                    }
                    $this->roster->submissionClassification($batch);
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

    private function numberFromLabel(string $label): ?int
    {
        return preg_match('/^Batch (\d+)$/i', trim($label), $matches) ? (int) $matches[1] : null;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['billing' => $message]);
    }
}
