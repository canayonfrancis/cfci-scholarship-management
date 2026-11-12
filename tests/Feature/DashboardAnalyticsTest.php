<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\AcademicValidationContext;
use App\Models\EvaluationRun;
use App\Models\ExternalApproval;
use App\Models\FundingAllocation;
use App\Models\FundingReceipt;
use App\Models\Liquidation;
use App\Models\Payout;
use App\Models\ProgramPeriodTerm;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Models\User;
use App\Services\DashboardAnalytics;
use App\Services\Eligibility\PrivateEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsAcknowledgmentRecords;
use Tests\Concerns\BuildsBillableAllocations;
use Tests\Concerns\BuildsScholarshipRecords;
use Tests\TestCase;

class DashboardAnalyticsTest extends TestCase
{
    use BuildsAcknowledgmentRecords, BuildsBillableAllocations, BuildsScholarshipRecords, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('scholarship');
    }

    public function test_latest_runs_and_current_source_checks_prevent_historical_eligibility_counts(): void
    {
        $g = $this->ready('TES');
        $this->actingAs($this->staff('administrator'));
        $service = app(DashboardAnalytics::class);
        $before = $service->build([]);
        $this->assertSame(1, $before['metrics']['school_eligible']);
        $this->assertSame(0, $before['metrics']['pending_evaluations']);
        $this->assertSame(1, $before['metrics']['active_awards']);
        $run = $g['evaluation']->fresh();
        EvaluationRun::create(array_replace($run->only($run->getFillable()), ['run_number' => 2, 'school_decision' => 'pending', 'automatic_outcome' => 'needs_review']));
        $after = $service->build([]);
        $this->assertDatabaseCount('evaluation_runs', 2);
        $this->assertCount(1, $after['evaluations']);
        $this->assertSame(0, $after['metrics']['school_eligible']);
        $this->assertSame(1, $after['metrics']['pending_evaluations']);
        $this->assertSame(2, $after['evaluations']->sole()['run_number']);
        $this->get(route('dashboard'))->assertOk()->assertViewHas('recentApplications', fn ($rows) => $rows->count() === 1 && $rows->first()['status'] === 'Pending review');
    }

    public function test_context_configuration_change_marks_old_passed_decisions_for_reassessment(): void
    {
        $g = $this->ready('TES');
        AcademicValidationContext::current()->increment('revision');
        $data = app(DashboardAnalytics::class)->build([]);
        $this->assertSame(0, $data['metrics']['school_eligible']);
        $this->assertSame('passed', $data['evaluations']->sole()['historical_outcome']);
        $this->assertSame('Pending review', $data['evaluations']->sole()['state']);
    }

    public function test_program_year_and_semester_filters_share_scope_and_keep_unique_students(): void
    {
        $g = $this->ready('TES');
        $second = AcademicPeriod::create(['academic_year' => '2026-2027', 'semester_code' => '2']);
        $terms = ProgramPeriodTerm::create(['program_id' => $g['program']->id, 'academic_period_id' => $second->id]);
        ScholarshipApplication::create(['student_id' => $g['student']->id, 'program_id' => $g['program']->id, 'academic_period_id' => $second->id, 'program_period_term_id' => $terms->id, 'assignment_id' => $g['assignment']->id]);
        Student::create(['student_number' => 'MANUAL-UNASSIGNED', 'first_name' => 'Unassigned', 'last_name' => 'Scholar']);
        $service = app(DashboardAnalytics::class);
        $this->assertSame(2, $service->build([])['metrics']['total_scholars']);
        $f = ['program_id' => $g['program']->id, 'academic_year' => '2026-2027', 'semester_code' => '2'];
        $data = $service->build($f);
        $this->assertSame(1, $data['metrics']['total_scholars']);
        $this->assertSame(1, $data['metrics']['active_awards']);
        $this->assertCount(1, $data['evaluations']);
        $this->assertSame(1, $data['metrics']['pending_evaluations']);
        $this->assertSame(0, $data['metrics']['school_eligible']);
        $this->assertSame(100.0, (float) $data['programs']->sole()['percent']);
        $this->actingAs($this->staff('administrator'))->get(route('dashboard', $f))->assertOk()->assertViewHas('metrics', fn ($m) => $m === $service->build([])['metrics']);
        $this->assertSame(0, $service->build(['academic_year' => '2007-2008', 'semester_code' => '1'])['metrics']['total_scholars']);
    }

    public function test_independent_financial_aggregates_match_ledgers_despite_multiple_children_and_reversals(): void
    {
        $g = $this->ready('TES', '7500.25');
        $this->add($g)->assertSessionHasNoErrors();
        $this->submit($g['batch'])->assertSessionHasNoErrors();
        $admin = $this->staff('administrator');
        $this->actingAs($admin)->post(route('billing-batches.budget'), ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'amount' => '7500.25', 'previous_budget' => 0, 'source_reference' => 'SAMPLE budget'])->assertSessionHasNoErrors();
        $this->post(route('billing-batches.approve', $g['batch']), ['reference' => 'SAMPLE approval'])->assertSessionHasNoErrors();
        $item = $g['batch']->items()->sole();
        $posted = ['status' => 'posted', 'posted_by' => $admin->id, 'posted_at' => now()];
        $receiptData = ['program_period_term_id' => $g['terms']->id, 'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id];
        $receipt = FundingReceipt::create($receiptData + $posted + ['amount' => '1000.00', 'posting_key' => 'report-receipt-1']);
        FundingReceipt::create($receiptData + $posted + ['amount' => '500.25', 'posting_key' => 'report-receipt-2']);
        FundingReceipt::create($receiptData + $posted + ['amount' => '100.00', 'posting_key' => 'report-receipt-reverse', 'entry_type' => 'reversal', 'reversal_of_id' => $receipt->id, 'correction_reason' => 'SAMPLE retained reversal']);
        FundingReceipt::create($receiptData + ['amount' => '999999.99']);
        foreach (['800.00', '150.00'] as $index => $amount) {
            FundingAllocation::create($posted + ['funding_receipt_id' => $receipt->id, 'billing_batch_id' => $g['batch']->id, 'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'amount' => $amount, 'posting_key' => 'report-allocation-'.$index]);
        }
        $paymentBase = $posted + ['billing_item_id' => $item->id, 'billing_batch_id' => $g['batch']->id, 'paid_on' => now('Asia/Manila')->toDateString()];
        $school = Payout::create($paymentBase + ['gross_amount' => '500.10', 'net_amount' => '500.10', 'payment_mode' => 'school', 'posting_key' => 'report-pay-school']);
        $direct = Payout::create($paymentBase + ['gross_amount' => '1000.15', 'net_amount' => '1000.15', 'payment_mode' => 'direct', 'posting_key' => 'report-pay-direct']);
        Payout::create($paymentBase + ['gross_amount' => '100.15', 'net_amount' => '100.15', 'payment_mode' => 'direct', 'posting_key' => 'report-pay-reverse', 'entry_type' => 'reversal', 'reversal_of_id' => $direct->id, 'correction_reason' => 'SAMPLE partial legacy reversal']);
        foreach (range(1, 3) as $index) {
            app(PrivateEvidence::class)->store($school, $admin, UploadedFile::fake()->createWithContent('sample.pdf', "%PDF-1.4\nSAMPLE\n%%EOF"), 'payment_evidence', true);
        }
        foreach ([1, 2] as $sequence) {
            Liquidation::create(['billing_batch_id' => $g['batch']->id, 'sequence' => $sequence]);
        }
        $this->post(route('billing-batches.items.adjust', [$g['batch'], $item]), ['previous_adjustment' => 0, 'new_amount' => '7400.25', 'reference' => 'SAMPLE', 'reason' => 'SAMPLE authorized reduction'])->assertSessionHasNoErrors();
        $service = app(DashboardAnalytics::class);
        $data = $service->build([]);
        $m = $data['metrics'];
        $this->assertSame('7500.25', $m['billing_total']);
        $this->assertSame('7400.25', $m['approved_effective']);
        $this->assertSame('1400.25', $m['school_funding']);
        $this->assertSame('1400.10', $m['student_payouts']);
        $this->assertSame('500.10', $m['school_payouts']);
        $this->assertSame('900.00', $m['direct_payouts']);
        $this->assertSame(1, $m['incomplete_liquidations']);
        $this->get(route('dashboard'))->assertOk()->assertViewHas('metrics', fn ($d) => $d === $m);
    }

    public function test_recent_validations_keep_long_identities_and_private_fields_out_of_output(): void
    {
        $g = $this->ready('TES');
        $g['student']->update(['student_number' => 'SAMPLE-LONG-INSTITUTIONAL-STUDENT-ID-2026', 'first_name' => 'Maria Alexandra Isabel', 'last_name' => 'Dela Cruz Villanueva', 'philsys_id' => '0000-0000-0000-0000', 'four_ps_id' => 'PRIVATE-SAMPLE-4PS', 'lrn' => '000000000001']);
        $this->actingAs($this->staff('administrator'));
        $this->get(route('dashboard'))->assertOk()->assertSee('SAMPLE-LONG-INSTITUTIONAL-STUDENT-ID-2026')->assertSee('Maria Alexandra Isabel')->assertSee('Dela Cruz Villanueva')->assertSee(route('evaluations.show', $g['application']))->assertDontSee('0000-0000-0000-0000')->assertDontSee('PRIVATE-SAMPLE-4PS')->assertDontSee('000000000001');
    }

    public static function roles(): array
    {
        return [['administrator', true, true], ['coordinator', true, true], ['registrar', true, true], ['finance', false, true], ['viewer', false, true]];
    }

    #[DataProvider('roles')]
    public function test_dashboard_keeps_evaluation_and_financial_visibility(string $role, bool $evaluations, bool $financial): void
    {
        $this->actingAs($this->staff($role));
        $response = $this->get(route('dashboard'))->assertOk();
        if ($evaluations) {
            $response->assertSee(route('evaluations.index'));
        } else {
            $response->assertSee('Detailed academic evaluations are restricted');
        }
        $response->assertDontSee('/reports');
        if ($financial) {
            $response->assertSee('Recorded school funding');
        } else {
            $response->assertDontSee('Recorded school funding');
        }
    }

    public function test_roleless_accounts_and_guests_cannot_access_dashboard_data(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create());
        $this->get(route('dashboard'))->assertOk()->assertSee('does not have staff reporting access')->assertDontSee('Total scholars');
    }

    public function test_empty_dashboard_and_obsolete_scope_parameters_are_handled(): void
    {
        $this->actingAs($this->staff('administrator'));
        $this->get(route('dashboard'))->assertOk()->assertViewHas('metrics', fn ($m) => $m['total_scholars'] === 0 && $m['billing_total'] === '0.00')->assertViewHas('donutStyle', '#e6e8ec');
        foreach ([['program_id' => 99999], ['academic_year' => '2026-2028'], ['semester_code' => 'not-a-semester']] as $f) {
            $this->get(route('dashboard', $f))->assertOk()->assertSessionHasNoErrors()->assertViewHas('metrics', fn ($m) => $m['total_scholars'] === 0);
        }
        foreach (['/reports', '/reports/scholars', '/reports/evaluations', '/reports/billing', '/reports/funds?print=1'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_latest_external_decision_is_separate_from_school_qualification(): void
    {
        $g = $this->ready('TES');
        $before = $g['approval']->fresh();
        $new = ExternalApproval::create(array_replace($before->only($before->getFillable()), ['decision' => 'revoked', 'supersedes_id' => $before->id, 'reference' => 'SAMPLE-REVOKED']));
        $service = app(DashboardAnalytics::class);
        $this->assertSame('Revoked', $service->build([])['evaluations']->sole()['external']);
        ExternalApproval::create(array_replace($new->only($new->getFillable()), ['decision' => 'pending', 'supersedes_id' => $new->id, 'reference' => 'SAMPLE-PENDING']));
        $data = $service->build([]);
        $this->assertSame(1, $data['metrics']['pending_external']);
        $this->assertSame(1, $data['metrics']['school_eligible']);
        $this->assertCount(1, $data['evaluations']);
    }

    public function test_accepted_liquidation_with_lost_evidence_requires_attention_without_erasing_paid_totals(): void
    {
        $g = $this->ready('TES', '7500.00');
        $finance = auth()->user();
        $this->add($g)->assertSessionHasNoErrors();
        $this->submit($g['batch'])->assertSessionHasNoErrors();
        $admin = $this->staff('administrator');
        $this->actingAs($admin)->post(route('billing-batches.budget'), ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'previous_budget' => 0, 'amount' => '7500.00', 'source_reference' => 'SAMPLE'])->assertSessionHasNoErrors();
        $this->post(route('billing-batches.approve', $g['batch']), ['reference' => 'SAMPLE'])->assertSessionHasNoErrors();
        $item = $g['batch']->items()->sole();
        $payment = Payout::create(['billing_item_id' => $item->id, 'billing_batch_id' => $g['batch']->id, 'payment_mode' => 'direct', 'gross_amount' => '7500.00', 'net_amount' => '7500.00', 'paid_on' => now('Asia/Manila')->toDateString(), 'institutional_student_id' => $g['student']->student_number, 'payee' => $g['student']->full_name, 'method' => 'bank_transfer', 'reference' => 'SAMPLE', 'status' => 'posted', 'posted_by' => $admin->id, 'posted_at' => now(), 'posting_key' => 'SAMPLE-direct']);
        $proof = app(PrivateEvidence::class)->store($payment, $admin, UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\nSAMPLE\n%%EOF"), 'payment_evidence', true);
        $this->actingAs($finance)->post(route('financial.liquidations.store', $g['batch']), ['reference' => 'SAMPLE'])->assertSessionHasNoErrors();
        $report = Liquidation::sole();
        $this->makeSignedAcknowledgment($payment, $finance, $admin);
        $this->makeLiquidationSupport($report, $finance, $admin);
        $this->post(route('financial.liquidations.submit', $report))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('financial.liquidations.review', $report), ['decision' => 'accepted', 'review_notes' => 'SAMPLE'])->assertSessionHasNoErrors();
        $service = app(DashboardAnalytics::class);
        $this->assertSame(0, $service->build([])['metrics']['incomplete_liquidations']);
        Storage::disk('scholarship')->put($proof->storage_path, 'changed');
        $data = $service->build([]);
        $this->assertSame(1, $data['metrics']['incomplete_liquidations']);
        $this->assertSame('7500.00', $data['metrics']['student_payouts']);
        $this->assertSame('accepted', $report->fresh()->status);
    }

    public function test_chart_scale_and_accessible_totals_are_derived_above_the_old_fixed_limit(): void
    {
        $program = ScholarshipProgram::create(['code' => 'TES', 'name' => 'TES']);
        $period = AcademicPeriod::create(['academic_year' => '2026-2027', 'semester_code' => '1']);
        $terms = ProgramPeriodTerm::create(['program_id' => $program->id, 'academic_period_id' => $period->id]);
        foreach (range(1, 110) as $i) {
            $s = Student::create(['student_number' => 'SAMPLE-CHART-'.$i, 'first_name' => 'Sample', 'last_name' => 'Scholar '.$i]);
            $app = ScholarshipApplication::create(['student_id' => $s->id, 'program_id' => $program->id, 'academic_period_id' => $period->id, 'program_period_term_id' => $terms->id]);
            EvaluationRun::create(['application_id' => $app->id, 'student_id' => $s->id, 'program_id' => $program->id, 'academic_period_id' => $period->id, 'run_number' => 1, 'application_revision' => 1, 'automatic_outcome' => 'needs_review', 'completed_at' => now()]);
        }
        $this->actingAs($this->staff('administrator'))->get(route('dashboard'))->assertOk()->assertViewHas('chartMax', fn ($max) => $max >= 110)->assertViewHas('applications', fn ($series) => array_sum($series) === 110)->assertViewHas('statuses', fn ($states) => array_sum(array_column($states, 'count')) === 110)->assertSee('110 semester validations')->assertDontSee('demonstration data');
    }
}
