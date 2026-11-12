<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\ManageBillingBatch;
use App\Actions\Records\RecordAudit;
use App\Models\AcademicPeriod;
use App\Models\BillingBatch;
use App\Models\BillingItem;
use App\Models\ScholarshipAssignment;
use App\Models\ScholarshipBatch;
use App\Models\ScholarshipProgram;
use App\Services\Billing\BillingRoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsBillableAllocations;
use Tests\Concerns\BuildsScholarshipRecords;
use Tests\TestCase;

class BillingRosterIntegrationTest extends TestCase
{
    use BuildsBillableAllocations, BuildsScholarshipRecords, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('scholarship');
    }

    private function scope(array $g): array
    {
        return ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'scholarship_batch_id' => $g['roster']->id];
    }

    private function create(array $g): BillingBatch
    {
        $this->post(route('billing-batches.store'), $this->scope($g))->assertSessionHasNoErrors();

        return BillingBatch::latest('id')->firstOrFail();
    }

    public function test_http_creation_retains_one_roster_for_multiple_submissions_and_shows_scope_controls(): void
    {
        $g = $this->ready();
        $before = BillingItem::count();
        $first = $this->create($g);
        $second = $this->create($g);
        $this->assertSame($g['roster']->id, $first->scholarship_batch_id);
        $this->assertSame($g['roster']->id, $second->scholarship_batch_id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($before, BillingItem::count());
        $this->get(route('billing-batches.index'))->assertOk()->assertSee('name="scholarship_batch_id"', false)->assertSee('Select a matching semester roster');
        $this->get(route('billing-batches.show', $first))->assertOk()->assertSee($g['roster']->batch_number)->assertSee('Final submission remains blocked');
        $this->assertDatabaseCount('payouts', 0);
    }

    public static function invalidSelections(): array
    {
        return [['missing'], ['nonexistent'], ['program'], ['period']];
    }

    #[DataProvider('invalidSelections')]
    public function test_incompatible_or_missing_rosters_fail_without_partial_creation(string $kind): void
    {
        $g = $this->ready();
        $data = $this->scope($g);
        if ($kind === 'missing') {
            unset($data['scholarship_batch_id']);
        } elseif ($kind === 'nonexistent') {
            $data['scholarship_batch_id'] = 999999;
        } elseif ($kind === 'program') {
            $data['program_id'] = ScholarshipProgram::create(['code' => 'OTHER', 'name' => 'Other'])->id;
        } else {
            $data['academic_period_id'] = AcademicPeriod::create(['academic_year' => '2027-2028', 'semester_code' => '2'])->id;
        }
        $before = BillingBatch::count();
        $audit = DB::table('audit_events')->count();
        $this->postJson(route('billing-batches.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('scholarship_batch_id');
        $this->assertSame($before, BillingBatch::count());
        $this->assertSame($audit, DB::table('audit_events')->count());
    }

    public function test_candidates_and_add_reject_nonmembers_even_with_qualified_financial_fixtures(): void
    {
        $g = $this->ready();
        $outsider = $this->ready(withRoster: false);
        $batch = $this->create($g);
        $this->get(route('billing-batches.show', $batch))->assertOk()->assertViewHas('candidates', fn ($rows) => $rows->pluck('application.id')->all() === [$g['application']->id]);
        $before = DB::table('audit_events')->count();
        $this->add($outsider, $batch)->assertSessionHasErrors('billing');
        $this->assertSame($before, DB::table('audit_events')->count());
        $this->assertSame(0, $batch->items()->count());
        $this->assertSame(0, $batch->fresh()->lock_version);
        $this->add($g, $batch)->assertSessionHasNoErrors();
        $this->add($g, $this->create($g))->assertSessionHasErrors('billing');
        $this->assertSame(1, BillingItem::where('student_id', $g['student']->id)->count());
    }

    public function test_same_student_membership_under_another_assignment_does_not_qualify(): void
    {
        $g = $this->ready();
        $roster = ScholarshipBatch::create(['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'batch_number' => 'Other assignment roster', 'created_by' => $g['actor']->id]);
        $assignment = ScholarshipAssignment::create(['student_id' => $g['student']->id, 'program_id' => $g['program']->id, 'start_period_id' => $g['period']->id, 'status' => 'pending']);
        $roster->memberships()->create(['student_id' => $g['student']->id, 'assignment_id' => $assignment->id, 'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'assigned_by' => $g['actor']->id, 'assigned_at' => now()]);
        $batch = $this->create(array_replace($g, ['roster' => $roster]));
        $this->add($g, $batch)->assertSessionHasErrors('billing');
        $this->get(route('billing-batches.show', $batch))->assertViewHas('candidates', fn ($rows) => $rows->isEmpty());
        $this->assertSame(0, $batch->items()->count());
    }

    public function test_submission_rechecks_membership_and_preserves_all_draft_state_on_failure(): void
    {
        $g = $this->ready();
        $batch = $this->create($g);
        $this->add($g, $batch)->assertSessionHasNoErrors();
        $other = ScholarshipBatch::create(['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'batch_number' => 'Empty roster', 'created_by' => $g['actor']->id]);
        // Simulate a changed draft source, never change immutable membership or submitted history.
        $batch->update(['scholarship_batch_id' => $other->id]);
        $before = DB::table('audit_events')->count();
        $this->submit($batch)->assertSessionHasErrors('billing');
        $this->assertSame('draft', $batch->fresh()->status);
        $this->assertSame('draft', $batch->items()->sole()->status);
        $this->assertSame($before, DB::table('audit_events')->count());
    }

    public function test_production_classification_block_rolls_back_finalization_without_invented_form_rules(): void
    {
        $g = $this->ready();
        $batch = $this->create($g);
        $this->add($g, $batch)->assertSessionHasNoErrors();
        // Keep synthetic resolved grading, but exercise the real CR-02 submission guard.
        app()->forgetInstance(BillingRoster::class);
        $before = DB::table('audit_events')->count();
        $version = $batch->fresh()->lock_version;
        $this->submit($batch)->assertSessionHasErrors('classification');
        $this->assertSame('draft', $batch->fresh()->status);
        $this->assertNull($batch->submitted_at);
        $this->assertSame($version, $batch->fresh()->lock_version);
        $this->assertSame('draft', $batch->items()->sole()->status);
        $this->assertNull($batch->items()->sole()->finalized_at);
        $this->assertSame($before, DB::table('audit_events')->count());
    }

    public function test_direct_action_requires_roster_and_legacy_unlinked_drafts_cannot_gain_new_items(): void
    {
        $g = $this->ready(withRoster: false);
        try {
            app(ManageBillingBatch::class)->create(auth()->user(), ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id]);
            $this->fail('Direct action creation must require a roster too.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('scholarship_batch_id', $error->errors());
        }
        $attributes = $g['batch']->fresh()->getAttributes();
        $this->get(route('billing-batches.show', $g['batch']))->assertOk()->assertSee('No recorded roster link');
        $this->add($g)->assertSessionHasErrors('scholarship_batch_id');
        $this->assertSame($attributes, $g['batch']->fresh()->getAttributes());
        $this->assertDatabaseCount('billing_items', 0);
    }

    public function test_linked_creation_rolls_back_with_failed_audit_and_does_not_change_roster(): void
    {
        $g = $this->ready();
        $before = BillingBatch::count();
        $roster = $g['roster']->fresh()->getAttributes();
        $this->mock(RecordAudit::class)->shouldReceive('record')->andThrow(new RuntimeException('Synthetic audit failure'));
        try {
            app(ManageBillingBatch::class)->create(auth()->user(), $this->scope($g));
            $this->fail('Failed auditing must roll back the new linked header.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic audit failure', $error->getMessage());
        }
        $this->assertSame($before, BillingBatch::count());
        $this->assertSame($roster, $g['roster']->fresh()->getAttributes());
    }
}
