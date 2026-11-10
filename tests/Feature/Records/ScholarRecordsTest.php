<?php

namespace Tests\Feature\Records;

use App\Actions\Records\RecordAudit;
use App\Models\AcademicPeriod;
use App\Models\AuditEvent;
use App\Models\BillingItem;
use App\Models\EvaluationRun;
use App\Models\ExternalApproval;
use App\Models\ScholarshipAssignment;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ScholarRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role = 'administrator'): User
    {
        $user = User::factory()->create();
        $user->roles()->create(['role' => $role]);

        return $user;
    }

    private function scholarData(array $overrides = []): array
    {
        return array_replace(['student_number' => 'CFC-001', 'first_name' => 'Ana', 'middle_name' => 'Dela', 'last_name' => 'Cruz', 'email' => 'ana@example.test', 'phone' => null, 'record_status' => 'active'], $overrides);
    }

    private function fixtures(): array
    {
        $scholar = Student::create($this->scholarData());
        $program = ScholarshipProgram::create(['code' => 'TDP', 'name' => 'TDP', 'is_active' => true]);
        $period = AcademicPeriod::create(['academic_year' => '2026-2027', 'semester_code' => '1', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-31']);

        return compact('scholar', 'program', 'period');
    }

    private function assignmentData(array $g, array $overrides = []): array
    {
        return array_replace(['program_id' => $g['program']->id, 'start_period_id' => $g['period']->id, 'status' => 'active', 'starts_on' => '2026-08-01', 'status_reason' => 'Recorded from the school register'], $overrides);
    }

    private function periodData(array $overrides = []): array
    {
        return array_replace(['academic_year' => '2027-2028', 'semester_code' => '1', 'starts_on' => null, 'ends_on' => null, 'is_open' => '0'], $overrides);
    }

    private function programData(array $overrides = []): array
    {
        return array_replace(['code' => 'TES', 'name' => 'TES', 'school_stated_semester_amount' => null, 'planning_duration_years' => 4, 'source_notes' => null, 'is_active' => '1'], $overrides);
    }

    public function test_guests_are_redirected_and_accounts_without_staff_roles_are_denied(): void
    {
        $g = $this->fixtures();
        foreach (['scholars.index', 'scholars.create', 'scholarship-programs.index', 'academic-periods.index'] as $route) {
            $this->get(route($route))->assertRedirect(route('login'));
        }
        $this->post(route('scholars.store'), $this->scholarData())->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create());
        foreach (['scholars.index', 'scholars.create', 'scholarship-programs.index', 'academic-periods.index'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->get(route('scholars.show', $g['scholar']))->assertForbidden();
        $this->post(route('scholars.assignments.store', $g['scholar']), $this->assignmentData($g))->assertForbidden();
    }

    public static function roles(): array
    {
        return [['administrator', true, true, true], ['coordinator', true, false, true], ['registrar', true, false, false], ['finance', false, false, false], ['viewer', false, false, false]];
    }

    #[DataProvider('roles')]
    public function test_role_permissions_apply_to_pages_and_direct_posts(string $role, bool $scholarWrite, bool $catalogWrite, bool $assignmentWrite): void
    {
        $g = $this->fixtures();
        $this->actingAs($this->staff($role));
        foreach (['scholars.index', 'scholarship-programs.index', 'academic-periods.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('scholars.show', $g['scholar']))->assertOk();
        $this->get(route('scholarship-programs.show', $g['program']))->assertOk();
        $this->get(route('academic-periods.show', $g['period']))->assertOk();
        $this->get(route('scholars.create'))->assertStatus($scholarWrite ? 200 : 403);
        $this->get(route('scholarship-programs.create'))->assertStatus($catalogWrite ? 200 : 403);
        $this->get(route('academic-periods.create'))->assertStatus($catalogWrite ? 200 : 403);
        $this->get(route('scholars.assignments.create', $g['scholar']))->assertStatus($assignmentWrite ? 200 : 403);
        $this->post(route('scholars.store'), $this->scholarData(['student_number' => 'CFC-002']))->assertStatus($scholarWrite ? 302 : 403);
        $this->post(route('scholarship-programs.store'), $this->programData())->assertStatus($catalogWrite ? 302 : 403);
        $this->post(route('academic-periods.store'), $this->periodData())->assertStatus($catalogWrite ? 302 : 403);
        $this->post(route('scholars.assignments.store', $g['scholar']), $this->assignmentData($g))->assertStatus($assignmentWrite ? 302 : 403);
        $this->assertSame($scholarWrite ? 2 : 1, Student::count());
        $this->assertSame($catalogWrite ? 2 : 1, ScholarshipProgram::count());
        $this->assertSame($catalogWrite ? 2 : 1, AcademicPeriod::count());
        $this->assertSame($assignmentWrite ? 1 : 0, ScholarshipAssignment::count());
    }

    #[DataProvider('roles')]
    public function test_update_permissions_cannot_be_bypassed_by_direct_patch(string $role, bool $scholarWrite, bool $catalogWrite, bool $assignmentWrite): void
    {
        $g = $this->fixtures();
        $assignment = ScholarshipAssignment::create(['student_id' => $g['scholar']->id, 'program_id' => $g['program']->id, 'start_period_id' => $g['period']->id]);
        $this->actingAs($this->staff($role));
        $this->patch(route('scholars.update', $g['scholar']), $this->scholarData(['first_name' => 'Updated']))->assertStatus($scholarWrite ? 302 : 403);
        $this->patch(route('scholarship-programs.update', $g['program']), $this->programData(['code' => 'TDP', 'name' => 'Updated TDP']))->assertStatus($catalogWrite ? 302 : 403);
        $this->patch(route('academic-periods.update', $g['period']), $this->periodData(['academic_year' => '2026-2027', 'is_open' => '1']))->assertStatus($catalogWrite ? 302 : 403);
        $this->patch(route('scholars.assignments.update', [$g['scholar'], $assignment]), $this->assignmentData($g))->assertStatus($assignmentWrite ? 302 : 403);
        $this->assertSame($scholarWrite ? 'Updated' : 'Ana', $g['scholar']->fresh()->first_name);
        $this->assertSame($assignmentWrite ? 'active' : 'pending', $assignment->fresh()->status);
    }

    public function test_scholar_creation_and_editing_validate_and_audit_without_mass_assigning_accounts_or_roles(): void
    {
        $actor = $this->staff('registrar');
        $this->actingAs($actor)->post(route('scholars.store'), $this->scholarData(['student_number' => ' cfc-010 ', 'email' => 'ANA@EXAMPLE.TEST', 'user_id' => $actor->id, 'roles' => ['administrator']]))->assertRedirect();
        $scholar = Student::firstOrFail();
        $this->assertSame('CFC-010', $scholar->student_number);
        $this->assertSame('ana@example.test', $scholar->email);
        $this->assertNull($scholar->user_id);
        $this->assertSame(['registrar'], $actor->roles()->pluck('role')->all());
        $this->get(route('scholars.edit', $scholar))->assertOk();
        $this->patch(route('scholars.update', $scholar), $this->scholarData(['student_number' => 'CFC-010', 'phone' => '+63 900 123 4567']))->assertSessionHasNoErrors()->assertRedirect(route('scholars.show', $scholar));
        $this->assertSame('+63 900 123 4567', $scholar->fresh()->phone);
        $this->assertSame(2, AuditEvent::where('subject_type', Student::class)->where('subject_id', $scholar->id)->count());
    }

    public function test_duplicate_student_numbers_and_invalid_scholar_fields_are_rejected(): void
    {
        $this->fixtures();
        $this->actingAs($this->staff());
        $this->post(route('scholars.store'), $this->scholarData(['student_number' => ' cfc-001 ']))->assertSessionHasErrors('student_number');
        $this->post(route('scholars.store'), $this->scholarData(['student_number' => ['invalid'], 'first_name' => '', 'email' => 'bad', 'record_status' => 'qualified']))->assertSessionHasErrors(['student_number', 'first_name', 'email', 'record_status']);
        $this->assertSame(1, Student::count());
    }

    public function test_search_handles_full_names_student_numbers_email_and_literal_wildcards(): void
    {
        $g = $this->fixtures();
        Student::create($this->scholarData(['student_number' => 'CFC-002', 'first_name' => 'Other', 'last_name' => 'Scholar', 'email' => 'other@example.test']));
        $this->actingAs($this->staff('viewer'));
        foreach (['Ana Cruz', 'CFC-001', 'ana@example.test'] as $term) {
            $this->get(route('scholars.index', ['q' => $term]))->assertOk()->assertSeeText($g['scholar']->full_name)->assertDontSeeText('Other Dela Scholar');
        }
        $this->get(route('scholars.index', ['q' => '%']))->assertOk()->assertSeeText('No scholars found');
    }

    public function test_scholar_filters_and_pagination_preserve_query_parameters(): void
    {
        $g = $this->fixtures();
        $g['scholar']->update(['record_status' => 'archived']);
        for ($i = 2; $i <= 18; $i++) {
            Student::create($this->scholarData(['student_number' => 'CFC-'.$i, 'first_name' => 'Group', 'last_name' => sprintf('Scholar%02d', $i)]));
        }
        $this->actingAs($this->staff('viewer'));
        $this->get(route('scholars.index', ['q' => 'Group', 'record_status' => 'active']))->assertOk()->assertViewHas('scholars', fn ($rows) => $rows->count() === 15 && $rows->total() === 17)->assertSee('q=Group', false);
        $this->get(route('scholars.index', ['q' => 'Group', 'record_status' => 'active', 'page' => 2]))->assertOk()->assertViewHas('scholars', fn ($rows) => $rows->count() === 2);
        $this->get(route('scholars.index', ['record_status' => 'archived']))->assertOk()->assertSeeText('Ana Dela Cruz')->assertDontSeeText('Group Dela');
    }

    public function test_programs_support_the_three_codes_and_keep_unknown_edukar_amount_null(): void
    {
        $this->actingAs($this->staff());
        foreach (['TDP' => '7500.00', 'TES' => '13500.00', 'EDUKAR' => null] as $code => $amount) {
            $this->post(route('scholarship-programs.store'), $this->programData(['code' => $code, 'name' => $code, 'school_stated_semester_amount' => $amount, 'planning_duration_years' => $code === 'EDUKAR' ? 5 : 4]))->assertSessionHasNoErrors()->assertRedirect();
        }
        $edukar = ScholarshipProgram::where('code', 'EDUKAR')->firstOrFail();
        $this->assertNull($edukar->school_stated_semester_amount);
        $this->get(route('scholarship-programs.show', $edukar))->assertOk()->assertSeeText('Unconfirmed')->assertSeeText('5 years');
        $this->post(route('scholarship-programs.store'), $this->programData(['code' => 'tes']))->assertSessionHasErrors('code');
        $this->post(route('scholarship-programs.store'), $this->programData(['code' => 'UNKNOWN']))->assertSessionHasErrors('code');
        $this->assertSame(3, ScholarshipProgram::count());
    }

    public function test_program_updates_preserve_identity_and_require_a_source_for_amount_changes(): void
    {
        $g = $this->fixtures();
        $this->actingAs($this->staff());
        $this->get(route('scholarship-programs.edit', $g['program']))->assertOk();
        $this->patch(route('scholarship-programs.update', $g['program']), $this->programData(['code' => 'TES']))->assertSessionHasErrors('code');
        $this->patch(route('scholarship-programs.update', $g['program']), $this->programData(['code' => 'TDP', 'school_stated_semester_amount' => '7500.00']))->assertSessionHasErrors('source_notes');
        $this->patch(route('scholarship-programs.update', $g['program']), $this->programData(['code' => 'TDP', 'school_stated_semester_amount' => '7500.00', 'source_notes' => 'School-stated reference']))->assertSessionHasNoErrors();
        $this->patch(route('scholarship-programs.update', $g['program']), $this->programData(['code' => 'TDP', 'school_stated_semester_amount' => '7500', 'source_notes' => null]))->assertSessionHasNoErrors();
        $this->assertSame('7500.00', $g['program']->fresh()->school_stated_semester_amount);
        $this->post(route('scholarship-programs.store'), $this->programData(['school_stated_semester_amount' => '-1']))->assertSessionHasErrors('school_stated_semester_amount');
        $this->post(route('scholarship-programs.store'), $this->programData(['school_stated_semester_amount' => '1.001']))->assertSessionHasErrors('school_stated_semester_amount');
    }

    public function test_academic_years_and_semesters_validate_duplicate_pairs_and_date_ranges(): void
    {
        $this->actingAs($this->staff());
        $this->post(route('academic-periods.store'), $this->periodData())->assertSessionHasNoErrors();
        $period = AcademicPeriod::firstOrFail();
        $this->post(route('academic-periods.store'), $this->periodData())->assertSessionHasErrors('semester_code');
        $this->post(route('academic-periods.store'), $this->periodData(['academic_year' => '2028-2029']))->assertSessionHasNoErrors();
        $this->post(route('academic-periods.store'), $this->periodData(['semester_code' => '2', 'starts_on' => '2027-12-01', 'ends_on' => '2027-11-01']))->assertSessionHasErrors('ends_on');
        $this->get(route('academic-periods.edit', $period))->assertOk();
        $this->patch(route('academic-periods.update', $period), $this->periodData(['is_open' => 1]))->assertSessionHasNoErrors();
        $this->assertTrue($period->fresh()->is_open);
        $this->get(route('academic-periods.index', ['academic_year' => '2027-2028']))->assertOk()->assertViewHas('periods', fn ($rows) => $rows->total() === 1);
    }

    public function test_used_period_identity_is_retained_while_dates_and_open_status_can_be_maintained(): void
    {
        $g = $this->fixtures();
        ScholarshipAssignment::create(['student_id' => $g['scholar']->id, 'program_id' => $g['program']->id, 'start_period_id' => $g['period']->id]);
        $this->actingAs($this->staff());
        $this->patch(route('academic-periods.update', $g['period']), $this->periodData(['academic_year' => '2030-2031']))->assertSessionHasErrors('academic_year');
        $this->assertSame('2026-2027', $g['period']->fresh()->academic_year);
        $this->patch(route('academic-periods.update', $g['period']), $this->periodData(['academic_year' => '2026-2027', 'starts_on' => '2026-08-02', 'ends_on' => '2026-12-31', 'is_open' => 1]))->assertSessionHasNoErrors();
        $this->assertSame('2026-08-02', $g['period']->fresh()->starts_on->toDateString());
    }

    public function test_assignment_creation_is_audited_and_does_not_create_evaluations_bills_or_external_approval(): void
    {
        $g = $this->fixtures();
        $actor = $this->staff('coordinator');
        $this->actingAs($actor)->post(route('scholars.assignments.store', $g['scholar']), $this->assignmentData($g) + ['activated_by' => 9999, 'student_id' => 9999])->assertSessionHasNoErrors()->assertRedirect(route('scholars.show', $g['scholar']));
        $assignment = ScholarshipAssignment::firstOrFail();
        $this->assertSame($g['scholar']->id, $assignment->student_id);
        $this->assertSame($actor->id, $assignment->activated_by);
        $this->assertSame(1, AuditEvent::where('action', 'scholarship_assigned')->count());
        $this->assertSame(0, EvaluationRun::count());
        $this->assertSame(0, ExternalApproval::count());
        $this->assertSame(0, BillingItem::count());
        $this->get(route('scholars.show', $g['scholar']))->assertOk()->assertSeeText('Scholarship history')->assertSeeText('Active')->assertSeeText('TDP');
        $this->get(route('scholars.index', ['program_id' => $g['program']->id]))->assertOk()->assertViewHas('scholars', fn ($rows) => $rows->total() === 1);
    }

    public function test_a_second_active_program_is_rejected_with_a_field_error_and_no_extra_history(): void
    {
        $g = $this->fixtures();
        $this->actingAs($this->staff('coordinator'));
        $this->post(route('scholars.assignments.store', $g['scholar']), $this->assignmentData($g))->assertSessionHasNoErrors();
        $tes = ScholarshipProgram::create(['code' => 'TES', 'name' => 'TES']);
        $this->post(route('scholars.assignments.store', $g['scholar']), $this->assignmentData($g, ['program_id' => $tes->id]))->assertSessionHasErrors('status');
        $this->assertSame(1, ScholarshipAssignment::count());
        $this->assertSame(1, AuditEvent::where('subject_type', ScholarshipAssignment::class)->count());
    }

    public function test_pending_duplicates_are_prevented_and_pending_assignments_can_be_activated(): void
    {
        $g = $this->fixtures();
        $this->actingAs($this->staff('coordinator'));
        $pending = $this->assignmentData($g, ['status' => 'pending']);
        $this->post(route('scholars.assignments.store', $g['scholar']), $pending)->assertSessionHasNoErrors();
        $this->post(route('scholars.assignments.store', $g['scholar']), $pending)->assertSessionHasErrors('program_id');
        $assignment = ScholarshipAssignment::firstOrFail();
        $this->get(route('scholars.assignments.edit', [$g['scholar'], $assignment]))->assertOk();
        $this->patch(route('scholars.assignments.update', [$g['scholar'], $assignment]), $this->assignmentData($g))->assertSessionHasNoErrors();
        $this->assertSame('active', $assignment->fresh()->status);
        $this->patch(route('scholars.assignments.update', [$g['scholar'], $assignment]), $this->assignmentData($g, ['status' => 'pending']))->assertSessionHasErrors('status');
        $this->assertSame('active', $assignment->fresh()->status);
    }

    public function test_relinquishing_retains_history_and_allows_a_new_program_without_reopening_the_old_assignment(): void
    {
        $g = $this->fixtures();
        $this->actingAs($this->staff('coordinator'));
        $this->post(route('scholars.assignments.store', $g['scholar']), $this->assignmentData($g))->assertSessionHasNoErrors();
        $old = ScholarshipAssignment::firstOrFail();
        $closed = $this->assignmentData($g, ['status' => 'relinquished', 'end_period_id' => $g['period']->id, 'ends_on' => '2026-11-11', 'status_reason' => 'Scholar relinquished TDP by written request']);
        $this->patch(route('scholars.assignments.update', [$g['scholar'], $old]), $closed)->assertSessionHasNoErrors();
        $this->assertSame('ended', $old->fresh()->status);
        $this->assertSame('relinquished', $old->fresh()->closure_reason);
        $tes = ScholarshipProgram::create(['code' => 'TES', 'name' => 'TES']);
        $this->post(route('scholars.assignments.store', $g['scholar']), $this->assignmentData($g, ['program_id' => $tes->id]))->assertSessionHasNoErrors();
        $this->assertSame(2, $g['scholar']->assignments()->count());
        $this->assertSame(1, $g['scholar']->assignments()->where('status', 'active')->count());
        $this->get(route('scholars.show', $g['scholar']))->assertOk()->assertSeeText('Relinquished')->assertSeeText('TDP')->assertSeeText('TES')->assertSeeText('Scholar relinquished TDP by written request');
        $this->patch(route('scholars.assignments.update', [$g['scholar'], $old]), $this->assignmentData($g))->assertForbidden();
        $this->assertSame(3, AuditEvent::where('subject_type', ScholarshipAssignment::class)->count());
    }

    public function test_closed_assignments_cannot_be_changed_or_deleted_even_by_raw_database_writes(): void
    {
        $g = $this->fixtures();
        $closed = ScholarshipAssignment::create(['student_id' => $g['scholar']->id, 'program_id' => $g['program']->id, 'status' => 'ended', 'closure_reason' => 'relinquished']);
        foreach (['update', 'delete'] as $operation) {
            try {
                $query = DB::table('scholarship_assignments')->where('id', $closed->id);
                $operation === 'update' ? $query->update(['status' => 'active']) : $query->delete();
                $this->fail('Closed history must be immutable.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('Closed scholarship history is immutable', $error->getMessage());
            }
        }
    }

    public function test_nested_assignments_cannot_be_read_or_changed_through_another_scholar(): void
    {
        $g = $this->fixtures();
        $assignment = ScholarshipAssignment::create(['student_id' => $g['scholar']->id, 'program_id' => $g['program']->id, 'start_period_id' => $g['period']->id]);
        $other = Student::create($this->scholarData(['student_number' => 'OTHER']));
        $this->actingAs($this->staff());
        $this->get(route('scholars.assignments.edit', [$other, $assignment]))->assertNotFound();
        $this->patch(route('scholars.assignments.update', [$other, $assignment]), $this->assignmentData($g))->assertNotFound();
        $this->assertSame('pending', $assignment->fresh()->status);
    }

    public function test_original_assignment_program_and_semester_cannot_be_rewritten(): void
    {
        $g = $this->fixtures();
        $assignment = ScholarshipAssignment::create(['student_id' => $g['scholar']->id, 'program_id' => $g['program']->id, 'start_period_id' => $g['period']->id]);
        $other = ScholarshipProgram::create(['code' => 'TES', 'name' => 'TES']);
        $period = AcademicPeriod::create($this->periodData());
        $this->actingAs($this->staff());
        $this->patch(route('scholars.assignments.update', [$g['scholar'], $assignment]), $this->assignmentData($g, ['program_id' => $other->id, 'start_period_id' => $period->id]))->assertSessionHasErrors(['program_id', 'start_period_id']);
        $this->assertSame($g['program']->id, $assignment->fresh()->program_id);
    }

    public function test_archived_scholars_and_unavailable_programs_cannot_receive_new_active_assignments(): void
    {
        $g = $this->fixtures();
        $this->actingAs($this->staff());
        $g['scholar']->update(['record_status' => 'archived']);
        $this->post(route('scholars.assignments.store', $g['scholar']), $this->assignmentData($g))->assertSessionHasErrors('program_id');
        $g['scholar']->update(['record_status' => 'active']);
        $g['program']->update(['is_active' => false]);
        $this->post(route('scholars.assignments.store', $g['scholar']), $this->assignmentData($g))->assertSessionHasErrors('program_id');
        $this->assertSame(0, ScholarshipAssignment::count());
    }

    public function test_scholar_archiving_does_not_silently_end_an_active_scholarship(): void
    {
        $g = $this->fixtures();
        $assignment = ScholarshipAssignment::create(['student_id' => $g['scholar']->id, 'program_id' => $g['program']->id, 'status' => 'active']);
        $this->actingAs($this->staff())->patch(route('scholars.update', $g['scholar']), $this->scholarData(['record_status' => 'archived']))->assertSessionHasErrors('record_status');
        $this->assertSame('active', $g['scholar']->fresh()->record_status);
        $this->assertSame('active', $assignment->fresh()->status);
    }

    public function test_historical_assignments_allow_unknown_exact_dates_but_require_a_closing_semester_and_reason(): void
    {
        $g = $this->fixtures();
        $this->actingAs($this->staff());
        $this->post(route('scholars.assignments.store', $g['scholar']), $this->assignmentData($g, ['status' => 'relinquished', 'status_reason' => null]))->assertSessionHasErrors(['end_period_id', 'status_reason']);
        $this->post(route('scholars.assignments.store', $g['scholar']), $this->assignmentData($g, ['status' => 'ended', 'start_period_id' => 9999]))->assertSessionHasErrors('start_period_id');
        $this->post(route('scholars.assignments.store', $g['scholar']), $this->assignmentData($g, ['status' => 'ended', 'end_period_id' => $g['period']->id, 'starts_on' => null]))->assertSessionHasNoErrors();
        $this->assertNull(ScholarshipAssignment::firstOrFail()->starts_on);
    }

    public function test_assignment_and_audit_write_are_atomic(): void
    {
        $g = $this->fixtures();
        $this->actingAs($this->staff());
        $this->mock(RecordAudit::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Simulated history storage failure'));
        $this->withoutExceptionHandling();
        try {
            $this->post(route('scholars.assignments.store', $g['scholar']), $this->assignmentData($g));
            $this->fail('The simulated audit failure must abort the write.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated history storage failure', $error->getMessage());
        }
        $this->assertSame(0, ScholarshipAssignment::count());
    }

    public function test_record_content_is_escaped_and_delete_routes_are_not_exposed(): void
    {
        $g = $this->fixtures();
        $g['scholar']->update(['first_name' => '<script>alert(1)</script>']);
        $this->actingAs($this->staff())->get(route('scholars.show', $g['scholar']))->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $this->delete(route('scholars.show', $g['scholar']))->assertStatus(405);
        $this->assertSame(1, Student::count());
    }

    public function test_role_grants_are_explicit_idempotent_and_do_not_change_credentials(): void
    {
        $user = User::factory()->create();
        $hash = $user->password;
        $this->artisan('sms:grant-role', ['email' => $user->email, 'role' => 'administrator'])->assertSuccessful();
        $this->artisan('sms:grant-role', ['email' => $user->email, 'role' => 'administrator'])->assertSuccessful();
        $this->artisan('sms:grant-role', ['email' => $user->email, 'role' => 'invalid'])->assertFailed();
        $this->artisan('sms:grant-role', ['email' => 'missing@example.test', 'role' => 'administrator'])->assertFailed();
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertSame(1, $user->roles()->count());
        $this->assertSame(1, AuditEvent::where('action', 'staff_role_granted')->count());
    }

    public function test_linked_account_deletion_returns_a_validation_error_and_keeps_the_session(): void
    {
        $actor = $this->staff();
        Student::create($this->scholarData(['user_id' => $actor->id]));
        $this->actingAs($actor)->from(route('profile.edit'))->delete(route('profile.destroy'), ['password' => 'password'])->assertSessionHasErrorsIn('userDeletion', 'password')->assertRedirect(route('profile.edit'));
        $this->assertAuthenticatedAs($actor);
        $this->assertNotNull($actor->fresh());
    }
}
