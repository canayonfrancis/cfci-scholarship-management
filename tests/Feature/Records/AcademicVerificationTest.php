<?php

namespace Tests\Feature\Records;

use App\Actions\Records\RecordAudit;
use App\Models\AcademicPeriod;
use App\Models\AcademicRecord;
use App\Models\AcademicValidationContext;
use App\Models\EvaluationRun;
use App\Models\Student;
use App\Models\User;
use App\Services\Eligibility\SchoolGradingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsScholarshipRecords;
use Tests\TestCase;

class AcademicVerificationTest extends TestCase
{
    use BuildsScholarshipRecords, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('academic');
    }

    private function staff(string $role = 'registrar'): User
    {
        $actor = User::factory()->create();
        $actor->roles()->create(['role' => $role]);

        return $actor;
    }

    private function fixture(): array
    {
        $student = Student::create(['student_number' => 'AV-001', 'first_name' => 'Ana', 'last_name' => 'Scholar']);
        $period = AcademicPeriod::create(['academic_year' => '2026-2027', 'semester_code' => '1']);

        return [$student, $period];
    }

    private function data(Student $student, AcademicPeriod $period, array $overrides = []): array
    {
        return array_replace([
            'student_id' => $student->id, 'academic_period_id' => $period->id,
            'course' => 'BS Information Technology', 'year_level' => '2',
            'enrollment_status' => 'enrolled', 'academic_standing' => 'passing',
            'source_reference' => 'Registrar certificate reference AV-1', 'verification_notes' => null, 'action' => 'verify',
        ], $overrides);
    }

    private function updateData(array $overrides = []): array
    {
        return array_replace([
            'lock_version' => 1, 'enrollment_status' => 'enrolled', 'academic_standing' => 'failing',
            'source_reference' => 'Corrected school certificate AV-2', 'action' => 'verify',
        ], $overrides);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('grades.pdf', "%PDF-1.4\nSynthetic grade certificate\n%%EOF");
    }

    private function verifiedRecord(): AcademicRecord
    {
        [$student, $period] = $this->fixture();
        $this->actingAs($this->staff())->post(route('academic-verifications.store'), $this->data($student, $period))->assertSessionHasNoErrors();

        return AcademicRecord::firstOrFail();
    }

    public static function roles(): array
    {
        return [['registrar', true, true], ['coordinator', true, false], ['administrator', true, false], ['finance', false, false], ['viewer', false, false], ['none', false, false]];
    }

    #[DataProvider('roles')]
    public function test_roles_protect_reads_writes_corrections_and_private_certificates(string $role, bool $read, bool $write): void
    {
        $record = $this->verifiedRecord();
        Storage::disk('academic')->put('certificates/'.$record->id.'/test.pdf', 'private evidence');
        $document = $record->documents()->create(['disk' => 'academic', 'storage_path' => 'certificates/'.$record->id.'/test.pdf', 'original_name' => 'test.pdf', 'document_type' => 'grade_certificate']);
        $actor = $role === 'none' ? User::factory()->create() : $this->staff($role);
        $this->actingAs($actor);
        $this->get(route('academic-verifications.index'))->assertStatus($read ? 200 : 403);
        $this->get(route('academic-verifications.show', $record))->assertStatus($read ? 200 : 403);
        $this->get(route('academic-verifications.certificate', [$record, $document]))->assertStatus($read ? 200 : 403);
        $this->get(route('academic-verifications.create'))->assertStatus($write ? 200 : 403);
        $this->get(route('academic-verifications.correction', $record))->assertStatus($write ? 200 : 403);
        $this->post(route('academic-verifications.store'), [])->assertStatus($write ? 302 : 403);
        $this->post(route('academic-verifications.correct', $record), $this->updateData(['correction_reason' => 'Corrected transcription']))->assertStatus($write ? 302 : 403);
        $this->assertSame($write ? 2 : 1, AcademicRecord::count());
    }

    public function test_guests_cannot_read_write_or_download(): void
    {
        $record = $this->verifiedRecord();
        $this->app['auth']->forgetGuards();
        foreach (['academic-verifications.index', 'academic-verifications.create'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
        $this->get(route('academic-verifications.show', $record))->assertRedirect(route('login'));
        $this->get(route('academic-verifications.certificate', [$record, 1]))->assertRedirect(route('login'));
        $this->post(route('academic-verifications.store'), [])->assertRedirect(route('login'));
    }

    public function test_verification_tracks_server_actor_time_private_evidence_and_escapes_content(): void
    {
        [$student, $period] = $this->fixture();
        $actor = $this->staff();
        $this->travelTo(now()->startOfSecond());
        $this->actingAs($actor)->post(route('academic-verifications.store'), $this->data($student, $period, [
            'certificates' => [$this->pdf()], 'verified_by' => 9999, 'verified_at' => '1990-01-01', 'revision' => 88,
            'verification_notes' => '<script>alert(1)</script>', 'verification_status' => 'rejected', 'academic_summary' => ['is_eligible' => true],
        ]))->assertSessionHasNoErrors();
        $record = AcademicRecord::firstOrFail();
        $this->assertSame($actor->id, $record->verified_by);
        $this->assertTrue($record->verified_at->equalTo(now()));
        $this->assertSame(1, $record->revision);
        $this->assertSame('verified', $record->verification_status);
        $this->assertNull($record->academic_summary);
        $document = $record->documents()->firstOrFail();
        $this->assertSame('academic', $document->disk);
        $this->assertSame($actor->id, $document->uploaded_by);
        $this->assertSame('grade_certificate', $document->document_type);
        $this->assertSame('accepted', $document->review_status);
        $this->assertSame($actor->id, $document->reviewed_by);
        $this->assertSame(64, strlen($document->checksum));
        Storage::disk('academic')->assertExists($document->storage_path);
        $this->get(route('academic-verifications.certificate', [$record, $document]))->assertDownload('grades.pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('academic-verifications.show', $record))->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertDontSee($document->storage_path);
        $this->assertDatabaseHas('audit_events', ['subject_id' => $record->id, 'action' => 'academic_record_verified', 'actor_id' => $actor->id]);
        $this->assertDatabaseCount('evaluation_runs', 0);
        $this->assertDatabaseCount('billing_items', 0);
        $this->assertDatabaseRejects(fn () => DB::table('documents')->where('id', $document->id)->update(['storage_path' => 'other.pdf']));
    }

    public function test_invalid_fields_missing_sources_and_duplicate_contexts_are_rejected(): void
    {
        [$student, $period] = $this->fixture();
        $this->actingAs($this->staff());
        $this->post(route('academic-verifications.store'), $this->data($student, $period, ['enrollment_status' => 'maybe', 'academic_standing' => 'eligible', 'source_reference' => '', 'student_id' => 9999, 'academic_period_id' => 9999]))->assertSessionHasErrors(['student_id', 'academic_period_id', 'enrollment_status', 'academic_standing', 'source_reference']);
        $this->post(route('academic-verifications.store'), $this->data($student, $period, ['enrollment_status' => 'unverified', 'academic_standing' => 'unverified']))->assertSessionHasErrors('action');
        $this->post(route('academic-verifications.store'), $this->data($student, $period))->assertSessionHasNoErrors();
        $this->post(route('academic-verifications.store'), $this->data($student, $period))->assertSessionHasErrors('academic_period_id');
        $this->assertDatabaseCount('academic_records', 1);
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertDatabaseRejects(fn () => AcademicRecord::create(['student_id' => $student->id, 'academic_period_id' => $period->id, 'revision' => 1]));
        $this->assertDatabaseRejects(fn () => AcademicRecord::create(['student_id' => $student->id, 'academic_period_id' => $period->id, 'revision' => 2, 'academic_standing' => 'eligible']));
    }

    public function test_drafts_can_be_saved_verified_and_protected_from_stale_edits_and_role_bypasses(): void
    {
        [$student, $period] = $this->fixture();
        $actor = $this->staff();
        $this->actingAs($actor)->post(route('academic-verifications.store'), $this->data($student, $period, ['action' => 'draft', 'enrollment_status' => 'unverified', 'academic_standing' => 'unverified', 'source_reference' => null]))->assertSessionHasNoErrors();
        $record = AcademicRecord::firstOrFail();
        $this->assertNull($record->verified_by);
        $this->assertNull($record->verified_at);
        $this->get(route('academic-verifications.edit', $record))->assertOk();
        $this->actingAs($this->staff('coordinator'))->patch(route('academic-verifications.update', $record), $this->updateData())->assertForbidden();
        $this->actingAs($actor)->patch(route('academic-verifications.update', $record), $this->updateData(['action' => 'draft']))->assertSessionHasNoErrors();
        $this->patch(route('academic-verifications.update', $record), $this->updateData(['lock_version' => ['invalid']]))->assertSessionHasErrors('lock_version');
        $this->get(route('academic-verifications.edit', $record))->assertOk();
        $this->patch(route('academic-verifications.update', $record), $this->updateData())->assertSessionHasErrors('lock_version');
        $this->patch(route('academic-verifications.update', $record), $this->updateData(['lock_version' => 2, 'student_id' => $student->id]))->assertSessionHasErrors('student_id');
        $this->patch(route('academic-verifications.update', $record), $this->updateData(['lock_version' => 2]))->assertSessionHasNoErrors();
        $this->assertSame('verified', $record->fresh()->verification_status);
        $this->assertSame(3, $record->fresh()->lock_version);
        $this->patch(route('academic-verifications.update', $record), $this->updateData(['lock_version' => 3]))->assertForbidden();
        $this->delete(route('academic-verifications.show', $record))->assertStatus(405);
    }

    public function test_corrections_preserve_verified_history_and_reject_duplicate_or_outdated_corrections(): void
    {
        $record = $this->verifiedRecord();
        $original = $record->getAttributes();
        $this->post(route('academic-verifications.correct', $record), $this->updateData())->assertSessionHasErrors('correction_reason');
        $this->post(route('academic-verifications.correct', $record), $this->updateData(['correction_reason' => 'School corrected the grade certificate']))->assertSessionHasNoErrors();
        $this->assertSame($original, $record->fresh()->getAttributes());
        $new = AcademicRecord::latest('id')->first();
        $this->assertSame($record->id, $new->supersedes_id);
        $this->assertSame(2, $new->revision);
        $this->assertSame('failing', $new->academic_standing);
        $this->post(route('academic-verifications.correct', $record), $this->updateData(['correction_reason' => 'Duplicate correction']))->assertForbidden();
        $this->assertDatabaseCount('academic_records', 2);
        $this->get(route('academic-verifications.show', $record))->assertSee('superseded')->assertSee('Revision 2');
        $this->assertDatabaseRejects(fn () => DB::table('academic_records')->where('id', $record->id)->update(['academic_standing' => 'failing']));
        $this->assertDatabaseRejects(fn () => DB::table('academic_records')->where('id', $record->id)->delete());
    }

    public function test_reported_grades_are_preserved_without_conversion_or_invented_standing(): void
    {
        [$student, $period] = $this->fixture();
        $this->actingAs($this->staff());
        $this->get(route('academic-verifications.create'))->assertSee('Reported subject grades')->assertSee('No formula');
        $this->post(route('academic-verifications.store'), $this->data($student, $period, ['grade_average' => '90']))->assertSessionHasErrors('grade_average');
        $grades = [['subject_code' => 'it101', 'subject_name' => 'Computing', 'units' => '3.00', 'prelim' => '92', 'midterm' => '93', 'semi_final' => '94', 'final' => '94', 'average' => '92.7500', 'equivalent' => '1.10', 'remarks' => 'As recorded']];
        $this->post(route('academic-verifications.store'), $this->data($student, $period, ['grades' => $grades, 'academic_standing' => 'unverified']))->assertSessionHasNoErrors();
        $record = AcademicRecord::firstOrFail();
        $grade = $record->grades()->firstOrFail();
        $this->assertSame('92.7500', $grade->average);
        $this->assertSame('1.10', $grade->equivalent);
        $this->assertSame('3.00', $grade->units);
        $this->assertSame('IT101', $grade->subject_code);
        $this->assertSame('unverified', $record->academic_standing);
        $this->assertNull($record->grade_average);
        $this->get(route('academic-verifications.show', $record))->assertSee('92.7500')->assertSee('1.10');
    }

    private function resolvedContext(array $g, ?AcademicPeriod $source = null): array
    {
        // Synthetic resolved prerequisite for testing revision freshness, not CFCI policy.
        $context = AcademicValidationContext::current();
        $context->update(['active_period_id' => $g['period']->id, 'source_period_id' => ($source ?? $g['period'])->id, 'source_policy_reference' => 'Synthetic source policy', 'confirmed_by' => $g['actor']->id, 'confirmed_at' => now()]);

        return ['assessment_scope' => SchoolGradingPolicy::SCOPE, 'academic_context' => $context->snapshot(), 'grading_policy_check' => ['status' => 'pass', 'reason' => 'Synthetic prerequisite']];
    }

    public function test_files_reject_executables_oversized_and_excess_uploads(): void
    {
        [$student, $period] = $this->fixture();
        $this->actingAs($this->staff());
        $unsafe = UploadedFile::fake()->createWithContent('grades.pdf', '<?php echo "unsafe";');
        // Use Symfony's real MIME detection; Laravel fake files report MIME from their name.
        $disguised = new UploadedFile($unsafe->getPathname(), 'grades.pdf', 'application/pdf', null, true);
        foreach ([$disguised, UploadedFile::fake()->create('big.pdf', 2049, 'application/pdf')] as $file) {
            $this->post(route('academic-verifications.store'), $this->data($student, $period, ['certificates' => [$file]]))->assertSessionHasErrors('certificates.0');
        }
        $this->post(route('academic-verifications.store'), $this->data($student, $period, ['certificates' => array_map(fn () => $this->pdf(), range(1, 4))]))->assertSessionHasErrors('certificates');
        $this->assertDatabaseCount('academic_records', 0);
        $this->assertSame([], Storage::disk('academic')->allFiles());
    }

    public function test_verifying_a_draft_accepts_its_existing_evidence_and_retains_it_on_correction(): void
    {
        [$student, $period] = $this->fixture();
        $this->actingAs($this->staff())->post(route('academic-verifications.store'), $this->data($student, $period, ['action' => 'draft', 'certificates' => [$this->pdf()]]))->assertSessionHasNoErrors();
        $record = AcademicRecord::firstOrFail();
        $document = $record->documents()->firstOrFail();
        $this->assertSame('pending', $document->review_status);
        $this->patch(route('academic-verifications.update', $record), $this->updateData())->assertSessionHasNoErrors();
        $this->assertSame('accepted', $document->fresh()->review_status);
        $this->post(route('academic-verifications.correct', $record), $this->updateData(['lock_version' => 2, 'correction_reason' => 'Corrected Registrar standing']))->assertSessionHasNoErrors();
        $this->assertSame($record->id, $document->fresh()->academic_record_id);
        Storage::disk('academic')->assertExists($document->storage_path);
        $this->assertSame(0, AcademicRecord::latest('id')->first()->documents()->count());
        $this->get(route('academic-verifications.certificate', [$record, $document]))->assertDownload();
    }

    public function test_document_ownership_other_disks_missing_files_and_public_paths_do_not_bypass_access(): void
    {
        $record = $this->verifiedRecord();
        $second = AcademicRecord::create(['student_id' => $record->student_id, 'academic_period_id' => $record->academic_period_id, 'revision' => 2, 'supersedes_id' => $record->id]);
        $document = $second->documents()->create(['disk' => 'academic', 'storage_path' => 'certificates/'.$second->id.'/missing.pdf', 'original_name' => 'missing.pdf', 'document_type' => 'grade_certificate']);
        $this->get(route('academic-verifications.certificate', [$record, $document]))->assertNotFound();
        $this->get(route('academic-verifications.certificate', [$second, $document]))->assertNotFound();
        $document->update(['disk' => 'public']);
        $this->get(route('academic-verifications.certificate', [$second, $document]))->assertNotFound();
        $this->get('/storage/certificates/'.$second->id.'/missing.pdf')->assertForbidden();
        $this->assertFalse(config('filesystems.disks.academic.serve'));
        $this->assertNotSame(config('filesystems.disks.local.root'), config('filesystems.disks.academic.root'));
    }

    public function test_audit_failure_rolls_back_record_metadata_and_removes_uploaded_files(): void
    {
        [$student, $period] = $this->fixture();
        $this->mock(RecordAudit::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Audit unavailable'));
        $this->withoutExceptionHandling()->actingAs($this->staff());
        try {
            $this->post(route('academic-verifications.store'), $this->data($student, $period, ['certificates' => [$this->pdf()]]));
            $this->fail('Audit failure must abort the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('academic_records', 0);
        $this->assertDatabaseCount('documents', 0);
        $this->assertSame([], Storage::disk('academic')->allFiles());
    }

    public function test_correction_invalidates_final_decisions_even_when_grade_source_is_a_different_semester(): void
    {
        $g = $this->applicationContext();
        $this->actingAs($this->staff());
        $g['record']->update(['verification_status' => 'verified', 'verified_by' => auth()->id(), 'verified_at' => now()->subDay()]);
        $otherPeriod = AcademicPeriod::create(['academic_year' => '2025-2026', 'semester_code' => '2']);
        $gradeSource = AcademicRecord::create(['student_id' => $g['student']->id, 'academic_period_id' => $otherPeriod->id, 'verification_status' => 'verified', 'verified_by' => auth()->id(), 'verified_at' => now()->subDay()]);
        $g['evaluation']->update(['input_snapshot' => $this->resolvedContext($g, $otherPeriod), 'grade_record_id' => $gradeSource->id, 'school_decision' => 'qualified', 'coordinator_id' => $g['actor']->id, 'completed_at' => now(), 'decided_at' => now()]);
        $this->assertTrue($g['evaluation']->isCurrent());
        $this->assertSame($g['evaluation']->id, $g['application']->currentSchoolDecision()?->id);
        $snapshot = $g['evaluation']->fresh()->getAttributes();
        $this->post(route('academic-verifications.correct', $gradeSource), $this->updateData(['action' => 'draft', 'correction_reason' => 'Corrected prior-semester grades']))->assertSessionHasNoErrors();
        $this->assertFalse($g['evaluation']->isCurrent());
        $this->assertNull($g['application']->currentSchoolDecision());
        $this->assertSame($snapshot, $g['evaluation']->fresh()->getAttributes());
        $correction = AcademicRecord::latest('id')->first();
        $this->get(route('academic-verifications.show', $correction))->assertSee('Requires Coordinator reassessment');
        $this->patch(route('academic-verifications.update', $correction), $this->updateData())->assertSessionHasNoErrors();
        $this->assertFalse($g['evaluation']->isCurrent());
        $new = EvaluationRun::create(array_replace($g['evaluation']->only($g['evaluation']->getFillable()), ['run_number' => 2, 'grade_record_id' => $correction->id, 'completed_at' => now()->addSecond(), 'decided_at' => now()->addSecond()]));
        $this->assertTrue($new->isCurrent());
        $this->assertSame($new->id, $g['application']->currentSchoolDecision()?->id);
        $g['application']->update(['submission_revision' => 2]);
        $this->assertFalse($new->isCurrent());
        $this->assertNull($g['application']->currentSchoolDecision());
    }

    public function test_new_pending_run_blocks_fallback_to_old_qualified_decision_and_unverified_sources_are_not_current(): void
    {
        $g = $this->applicationContext();
        $g['evaluation']->update(['input_snapshot' => $this->resolvedContext($g), 'school_decision' => 'qualified', 'completed_at' => now(), 'decided_at' => now()]);
        $this->assertFalse($g['evaluation']->isCurrent());
        $g['record']->update(['verification_status' => 'verified', 'verified_by' => $g['actor']->id, 'verified_at' => now()->addMinute()]);
        $this->assertFalse($g['evaluation']->isCurrent());
        $run = EvaluationRun::create(array_replace($g['evaluation']->only($g['evaluation']->getFillable()), ['run_number' => 2, 'completed_at' => now()->addMinutes(2), 'decided_at' => now()->addMinutes(2)]));
        $this->assertTrue($run->isCurrent());
        EvaluationRun::create(array_replace($run->only($run->getFillable()), ['run_number' => 3, 'completed_at' => null, 'decided_at' => null, 'school_decision' => 'pending']));
        $this->assertFalse($run->isCurrent());
        $this->assertNull($g['application']->currentSchoolDecision());
    }

    public function test_enrollment_corrections_and_same_second_verifications_cannot_reuse_a_decision(): void
    {
        $this->travelTo(now()->startOfSecond());
        $g = $this->applicationContext();
        $this->actingAs($this->staff());
        $g['evaluation']->update(['input_snapshot' => $this->resolvedContext($g), 'school_decision' => 'qualified', 'completed_at' => now(), 'decided_at' => now()]);
        $g['record']->update(['verification_status' => 'verified', 'verified_by' => auth()->id(), 'verified_at' => now()]);
        $this->assertFalse($g['evaluation']->isCurrent());
        $run = EvaluationRun::create(array_replace($g['evaluation']->only($g['evaluation']->getFillable()), ['run_number' => 2, 'completed_at' => now()->addSecond(), 'decided_at' => now()->addSecond()]));
        $this->assertTrue($run->isCurrent());
        $this->post(route('academic-verifications.correct', $g['record']), $this->updateData(['enrollment_status' => 'not_enrolled', 'correction_reason' => 'Enrollment corrected from Registrar source']))->assertSessionHasNoErrors();
        $this->assertFalse($run->isCurrent());
        $this->assertNull($g['application']->currentSchoolDecision());
        $this->assertSame('qualified', $run->fresh()->school_decision);
    }

    public function test_filters_pagination_and_scholar_history_links(): void
    {
        $record = $this->verifiedRecord();
        foreach (range(2, 17) as $revision) {
            AcademicRecord::create(['student_id' => $record->student_id, 'academic_period_id' => $record->academic_period_id, 'revision' => $revision]);
        }
        $this->get(route('academic-verifications.index', ['student_id' => $record->student_id, 'academic_period_id' => $record->academic_period_id]))->assertOk()->assertSee('page=2', false)->assertSee('student_id='.$record->student_id, false);
        $this->get(route('academic-verifications.index', ['verification_status' => 'verified']))->assertViewHas('records', fn ($records) => $records->total() === 1 && $records->first()->id === $record->id);
        $this->get(route('scholars.show', $record->student))->assertSee('View academic history')->assertSee('Add academic record');
    }
}
