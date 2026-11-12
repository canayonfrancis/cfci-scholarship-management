<?php

namespace Tests\Concerns;

use App\Models\AcademicValidationContext;
use App\Models\BillingBatch;
use App\Models\ScholarshipBatch;
use App\Models\User;
use App\Services\Billing\BillingRoster;
use App\Services\Eligibility\OfficialRuleCatalog;
use App\Services\Eligibility\PrivateEvidence;
use App\Services\Eligibility\SchoolGradingPolicy;
use Illuminate\Http\UploadedFile;

trait BuildsBillableAllocations
{
    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->create(['role' => $role]);

        return $user;
    }

    /** Synthetic resolved academic prerequisite, never a production school-policy default. */
    private function ready(string $code = 'TDP', string $amount = '7500.00', bool $withRoster = true): array
    {
        $g = $this->applicationContext($code);
        // Downstream financial tests assume a resolved form prerequisite, never a production classification.
        $this->partialMock(BillingRoster::class)->shouldReceive('submissionClassification')->andReturnNull();
        if ($withRoster) {
            $g['roster'] = ScholarshipBatch::firstOrCreate([
                'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'batch_number' => 'SYNTHETIC-BILLING-ROSTER',
            ], ['created_by' => $g['actor']->id]);
            $g['roster']->memberships()->create([
                'student_id' => $g['student']->id, 'assignment_id' => $g['assignment']->id, 'program_id' => $g['program']->id,
                'academic_period_id' => $g['period']->id, 'assigned_by' => $g['actor']->id, 'assigned_at' => now(),
            ]);
            $g['batch']->update(['scholarship_batch_id' => $g['roster']->id]);
        }
        $context = AcademicValidationContext::current();
        $context->update(['active_period_id' => $g['period']->id, 'source_period_id' => $g['period']->id, 'source_policy_reference' => 'Synthetic test source', 'confirmed_by' => $g['actor']->id, 'confirmed_at' => now()]);
        $policy = app(OfficialRuleCatalog::class)->forProgram($code);
        if (! $g['terms']->confirmed_at) {
            $g['terms']->update(['criteria' => $policy, 'rule_version' => $policy['version'] ?? null, 'source_reference' => $policy['source'] ?? null, 'confirmed_by' => $g['actor']->id, 'confirmed_at' => now()]);
        }
        $g['record']->grades()->create(['subject_name' => 'Synthetic subject', 'average' => '93']);
        $g['record']->update(['verification_status' => 'verified', 'verified_by' => $g['actor']->id, 'verified_at' => now()]);
        $g['record']->refresh();
        $g['evaluation']->update([
            'input_snapshot' => [
                'assessment_scope' => SchoolGradingPolicy::SCOPE, 'grading_policy_check' => ['status' => 'pass', 'reason' => 'Synthetic resolved prerequisite for testing downstream billing'],
                'academic_context' => ['revision' => $context->revision, 'source_period_id' => $g['period']->id],
                'source_records' => ['grade' => $g['record']->only(['id', 'revision', 'lock_version', 'verification_status', 'verified_by']), 'enrollment' => $g['record']->only(['id', 'revision', 'lock_version', 'verification_status', 'verified_by'])],
            ],
            'rule_snapshot' => ['confirmed' => true, 'policy' => $policy], 'automatic_outcome' => 'passed', 'school_decision' => 'qualified',
            'evaluated_by' => $g['actor']->id, 'evaluated_at' => now(), 'coordinator_id' => $g['actor']->id, 'completed_at' => now(), 'decided_at' => now(),
        ]);
        $g['approval']->update(['assignment_id' => $g['assignment']->id, 'decision' => 'approved', 'authority' => $code === 'EDUKAR' ? 'EDUKAR/AKLAT' : 'CHED/UniFAST', 'reference' => 'SYNTHETIC-EXTERNAL', 'decided_on' => now('Asia/Manila')->toDateString(), 'recorded_by' => $g['actor']->id]);
        app(PrivateEvidence::class)->store($g['approval'], $g['actor'], UploadedFile::fake()->createWithContent('test.pdf', "%PDF-1.4\nSynthetic evidence\n%%EOF"), 'external_approval', true);
        if (! $g['terms']->billing_confirmed_at) {
            $this->actingAs($this->staff('administrator'))->post(route('billing-batches.terms'), [
                'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id,
                'semester_gross_amount' => $amount, 'billing_source_reference' => 'Synthetic approved school billing policy and amount', 'confirm_billing_policy' => 1,
            ])->assertSessionHasNoErrors();
        }
        $g['terms']->refresh();
        $g['batch']->update(['prepared_by' => $g['actor']->id]);
        $this->actingAs($this->staff('finance'));

        return $g;
    }

    private function add(array $g, ?BillingBatch $batch = null)
    {
        $batch ??= $g['batch'];

        return $this->post(route('billing-batches.items.store', $batch), ['application_id' => $g['application']->id, 'lock_version' => $batch->fresh()->lock_version]);
    }

    private function submit(BillingBatch $batch)
    {
        return $this->post(route('billing-batches.submit', $batch), ['reference' => 'SYNTHETIC-SUBMISSION', 'lock_version' => $batch->fresh()->lock_version]);
    }
}
