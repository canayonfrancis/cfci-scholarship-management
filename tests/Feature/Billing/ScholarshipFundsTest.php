<?php

namespace Tests\Feature\Billing;

use App\Actions\Records\RecordAudit;
use App\Models\AcademicPeriod;
use App\Models\AcademicRecord;
use App\Models\AuditEvent;
use App\Models\BillingBatch;
use App\Models\BillingItem;
use App\Models\FundingReceipt;
use App\Models\ProgramPeriodTerm;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipAssignment;
use App\Models\ScholarshipBatch;
use App\Models\ScholarshipProgram;
use App\Services\Billing\AllocationSummary;
use Database\Seeders\ConfirmedScholarshipProgramSeeder;
use Database\Seeders\ScholarshipProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\BuildsBillableAllocations;
use Tests\Concerns\BuildsScholarshipRecords;
use Tests\TestCase;

class ScholarshipFundsTest extends TestCase
{
    use BuildsBillableAllocations, BuildsScholarshipRecords, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('scholarship');
    }

    private function submitted(string $code = 'TES', string $amount = '7500.00'): array
    {
        $g = $this->ready($code, $amount);
        $g['finance'] = auth()->user();
        $this->post(route('billing-batches.store'), ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'scholarship_batch_id' => $g['roster']->id])->assertSessionHasNoErrors();
        $g['batch'] = BillingBatch::latest('id')->firstOrFail();
        $this->add($g)->assertSessionHasNoErrors();
        $this->submit($g['batch'])->assertSessionHasNoErrors();

        return $g;
    }

    private function budget(array $g, string $amount = '75000.00'): void
    {
        $previous = $g['terms']->budgets()->orderByDesc('revision')->first();
        $this->post(route('billing-batches.budget'), [
            'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'previous_budget' => $previous?->id ?? 0,
            'amount' => $amount, 'source_reference' => 'Synthetic approved scholarship budget',
        ])->assertSessionHasNoErrors();
    }

    private function approve(array $g)
    {
        return $this->post(route('billing-batches.approve', $g['batch']), ['reference' => 'SYNTHETIC-ADMIN-APPROVAL']);
    }

    public function test_finance_submits_and_a_different_administrator_approves_without_receipts_or_payments(): void
    {
        $g = $this->submitted();
        $summary = app(AllocationSummary::class)->forTerms($g['terms']);
        $this->assertSame('0.00', $summary['allocated']);
        $this->assertSame('7500.00', $summary['pending']);
        $this->approve($g)->assertForbidden();
        $this->actingAs($this->staff('administrator'));
        $this->budget($g);
        $this->approve($g)->assertSessionHasNoErrors();
        $this->assertSame('approved', $g['batch']->fresh()->allocationStatus());
        $this->assertSame('submitted', $g['batch']->fresh()->status); // Original submission remains immutable.
        $approval = $g['batch']->approval()->firstOrFail();
        $this->assertSame(auth()->id(), $approval->approved_by);
        $this->assertSame('7500.00', $approval->amount_snapshot);
        $this->assertDatabaseRejects(fn () => $approval->update(['reference' => 'Changed']));
        $this->assertDatabaseRejects(fn () => $approval->delete());
        $summary = app(AllocationSummary::class)->forTerms($g['terms']);
        $this->assertSame('7500.00', $summary['allocated']);
        $this->assertSame('67500.00', $summary['remaining']);
        $this->assertSame('0.00', $summary['received']);
        $this->assertSame('0.00', $summary['released_net']);
        $this->get(route('billing-batches.show', $g['batch']))->assertOk()->assertSee('Coordinator allocation approval')->assertSee('Approved');
        $this->approve($g)->assertForbidden();
        foreach (['funding_receipts', 'funding_allocations', 'payouts', 'deductions', 'liquidations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_dual_role_finance_administrator_cannot_approve_a_batch_they_prepared_or_submitted(): void
    {
        $g = $this->submitted();
        $g['finance']->roles()->create(['role' => 'administrator']);
        $this->actingAs($g['finance']->fresh());
        $this->budget($g);
        $this->approve($g)->assertForbidden();
        $this->assertNull($g['batch']->approval()->first());
        $this->assertDatabaseRejects(fn () => $g['batch']->approval()->create([
            'approved_by' => $g['finance']->id, 'approved_at' => now(), 'reference' => 'Forged self approval',
            'amount_snapshot' => '7500.00', 'scholarship_budget_id' => $g['terms']->budgets()->firstOrFail()->id,
        ]));
    }

    public function test_budget_limits_and_revisions_count_only_approved_allocations(): void
    {
        $g = $this->submitted();
        $admin = $this->staff('administrator');
        $this->actingAs($admin);
        $this->approve($g)->assertSessionHasErrors('billing');
        $this->budget($g, '7499.99');
        $this->approve($g)->assertSessionHasErrors('billing');
        $this->budget($g, '7500.00');
        $this->approve($g)->assertSessionHasNoErrors();
        $second = $this->submitted();
        $this->actingAs($admin);
        $this->approve($second)->assertSessionHasErrors('billing');
        $this->assertSame('0.00', app(AllocationSummary::class)->forTerms($g['terms'])['remaining']);
        $previous = $g['terms']->budgets()->orderByDesc('revision')->firstOrFail();
        $this->post(route('billing-batches.budget'), ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'previous_budget' => $previous->id, 'amount' => '7000.00', 'source_reference' => 'Invalid reduction'])->assertSessionHasErrors('billing');
        $this->budget($g, '15000.00');
        $this->approve($second)->assertSessionHasNoErrors();
        $this->assertSame('15000.00', app(AllocationSummary::class)->forTerms($g['terms'])['allocated']);
        $this->assertDatabaseRejects(fn () => $previous->update(['amount' => '99999.00']));
    }

    public function test_new_rates_apply_to_new_batches_and_never_reprice_approved_history(): void
    {
        $g = $this->submitted();
        $this->actingAs($this->staff('administrator'));
        $this->budget($g);
        $this->approve($g)->assertSessionHasNoErrors();
        $oldRate = $g['batch']->rate;
        $this->post(route('billing-batches.terms'), [
            'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'previous_rate' => $oldRate->id,
            'semester_gross_amount' => '8000.25', 'billing_source_reference' => 'Synthetic approved rate revision', 'confirm_billing_policy' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, $g['terms']->rates()->count());
        $this->assertSame('7500.00', $g['batch']->fresh()->rate->amount);
        $this->assertSame('7500.00', $g['batch']->approval()->firstOrFail()->amount_snapshot);
        $this->assertSame('7500.00', $g['batch']->items()->firstOrFail()->gross_amount);
        $this->assertSame('7500.00', $g['terms']->fresh()->semester_gross_amount);
        $second = $this->submitted('TES', '8000.25');
        $this->assertSame('8000.25', $second['batch']->rate->amount);
        $this->assertSame('8000.25', $second['batch']->totalAmount());
        $this->assertDatabaseRejects(fn () => $oldRate->update(['amount' => '1.00']));
        $this->assertDatabaseRejects(fn () => $oldRate->delete());
    }

    public function test_administrator_rechecks_eligibility_when_approving_a_submission(): void
    {
        $g = $this->submitted();
        AcademicRecord::create(['student_id' => $g['student']->id, 'academic_period_id' => $g['period']->id, 'revision' => 2, 'supersedes_id' => $g['record']->id]);
        $this->actingAs($this->staff('administrator'));
        $this->budget($g);
        $this->approve($g)->assertSessionHasErrors('billing');
        $this->assertDatabaseCount('billing_batch_approvals', 0);
        $this->assertSame('submitted', $g['batch']->fresh()->status);
    }

    public function test_legacy_submissions_keep_their_original_rate_after_new_rate_revisions(): void
    {
        $g = $this->ready('TES', '7500.00');
        BillingItem::create($this->billingItemData($g) + [
            'gross_amount' => '7500.00', 'entitlement_snapshot' => '7500.00',
            'basis_snapshot' => ['policy' => 'legacy-confirmed-semester-amount'],
            'status' => 'finalized', 'finalized_by' => auth()->id(), 'finalized_at' => now(),
        ]);
        $g['batch']->update(['status' => 'submitted', 'submitted_by' => auth()->id(), 'submitted_at' => now(), 'reference' => 'Synthetic retained legacy submission']);
        $this->assertNull($g['batch']->fresh()->scholarship_rate_id);
        $rate = $g['terms']->rates()->firstOrFail();
        $this->actingAs($this->staff('administrator'));
        $this->post(route('billing-batches.terms'), [
            'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id,
            'previous_rate' => $rate->id, 'semester_gross_amount' => '8000.00',
            'billing_source_reference' => 'Synthetic later rate revision', 'confirm_billing_policy' => 1,
        ])->assertSessionHasNoErrors();
        $this->budget($g);
        $this->approve($g)->assertSessionHasNoErrors();
        $this->assertSame('7500.00', $g['batch']->approval()->firstOrFail()->amount_snapshot);
        $this->assertSame($rate->id, $g['batch']->appliedRate()->id);
    }

    public function test_authorized_adjustments_preserve_originals_and_reconcile_budget(): void
    {
        $g = $this->submitted();
        $this->actingAs($this->staff('administrator'));
        $this->budget($g, '15000.00');
        $this->approve($g)->assertSessionHasNoErrors();
        $item = $g['batch']->items()->firstOrFail();
        $url = route('billing-batches.items.adjust', [$g['batch'], $item]);
        $data = ['previous_adjustment' => 0, 'new_amount' => '7000.00', 'reference' => 'SYNTHETIC-CORRECTION', 'reason' => 'Synthetic approved amount correction'];
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->assertSame('7000.00', $item->effectiveAmount());
        $this->assertSame('7500.00', $item->fresh()->gross_amount);
        $this->assertSame('8000.00', app(AllocationSummary::class)->forTerms($g['terms'])['remaining']);
        $this->post($url, $data)->assertSessionHasErrors('billing');
        $this->post($url, array_replace($data, ['previous_adjustment' => 1, 'new_amount' => '7500.00']))->assertSessionHasNoErrors();
        $this->assertSame('7500.00', $item->effectiveAmount());
        $adjustment = $item->adjustments()->firstOrFail();
        $this->assertDatabaseRejects(fn () => $adjustment->update(['reason' => 'Changed']));
        $this->assertDatabaseRejects(fn () => $adjustment->delete());
        $this->actingAs($g['finance']);
        $this->post($url, array_replace($data, ['previous_adjustment' => 2]))->assertForbidden();
        $this->assertSame(2, $item->adjustments()->count());
    }

    public function test_an_increase_requires_current_validation_approved_rate_and_budget_capacity(): void
    {
        $g = $this->submitted();
        $this->actingAs($this->staff('administrator'));
        $this->budget($g, '7500.00');
        $this->approve($g)->assertSessionHasNoErrors();
        $item = $g['batch']->items()->firstOrFail();
        $url = route('billing-batches.items.adjust', [$g['batch'], $item]);
        $data = ['previous_adjustment' => 0, 'new_amount' => '8000.00', 'reference' => 'SYNTHETIC-INCREASE', 'reason' => 'Synthetic rate change correction'];
        $this->post($url, $data)->assertSessionHasErrors('billing');
        $this->post(route('billing-batches.terms'), ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'previous_rate' => $g['batch']->rate->id, 'semester_gross_amount' => '8000.00', 'billing_source_reference' => 'Synthetic updated rate', 'confirm_billing_policy' => 1])->assertSessionHasNoErrors();
        $this->post($url, $data)->assertSessionHasErrors('billing'); // No budget capacity.
        $this->budget($g, '8000.00');
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->assertSame('8000.00', $item->effectiveAmount());
        $this->assertSame('0.00', app(AllocationSummary::class)->forTerms($g['terms'])['remaining']);
        $this->assertNotNull($item->adjustments()->firstOrFail()->basis_snapshot['evaluation_run_id']);
    }

    public function test_adjustments_cannot_reduce_an_allocation_below_released_funds(): void
    {
        $g = $this->submitted();
        $this->actingAs($this->staff('administrator'));
        $this->budget($g);
        $this->approve($g)->assertSessionHasNoErrors();
        $item = $g['batch']->items()->firstOrFail();
        $item->payouts()->create(['billing_batch_id' => $g['batch']->id, 'gross_amount' => '5000.00', 'net_amount' => '5000.00', 'status' => 'posted', 'posting_key' => 'synthetic-posted-release', 'posted_by' => auth()->id(), 'posted_at' => now(), 'paid_on' => now()->toDateString()]);
        $data = ['previous_adjustment' => 0, 'new_amount' => '4999.99', 'reference' => 'Synthetic correction', 'reason' => 'Cannot erase a release'];
        $url = route('billing-batches.items.adjust', [$g['batch'], $item]);
        $this->post($url, $data)->assertSessionHasErrors('billing');
        $this->post($url, array_replace($data, ['new_amount' => '5000.00']))->assertSessionHasNoErrors();
        $this->assertSame('5000.00', app(AllocationSummary::class)->forTerms($g['terms'])['released_net']);
    }

    public function test_reports_keep_inactive_scholars_and_distinguish_budgets_allocations_and_actual_cash(): void
    {
        $g = $this->submitted();
        $this->actingAs($this->staff('administrator'));
        $this->budget($g, '15000.00');
        $this->approve($g)->assertSessionHasNoErrors();
        $g['student']->update(['record_status' => 'archived']);
        FundingReceipt::create(['program_period_term_id' => $g['terms']->id, 'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'amount' => '1000.00', 'received_on' => now()->toDateString(), 'source' => 'Synthetic funder', 'reference' => 'Synthetic actual receipt', 'status' => 'posted', 'posting_key' => 'synthetic-cash', 'posted_by' => auth()->id(), 'posted_at' => now()]);
        $summary = app(AllocationSummary::class)->forTerms($g['terms']);
        $this->assertSame('7500.00', $summary['allocated']);
        $this->assertSame('1000.00', $summary['received']);
        $this->assertSame('0.00', $summary['released_net']);
        $this->get(route('billing-batches.report', ['program_id' => $g['program']->id]))->assertOk()->assertSee($g['student']->student_number)->assertSee('Actually received');
        $csv = $this->get(route('billing-batches.report', ['academic_period_id' => $g['period']->id, 'export' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('SYNTHETIC-ADMIN-APPROVAL', $csv);
        $this->assertStringContainsString($g['student']->student_number, $csv);
        $this->actingAs($this->staff('registrar'))->get(route('billing-batches.report'))->assertOk();
    }

    public function test_program_changes_preserve_history_and_cannot_create_another_scholar_semester_allocation(): void
    {
        $g = $this->submitted();
        $admin = $this->staff('administrator');
        $this->actingAs($admin);
        $this->budget($g);
        $this->approve($g)->assertSessionHasNoErrors();
        $this->patch(route('scholars.assignments.update', [$g['student'], $g['assignment']]), ['program_id' => $g['program']->id, 'start_period_id' => $g['period']->id, 'end_period_id' => $g['period']->id, 'status' => 'ended', 'status_reason' => 'Administrator authorized program change'])->assertSessionHasNoErrors();
        $program = ScholarshipProgram::create(['code' => 'TDP', 'name' => 'TDP']);
        $assignment = ScholarshipAssignment::create(['student_id' => $g['student']->id, 'program_id' => $program->id, 'start_period_id' => $g['period']->id, 'status' => 'active']);
        $this->post(route('billing-batches.terms'), ['program_id' => $program->id, 'academic_period_id' => $g['period']->id, 'semester_gross_amount' => '13500.00', 'billing_source_reference' => 'Synthetic program-change semester rate', 'confirm_billing_policy' => 1])->assertSessionHasNoErrors();
        $terms = ProgramPeriodTerm::where('program_id', $program->id)->firstOrFail();
        $application = ScholarshipApplication::create(['student_id' => $g['student']->id, 'program_id' => $program->id, 'academic_period_id' => $g['period']->id, 'assignment_id' => $assignment->id, 'program_period_term_id' => $terms->id]);
        $this->actingAs($g['finance']);
        $roster = ScholarshipBatch::create(['program_id' => $program->id, 'academic_period_id' => $g['period']->id, 'batch_number' => 'SYNTHETIC-TRANSFER', 'created_by' => $admin->id]);
        $roster->memberships()->create(['student_id' => $g['student']->id, 'assignment_id' => $assignment->id, 'program_id' => $program->id, 'academic_period_id' => $g['period']->id, 'assigned_by' => $admin->id, 'assigned_at' => now()]);
        $this->post(route('billing-batches.store'), ['program_id' => $program->id, 'academic_period_id' => $g['period']->id, 'scholarship_batch_id' => $roster->id])->assertSessionHasNoErrors();
        $batch = BillingBatch::latest('id')->firstOrFail();
        $this->post(route('billing-batches.items.store', $batch), ['lock_version' => 0, 'application_id' => $application->id])->assertSessionHasErrors('billing');
        $this->assertDatabaseRejects(fn () => BillingItem::create(['billing_batch_id' => $batch->id, 'application_id' => $application->id, 'assignment_id' => $assignment->id, 'student_id' => $g['student']->id, 'program_id' => $program->id, 'academic_period_id' => $g['period']->id]));
        $this->assertSame('ended', $g['assignment']->fresh()->status);
        $this->assertSame(1, BillingItem::where('student_id', $g['student']->id)->count());
    }

    public function test_assignment_must_cover_the_selected_semester(): void
    {
        $g = $this->ready('TES');
        $future = AcademicPeriod::create(['academic_year' => '2027-2028', 'semester_code' => '1']);
        $g['assignment']->update(['start_period_id' => $future->id]);
        $this->add($g)->assertSessionHasErrors('billing');
        $this->assertDatabaseCount('billing_items', 0);
    }

    public function test_coscho_and_custom_programs_are_configurable_without_assuming_eligibility_rules(): void
    {
        $this->actingAs($this->staff('administrator'));
        foreach ([['CoScho', '45000.00'], ['FUTURE-AID', '1234.56']] as [$code, $amount]) {
            $this->post(route('scholarship-programs.store'), ['code' => $code, 'name' => $code, 'school_stated_semester_amount' => $amount, 'is_active' => 1])->assertSessionHasNoErrors();
        }
        $this->assertSame('45000.00', ScholarshipProgram::where('code', 'COSCHO')->firstOrFail()->school_stated_semester_amount);
        $g = $this->ready('COSCHO', '45000.00');
        $this->add($g)->assertSessionHasErrors('billing'); // No production CoScho rule profile has been invented.
        $this->assertDatabaseCount('billing_items', 0);
    }

    public function test_confirmed_initial_values_are_seeded_once_without_rewriting_rates_or_school_history(): void
    {
        $this->seed(ScholarshipProgramSeeder::class);
        $this->assertSame('7500.00', ScholarshipProgram::where('code', 'TES')->firstOrFail()->school_stated_semester_amount);
        $this->assertSame('13500.00', ScholarshipProgram::where('code', 'TDP')->firstOrFail()->school_stated_semester_amount);
        $this->assertSame('45000.00', ScholarshipProgram::where('code', 'COSCHO')->firstOrFail()->school_stated_semester_amount);
        $tes = ScholarshipProgram::where('code', 'TES')->firstOrFail();
        $tes->update(['school_stated_semester_amount' => '13500.00']);
        $this->seed(ConfirmedScholarshipProgramSeeder::class);
        $this->assertSame('7500.00', $tes->fresh()->school_stated_semester_amount);
        $count = AuditEvent::count();
        $tes->update(['school_stated_semester_amount' => '8000.00', 'source_notes' => 'Later Administrator reference replaces all initial source notes.']);
        $this->seed(ConfirmedScholarshipProgramSeeder::class);
        $this->assertSame('8000.00', $tes->fresh()->school_stated_semester_amount);
        $this->assertSame($count, AuditEvent::count());
        $this->assertDatabaseCount('academic_periods', 0);
        $this->assertDatabaseCount('scholarship_rates', 0);
    }

    public function test_academic_years_begin_in_2007_and_support_future_years(): void
    {
        $this->actingAs($this->staff('administrator'));
        $this->post(route('academic-periods.store'), ['academic_year' => '2006-2007', 'semester_code' => '1', 'is_open' => 0])->assertSessionHasErrors('academic_year');
        foreach (['2007-2008', '2035-2036'] as $year) {
            $this->post(route('academic-periods.store'), ['academic_year' => $year, 'semester_code' => '1', 'is_open' => 0])->assertSessionHasNoErrors();
        }
        $this->post(route('academic-periods.store'), ['academic_year' => '2028-2030', 'semester_code' => '1', 'is_open' => 0])->assertSessionHasErrors('academic_year');
        $this->assertDatabaseCount('scholarship_rates', 0);
    }

    public function test_fractional_rates_and_adjustments_use_exact_centavos_for_whole_batches(): void
    {
        $g = $this->ready('TES', '0.10');
        $this->post(route('billing-batches.store'), ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'scholarship_batch_id' => $g['roster']->id])->assertSessionHasNoErrors();
        $g['batch'] = BillingBatch::latest('id')->firstOrFail();
        $this->add($g)->assertSessionHasNoErrors();
        for ($i = 0; $i < 2; $i++) {
            $other = $this->ready('TES', '0.10');
            $this->add($other, $g['batch'])->assertSessionHasNoErrors();
        }
        $this->submit($g['batch'])->assertSessionHasNoErrors();
        $this->assertSame('0.30', $g['batch']->totalAmount());
        $this->actingAs($this->staff('administrator'));
        $this->budget($g, '0.30');
        $this->approve($g)->assertSessionHasNoErrors();
        $item = $g['batch']->items()->firstOrFail();
        $this->post(route('billing-batches.items.adjust', [$g['batch'], $item]), ['previous_adjustment' => 0, 'new_amount' => '0.03', 'reference' => 'Synthetic exact correction', 'reason' => 'Exact seven-centavo decrease'])->assertSessionHasNoErrors();
        $summary = app(AllocationSummary::class)->forTerms($g['terms']);
        $this->assertSame('0.23', $summary['allocated']);
        $this->assertSame('0.07', $summary['remaining']);
        $this->assertSame('0.03', $item->effectiveAmount());
        $adjustment = $item->adjustments()->firstOrFail();
        $this->assertDatabaseRejects(fn () => $item->adjustments()->create(array_replace($adjustment->getAttributes(), ['sequence' => 2, 'amount_before' => '100.00', 'amount_after' => '99.93'])));
    }

    public function test_new_allocation_migration_preserves_legacy_rows_and_checks_duplicates_before_ddl(): void
    {
        $g = $this->applicationContext('TES');
        $original = BillingItem::create($this->billingItemData($g));
        $migration = require database_path('migrations/2026_11_11_000006_add_scholarship_allocation_approvals.php');
        $migration->down();
        $program = ScholarshipProgram::create(['code' => 'TDP', 'name' => 'TDP']);
        $terms = ProgramPeriodTerm::create(['program_id' => $program->id, 'academic_period_id' => $g['period']->id]);
        $assignment = ScholarshipAssignment::create(['student_id' => $g['student']->id, 'program_id' => $program->id, 'start_period_id' => $g['period']->id]);
        $application = ScholarshipApplication::create(['student_id' => $g['student']->id, 'program_id' => $program->id, 'academic_period_id' => $g['period']->id, 'assignment_id' => $assignment->id, 'program_period_term_id' => $terms->id]);
        $batch = BillingBatch::create(['program_period_term_id' => $terms->id, 'program_id' => $program->id, 'academic_period_id' => $g['period']->id, 'batch_number' => 'Legacy second program']);
        $duplicate = BillingItem::create(['billing_batch_id' => $batch->id, 'application_id' => $application->id, 'assignment_id' => $assignment->id, 'student_id' => $g['student']->id, 'program_id' => $program->id, 'academic_period_id' => $g['period']->id]);
        try {
            $migration->up();
            $this->fail('Duplicate legacy allocation requires explicit reconciliation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('authorized reconciliation', $exception->getMessage());
        }
        $this->assertFalse(Schema::hasTable('scholarship_rates'));
        $this->assertDatabaseHas('billing_items', ['id' => $original->id]);
        $duplicate->delete();
        $migration->up();
        $this->assertTrue(Schema::hasTable('scholarship_rates'));
        $this->assertSame($g['student']->id, $original->fresh()->student_id);
    }

    public function test_approval_audit_failure_rolls_back_the_approval_and_budget_commitment(): void
    {
        $g = $this->submitted();
        $this->actingAs($this->staff('administrator'));
        $this->budget($g);
        $this->mock(RecordAudit::class)->shouldReceive('record')->andThrow(new RuntimeException('Synthetic audit failure'));
        $this->withoutExceptionHandling();
        try {
            $this->approve($g);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('billing_batch_approvals', 0);
        $this->assertSame('75000.00', app(AllocationSummary::class)->forTerms($g['terms'])['remaining']);
    }
}
