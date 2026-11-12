<?php

namespace Tests\Feature\Eligibility;

use App\Actions\Records\RecordAudit;
use App\Models\AcademicPeriod;
use App\Models\AcademicRecord;
use App\Models\AcademicValidationContext;
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
use RuntimeException;
use Tests\Concerns\BuildsScholarshipRecords;
use Tests\TestCase;

class DirectValidationScopeTest extends TestCase
{
    use BuildsScholarshipRecords, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('academic');
        Storage::fake('scholarship');
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->create(['role' => $role]);

        return $user;
    }

    private function fixtures(string $programCode = 'TDP'): array
    {
        $admin = $this->staff('administrator');
        $registrar = $this->staff('registrar');
        $coordinator = $this->staff('coordinator');
        $target = AcademicPeriod::create(['academic_year' => '2028-2029', 'semester_code' => '2']);
        $source = AcademicPeriod::create(['academic_year' => '2027-2028', 'semester_code' => '2']);
        $this->actingAs($admin)->post(route('academic-periods.context'), ['revision' => 0, 'active_period_id' => $target->id, 'source_period_id' => $source->id, 'source_policy_reference' => 'Synthetic CFCI source-period decision', 'confirm_source' => 1])->assertSessionHasNoErrors();
        $student = Student::create(['student_number' => 'SCOPE-001', 'first_name' => 'Scope', 'last_name' => 'Scholar']);
        $program = ScholarshipProgram::create(['code' => $programCode, 'name' => $programCode, 'is_active' => true]);
        $assignment = ScholarshipAssignment::create(['student_id' => $student->id, 'program_id' => $program->id, 'start_period_id' => $target->id]);
        $this->actingAs($coordinator)->post(route('evaluations.store'), ['program_id' => $program->id, 'academic_period_id' => $target->id, 'assignment_id' => $assignment->id])->assertSessionHasNoErrors();
        $application = ScholarshipApplication::firstOrFail();
        if ($programCode !== 'EDUKAR') {
            $this->post(route('evaluations.policy', $application), ['confirm_policy' => 1])->assertSessionHasNoErrors();
            foreach (['eligibility_support', 'enrollment_certificate'] as $type) {
                $this->post(route('evaluations.evidence', $application), ['submission_revision' => $application->fresh()->submission_revision, 'document_type' => $type, 'certificate' => $this->pdf(), 'verified' => 1])->assertSessionHasNoErrors();
            }
        }
        $enrollment = AcademicRecord::create(['student_id' => $student->id, 'academic_period_id' => $target->id, 'enrollment_status' => 'enrolled', 'verification_status' => 'verified', 'verified_by' => $registrar->id, 'verified_at' => now()]);
        $grades = AcademicRecord::create(['student_id' => $student->id, 'academic_period_id' => $source->id, 'enrollment_status' => 'enrolled']);
        $grades->grades()->create($this->reportedGrade());
        $grades->update(['verification_status' => 'verified', 'verified_by' => $registrar->id, 'verified_at' => now()]);

        return compact('admin', 'registrar', 'coordinator', 'target', 'source', 'student', 'program', 'assignment', 'application', 'enrollment', 'grades');
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('source.pdf', "%PDF-1.4\nSynthetic source\n%%EOF");
    }

    private function reportedGrade(): array
    {
        return ['subject_code' => 'CS101', 'subject_name' => 'Computer Science', 'units' => '3.00', 'prelim' => '92', 'midterm' => '93', 'semi_final' => '94', 'final' => '94', 'average' => '92.7500', 'equivalent' => '1.10', 'remarks' => 'Copied from certificate'];
    }

    private function data(ScholarshipApplication $application, array $extra = []): array
    {
        return array_replace([
            'submission_revision' => $application->fresh()->submission_revision, 'previous_run' => $application->evaluationRuns()->max('run_number') ?? 0,
            'applicant_type' => 'new', 'facts' => array_fill_keys(array_keys(OfficialRuleCatalog::FACTS), 'yes'),
            'fact_sources' => array_fill_keys(array_keys(OfficialRuleCatalog::FACTS), 'Synthetic checked source bundle'),
            'returning' => 'no', 'transferee' => 'no', 'legacy_pnsl_transfer' => 'no', 'claiming_pwd' => 'no', 'smart_same_semester' => 'no', 'special_case' => 'none',
            'duration_review' => 'within_limit', 'duration_basis' => 'Synthetic curriculum, grace-period and exception review', 'exceptions_reviewed' => 1,
        ], $extra);
    }

    public function test_active_period_and_grading_source_are_separate_configured_periods(): void
    {
        $g = $this->fixtures();
        $this->actingAs($g['coordinator'])->post(route('evaluations.run', $g['application']), $this->data($g['application']))->assertSessionHasNoErrors();
        $run = $g['application']->evaluationRuns()->firstOrFail();
        $this->assertSame($g['target']->id, $run->academic_period_id);
        $this->assertSame($g['enrollment']->id, $run->enrollment_record_id);
        $this->assertSame($g['grades']->id, $run->grade_record_id);
        $this->assertSame('92.7500', $run->input_snapshot['grading_academic']['subjects'][0]['average']);
        $this->assertSame('1.10', $run->input_snapshot['grading_academic']['subjects'][0]['equivalent']);
        $this->assertSame('needs_review', $run->resultLabel());
        $this->assertSame('review', $run->check_results['school_grading_policy']['status']);
        $this->assertSame('draft', $g['application']->fresh()->status);
        $this->assertNull($g['application']->fresh()->submitted_at);
        $this->get(route('evaluations.create', ['program_id' => $g['program']->id, 'academic_period_id' => $g['source']->id]))->assertSee('2028-2029 / 2')->assertSee('Open validation');
        $this->post(route('evaluations.store'), ['program_id' => $g['program']->id, 'academic_period_id' => $g['source']->id, 'assignment_id' => $g['assignment']->id])->assertSessionHasErrors('academic_period_id');
    }

    public function test_missing_grading_policy_blocks_both_eligible_and_ineligible_even_with_forged_flags(): void
    {
        $g = $this->fixtures();
        $data = $this->data($g['application'], ['grading_policy_check' => ['status' => 'pass'], 'automatic_outcome' => 'passed']);
        $data['facts']['filipino'] = 'no';
        $this->actingAs($g['coordinator'])->post(route('evaluations.run', $g['application']), $data)->assertSessionHasNoErrors();
        $run = $g['application']->evaluationRuns()->firstOrFail();
        $this->assertSame('needs_review', $run->resultLabel());
        $this->assertSame('needs_information', $run->school_decision);
        $this->assertSame('fail', $run->check_results['filipino']['status']);
        $this->assertFalse($run->isCurrent());
        $this->assertNull($g['application']->currentSchoolDecision());
    }

    public function test_unconfigured_context_has_no_automatic_year_or_source_fallback(): void
    {
        $this->assertNull(AcademicValidationContext::current()->active_period_id);
        $this->actingAs($this->staff('coordinator'))->get(route('evaluations.create'))->assertOk()->assertSee('not configured');
        $g = $this->fixtures();
        AcademicValidationContext::current()->update(['active_period_id' => null, 'source_period_id' => null, 'source_policy_reference' => null, 'confirmed_by' => null, 'confirmed_at' => null, 'revision' => 2]);
        $this->actingAs($g['coordinator'])->post(route('evaluations.run', $g['application']), $this->data($g['application']))->assertSessionHasNoErrors();
        $run = $g['application']->evaluationRuns()->first();
        $this->assertSame('needs_review', $run->resultLabel());
        $this->assertNull($run->grade_record_id);
        $this->assertSame('review', $run->check_results['active_period']['status']);
        $this->assertSame('review', $run->check_results['grade_source_policy']['status']);
    }

    public function test_latest_pending_source_revision_prevents_fallback_to_verified_older_grades(): void
    {
        $g = $this->fixtures();
        AcademicRecord::create(['student_id' => $g['student']->id, 'academic_period_id' => $g['source']->id, 'revision' => 2, 'supersedes_id' => $g['grades']->id]);
        $this->actingAs($g['coordinator'])->post(route('evaluations.run', $g['application']), $this->data($g['application']))->assertSessionHasNoErrors();
        $run = $g['application']->evaluationRuns()->first();
        $this->assertNull($run->grade_record_id);
        $this->assertSame($g['enrollment']->id, $run->enrollment_record_id);
        $this->assertSame('review', $run->check_results['grade_source_data']['status']);
    }

    public function test_context_changes_invalidate_current_decisions_without_overwriting_history(): void
    {
        $this->mock(SchoolGradingPolicy::class)->shouldReceive('check')->andReturn(['status' => 'pass', 'reason' => 'Synthetic resolved prerequisite, not school policy']);
        $g = $this->fixtures();
        $this->actingAs($g['coordinator'])->post(route('evaluations.run', $g['application']), $this->data($g['application']))->assertSessionHasNoErrors();
        $run = $g['application']->evaluationRuns()->first();
        $this->assertTrue($run->isCurrent());
        $original = $run->getAttributes();
        $this->actingAs($g['admin'])->post(route('academic-periods.context'), ['revision' => 1, 'active_period_id' => $g['target']->id, 'source_period_id' => $g['target']->id, 'source_policy_reference' => 'Changed synthetic source policy', 'confirm_source' => 1])->assertSessionHasNoErrors();
        $this->assertFalse($run->isCurrent());
        $this->assertSame($original, $run->fresh()->getAttributes());
        $this->assertNull($g['application']->currentSchoolDecision());
    }

    public function test_context_requires_coordinator_school_reference_confirmation_and_a_fresh_version(): void
    {
        $g = $this->fixtures();
        $data = ['revision' => 1, 'active_period_id' => $g['target']->id, 'source_period_id' => $g['source']->id];
        $this->actingAs($g['registrar'])->post(route('academic-periods.context'), $data)->assertForbidden();
        $this->actingAs($g['coordinator'])->post(route('academic-periods.context'), $data)->assertSessionHasErrors(['source_policy_reference', 'confirm_source']);
        $data += ['source_policy_reference' => 'Approved source reference', 'confirm_source' => 1];
        $this->post(route('academic-periods.context'), $data + ['confirmed_by' => 9999])->assertSessionHasNoErrors();
        $this->assertSame($g['coordinator']->id, AcademicValidationContext::current()->confirmed_by);
        $this->post(route('academic-periods.context'), $data)->assertSessionHasErrors('revision');
        $this->assertSame(2, AcademicValidationContext::current()->revision);
        $this->assertDatabaseRejects(fn () => DB::table('academic_validation_context')->insert(['id' => 2]));
        $this->assertDatabaseRejects(fn () => AcademicValidationContext::current()->update(['source_period_id' => 9999]));
    }

    public function test_verified_grade_children_cannot_be_added_changed_moved_or_deleted(): void
    {
        $g = $this->fixtures();
        $grade = $g['grades']->grades()->first();
        $draft = AcademicRecord::create(['student_id' => $g['student']->id, 'academic_period_id' => $g['source']->id, 'revision' => 2]);
        $this->assertDatabaseRejects(fn () => $g['grades']->grades()->create(['subject_name' => 'Inserted after verification']));
        $this->assertDatabaseRejects(fn () => DB::table('academic_record_grades')->where('id', $grade->id)->update(['average' => '1']));
        $this->assertDatabaseRejects(fn () => DB::table('academic_record_grades')->where('id', $grade->id)->update(['academic_record_id' => $draft->id]));
        $this->assertDatabaseRejects(fn () => $grade->delete());
    }

    public function test_raw_grade_edits_validate_duplicate_codes_and_preserve_corrections(): void
    {
        $g = $this->fixtures();
        $this->actingAs($g['registrar']);
        $data = ['lock_version' => 1, 'enrollment_status' => 'enrolled', 'academic_standing' => 'unverified', 'source_reference' => 'Corrected certified record', 'correction_reason' => 'Source certificate amended', 'action' => 'verify', 'grades' => [$this->reportedGrade()]];
        $bad = $data;
        $bad['grades'][] = array_replace($this->reportedGrade(), ['subject_code' => 'cs101']);
        $this->post(route('academic-verifications.correct', $g['grades']), $bad)->assertSessionHasErrors(['grades.0.subject_code', 'grades.1.subject_code']);
        $bad = $data;
        $bad['grades'][0]['average'] = ['bad'];
        $this->post(route('academic-verifications.correct', $g['grades']), $bad)->assertSessionHasErrors('grades.0.average');
        $data['grades'][0]['average'] = '94';
        $data['grades'][0]['equivalent'] = '2.20';
        $this->post(route('academic-verifications.correct', $g['grades']), $data)->assertSessionHasNoErrors();
        $new = AcademicRecord::latest('id')->first();
        $this->assertSame('94', $new->grades()->first()->average);
        $this->assertSame('2.20', $new->grades()->first()->equivalent);
        $this->assertSame('92.7500', $g['grades']->grades()->first()->average);
        $this->assertSame('unverified', $new->academic_standing);
    }

    public function test_edukar_has_no_application_requirements_or_exception_workflow(): void
    {
        $g = $this->fixtures('EDUKAR');
        $this->actingAs($g['coordinator'])->get(route('evaluations.show', $g['application']))->assertOk()->assertSee('Students apply directly')->assertDontSee('name="applicant_type"', false)->assertDontSee('name="duration_review"', false)->assertDontSee('name="document_type"', false);
        $this->post(route('evaluations.run', $g['application']), ['submission_revision' => 1, 'previous_run' => 0, 'evaluator_notes' => 'Review existing academic data only'])->assertSessionHasNoErrors();
        $run = $g['application']->evaluationRuns()->first();
        $this->assertSame('needs_review', $run->resultLabel());
        $this->assertArrayNotHasKey('first_degree', $run->check_results);
        $this->assertArrayNotHasKey('duration', $run->check_results);
        $this->post(route('evaluations.evidence', $g['application']), ['submission_revision' => 1, 'document_type' => 'application_form', 'certificate' => $this->pdf()])->assertForbidden();
        $this->assertSame('draft', $g['application']->fresh()->status);
        $this->assertNull($g['application']->fresh()->submitted_at);
    }

    public function test_intake_and_billing_forms_are_not_direct_validation_upload_types(): void
    {
        $g = $this->fixtures();
        $this->actingAs($g['coordinator']);
        foreach (['application_form', 'annex_forms', 'income_evidence'] as $type) {
            $this->post(route('evaluations.evidence', $g['application']), ['submission_revision' => $g['application']->fresh()->submission_revision, 'document_type' => $type, 'certificate' => $this->pdf(), 'verified' => 1])->assertSessionHasErrors('document_type');
        }
        $this->get(route('evaluations.show', $g['application']))->assertDontSee('<option value="application_form">', false)->assertDontSee('Applicant type');
    }

    public function test_context_audit_failure_rolls_back_the_configuration(): void
    {
        $g = $this->fixtures();
        $before = AcademicValidationContext::current()->getAttributes();
        $this->mock(RecordAudit::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Context audit failed'));
        $this->withoutExceptionHandling()->actingAs($g['admin']);
        try {
            $this->post(route('academic-periods.context'), ['revision' => 1, 'active_period_id' => $g['source']->id]);
            $this->fail('The context change must roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Context audit failed', $exception->getMessage());
        }
        $this->assertSame($before, AcademicValidationContext::current()->getAttributes());
    }
}
