<?php

namespace Tests\Feature\Billing;

use App\Actions\Records\RecordAudit;
use App\Models\AcademicPeriod;
use App\Models\BillingBatch;
use App\Models\ScholarshipBatch;
use App\Models\ScholarshipProgram;
use App\Models\User;
use App\Rules\PositiveBatchNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsScholarshipRecords;
use Tests\TestCase;

class ProgramBatchNumberTest extends TestCase
{
    use BuildsScholarshipRecords, RefreshDatabase;

    private function staff(string $role = 'administrator'): User
    {
        $user = User::factory()->create();
        $user->roles()->create(['role' => $role]);

        return $user;
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['code' => 'SAMPLEBN', 'name' => 'Synthetic batch-number program', 'is_active' => 1, 'school_stated_semester_amount' => '7500.00'], $overrides);
    }

    private function period(string $semester = '1'): AcademicPeriod
    {
        return AcademicPeriod::firstOrCreate(['academic_year' => '2026-2027', 'semester_code' => $semester]);
    }

    private function approveRate(ScholarshipProgram $program, AcademicPeriod $period): array
    {
        $scope = ['program_id' => $program->id, 'academic_period_id' => $period->id];
        $this->post(route('billing-batches.terms'), $scope + ['semester_gross_amount' => '7500.00', 'billing_source_reference' => 'SYNTHETIC approved rate fixture; no academic policy inferred', 'confirm_billing_policy' => 1])->assertSessionHasNoErrors();

        $roster = ScholarshipBatch::firstOrCreate($scope + ['batch_number' => 'SYNTHETIC-NUMBERS'], ['created_by' => auth()->id()]);

        return $scope + ['scholarship_batch_id' => $roster->id];
    }

    public static function authorizedRoles(): array
    {
        return [['administrator'], ['coordinator']];
    }

    #[DataProvider('authorizedRoles')]
    public function test_program_only_creation_and_explicit_billing_workflow_keep_correct_relationships(string $role): void
    {
        $this->actingAs($actor = $this->staff($role));
        $period = $this->period();
        $create = route('scholarship-programs.create');
        $this->get($create)->assertOk()->assertDontSee('name="batch_number"', false)->assertSee('<select id="billing_batch_id" name="billing_batch_id"', false)->assertSee('name="academic_period_id"', false)->assertSee('The Batch Number selection provides context only');
        $response = $this->post(route('scholarship-programs.store'), $this->data());
        $program = ScholarshipProgram::sole();
        $response->assertSessionHasNoErrors()->assertRedirect(route('scholarship-programs.show', $program));
        $this->assertArrayNotHasKey('batch_number', $program->getAttributes());
        $this->assertArrayNotHasKey('academic_period_id', $program->getAttributes());
        $this->assertDatabaseCount('billing_batches', 0);
        $this->assertDatabaseCount('program_period_terms', 0);
        $this->assertDatabaseCount('scholarship_rates', 0);
        $scope = ['program_id' => $program->id, 'academic_period_id' => $period->id];
        $billing = route('billing-batches.index', $scope);
        $roster = ScholarshipBatch::create($scope + ['batch_number' => 'SYNTHETIC-NUMBERS', 'created_by' => $actor->id]);
        $scope['scholarship_batch_id'] = $roster->id;
        $this->get($billing)->assertOk()->assertSee('<select id="billing_batch_id" name="billing_batch_id"', false)->assertSee('No matching billing batches');
        $this->from($billing)->post(route('billing-batches.store'), $scope)->assertSessionHasErrors('billing');
        $this->assertDatabaseCount('billing_batches', 0);
        $this->from($billing);
        $this->approveRate($program, $period);
        $this->post(route('billing-batches.store'), $scope)->assertSessionHasNoErrors();
        $batch = BillingBatch::sole();
        $this->assertSame('Batch 1', $batch->batch_number);
        $this->assertSame($program->id, $batch->program_id);
        $this->assertSame($period->id, $batch->academic_period_id);
        $this->assertSame($program->terms()->sole()->id, $batch->program_period_term_id);
        $this->assertSame($program->terms()->sole()->rates()->sole()->id, $batch->scholarship_rate_id);
        $this->assertSame($actor->id, $batch->prepared_by);
        $this->assertSame('draft', $batch->status);
        $this->get(route('billing-batches.show', $batch))->assertOk()->assertSeeText('Batch 1');
        $this->assertDatabaseCount('funding_receipts', 0);
        $this->assertDatabaseCount('payouts', 0);
    }

    public function test_program_edits_cannot_change_batch_scope(): void
    {
        $this->actingAs($this->staff());
        $this->post(route('scholarship-programs.store'), $this->data())->assertSessionHasNoErrors();
        $program = ScholarshipProgram::sole();
        $this->get(route('scholarship-programs.edit', $program))->assertOk()->assertDontSee('name="batch_number"', false);
        $this->patch(route('scholarship-programs.update', $program), $this->data(['batch_number' => 1, 'academic_period_id' => $this->period()->id]))->assertSessionHasErrors('batch_number');
        $this->assertDatabaseCount('billing_batches', 0);
        $this->assertSame($this->data()['name'], $program->fresh()->name);
    }

    public function test_program_forms_reject_invalid_batch_context_without_partial_records(): void
    {
        $this->actingAs($this->staff());
        foreach ([['batch_number' => 1], ['academic_period_id' => 999999], ['billing_batch_id' => 123], ['billing_batch_id' => ['invalid']]] as $fields) {
            $this->postJson(route('scholarship-programs.store'), $this->data($fields))->assertUnprocessable()->assertJsonValidationErrors(array_keys($fields));
        }
        $this->assertDatabaseCount('scholarship_programs', 0);
        $this->assertDatabaseCount('billing_batches', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_manual_and_automatic_numbers_are_unique_per_program_period_and_legacy_aliases_cannot_conflict(): void
    {
        $this->actingAs($this->staff());
        $firstProgram = ScholarshipProgram::create($this->data());
        $secondProgram = ScholarshipProgram::create($this->data(['code' => 'SAMPLEOTHER']));
        $firstScope = $this->approveRate($firstProgram, $this->period());
        $secondScope = $this->approveRate($firstProgram, $this->period('2'));
        $thirdScope = $this->approveRate($secondProgram, $this->period());
        foreach ([$firstScope, $secondScope, $thirdScope] as $scope) {
            $this->post(route('billing-batches.store'), $scope + ['batch_number' => 1])->assertSessionHasNoErrors();
        }
        $this->post(route('billing-batches.store'), $firstScope + ['batch_number' => 1])->assertSessionHasErrors('batch_number');
        $this->assertDatabaseCount('billing_batches', 3);
        $first = BillingBatch::where('program_id', $firstProgram->id)->where('academic_period_id', $firstScope['academic_period_id'])->sole();
        $this->assertDatabaseRejects(fn () => $first->replicate()->save());
        $first->update(['batch_number' => 'Batch 01']); // Retained legacy spelling is not rewritten by a create attempt.
        $this->post(route('billing-batches.store'), $firstScope + ['batch_number' => 1])->assertSessionHasErrors('batch_number');
        $this->post(route('billing-batches.store'), $firstScope)->assertSessionHasNoErrors();
        $next = BillingBatch::latest('id')->firstOrFail();
        $this->assertSame('Batch 2', $next->batch_number);
        $this->patch(route('billing-batches.update', $next), ['lock_version' => $next->lock_version, 'batch_number' => 'Batch 001'])->assertSessionHasErrors('batch_number');
        $this->assertSame('Batch 01', $first->fresh()->batch_number);
        $this->assertSame('Batch 2', $next->fresh()->batch_number);
    }

    public function test_billing_entry_validates_numbers_and_supported_integer_limit_without_overflow(): void
    {
        $this->actingAs($this->staff());
        $program = ScholarshipProgram::create($this->data());
        $scope = $this->approveRate($program, $this->period());
        foreach ([0, -2, '2.5', '01', ['invalid']] as $number) {
            $this->postJson(route('billing-batches.store'), $scope + ['batch_number' => $number])->assertUnprocessable()->assertJsonValidationErrors('batch_number');
        }
        $this->post(route('billing-batches.store'), $scope + ['batch_number' => PositiveBatchNumber::MAX])->assertSessionHasNoErrors();
        $this->assertSame('Batch '.PositiveBatchNumber::MAX, BillingBatch::sole()->batch_number);
        $this->post(route('billing-batches.store'), $scope)->assertSessionHasErrors('batch_number');
        $this->post(route('billing-batches.store'), $scope + ['batch_number' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('billing_batches', 2);
    }

    public function test_unauthorized_program_creation_does_not_gain_billing_permissions_from_new_fields(): void
    {
        $period = $this->period();
        foreach (['registrar', 'finance', 'viewer', 'unknown'] as $role) {
            $this->actingAs($this->staff($role))->post(route('scholarship-programs.store'), $this->data(['batch_number' => 1, 'academic_period_id' => $period->id]))->assertForbidden();
        }
        $this->assertDatabaseCount('scholarship_programs', 0);
        $this->assertDatabaseCount('billing_batches', 0);
    }

    public function test_batch_and_audit_roll_back_together_without_consuming_the_requested_number(): void
    {
        $this->actingAs($this->staff());
        $program = ScholarshipProgram::create($this->data());
        $scope = $this->approveRate($program, $this->period());
        $before = DB::table('audit_events')->count();
        $this->mock(RecordAudit::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('SYNTHETIC audit failure'));
        $this->withoutExceptionHandling();
        try {
            $this->post(route('billing-batches.store'), $scope + ['batch_number' => 3]);
            $this->fail('Failed auditing must not persist a batch.');
        } catch (RuntimeException $exception) {
            $this->assertSame('SYNTHETIC audit failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('billing_batches', 0);
        $this->assertSame($before, DB::table('audit_events')->count());
    }
}
