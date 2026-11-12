<?php

namespace Tests\Feature\Eligibility;

use App\Actions\Records\RecordAudit;
use App\Models\AcademicPeriod;
use App\Models\AcademicRecord;
use App\Models\AcademicValidationContext;
use App\Models\AuditEvent;
use App\Models\EvaluationRun;
use App\Models\ExternalApproval;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipAssignment;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Models\User;
use App\Services\Eligibility\OfficialRuleCatalog;
use App\Services\Eligibility\SchoolGradingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsScholarshipRecords;
use Tests\TestCase;

class ScholarshipEvaluationTest extends TestCase
{
    use BuildsScholarshipRecords, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('scholarship');
        // These regression cases isolate national-rule/activation behavior under a
        // synthetic resolved school policy. Production policy remains unconfirmed.
        $this->mock(SchoolGradingPolicy::class)->shouldReceive('check')->andReturn(['status' => 'pass', 'reason' => 'Synthetic confirmed school-policy prerequisite for this test only']);
    }

    private function staff(string $role = 'coordinator'): User
    {
        $user = User::factory()->create();
        $user->roles()->create(['role' => $role]);

        return $user;
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('verified-evidence.pdf', "%PDF-1.4\nSynthetic fixture evidence only\n%%EOF");
    }

    private function context(string $code = 'TDP', bool $confirmed = true, bool $withEvidence = true): ScholarshipApplication
    {
        $actor = $this->staff();
        $this->actingAs($actor);
        $program = ScholarshipProgram::firstOrCreate(['code' => $code], ['name' => $code, 'planning_duration_years' => 4, 'is_active' => true]);
        $period = AcademicPeriod::firstOrCreate(['academic_year' => '2026-2027', 'semester_code' => '1']);
        AcademicValidationContext::current()->update(['active_period_id' => $period->id, 'source_period_id' => $period->id, 'source_policy_reference' => 'Synthetic fixture school source policy', 'confirmed_by' => $actor->id, 'confirmed_at' => now()]);
        $student = Student::create(['student_number' => (string) Str::uuid(), 'first_name' => 'Synthetic', 'last_name' => 'Scholar']);
        $assignment = ScholarshipAssignment::create(['student_id' => $student->id, 'program_id' => $program->id, 'start_period_id' => $period->id]);
        $this->post(route('evaluations.store'), ['program_id' => $program->id, 'academic_period_id' => $period->id, 'assignment_id' => $assignment->id])->assertSessionHasNoErrors();
        $application = ScholarshipApplication::where('student_id', $student->id)->firstOrFail();
        $record = AcademicRecord::create(['student_id' => $student->id, 'academic_period_id' => $period->id, 'enrollment_status' => 'enrolled', 'academic_standing' => 'passing']);
        $record->grades()->create(['subject_code' => 'TEST-101', 'subject_name' => 'Synthetic subject', 'average' => '93', 'equivalent' => '1.1']);
        $record->update(['verification_status' => 'verified', 'verified_by' => $this->staff('registrar')->id, 'verified_at' => now()]);
        if ($confirmed) {
            $this->post(route('evaluations.policy', $application), ['confirm_policy' => 1])->assertSessionHasNoErrors();
        }
        if ($withEvidence) {
            foreach (app(OfficialRuleCatalog::class)->requiredDocuments($code, $this->data($application)) as $type) {
                $this->upload($application, $type);
            }
        }

        return $application->fresh();
    }

    private function upload(ScholarshipApplication $application, string $type, bool $verified = true): void
    {
        $this->post(route('evaluations.evidence', $application), ['submission_revision' => $application->fresh()->submission_revision, 'document_type' => $type, 'certificate' => $this->pdf(), 'verified' => $verified ? 1 : 0])->assertSessionHasNoErrors();
    }

    private function data(ScholarshipApplication $application, array $overrides = []): array
    {
        // These are synthetic assertions of checked facts, not invented CFCI eligibility policy.
        return array_replace([
            'submission_revision' => $application->fresh()->submission_revision,
            'previous_run' => $application->evaluationRuns()->max('run_number') ?? 0,
            'applicant_type' => 'new', 'facts' => array_fill_keys(array_keys(OfficialRuleCatalog::FACTS), 'yes'),
            'fact_sources' => array_fill_keys(array_keys(OfficialRuleCatalog::FACTS), 'Synthetic certified supporting bundle, section A'),
            'returning' => 'no', 'transferee' => 'no', 'legacy_pnsl_transfer' => 'no', 'claiming_pwd' => 'no', 'smart_same_semester' => 'no', 'special_case' => 'none',
            'duration_review' => 'within_limit', 'duration_basis' => 'Synthetic curriculum, award start, grace and LOA review', 'exceptions_reviewed' => 1,
            'evaluator_notes' => 'Synthetic coordinator review',
        ], $overrides);
    }

    private function evaluate(ScholarshipApplication $application, array $overrides = []): EvaluationRun
    {
        $this->post(route('evaluations.run', $application), $this->data($application, $overrides))->assertSessionHasNoErrors();

        return $application->evaluationRuns()->orderByDesc('run_number')->firstOrFail();
    }

    private function approval(ScholarshipApplication $application, array $overrides = []): ExternalApproval
    {
        $data = array_replace([
            'previous_approval' => $application->externalApprovals()->max('id') ?? 0,
            'authority' => $application->program->code === 'EDUKAR' ? 'EDUKAR/AKLAT' : 'CHED/UniFAST',
            'decision' => 'approved', 'reference' => 'SYNTHETIC-'.Str::uuid(), 'decided_on' => now('Asia/Manila')->toDateString(),
            'verified' => 1, 'verification_notes' => 'Checked synthetic external authority decision', 'certificate' => $this->pdf(),
        ], $overrides);
        $this->post(route('evaluations.external', $application), $data)->assertSessionHasNoErrors();

        return $application->externalApprovals()->orderByDesc('id')->firstOrFail();
    }

    private function activate(ScholarshipApplication $application, EvaluationRun $run, ExternalApproval $approval)
    {
        return $this->post(route('evaluations.activate', $application), ['evaluation_id' => $run->id, 'approval_id' => $approval->id]);
    }

    public static function programs(): array
    {
        return [['TDP'], ['TES']];
    }

    #[DataProvider('programs')]
    public function test_eligible_school_result_is_immutable_and_is_not_external_approval(string $program): void
    {
        $application = $this->context($program);
        $run = $this->evaluate($application);
        $this->assertSame('eligible', $run->resultLabel());
        $this->assertSame('qualified', $run->school_decision);
        $this->assertSame(auth()->id(), $run->evaluated_by);
        $this->assertNotNull($run->evaluated_at);
        $this->assertTrue($run->isCurrent()); // Server-pinned verified state permits same-second evaluation.
        $this->assertSame($run->id, $application->currentSchoolDecision()?->id);
        $this->assertSame('pending', $application->assignment->status);
        $this->assertDatabaseCount('external_approvals', 0);
        $this->assertDatabaseCount('billing_items', 0);
        $this->assertNotEmpty($run->rule_snapshot['policy']['sha256']);
        $this->assertNotEmpty($run->input_snapshot['evidence']);
        $this->assertDatabaseRejects(fn () => DB::table('evaluation_runs')->where('id', $run->id)->update(['automatic_outcome' => 'failed']));
        $this->assertDatabaseRejects(fn () => DB::table('evaluation_runs')->where('id', $run->id)->delete());
        $this->get(route('evaluations.show', $application))->assertOk()->assertSee('School evaluation history')->assertSee('Eligible')->assertSee('Recorded inputs and rule snapshot');
    }

    #[DataProvider('programs')]
    public function test_complete_verified_disqualifying_facts_produce_ineligible(string $program): void
    {
        $application = $this->context($program);
        $facts = $this->data($application)['facts'];
        $facts['filipino'] = 'no';
        $run = $this->evaluate($application, ['facts' => $facts]);
        $this->assertSame('ineligible', $run->resultLabel());
        $this->assertSame('not_qualified', $run->school_decision);
        $this->assertSame('fail', $run->check_results['filipino']['status']);
    }

    public function test_missing_unreviewed_and_missing_physical_evidence_never_produce_eligible(): void
    {
        $application = $this->context(withEvidence: false);
        $this->assertSame('needs_review', $this->evaluate($application)->resultLabel());
        foreach (['eligibility_support', 'enrollment_certificate'] as $type) {
            $this->upload($application, $type, false);
        }
        $this->assertSame('needs_review', $this->evaluate($application)->resultLabel());
        foreach ($application->documents()->get() as $document) {
            $this->patch(route('evaluations.evidence.review', [$application, $document]), ['review_status' => 'accepted', 'review_notes' => 'Checked certified original'])->assertSessionHasNoErrors();
        }
        $this->assertSame('eligible', $this->evaluate($application)->resultLabel());
        $file = $application->documents()->firstOrFail();
        Storage::disk('scholarship')->delete($file->storage_path);
        $this->assertSame('needs_review', $this->evaluate($application)->resultLabel());
    }

    public function test_external_corrections_append_and_exact_duplicates_are_rejected(): void
    {
        $application = $this->context();
        $approval = $this->approval($application, ['reference' => 'SYNTHETIC-UNCHANGED-REFERENCE']);
        $this->post(route('evaluations.external', $application), [
            'previous_approval' => $approval->id, 'authority' => 'CHED/UniFAST', 'decision' => 'approved', 'reference' => $approval->reference,
            'decided_on' => $approval->decided_on->toDateString(), 'certificate' => $this->pdf(), 'verified' => 1, 'verification_notes' => 'Duplicate',
        ])->assertSessionHasErrors('reference');
        $new = $this->approval($application, ['reference' => $approval->reference, 'valid_until' => now()->addMonth()->toDateString()]);
        $this->assertSame($approval->id, $new->supersedes_id);
        $this->assertDatabaseCount('external_approvals', 2);
        $this->assertNull($approval->fresh()->valid_until);
    }

    public function test_missing_or_corrupted_files_make_the_previous_decision_noncurrent(): void
    {
        $application = $this->context();
        $run = $this->evaluate($application);
        $this->assertTrue($run->isCurrent());
        $document = $application->documents()->first();
        Storage::disk('scholarship')->put($document->storage_path, 'corrupted evidence');
        $this->assertFalse($run->isCurrent());
        $this->assertSame('needs_review', $this->evaluate($application)->resultLabel());
    }

    public function test_unknown_policy_and_edukar_do_not_qualify_and_cannot_confirm_invented_rules(): void
    {
        $application = $this->context(confirmed: false);
        $this->assertSame('needs_review', $this->evaluate($application)->resultLabel());
        $edukar = $this->context('EDUKAR', false, false);
        $this->post(route('evaluations.policy', $edukar), ['confirm_policy' => 1, 'grade_threshold' => 75])->assertSessionHasErrors('confirm_policy');
        $this->assertSame('needs_review', $this->evaluate($edukar, ['automatic_outcome' => 'passed'])->resultLabel());
        $approval = $this->approval($edukar);
        $this->activate($edukar, $edukar->evaluationRuns()->first(), $approval)->assertSessionHasErrors('award');
    }

    public function test_historical_and_custom_periods_cannot_silently_use_the_2026_policy(): void
    {
        foreach ([['2025-2026', '1'], ['2026-2027', 'SUMMER']] as [$year, $semester]) {
            $application = $this->context(confirmed: false, withEvidence: false);
            $application->academicPeriod->update(['academic_year' => $year, 'semester_code' => $semester]);
            $this->post(route('evaluations.policy', $application), ['confirm_policy' => 1])->assertSessionHasErrors('confirm_policy');
            $this->assertSame('needs_review', $this->evaluate($application)->resultLabel());
        }
    }

    public function test_no_grade_or_income_cutoff_is_invented_and_duration_exceptions_require_review(): void
    {
        $application = $this->context();
        $application->assignment->update(['starts_on' => '2010-01-01']);
        $run = $this->evaluate($application, ['duration_review' => 'approved_exception', 'duration_basis' => 'Synthetic documented authorized extension, reference X-1']);
        $this->assertSame('eligible', $run->resultLabel());
        $this->assertArrayNotHasKey('grade_threshold', $run->rule_snapshot['policy']);
        $this->assertArrayNotHasKey('household_income_cap', $run->rule_snapshot['policy']);
        $this->assertSame('needs_review', $this->evaluate($application, ['duration_review' => 'exceeded'])->resultLabel());
        $this->assertSame('needs_review', $this->evaluate($application, ['duration_review' => 'approved_exception', 'exceptions_reviewed' => 0])->resultLabel());
        $this->assertSame('needs_review', $this->evaluate($application, ['duration_basis' => null])->resultLabel());
        $this->assertSame('ineligible', $this->evaluate($application, ['smart_same_semester' => 'yes'])->resultLabel());
    }

    public function test_conditional_and_continuing_requirements_are_not_skipped(): void
    {
        $application = $this->context('TES');
        foreach (['returning' => 'approved_loa', 'transferee' => 'previous_enrollment', 'claiming_pwd' => 'pwd_id', 'legacy_pnsl_transfer' => 'residency_certificate'] as $condition => $type) {
            $this->assertSame('needs_review', $this->evaluate($application, [$condition => 'yes'])->resultLabel());
            $this->upload($application, $type);
            $this->assertSame('eligible', $this->evaluate($application, [$condition => 'yes'])->resultLabel());
        }
        $this->assertSame('needs_review', $this->evaluate($application, ['special_case' => 'sardo'])->resultLabel());
        $facts = $this->data($application)['facts'];
        $facts['continuing_award'] = 'unverified';
        $this->assertSame('needs_review', $this->evaluate($application, ['applicant_type' => 'continuing', 'facts' => $facts])->resultLabel());
    }

    public function test_academic_correction_invalidates_decision_and_reevaluation_appends_history(): void
    {
        $application = $this->context();
        $first = $this->evaluate($application);
        $original = $first->getAttributes();
        $record = $application->student->academicRecords()->first();
        $correction = AcademicRecord::create(['student_id' => $record->student_id, 'academic_period_id' => $record->academic_period_id, 'revision' => 2, 'supersedes_id' => $record->id]);
        $this->assertFalse($first->isCurrent());
        $this->assertNull($application->currentSchoolDecision());
        $second = $this->evaluate($application);
        $this->assertSame('needs_review', $second->resultLabel());
        $correction->grades()->create(['subject_name' => 'Corrected synthetic subject', 'average' => '93']);
        $correction->update(['verification_status' => 'verified', 'verified_by' => $record->verified_by, 'verified_at' => now(), 'enrollment_status' => 'enrolled']);
        $third = $this->evaluate($application);
        $this->assertSame('eligible', $third->resultLabel());
        $this->assertSame(3, $third->run_number);
        $this->assertSame($original, $first->fresh()->getAttributes());
        $this->assertFalse($first->isCurrent());
        $this->assertSame($third->id, $application->currentSchoolDecision()?->id);
    }

    public function test_changed_evidence_and_stale_forms_require_a_new_evaluation(): void
    {
        $application = $this->context();
        $data = $this->data($application);
        $first = $this->evaluate($application);
        $this->post(route('evaluations.run', $application), $data)->assertSessionHasErrors('previous_run');
        $this->upload($application, 'enrollment_certificate');
        $this->assertFalse($first->isCurrent());
        $this->assertSame('eligible', $this->evaluate($application)->resultLabel());
    }

    public function test_only_verified_latest_external_approval_permits_activation_and_preserves_provenance(): void
    {
        $application = $this->context();
        $run = $this->evaluate($application);
        $this->post(route('evaluations.activate', $application), ['evaluation_id' => $run->id, 'approval_id' => 0])->assertSessionHasErrors('award');
        $approval = $this->approval($application, ['valid_from' => now('Asia/Manila')->toDateString(), 'valid_until' => now('Asia/Manila')->toDateString()]);
        $this->activate($application, $run, $approval)->assertSessionHasNoErrors();
        $assignment = $application->assignment->fresh();
        $this->assertSame('active', $assignment->status);
        $this->assertSame($run->id, $assignment->activation_evaluation_id);
        $this->assertSame($approval->id, $assignment->activation_approval_id);
        $this->assertSame(auth()->id(), $assignment->activated_by);
        $this->assertNotNull($assignment->activated_at);
        $this->activate($application, $run, $approval)->assertSessionHasErrors('award');
        $this->assertDatabaseCount('billing_items', 0);
        $this->assertDatabaseCount('payouts', 0);
    }

    public static function unusableApprovals(): array
    {
        return [['rejected'], ['revoked'], ['expired'], ['future'], ['superseded'], ['missing_file']];
    }

    #[DataProvider('unusableApprovals')]
    public function test_unusable_external_approvals_do_not_activate_awards(string $case): void
    {
        $application = $this->context();
        $run = $this->evaluate($application);
        $data = match ($case) {
            'expired' => ['valid_until' => now('Asia/Manila')->subDay()->toDateString()],
            'future' => ['valid_from' => now('Asia/Manila')->addDay()->toDateString()],
            'rejected', 'revoked' => ['decision' => $case],
            default => [],
        };
        $approval = $this->approval($application, $data);
        if ($case === 'superseded') {
            $original = $approval->getAttributes();
            $new = $this->approval($application, ['decision' => 'revoked']);
            $this->assertSame($approval->id, $new->supersedes_id);
            $this->assertSame($original, $approval->fresh()->getAttributes());
        }
        if ($case === 'missing_file') {
            Storage::disk('scholarship')->delete($approval->documents()->first()->storage_path);
        }
        $this->activate($application, $run, $approval)->assertSessionHasErrors('award');
        $this->assertSame('pending', $application->assignment->fresh()->status);
    }

    public function test_conflicting_awards_and_archived_scholars_block_activation_and_old_form_bypasses(): void
    {
        $application = $this->context();
        $run = $this->evaluate($application);
        $approval = $this->approval($application);
        $other = ScholarshipProgram::create(['code' => 'TES', 'name' => 'TES']);
        $conflict = ScholarshipAssignment::create(['student_id' => $application->student_id, 'program_id' => $other->id, 'status' => 'active']);
        $this->activate($application, $run, $approval)->assertSessionHasErrors('award');
        $this->assertSame('needs_review', $this->evaluate($application)->resultLabel());
        $conflict->update(['status' => 'ended']);
        $run = $this->evaluate($application);
        $application->student->update(['record_status' => 'archived']);
        $this->activate($application, $run, $approval)->assertSessionHasErrors('award');
        $this->actingAs($this->staff('administrator'))->patch(route('scholars.assignments.update', [$application->student, $application->assignment]), ['program_id' => $application->program_id, 'start_period_id' => $application->academic_period_id, 'status' => 'active', 'status_reason' => 'Forged bypass'])->assertSessionHasErrors('status');
        $this->assertSame('pending', $application->assignment->fresh()->status);
    }

    public static function roles(): array
    {
        return [['coordinator', true, true], ['registrar', true, false], ['administrator', true, true], ['finance', false, false], ['viewer', false, false], ['none', false, false]];
    }

    #[DataProvider('roles')]
    public function test_permissions_apply_to_pages_evidence_and_direct_mutations(string $role, bool $read, bool $write): void
    {
        $application = $this->context();
        $document = $application->documents()->first();
        $this->actingAs($role === 'none' ? User::factory()->create() : $this->staff($role));
        $this->get(route('evaluations.index'))->assertStatus($read ? 200 : 403);
        $this->get(route('evaluations.show', $application))->assertStatus($read ? 200 : 403);
        $this->get(route('evaluations.evidence.download', [$application, $document]))->assertStatus($read ? 200 : 403);
        $this->get(route('evaluations.create'))->assertStatus($write ? 200 : 403);
        $this->post(route('evaluations.run', $application), $this->data($application))->assertStatus($write ? 302 : 403);
        foreach (['evaluations.policy', 'evaluations.evidence', 'evaluations.external', 'evaluations.activate'] as $route) {
            if (! $write) {
                $this->post(route($route, $application), [])->assertForbidden();
            }
        }
    }

    public function test_duplicate_context_wrong_program_and_cross_application_evidence_are_rejected(): void
    {
        $application = $this->context();
        $data = ['program_id' => $application->program_id, 'academic_period_id' => $application->academic_period_id, 'assignment_id' => $application->assignment_id];
        $this->post(route('evaluations.store'), $data)->assertRedirect(route('evaluations.show', $application));
        $this->assertDatabaseCount('scholarship_applications', 1);
        $other = $this->context('TES');
        $this->post(route('evaluations.store'), array_replace($data, ['program_id' => $other->program_id]))->assertSessionHasErrors('assignment_id');
        $this->get(route('evaluations.evidence.download', [$other, $application->documents()->first()]))->assertNotFound();
        $this->patch(route('evaluations.evidence.review', [$other, $application->documents()->first()]), ['review_status' => 'accepted', 'review_notes' => 'Wrong owner'])->assertNotFound();
        $this->delete(route('evaluations.show', $application))->assertStatus(405);
    }

    public function test_validation_rejects_forged_actor_rules_and_missing_external_evidence(): void
    {
        $application = $this->context();
        $run = $this->evaluate($application, ['evaluated_by' => 9999, 'school_decision' => 'not_qualified', 'rule_version' => 'forged', 'input_snapshot' => ['eligible' => true]]);
        $this->assertSame(auth()->id(), $run->evaluated_by);
        $this->assertSame('qualified', $run->school_decision);
        $this->assertSame('UNIFAST-TDP-MC02-2026-v1', $run->rule_version);
        $this->post(route('evaluations.external', $application), ['authority' => 'School', 'decision' => 'approved', 'reference' => 'X', 'decided_on' => now('Asia/Manila')->addDay()->toDateString(), 'previous_approval' => 0])->assertSessionHasErrors(['authority', 'decided_on', 'verified', 'certificate', 'verification_notes']);
        $this->assertDatabaseCount('external_approvals', 0);
        $this->assertDatabaseRejects(fn () => $application->terms->update(['criteria' => []]));
    }

    public function test_audit_failures_roll_back_evaluations_and_private_uploads(): void
    {
        $application = $this->context();
        $initial = Storage::disk('scholarship')->allFiles();
        $this->mock(RecordAudit::class)->shouldReceive('record')->andThrow(new RuntimeException('Audit unavailable'));
        $this->withoutExceptionHandling();
        foreach (['evaluation', 'evidence', 'approval'] as $operation) {
            try {
                if ($operation === 'evaluation') {
                    $this->post(route('evaluations.run', $application), $this->data($application));
                } elseif ($operation === 'evidence') {
                    $this->upload($application, 'approved_loa');
                } else {
                    $this->approval($application);
                }
                $this->fail('An audit failure must abort the change.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Audit unavailable', $exception->getMessage());
            }
        }
        $this->assertDatabaseCount('evaluation_runs', 0);
        $this->assertDatabaseCount('external_approvals', 0);
        $this->assertSame($initial, Storage::disk('scholarship')->allFiles());
    }

    public function test_activation_rolls_back_when_its_audit_cannot_be_written(): void
    {
        $application = $this->context();
        $run = $this->evaluate($application);
        $approval = $this->approval($application);
        $this->mock(RecordAudit::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Activation audit unavailable'));
        $this->withoutExceptionHandling();
        try {
            $this->activate($application, $run, $approval);
            $this->fail('Activation must not succeed without its audit.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Activation audit unavailable', $exception->getMessage());
        }
        $this->assertSame('pending', $application->assignment->fresh()->status);
        $this->assertNull($application->assignment->fresh()->activation_evaluation_id);
        $this->assertSame(0, AuditEvent::where('action', 'scholarship_award_activated')->count());
    }
}
