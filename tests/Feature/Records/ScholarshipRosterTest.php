<?php

namespace Tests\Feature\Records;

use App\Actions\Records\ManageScholarshipRoster;
use App\Actions\Records\RecordAudit;
use App\Models\AcademicPeriod;
use App\Models\AcademicRecord;
use App\Models\BillingBatch;
use App\Models\ScholarshipAssignment;
use App\Models\ScholarshipBatchMembership;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ScholarshipRosterTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $actor = User::factory()->create();
        $actor->roles()->create(['role' => 'coordinator']);
        $this->actingAs($actor);
        $program = ScholarshipProgram::create(['code' => 'TEST', 'name' => 'Synthetic roster program']);
        $period = AcademicPeriod::create(['academic_year' => '2026-2027', 'semester_code' => '1']);
        $student = Student::create(['student_number' => 'ROSTER-001', 'first_name' => 'Roster', 'last_name' => 'Scholar']);
        $assignment = ScholarshipAssignment::create(['student_id' => $student->id, 'program_id' => $program->id, 'start_period_id' => $period->id, 'status' => 'pending']);
        $batch = app(ManageScholarshipRoster::class)->create($actor, ['program_id' => $program->id, 'academic_period_id' => $period->id, 'batch_number' => 'Batch 1']);

        return compact('actor', 'program', 'period', 'student', 'assignment', 'batch');
    }

    private function join(array $g, array $overrides = [])
    {
        return $this->post(route('scholars.batch-memberships.store', $g['student']), array_replace(['scholarship_batch_id' => $g['batch']->id, 'assignment_id' => $g['assignment']->id], $overrides));
    }

    public function test_pending_scholar_joins_without_rate_enrollment_or_financial_records_and_duplicate_is_rejected(): void
    {
        $g = $this->fixture();
        $before = $g['student']->fresh()->getAttributes();
        $this->join($g, ['assigned_by' => 999, 'program_id' => 999])->assertSessionHasNoErrors();
        $this->join($g)->assertSessionHasErrors('scholarship_batch_id');
        $member = ScholarshipBatchMembership::sole();
        $this->assertSame($g['actor']->id, $member->assigned_by);
        $this->assertSame($before, $g['student']->fresh()->getAttributes());
        $this->assertSame('pending', $g['assignment']->fresh()->status);
        foreach (['billing_items', 'billing_batches', 'funding_receipts', 'payouts', 'scholarship_rates', 'academic_records'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->get(route('scholars.show', $g['student']))->assertOk()->assertSee('Semester batch memberships')->assertSee('Batch 1');
        $this->get(route('scholarship-batches.show', $g['batch']))->assertOk()->assertSee('Awaiting Registrar verification');
    }

    public static function invalidAssignments(): array
    {
        return [['student'], ['program'], ['future_period'], ['closed']];
    }

    #[DataProvider('invalidAssignments')]
    public function test_wrong_student_program_period_and_closed_assignment_cannot_join(string $kind): void
    {
        $g = $this->fixture();
        match ($kind) {
            'student' => $g['assignment']->update(['student_id' => Student::create(['student_number' => 'OTHER', 'first_name' => 'Other', 'last_name' => 'Scholar'])->id]),
            'program' => $g['assignment']->update(['program_id' => ScholarshipProgram::create(['code' => 'OTHER', 'name' => 'Other'])->id]),
            'future_period' => $g['assignment']->update(['start_period_id' => AcademicPeriod::create(['academic_year' => '2027-2028', 'semester_code' => '1'])->id]),
            'closed' => $g['assignment']->update(['status' => 'ended']),
        };
        $this->join($g)->assertSessionHasErrors('assignment_id');
        $this->assertDatabaseCount('scholarship_batch_memberships', 0);
    }

    public function test_database_rejects_duplicate_wrong_scope_and_mutation_of_retained_membership(): void
    {
        $g = $this->fixture();
        $this->join($g)->assertSessionHasNoErrors();
        $member = ScholarshipBatchMembership::sole();
        $data = $member->only($member->getFillable());
        $this->rejects(fn () => ScholarshipBatchMembership::create($data));
        $other = Student::create(['student_number' => 'OTHER', 'first_name' => 'Other', 'last_name' => 'Scholar']);
        $this->rejects(fn () => ScholarshipBatchMembership::create(array_replace($data, ['student_id' => $other->id])));
        $period = AcademicPeriod::create(['academic_year' => '2026-2027', 'semester_code' => '2']);
        $this->rejects(fn () => ScholarshipBatchMembership::create(array_replace($data, ['academic_period_id' => $period->id, 'student_id' => $other->id])));
        $empty = app(ManageScholarshipRoster::class)->create($g['actor'], ['program_id' => $g['program']->id, 'academic_period_id' => $period->id, 'batch_number' => 'Different semester']);
        $this->rejects(fn () => ScholarshipBatchMembership::create(array_replace($data, ['scholarship_batch_id' => $empty->id])));
        $this->rejects(fn () => DB::table('scholarship_batch_memberships')->where('id', $member->id)->update(['assigned_by' => $g['actor']->id]));
        $this->rejects(fn () => $member->delete());
        $this->assertDatabaseCount('scholarship_batch_memberships', 1);
    }

    public function test_one_roster_supports_multiple_separate_billing_headers_and_rejects_mismatched_scope(): void
    {
        $g = $this->fixture();
        $terms = $g['program']->terms()->create(['academic_period_id' => $g['period']->id]);
        foreach (['Submission A', 'Submission B'] as $number) {
            $bill = BillingBatch::create(['program_period_term_id' => $terms->id, 'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'batch_number' => $number]);
            $bill->forceFill(['scholarship_batch_id' => $g['batch']->id])->save();
        }
        $this->assertSame(2, $g['batch']->billingBatches()->count());
        $this->assertDatabaseCount('billing_items', 0);
        $other = app(ManageScholarshipRoster::class)->create($g['actor'], ['program_id' => ScholarshipProgram::create(['code' => 'OTHER', 'name' => 'Other'])->id, 'academic_period_id' => $g['period']->id, 'batch_number' => 'Other']);
        $this->rejects(fn () => DB::table('billing_batches')->where('id', $bill->id)->update(['scholarship_batch_id' => $other->id]));
    }

    public function test_roster_creation_is_scoped_unique_and_preserves_period_identity(): void
    {
        $g = $this->fixture();
        $data = ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'batch_number' => ' Batch 1 '];
        $this->post(route('scholarship-batches.store'), $data)->assertSessionHasErrors('batch_number');
        $data['batch_number'] = 'Batch 2';
        $this->post(route('scholarship-batches.store'), $data)->assertSessionHasNoErrors();
        $this->assertTrue($g['period']->isInUse());
        $this->rejects(fn () => $g['batch']->update(['batch_number' => 'Rewritten']));
    }

    public function test_rollback_refuses_to_drop_populated_roster_history(): void
    {
        $g = $this->fixture();
        $this->join($g)->assertSessionHasNoErrors();
        $migration = require database_path('migrations/2026_11_13_000009_add_independent_scholarship_rosters.php');
        try {
            $migration->down();
            $this->fail('Populated roster rollback must be blocked');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Retain recorded roster history', $error->getMessage());
        }
        $this->assertDatabaseCount('scholarship_batch_memberships', 1);
        $this->assertDatabaseCount('scholarship_batches', 1);
    }

    public function test_queue_uses_latest_exact_period_revision_and_retains_verified_non_enrolled_members(): void
    {
        $g = $this->fixture();
        $this->join($g)->assertSessionHasNoErrors();
        $url = route('scholarship-batches.show', [$g['batch'], 'view' => 'awaiting_verification']);
        $this->get($url)->assertOk()->assertViewHas('members', fn ($m) => $m->total() === 1);
        $wrong = AcademicPeriod::create(['academic_year' => '2025-2026', 'semester_code' => '1']);
        AcademicRecord::create(['student_id' => $g['student']->id, 'academic_period_id' => $wrong->id, 'revision' => 1, 'verification_status' => 'verified', 'verified_by' => $g['actor']->id, 'verified_at' => now(), 'enrollment_status' => 'enrolled']);
        $this->get($url)->assertViewHas('members', fn ($m) => $m->total() === 1 && $m->first()->enrollmentRecord === null);
        $record = AcademicRecord::create(['student_id' => $g['student']->id, 'academic_period_id' => $g['period']->id, 'revision' => 1, 'verification_status' => 'verified', 'verified_by' => $g['actor']->id, 'verified_at' => now(), 'enrollment_status' => 'not_enrolled']);
        $this->get($url)->assertViewHas('members', fn ($m) => $m->total() === 0);
        $this->get(route('scholarship-batches.show', $g['batch']))->assertSee('Verified not enrolled')->assertViewHas('members', fn ($m) => $m->first()->enrollmentRecord->id === $record->id);
        $newer = AcademicRecord::create(['student_id' => $g['student']->id, 'academic_period_id' => $g['period']->id, 'revision' => 2, 'supersedes_id' => $record->id]);
        $this->get($url)->assertViewHas('members', fn ($m) => $m->total() === 1 && $m->first()->enrollmentRecord->id === $newer->id);
        $this->assertDatabaseCount('scholarship_batch_memberships', 1);
        $this->assertDatabaseCount('billing_items', 0);
    }

    public function test_verified_standing_with_unknown_enrollment_stays_pending_and_link_prefills_exact_period(): void
    {
        $g = $this->fixture();
        $this->join($g)->assertSessionHasNoErrors();
        AcademicRecord::create(['student_id' => $g['student']->id, 'academic_period_id' => $g['period']->id, 'revision' => 1, 'verification_status' => 'verified', 'verified_by' => $g['actor']->id, 'verified_at' => now(), 'academic_standing' => 'passing', 'enrollment_status' => 'unverified']);
        $registrar = User::factory()->create();
        $registrar->roles()->create(['role' => 'registrar']);
        $this->actingAs($registrar)->get(route('scholarship-batches.show', [$g['batch'], 'view' => 'awaiting_verification']))->assertOk()->assertViewHas('members', fn ($m) => $m->total() === 1)->assertSee('Awaiting Registrar verification')->assertDontSee('Verified enrolled');
        $this->get(route('academic-verifications.create', ['student_id' => $g['student']->id, 'academic_period_id' => $g['period']->id]))->assertViewHas('record', fn ($r) => (int) $r->academic_period_id === $g['period']->id);
    }

    public static function roles(): array
    {
        return [['coordinator', true, true], ['registrar', true, false], ['administrator', true, true], ['finance', false, false], ['viewer', false, false], ['unknown', false, false]];
    }

    #[DataProvider('roles')]
    public function test_roster_read_write_authority_is_enforced_on_direct_requests(string $role, bool $read, bool $write): void
    {
        $g = $this->fixture();
        $user = User::factory()->create();
        $user->roles()->create(['role' => $role]);
        $this->actingAs($user)->get(route('scholarship-batches.index'))->assertStatus($read ? 200 : 403);
        $this->get(route('scholarship-batches.show', $g['batch']))->assertStatus($read ? 200 : 403);
        $this->join($g)->assertStatus($write ? 302 : 403);
        $this->assertDatabaseCount('scholarship_batch_memberships', $write ? 1 : 0);
    }

    public function test_audit_failure_rolls_back_membership(): void
    {
        $g = $this->fixture();
        $this->mock(RecordAudit::class)->shouldReceive('record')->andThrow(new RuntimeException('Synthetic audit failure'));
        try {
            app(ManageScholarshipRoster::class)->join($g['student'], $g['batch'], $g['actor'], $g['assignment']->id);
            $this->fail('Expected audit failure');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic audit failure', $error->getMessage());
        }
        $this->assertDatabaseCount('scholarship_batch_memberships', 0);
    }

    private function rejects(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('Database must reject the write');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }
}
