<?php

namespace Database\Seeders;

use App\Actions\Billing\ManageBillingBatch;
use App\Actions\Billing\ManageScholarshipFunds;
use App\Actions\Eligibility\ActivateAward;
use App\Actions\Eligibility\EvaluateApplication;
use App\Actions\Eligibility\RecordExternalApproval;
use App\Actions\Records\ManageScholarshipRoster;
use App\Actions\Records\RecordAudit;
use App\Actions\Records\SaveAcademicVerification;
use App\Models\AcademicPeriod;
use App\Models\AcademicValidationContext;
use App\Models\BillingBatch;
use App\Models\FundingReceipt;
use App\Models\ProgramPeriodTerm;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipBudget;
use App\Models\ScholarshipRate;
use App\Models\Student;
use App\Services\Billing\BillingRoster;
use App\Services\Billing\Money;
use App\Services\Eligibility\OfficialRuleCatalog;
use App\Services\Eligibility\PrivateEvidence;
use App\Services\Eligibility\SchoolGradingPolicy;
use Database\Seeders\Support\SampleData;
use Database\Seeders\Support\SyntheticAcademicPolicy;
use Database\Seeders\Support\SyntheticBillingRoster;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/** A complete financial fixture, restricted to a separate, explicitly owned demo database. */
class SyntheticDemoSeeder extends Seeder
{
    public function run(): void
    {
        SampleData::guardSynthetic();
        if (SampleData::completed('synthetic-demo')) {
            return;
        }
        // Commit the production-policy baseline separately, so a demo failure is safely retryable.
        $this->callSilent(DevelopmentSampleSeeder::class);
        $originalPolicy = app(SchoolGradingPolicy::class);
        $originalRoster = app(BillingRoster::class);
        $files = [];
        try {
            app()->instance(SchoolGradingPolicy::class, new SyntheticAcademicPolicy);
            app()->instance(BillingRoster::class, new SyntheticBillingRoster);
            DB::transaction(function () use (&$files) {
                if (BillingBatch::exists() || ScholarshipRate::exists() || ScholarshipBudget::exists()) {
                    throw new RuntimeException('The incomplete demo contains existing financial configuration. Use a new demo database; no existing financial records were overwritten.');
                }
                $year = SampleData::academicYear();
                $period = AcademicPeriod::where('academic_year', $year)->where('semester_code', '1')->firstOrFail();
                $admin = SampleData::staff('coordinator');
                $registrar = SampleData::staff('registrar');
                $coordinator = $admin;
                $finance = $registrar;
                $programs = SampleData::programs();
                $context = AcademicValidationContext::current();
                if ($context->active_period_id !== null && ! str_starts_with($context->source_policy_reference ?? '', 'SAMPLE:')) {
                    throw new RuntimeException('A manually configured validation context is present. It was not changed.');
                }
                $before = $context->only($context->getFillable());
                $context->update([
                    'active_period_id' => $period->id, 'source_period_id' => $period->id,
                    'source_policy_reference' => 'SAMPLE: synthetic same-semester source mapping in the isolated demo only; not the approved CFCI grading policy.',
                    'confirmed_by' => $admin->id, 'confirmed_at' => now(), 'revision' => $context->revision + 1,
                ]);
                $period->update(['is_open' => true]);
                app(RecordAudit::class)->record($admin, $context, 'sample_demo_context_configured', $before);
                $catalog = app(OfficialRuleCatalog::class);
                foreach ($programs as $code => $program) {
                    if ($program->school_stated_semester_amount === null || Money::cents($program->school_stated_semester_amount) <= 0) {
                        throw new RuntimeException('A positive configurable reference amount is required for '.$code.'.');
                    }
                    foreach (['1', '2'] as $semester) {
                        $scopePeriod = AcademicPeriod::where('academic_year', $year)->where('semester_code', $semester)->firstOrFail();
                        $terms = ProgramPeriodTerm::firstOrCreate(['program_id' => $program->id, 'academic_period_id' => $scopePeriod->id]);
                        if ($semester === '1' && in_array($code, ['TES', 'TDP'], true) && ! $terms->confirmed_at) {
                            $policy = $catalog->forProgram($code);
                            if (! $catalog->appliesTo($scopePeriod)) {
                                throw new RuntimeException('The demo does not invent a national eligibility profile for an unsupported historical semester.');
                            }
                            $terms->update(['criteria' => $policy, 'rule_version' => $policy['version'], 'source_reference' => $policy['source'], 'confirmed_by' => $coordinator->id, 'confirmed_at' => now()]);
                            app(RecordAudit::class)->record($coordinator, $terms, 'sample_national_profile_confirmed');
                        }
                        foreach ([40, 60, 80] as $index => $capacity) {
                            app(ManageBillingBatch::class)->configure($admin, [
                                'program_id' => $program->id, 'academic_period_id' => $scopePeriod->id,
                                'previous_rate' => $terms->rates()->orderByDesc('revision')->value('id') ?? 0,
                                'semester_gross_amount' => $program->school_stated_semester_amount,
                                'billing_source_reference' => 'SAMPLE: fictional semester rate '.($index + 1).' / '.$year.' / '.$semester.'. Reconfirmation retains the configured amount; not historical school evidence.',
                            ]);
                            app(ManageScholarshipFunds::class)->budget($admin, [
                                'program_id' => $program->id, 'academic_period_id' => $scopePeriod->id,
                                'previous_budget' => $terms->budgets()->orderByDesc('revision')->value('id') ?? 0,
                                'amount' => Money::decimal(Money::cents($program->school_stated_semester_amount) * $capacity),
                                'source_reference' => 'SAMPLE: fictional planning budget revision '.($index + 1).'; no cash receipt.',
                            ]);
                            FundingReceipt::create([
                                'program_period_term_id' => $terms->id, 'program_id' => $program->id, 'academic_period_id' => $scopePeriod->id,
                                'amount' => Money::decimal(Money::cents($program->school_stated_semester_amount) * ($index + 1)),
                                'source' => 'SAMPLE: unposted funding placeholder, not money received.',
                                'reference' => 'SAMPLE-FUND-'.$program->code.'-'.$scopePeriod->id.'-'.($index + 1), 'status' => 'draft',
                            ]);
                        }
                    }
                }
                $eligible = [];
                for ($i = 0; $i < 60; $i++) {
                    $number = sprintf('SAMPLE-C-%s-%03d', substr($year, 0, 4), $i + 1);
                    $student = Student::where('student_number', $number)->firstOrFail();
                    if (! SampleData::owned($student)) {
                        throw new RuntimeException('A sample student no longer has the required fixture provenance.');
                    }
                    $assessment = ScholarshipApplication::where('student_id', $student->id)->where('academic_period_id', $period->id)->firstOrFail();
                    $old = $student->academicRecords()->where('academic_period_id', $period->id)->orderByDesc('revision')->firstOrFail();
                    if ($old->revision !== 1 || $old->lock_version !== 1 || ! str_starts_with($old->source_reference ?? '', 'SAMPLE:')) {
                        throw new RuntimeException('A sample academic record was manually changed ('.$number.', revision '.$old->revision.', version '.$old->lock_version.'). Use a new demo database rather than overwriting it.');
                    }
                    $previousDocument = $old->documents()->max('id') ?? 0;
                    $grades = $old->grades()->orderBy('id')->get()->map(fn ($grade) => $grade->only($grade->getFillable()))->all();
                    $record = app(SaveAcademicVerification::class)->handle($registrar, [
                        ...$old->only($old->getFillable()), 'action' => $i < 42 ? 'verify' : 'save',
                        'academic_standing' => $i < 42 ? 'passing' : 'unverified', 'grades' => $grades,
                        'correction_reason' => 'SAMPLE: isolated synthetic workflow scenario, not a real grade correction.',
                        'source_reference' => 'SAMPLE: synthetic academic prerequisite for a fictional financial test scenario.',
                    ], [SampleData::pdf('demo-revision-'.$number)], $old, $old->verification_status !== 'pending');
                    $this->track($record, $files, $previousDocument);
                    $data = SampleData::facts() + [
                        'submission_revision' => $assessment->fresh()->submission_revision,
                        'previous_run' => $assessment->evaluationRuns()->max('run_number') ?? 0,
                    ];
                    if ($i >= 36 && $i < 42) {
                        $data['facts']['filipino'] = 'no';
                    }
                    if ($assessment->program->code !== 'COSCHO') {
                        foreach ($catalog->requiredDocuments($assessment->program->code, $data) as $type) {
                            $document = app(PrivateEvidence::class)->store($assessment, $coordinator, SampleData::pdf($number.'-'.$type), $type, $i < 42);
                            $files[] = [$document->disk, $document->storage_path];
                        }
                    }
                    $run = app(EvaluateApplication::class)->handle($assessment, $coordinator, $data);
                    if ($i < 42) {
                        $approval = app(RecordExternalApproval::class)->handle($assessment, $coordinator, [
                            'previous_approval' => $assessment->externalApprovals()->max('id') ?? 0,
                            'decision' => 'approved', 'authority' => $assessment->program->expectedApprovalAuthority(),
                            'reference' => 'SAMPLE-APPROVAL-'.$number, 'decided_on' => now('Asia/Manila')->toDateString(),
                            'verification_notes' => 'SAMPLE: fictional semester approval evidence. Does not assert a real provider decision.',
                        ], SampleData::pdf('external-'.$number));
                        $this->track($approval, $files);
                    }
                    if ($i < 36) {
                        app(ActivateAward::class)->handle($assessment, $coordinator, $run->id, $approval->id);
                        $eligible[] = $assessment;
                    }
                }
                $approvedItems = [];
                foreach ($eligible as $index => $assessment) {
                    $roster = app(ManageScholarshipRoster::class)->create($coordinator, [
                        'program_id' => $assessment->program_id, 'academic_period_id' => $period->id,
                        'batch_number' => (string) ($index + 1),
                    ]);
                    app(ManageScholarshipRoster::class)->join($assessment->student, $roster, $coordinator, $assessment->assignment_id);
                    $batch = app(ManageBillingBatch::class)->create($finance, [
                        'program_id' => $assessment->program_id, 'academic_period_id' => $period->id,
                        'scholarship_batch_id' => $roster->id,
                        'reference' => 'SAMPLE: fictional finance batch '.$year.' / '.($index + 1),
                    ]);
                    $batch = app(ManageBillingBatch::class)->change($batch, $finance, 0, 'add', ['application_id' => $assessment->id]);
                    if ($index < 27) {
                        $batch = app(ManageBillingBatch::class)->change($batch, $finance, $batch->lock_version, 'submit', ['reference' => 'SAMPLE-SUBMISSION-'.($index + 1)]);
                    }
                    if ($index < 18) {
                        app(ManageScholarshipFunds::class)->approve($batch, $admin, ['reference' => 'SAMPLE-ADMIN-APPROVAL-'.($index + 1)]);
                        $approvedItems[] = $batch->items()->firstOrFail();
                    }
                }
                foreach (array_slice($approvedItems, 0, 8) as $index => $item) {
                    $original = $item->gross_amount;
                    $common = ['reason' => 'SAMPLE: paired correction and restoration to exercise immutable financial history.', 'reference' => 'SAMPLE-ADJUSTMENT-'.($index + 1)];
                    app(ManageScholarshipFunds::class)->adjust($item, $admin, $common + ['previous_adjustment' => 0, 'new_amount' => Money::decimal(Money::cents($original) - 10000)]);
                    app(ManageScholarshipFunds::class)->adjust($item, $admin, $common + ['previous_adjustment' => 1, 'new_amount' => $original]);
                }
                SampleData::complete('synthetic-demo');
            });
        } catch (Throwable $error) {
            foreach ($files as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
            throw $error;
        } finally {
            app()->instance(SchoolGradingPolicy::class, $originalPolicy);
            app()->instance(BillingRoster::class, $originalRoster);
        }
    }

    private function track($owner, array &$files, int $afterId = 0): void
    {
        foreach ($owner->documents()->where('id', '>', $afterId)->get() as $document) {
            $files[] = [$document->disk, $document->storage_path];
        }
    }
}
