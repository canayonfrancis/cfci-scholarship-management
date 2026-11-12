<?php

namespace Tests\Feature\Reporting;

use App\Actions\Billing\ManageLiquidation;
use App\Actions\Billing\ManageScholarshipFunds;
use App\Actions\Billing\RecordFinancialActivity;
use App\Actions\Records\ManageScholarshipRoster;
use App\Models\AcademicPeriod;
use App\Models\AcademicRecord;
use App\Models\BillingItem;
use App\Models\Liquidation;
use App\Models\Payout;
use App\Models\ScholarshipAssignment;
use App\Models\ScholarshipBatch;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Models\User;
use App\Services\Reporting\ScholarshipReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsAcknowledgmentRecords;
use Tests\Concerns\BuildsPayrollRecords;
use Tests\TestCase;

class ScholarshipReportsTest extends TestCase
{
    use BuildsAcknowledgmentRecords, BuildsPayrollRecords, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('scholarship');
    }

    private function report(string $kind, array $filters = []): array
    {
        return app(ScholarshipReports::class)->build($kind, $filters);
    }

    private function url(string $report, array $filters = []): string
    {
        return route('scholarship-reports.index', ['report' => $report] + $filters);
    }

    private function roster(array $g, string $name = 'Semester roster'): ScholarshipBatch
    {
        return app(ManageScholarshipRoster::class)->create($g['reviewer'] ?? $g['actor'], ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'batch_number' => $name]);
    }

    private function submission(int $students = 1, ?ScholarshipBatch $roster = null, bool $submit = true, bool $linked = true): array
    {
        $g = $this->ready('TES', '7500.25');
        $g['recorder'] = $this->staff('registrar');
        $g['reviewer'] = $this->staff('coordinator');
        $g['roster'] = $roster ?? $this->roster($g, 'Roster '.$g['student']->id);
        // Explicit fixture link while still draft; never relink submitted history.
        if ($linked) {
            $g['batch']->forceFill(['scholarship_batch_id' => $g['roster']->id])->save();
        }
        $contexts = [$g];
        for ($i = 1; $i < $students; $i++) {
            $contexts[] = $this->ready('TES', '7500.25');
        }
        foreach ($contexts as $context) {
            app(ManageScholarshipRoster::class)->join($context['student'], $g['roster'], $g['reviewer'], $context['assignment']->id);
            $this->actingAs($g['recorder']);
            $this->add($context, $g['batch'])->assertSessionHasNoErrors();
        }
        if ($submit) {
            $this->submit($g['batch'])->assertSessionHasNoErrors();
            $this->actingAs($g['reviewer']);
            if (! $g['terms']->budgets()->exists()) {
                $this->post(route('billing-batches.budget'), ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'previous_budget' => 0, 'amount' => '1000000.00', 'source_reference' => 'Synthetic report test budget'])->assertSessionHasNoErrors();
            }
            $this->post(route('billing-batches.approve', $g['batch']), ['reference' => 'Synthetic independent approval'])->assertSessionHasNoErrors();
        }
        $g['items'] = $g['batch']->items()->orderBy('id')->get();
        $g['item'] = $g['items']->first();

        return $g;
    }

    public function test_roster_reports_include_pending_never_billed_and_non_enrolled_members_without_rates_or_evaluations(): void
    {
        $actor = $this->staff('coordinator');
        $program = ScholarshipProgram::create(['code' => 'TES', 'name' => 'TES']);
        $period = AcademicPeriod::create(['academic_year' => '2026-2027', 'semester_code' => '1']);
        $roster = $this->roster(compact('actor', 'program', 'period'));
        foreach (['NO-SOURCE', 'NOT-ENROLLED', 'CORRECTED'] as $number) {
            $student = Student::create(['student_number' => $number, 'first_name' => 'Synthetic', 'last_name' => $number]);
            $assignment = ScholarshipAssignment::create(['student_id' => $student->id, 'program_id' => $program->id, 'start_period_id' => $period->id, 'status' => 'pending']);
            app(ManageScholarshipRoster::class)->join($student, $roster, $actor, $assignment->id);
            if ($number !== 'NO-SOURCE') {
                $record = AcademicRecord::create(['student_id' => $student->id, 'academic_period_id' => $period->id, 'revision' => 1, 'verification_status' => 'verified', 'enrollment_status' => 'not_enrolled', 'verified_by' => $actor->id, 'verified_at' => now()]);
                if ($number === 'CORRECTED') {
                    AcademicRecord::create(['student_id' => $student->id, 'academic_period_id' => $period->id, 'revision' => 2, 'supersedes_id' => $record->id]);
                }
            }
        }
        foreach (['grants', 'not-billed'] as $kind) {
            $r = $this->report($kind);
            $this->assertSame(3, $r['counts']['rows']);
            $this->assertSame(3, $r['counts']['scholars']);
            $this->assertSame('Verified not enrolled', $r['rows']->firstWhere('student_number', 'NOT-ENROLLED')['enrollment']);
            $this->assertSame('Awaiting Registrar verification', $r['rows']->firstWhere('student_number', 'CORRECTED')['enrollment']);
            $this->actingAs($actor)->get($this->url($kind))->assertOk()->assertSee('Pending approved Form 2/Form 3 template');
        }
        $this->assertDatabaseCount('billing_items', 0);
        $this->assertDatabaseCount('scholarship_applications', 0);
    }

    public function test_membership_rows_do_not_duplicate_distinct_scholar_or_grant_counts(): void
    {
        $g = $this->submission(submit: false);
        $other = $this->roster($g, 'Other roster');
        app(ManageScholarshipRoster::class)->join($g['student'], $other, $g['reviewer'], $g['assignment']->id);
        foreach (['grants', 'not-billed'] as $kind) {
            $r = $this->report($kind);
            $this->assertSame(2, $r['counts']['rows']);
            $this->assertSame(1, $r['counts']['scholars']);
            $this->assertSame(1, $r['counts']['grants']);
            $this->assertCount(2, $r['groups']);
        }
        $this->assertSame(0, $this->report('billed')['counts']['rows']);
        $this->actingAs($g['recorder']);
        $this->submit($g['batch'])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->report('not-billed', ['scholarship_batch_id' => $other->id])['counts']['rows']);
        $this->assertSame(1, $this->report('billed')['counts']['rows']);
    }

    public function test_one_roster_can_report_two_separate_submissions_and_exact_adjusted_amounts(): void
    {
        $first = $this->submission();
        $second = $this->submission(roster: $first['roster']);
        app(ManageScholarshipFunds::class)->adjust($second['item'], $second['reviewer'], ['previous_adjustment' => 0, 'new_amount' => '7000.10', 'reference' => 'SYNTHETIC-ADJUSTMENT', 'reason' => 'Synthetic authorized correction']);
        $r = $this->report('billed', ['scholarship_batch_id' => $first['roster']->id]);
        $this->assertSame(2, $r['counts']['rows']);
        $this->assertSame(2, $r['counts']['billing_batches']);
        $this->assertSame(2, $r['counts']['scholars']);
        $this->assertSame('15000.50', $r['totals']['Total billing amount (original PHP)']);
        $this->assertSame('-500.15', $r['totals']['Signed adjustments (PHP)']);
        $this->assertSame('14500.35', $r['totals']['Effective billing (PHP)']);
        $single = $this->report('billed', ['billing_batch_id' => $second['batch']->id]);
        $this->assertSame('7000.10', $single['rows']->sole()['effective']);
    }

    public function test_actual_funding_shortfall_is_counted_once_and_funded_unpaid_is_separate(): void
    {
        $g = $this->submission(2);
        $this->payrollFunds($g, '1000.10');
        $r = $this->report('awaiting-funds');
        $this->assertSame(2, $r['counts']['rows']);
        $this->assertSame('14000.40', $r['totals']['Batch funding shortfall counted once (PHP)']);
        $this->assertSame('15000.50', $r['totals']['Listed unpaid benefit (PHP)']);
        $this->payrollFunds($g, '14000.40');
        $funded = $this->report('awaiting-funds');
        $this->assertSame(0, $funded['counts']['rows']);
        $this->assertSame('funded', $funded['stages']->firstWhere('batch.id', $g['batch']->id)['state']);
        $this->assertSame('0.00', $funded['totals']['Batch funding shortfall counted once (PHP)']);
    }

    public function test_direct_payment_reduces_benefit_funding_target_without_becoming_school_cash(): void
    {
        $g = $this->submission(2);
        $this->payrollPayment($g, '7500.25', 'direct');
        $r = $this->report('awaiting-funds');
        $this->assertSame(1, $r['counts']['rows']);
        $this->assertSame('7500.25', $r['totals']['Batch funding shortfall counted once (PHP)']);
        $stage = $r['stages']->firstWhere('batch.id', $g['batch']->id);
        $this->assertSame('0.00', $stage['usable_funding']);
        $this->assertSame('7500.25', $stage['direct_applied']);
    }

    public function test_invalid_receipt_proof_is_a_review_exception_instead_of_an_ordinary_awaiting_funds_case(): void
    {
        $g = $this->submission();
        $receipt = $this->payrollFunds($g);
        Storage::disk('scholarship')->put($receipt->documents()->firstOrFail()->storage_path, 'corrupt');
        $r = $this->report('awaiting-funds');
        $this->assertSame(0, $r['counts']['rows']);
        $this->assertSame('review', $r['stages']->firstWhere('batch.id', $g['batch']->id)['state']);
        $this->assertSame('posted', $receipt->fresh()->status);
    }

    public function test_signed_payments_are_counted_once_despite_multiple_evidence_and_acknowledgment_rows(): void
    {
        $g = $this->submission();
        $this->payrollFunds($g);
        $first = $this->payrollPayment($g, '3500.00');
        $second = $this->payrollPayment($g, '4000.25');
        $ack = $this->makeSignedAcknowledgment($first, $g['recorder'], $g['reviewer']);
        $backup = $this->makeSignedAcknowledgment($first, $g['recorder'], $g['reviewer']);
        $this->payrollProof($g, $first, 'payment_evidence');
        $r = $this->report('paid-signed');
        $this->assertSame(1, $r['counts']['rows']);
        $this->assertSame('3500.00', $r['totals']['Signed student net (PHP)']);
        $this->assertStringContainsString('portion only', $r['rows']->sole()['state']);
        $this->assertCount(1, $r['exceptions']);
        $this->makeSignedAcknowledgment($second, $g['recorder'], $g['reviewer']);
        $full = $this->report('paid-signed');
        $this->assertSame('7500.25', $full['totals']['Signed student net (PHP)']);
        $this->assertSame('7500.25', $full['totals']['Signed gross applied (PHP)']);
        $this->assertSame('Benefit fully applied; all payment portions signed', $full['rows']->sole()['state']);
        $this->assertStringContainsString($ack->verified_at->timezone('Asia/Manila')->toIso8601String(), $full['rows']->sole()['payments']);
        $g['student']->update(['record_status' => 'archived', 'first_name' => 'Changed current profile']);
        $historical = $this->report('paid-signed');
        $this->assertSame('7500.25', $historical['totals']['Signed student net (PHP)']);
        $this->assertStringContainsString($first->payee, $historical['rows']->sole()['payments']);
        Storage::disk('scholarship')->delete($ack->document->storage_path);
        $this->assertSame('7500.25', $this->report('paid-signed')['totals']['Signed student net (PHP)']);
        Storage::disk('scholarship')->delete($backup->document->storage_path);
        $this->assertSame('4000.25', $this->report('paid-signed')['totals']['Signed student net (PHP)']);
        $this->assertSame('posted', $first->fresh()->status);
    }

    public function test_ordinary_payment_proof_does_not_qualify_as_signed_payroll(): void
    {
        $g = $this->submission();
        $payment = $this->payrollPayment($g, '7500.25', 'direct');
        $r = $this->report('paid-signed');
        $this->assertSame(0, $r['counts']['rows']);
        $this->assertSame('0.00', $r['totals']['Signed student net (PHP)']);
        $this->assertCount(1, $r['exceptions']);
        $this->assertSame('posted', $payment->fresh()->status);
    }

    public function test_only_accepted_complete_liquidations_count_and_later_evidence_loss_preserves_acceptance(): void
    {
        $g = $this->submission();
        $payment = $this->payrollPayment($g, '7500.25', 'direct');
        $ack = $this->makeSignedAcknowledgment($payment, $g['recorder'], $g['reviewer']);
        $action = app(ManageLiquidation::class);
        $report = $action->prepare($g['batch'], $g['recorder'], 'SYNTHETIC-REPORT-LIQ');
        $this->makeLiquidationSupport($report, $g['recorder'], $g['reviewer']);
        $this->assertSame(0, $this->report('liquidated')['counts']['rows']);
        $action->submit($report, $g['recorder']);
        $this->assertSame(0, $this->report('liquidated')['counts']['rows']);
        $action->review($report, $g['reviewer'], 'accepted', 'Synthetic independent confirmed acceptance');
        $r = $this->report('liquidated');
        $this->assertSame(1, $r['counts']['rows']);
        $this->assertSame(1, $r['counts']['scholars']);
        $this->assertSame('7500.25', $r['totals']['Verified liquidated coverage (PHP)']);
        $this->assertStringEndsWith('+08:00', $r['rows']->sole()['accepted_at']);
        $before = $report->fresh()->getAttributes();
        Storage::disk('scholarship')->delete($ack->document->storage_path);
        $bad = $this->report('liquidated');
        $this->assertSame(0, $bad['counts']['rows']);
        $this->assertCount(1, $bad['exceptions']);
        $this->assertSame($before, $report->fresh()->getAttributes());
    }

    public function test_pagination_csv_and_print_share_whole_result_totals_and_escape_formulas(): void
    {
        $g = $this->submission(26);
        $g['student']->update(['first_name' => '=REPORT-FORMULA']);
        $this->actingAs($g['recorder'])->get($this->url('billed', ['page' => 2]))->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 1)
            ->assertViewHas('result', fn ($r) => $r['counts']['rows'] === 26 && $r['totals']['Total billing amount (original PHP)'] === '195006.50');
        $this->get($this->url('billed', ['format' => 'print']))->assertOk()->assertSee('195006.50')->assertSee('=REPORT-FORMULA');
        $csv = $this->get($this->url('billed', ['format' => 'csv']))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->streamedContent();
        $this->assertStringContainsString('195006.50', $csv);
        $this->assertStringContainsString("'=REPORT-FORMULA", $csv);
        $this->assertStringNotContainsString('storage_path', $csv);
        $this->assertStringNotContainsString('national_id', $csv);
    }

    public function test_filters_validate_program_period_roster_and_submission_scope_and_never_infer_a_roster_link(): void
    {
        $g = $this->submission();
        $other = ScholarshipProgram::create(['code' => 'OTHER', 'name' => 'Other program']);
        $this->actingAs($g['recorder'])->get($this->url('billed', ['program_id' => $other->id]))->assertOk()->assertViewHas('result', fn ($r) => $r['counts']['rows'] === 0);
        $this->get($this->url('billed', ['scholarship_batch_id' => $g['roster']->id, 'program_id' => $other->id]))->assertSessionHasErrors('scholarship_batch_id');
        $this->get($this->url('billed', ['academic_year' => '2026-2027', 'semester_code' => '1', 'billing_batch_id' => $g['batch']->id]))->assertOk()->assertViewHas('result', fn ($r) => $r['counts']['rows'] === 1);
        $this->get($this->url('not-billed', ['billing_batch_id' => $g['batch']->id]))->assertSessionHasErrors('billing_batch_id');
        $this->get($this->url('billed', ['program_id' => 999999]))->assertSessionHasErrors('program_id');
        $this->get($this->url('billed', ['academic_year' => '2026-2027', 'semester_code' => '2']))->assertSessionHasErrors('semester_code');
        $legacy = $this->submission(linked: false);
        $all = $this->report('billed');
        $this->assertSame('Unlinked historical submission', $all['rows']->firstWhere('_billing_id', $legacy['batch']->id)['roster']);
        $this->assertSame(1, $this->report('billed', ['scholarship_batch_id' => $g['roster']->id])['counts']['rows']);
    }

    public static function access(): array
    {
        return [['coordinator', true], ['registrar', true], ['administrator', true], ['finance', false], ['viewer', false]];
    }

    #[DataProvider('access')]
    public function test_report_roles_reuse_existing_roster_and_financial_permissions(string $role, bool $rosterAllowed): void
    {
        $g = $this->submission();
        $this->actingAs($this->staff($role));
        foreach (array_keys(ScholarshipReports::TITLES) as $kind) {
            foreach (['html', 'csv', 'print'] as $format) {
                $this->get($this->url($kind, ['format' => $format]))->assertStatus(in_array($kind, ['grants', 'not-billed'], true) && ! $rosterAllowed ? 403 : 200);
            }
        }
    }

    public function test_unknown_roles_guests_invalid_report_and_empty_database_are_handled(): void
    {
        $this->get($this->url('billed'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get($this->url('billed'))->assertForbidden();
        $this->actingAs($this->staff('coordinator'))->get($this->url('billed'))->assertOk()->assertSee('No qualifying records');
        $this->get($this->url('not-a-report'))->assertNotFound();
    }

    public function test_all_reports_are_read_only_and_legacy_accepted_history_is_not_automatically_certified(): void
    {
        $g = $this->submission();
        $payment = $this->payrollPayment($g, '7500.25', 'direct');
        $migration = require database_path('migrations/2026_11_13_000011_add_signed_payment_acknowledgments.php');
        $migration->down();
        $this->resumeAfterDdl();
        $report = Liquidation::create(['billing_batch_id' => $g['batch']->id, 'sequence' => 1, 'reference' => 'LEGACY-ACCEPTED', 'prepared_by' => $g['recorder']->id]);
        $report->items()->create(['billing_batch_id' => $g['batch']->id, 'payout_id' => $payment->id, 'amount' => '7500.25']);
        $report->update(['status' => 'submitted', 'submitted_by' => $g['recorder']->id, 'submitted_at' => now()]);
        $report->update(['status' => 'accepted', 'reviewed_by' => $g['reviewer']->id, 'reviewed_at' => now(), 'review_notes' => 'Legacy acceptance']);
        $migration->up();
        $this->resumeAfterDdl();
        $before = [];
        foreach (['students', 'scholarship_batch_memberships', 'billing_batches', 'billing_items', 'payouts', 'documents', 'liquidations', 'audit_events'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        foreach (array_keys(ScholarshipReports::TITLES) as $kind) {
            $this->report($kind);
        }
        $this->assertSame([], $writes);
        $this->assertSame(0, $this->report('liquidated')['counts']['rows']);
        $this->assertCount(1, $this->report('liquidated')['exceptions']);
        $this->assertSame(1, $this->report('liquidated')['totals']['Recorded accepted billing histories']);
        $this->assertSame(0, $this->report('liquidated')['totals']['Currently verified liquidated billings']);
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson());
        }
    }

    private function resumeAfterDdl(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::rollBack(0);
            RefreshDatabaseState::$migrated = false;
            DB::beginTransaction();
        }
    }

    public function test_authorized_deductions_and_settlements_are_separate_and_never_double_counted(): void
    {
        $g = $this->submission();
        $this->payrollFunds($g);
        $action = app(RecordFinancialActivity::class);
        $payment = $action->payout($g['recorder'], ['billing_item_id' => $g['item']->id, 'gross_amount' => '7500.25', 'net_amount' => '7000.00', 'payment_mode' => 'school', 'paid_on' => now('Asia/Manila')->toDateString(), 'method' => 'bank_transfer', 'reference' => 'SYNTHETIC-DEDUCTED']);
        $deduction = $action->deduct($payment, $g['reviewer'], ['type' => 'Synthetic specifically permitted deduction', 'amount' => '500.25', 'basis' => 'Synthetic exact-case authority', 'authority_reference' => 'SYNTHETIC-PROVIDER', 'consent_reference' => 'SYNTHETIC-CONSENT', 'destination' => 'Synthetic permitted destination', 'permitted_by_program' => true], UploadedFile::fake()->createWithContent('authority.pdf', "%PDF-1.4\nSynthetic authority\n%%EOF"));
        $this->payrollProof($g, $payment, 'payment_evidence');
        $action->postPayout($payment, $g['reviewer']);
        $this->makeSignedAcknowledgment($payment, $g['recorder'], $g['reviewer']);
        $paid = $this->report('paid-signed');
        $this->assertSame('7500.25', $paid['totals']['Signed gross applied (PHP)']);
        $this->assertSame('7000.00', $paid['totals']['Signed student net (PHP)']);
        $this->assertSame('500.25', $paid['totals']['Authorized deductions (PHP; settlement separate)']);
        $liquidations = app(ManageLiquidation::class);
        $report = $liquidations->prepare($g['batch'], $g['recorder'], 'SYNTHETIC-DEDUCTION-LIQ');
        $this->makeLiquidationSupport($report, $g['recorder'], $g['reviewer']);
        $this->payrollProof($g, $deduction, 'deduction_settlement');
        $action->settle($deduction, $g['reviewer'], ['settled_on' => now('Asia/Manila')->toDateString(), 'settlement_reference' => 'SYNTHETIC-SETTLED']);
        $liquidations->refresh($report, $g['recorder']);
        $liquidations->submit($report, $g['recorder']);
        $liquidations->review($report, $g['reviewer'], 'accepted', 'Synthetic complete reconciliation');
        $r = $this->report('liquidated');
        $this->assertSame(1, $r['counts']['rows']);
        $this->assertSame('7500.25', $r['totals']['Verified liquidated coverage (PHP)']);
        $this->assertSame('7000.00', $r['rows']->sole()['school_net']);
        $this->assertSame('500.25', $r['rows']->sole()['settled']);
    }

    public function test_submitted_unapproved_billing_is_billed_but_not_yet_awaiting_funds(): void
    {
        $g = $this->submission(submit: false);
        $this->actingAs($g['recorder']);
        $this->submit($g['batch'])->assertSessionHasNoErrors();
        $this->assertSame('7500.25', $this->report('billed')['totals']['Total billing amount (original PHP)']);
        $this->assertSame('Awaiting independent approval', $this->report('billed')['rows']->sole()['approval']);
        $awaiting = $this->report('awaiting-funds');
        $this->assertSame(0, $awaiting['counts']['rows']);
        $this->assertSame('submitted', $awaiting['stages']->firstWhere('batch.id', $g['batch']->id)['state']);
    }

    public function test_unallocated_actual_receipts_are_not_batch_funding(): void
    {
        $g = $this->submission();
        $action = app(RecordFinancialActivity::class);
        $receipt = $action->receipt($g['recorder'], ['program_period_term_id' => $g['terms']->id, 'amount' => '7500.25', 'received_on' => now('Asia/Manila')->toDateString(), 'source' => 'Synthetic report receipt', 'reference' => 'UNALLOCATED']);
        $this->payrollProof($g, $receipt, 'receipt_evidence');
        $action->postReceipt($receipt, $g['reviewer']);
        $r = $this->report('awaiting-funds');
        $this->assertSame(1, $r['counts']['rows']);
        $this->assertSame('7500.25', $r['totals']['Batch funding shortfall counted once (PHP)']);
    }

    public function test_academic_period_and_program_filters_use_membership_scope_not_current_profile(): void
    {
        $g = $this->submission(submit: false);
        $program = ScholarshipProgram::create(['code' => 'OTHER', 'name' => 'Other scholarship']);
        $period = AcademicPeriod::create(['academic_year' => '2027-2028', 'semester_code' => '2']);
        $roster = $this->roster(['reviewer' => $g['reviewer'], 'program' => $program, 'period' => $period]);
        $assignment = ScholarshipAssignment::create(['student_id' => $g['student']->id, 'program_id' => $program->id, 'start_period_id' => $period->id, 'status' => 'pending']);
        app(ManageScholarshipRoster::class)->join($g['student'], $roster, $g['reviewer'], $assignment->id);
        $g['student']->update(['academic_period_id' => $period->id]);
        $r = $this->report('grants', ['academic_year' => '2026-2027', 'semester_code' => '1']);
        $this->assertSame(1, $r['counts']['rows']);
        $this->assertSame('TES', $r['rows']->sole()['program']);
        $other = $this->report('grants', ['program_id' => $program->id, 'academic_period_id' => $period->id]);
        $this->assertSame('2027-2028', $other['rows']->sole()['academic_year']);
        $all = $this->report('grants');
        $this->assertSame(2, $all['counts']['rows']);
        $this->assertSame(1, $all['counts']['scholars']);
    }

    public function test_unknown_legacy_draft_amount_is_not_invented_as_submitted_billing_or_confirmed_funding(): void
    {
        $g = $this->applicationContext('EDUKAR');
        BillingItem::create($this->billingItemData($g));
        $r = $this->report('billed');
        $this->assertSame(0, $r['counts']['rows']);
        $stage = $this->report('awaiting-funds')['stages']->firstWhere('batch.id', $g['batch']->id);
        $this->assertNull($stage['effective']);
        $this->assertNull($stage['shortfall']);
        $this->assertSame('draft', $stage['state']);
    }

    public function test_reversed_payment_history_is_not_an_ordinary_signed_paid_grant(): void
    {
        $g = $this->submission();
        $payment = $this->payrollPayment($g, '7500.25', 'direct');
        $this->makeSignedAcknowledgment($payment, $g['recorder'], $g['reviewer']);
        Payout::create(['billing_item_id' => $g['item']->id, 'billing_batch_id' => $g['batch']->id, 'gross_amount' => '1000.10', 'net_amount' => '1000.10', 'payment_mode' => 'direct', 'institutional_student_id' => $payment->institutional_student_id, 'payee' => $payment->payee, 'paid_on' => $payment->paid_on, 'method' => 'bank_transfer', 'reference' => 'SYNTHETIC-REVERSAL', 'entry_type' => 'reversal', 'reversal_of_id' => $payment->id, 'correction_reason' => 'Synthetic retained reversal fixture', 'status' => 'posted', 'posted_by' => $g['reviewer']->id, 'posted_at' => now(), 'posting_key' => 'report-reversal']);
        $r = $this->report('paid-signed');
        $this->assertSame(0, $r['counts']['rows']);
        $this->assertSame('0.00', $r['totals']['Signed student net (PHP)']);
        $this->assertNotEmpty($r['exceptions']);
        $this->assertSame('7500.25', $payment->fresh()->gross_amount);
    }
}
