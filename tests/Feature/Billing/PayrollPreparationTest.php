<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\ManageScholarshipFunds;
use App\Actions\Billing\PreparePayroll;
use App\Actions\Records\RecordAudit;
use App\Models\AcademicRecord;
use App\Models\BillingBatch;
use App\Models\Payroll;
use App\Models\PayrollItem;
use App\Models\User;
use App\Services\Billing\AllocationSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsPayrollRecords;
use Tests\TestCase;

class PayrollPreparationTest extends TestCase
{
    use BuildsPayrollRecords, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('scholarship');
    }

    private function prepare(array $g, array $data = [])
    {
        return $this->actingAs($g['recorder'])->post(route('financial.payrolls.store', $g['batch']), ['reference' => 'PAYROLL-001', ...$data]);
    }

    public function test_full_funding_prepares_all_outstanding_lines_without_creating_or_counting_a_payment(): void
    {
        $g = $this->payrollBatch(2);
        $this->payrollFunds($g);
        $before = app(AllocationSummary::class)->forTerms($g['terms']);
        $student = $g['student']->fresh()->getAttributes();
        $bill = $g['batch']->fresh()->getAttributes();
        $this->prepare($g, ['reference' => '  PAYROLL-001  '])->assertSessionHasNoErrors();
        $payroll = Payroll::sole();
        $this->assertSame('prepared', $payroll->status);
        $this->assertSame('PAYROLL-001', $payroll->reference);
        $this->assertSame($g['recorder']->id, $payroll->prepared_by);
        $this->assertNotNull($payroll->prepared_at);
        $this->assertSame('15000.50', $payroll->totalPlanned());
        $this->assertSame('15000.50', $payroll->funding_snapshot['allocated_school_cash']);
        $this->assertSame($g['items']->pluck('id')->all(), $payroll->items()->orderBy('billing_item_id')->pluck('billing_item_id')->all());
        $this->assertSame('0.00', PayrollItem::first()->posted_gross_snapshot);
        $this->assertSame('7500.25', PayrollItem::first()->planned_gross_amount);
        foreach (['allocated', 'received', 'released_net', 'direct_paid_net'] as $key) {
            $this->assertSame($before[$key], app(AllocationSummary::class)->forTerms($g['terms'])[$key]);
        }
        foreach (['payouts', 'deductions', 'liquidations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($student, $g['student']->fresh()->getAttributes());
        $this->assertSame($bill, $g['batch']->fresh()->getAttributes());
        $this->get(route('financial.payrolls.show', $payroll))->assertOk()->assertSee('PREPARED ONLY')->assertSee('15000.50')->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('financial.payrolls.print', $payroll))->assertOk()->assertSee('PREPARED ONLY')->assertSee('15000.50');
        $this->get(route('financial.batch', $g['batch']))->assertOk()->assertSee('Open payroll PAYROLL-001');
    }

    public function test_budget_expected_funds_and_partial_cash_do_not_allow_preparation(): void
    {
        $g = $this->payrollBatch();
        $this->prepare($g)->assertSessionHasErrors('payroll');
        $this->payrollFunds($g, '1000.10');
        $this->prepare($g)->assertSessionHasErrors('payroll');
        $this->assertDatabaseCount('payrolls', 0);
        $this->assertDatabaseCount('payroll_items', 0);
        $this->assertDatabaseCount('payouts', 0);
    }

    public function test_unapproved_batch_and_missing_receipt_evidence_are_rejected(): void
    {
        $g = $this->payrollBatch();
        $receipt = $this->payrollFunds($g);
        $draft = BillingBatch::whereKeyNot($g['batch']->id)->firstOrFail();
        $this->actingAs($g['recorder'])->post(route('financial.payrolls.store', $draft), ['reference' => 'DRAFT'])->assertSessionHasErrors('payroll');
        Storage::disk('scholarship')->put($receipt->documents()->firstOrFail()->storage_path, 'changed');
        $this->prepare($g)->assertSessionHasErrors('payroll');
        $this->assertDatabaseCount('payrolls', 0);
        $this->assertSame('posted', $receipt->fresh()->status);
    }

    public function test_current_eligibility_is_required_despite_full_funding(): void
    {
        $g = $this->payrollBatch();
        $this->payrollFunds($g);
        AcademicRecord::create(['student_id' => $g['student']->id, 'academic_period_id' => $g['period']->id, 'revision' => 2, 'supersedes_id' => $g['record']->id]);
        $this->prepare($g)->assertSessionHasErrors('billing');
        $this->assertDatabaseCount('payrolls', 0);
    }

    public function test_retry_is_idempotent_and_another_reference_cannot_duplicate_the_register(): void
    {
        $g = $this->payrollBatch();
        $this->payrollFunds($g);
        $this->prepare($g)->assertSessionHasNoErrors();
        $prepared = Payroll::sole()->getAttributes();
        $this->prepare($g, ['reference' => ' payroll-001 '])->assertSessionHasNoErrors();
        $this->prepare($g, ['reference' => 'ANOTHER'])->assertSessionHasErrors('payroll');
        $this->assertSame($prepared, Payroll::sole()->getAttributes());
        $this->assertDatabaseCount('payroll_items', 1);
        $this->assertDatabaseCount('payouts', 0);
    }

    public function test_existing_partial_payments_remain_separate_and_new_actual_payments_keep_existing_safeguards(): void
    {
        $g = $this->payrollBatch();
        $this->payrollFunds($g);
        $first = $this->payrollPayment($g, '1000.10');
        $attributes = $first->getAttributes();
        $this->prepare($g)->assertSessionHasNoErrors();
        $line = PayrollItem::sole();
        $this->assertSame('1000.10', $line->posted_gross_snapshot);
        $this->assertSame('6500.15', $line->planned_gross_amount);
        $this->assertSame($attributes, $first->fresh()->getAttributes());
        $this->payrollPayment($g, '6500.15');
        $this->assertSame('prepared', Payroll::sole()->status);
        $this->assertSame('6500.15', $line->fresh()->planned_gross_amount);
        $this->get(route('financial.payrolls.show', Payroll::sole()))->assertOk()->assertSee('Source changed since preparation')->assertSee('0.00');
        $this->assertDatabaseCount('payouts', 2);
    }

    public function test_recorded_direct_payments_do_not_become_school_cash_or_new_direct_plans(): void
    {
        $g = $this->payrollBatch(2);
        $this->payrollPayment($g, '7500.25', 'direct');
        $this->assertDatabaseCount('funding_receipts', 0);
        $this->prepare($g)->assertSessionHasErrors('payroll');
        $this->payrollFunds($g, '7500.25');
        $this->prepare($g)->assertSessionHasNoErrors();
        $payroll = Payroll::sole();
        $this->assertSame('school', $payroll->funding_snapshot['payment_path']);
        $this->assertSame('7500.25', $payroll->funding_snapshot['previous_direct_gross']);
        $this->assertSame('7500.25', $payroll->funding_snapshot['allocated_school_cash']);
        $this->assertSame($g['items']->last()->id, PayrollItem::sole()->billing_item_id);
        $this->assertDatabaseCount('payouts', 1);
    }

    public function test_authorized_adjusted_amounts_are_snapshotted_exactly(): void
    {
        $g = $this->payrollBatch();
        app(ManageScholarshipFunds::class)->adjust($g['item'], $g['reviewer'], ['previous_adjustment' => 0, 'new_amount' => '7000.10', 'reason' => 'Synthetic correction', 'reference' => 'ADJUST']);
        $this->payrollFunds($g, '7000.10');
        $this->prepare($g)->assertSessionHasNoErrors();
        $this->assertSame('7000.10', PayrollItem::sole()->effective_amount_snapshot);
        $this->assertSame('7500.25', $g['item']->fresh()->gross_amount);
    }

    public function test_identity_snapshot_is_retained_and_viewer_cannot_prepare_but_can_read_existing_registers(): void
    {
        $g = $this->payrollBatch();
        $this->payrollFunds($g);
        $this->prepare($g)->assertSessionHasNoErrors();
        $line = PayrollItem::sole();
        $number = $line->institutional_student_id;
        $name = $line->beneficiary_name;
        $g['student']->update(['student_number' => 'CHANGED-ID', 'first_name' => 'Changed']);
        $this->assertSame($number, $line->fresh()->institutional_student_id);
        $this->assertSame($name, $line->fresh()->beneficiary_name);
        $this->actingAs($this->staff('viewer'))->get(route('financial.payrolls.show', Payroll::sole()))->assertOk()->assertSee($number)->assertDontSee('CHANGED-ID');
        $this->get(route('financial.payrolls.print', Payroll::sole()))->assertOk()->assertSee($name);
        $this->actingAs(User::factory()->create())->get(route('financial.payrolls.show', Payroll::sole()))->assertForbidden();
        auth()->logout();
        $this->get(route('financial.payrolls.show', Payroll::sole()))->assertRedirect(route('login'));
    }

    public function test_all_direct_paid_benefit_is_not_prepared_again(): void
    {
        $g = $this->payrollBatch();
        $this->payrollPayment($g, '7500.25', 'direct');
        $this->prepare($g)->assertSessionHasErrors('payroll');
        $this->assertDatabaseCount('payrolls', 0);
        $this->assertDatabaseCount('funding_receipts', 0);
    }

    public static function roles(): array
    {
        return [['registrar', true], ['coordinator', true], ['administrator', true], ['finance', true], ['viewer', false], ['unknown', false]];
    }

    #[DataProvider('roles')]
    public function test_preparation_authority_is_enforced_on_direct_requests(string $role, bool $allowed): void
    {
        $g = $this->payrollBatch();
        $this->payrollFunds($g);
        $this->actingAs($this->staff($role))->post(route('financial.payrolls.store', $g['batch']), ['reference' => 'ROLE'])->assertStatus($allowed ? 302 : 403);
        $this->assertDatabaseCount('payrolls', $allowed ? 1 : 0);
    }

    public function test_forged_amounts_payment_paths_dates_and_paid_flags_are_not_preparation_inputs(): void
    {
        $g = $this->payrollBatch();
        $this->payrollFunds($g);
        foreach (['items' => [999], 'gross_amount' => '1.00', 'net_amount' => '1.00', 'payment_mode' => 'direct', 'status' => 'paid', 'prepared_by' => $g['reviewer']->id, 'prepared_at' => '2020-01-01', 'payout_id' => 999] as $field => $value) {
            $this->prepare($g, [$field => $value])->assertSessionHasErrors($field);
        }
        $this->prepare($g, ['reference' => ' '])->assertSessionHasErrors('reference');
        $this->assertDatabaseCount('payrolls', 0);
    }

    public function test_prepared_lines_are_scoped_immutable_and_cannot_be_appended_or_marked_paid(): void
    {
        $g = $this->payrollBatch();
        $this->payrollFunds($g);
        $this->prepare($g)->assertSessionHasNoErrors();
        $payroll = Payroll::sole();
        $line = PayrollItem::sole();
        $this->assertDatabaseRejects(fn () => $payroll->update(['status' => 'paid']));
        $this->assertDatabaseRejects(fn () => $payroll->delete());
        $this->assertDatabaseRejects(fn () => $line->update(['planned_gross_amount' => '1.00']));
        $this->assertDatabaseRejects(fn () => $line->delete());
        $this->assertDatabaseRejects(fn () => PayrollItem::create($line->only($line->getFillable())));
        $other = $this->ready('TDP', '13500.00');
        $this->add($other)->assertSessionHasNoErrors();
        $this->submit($other['batch'])->assertSessionHasNoErrors();
        $draft = Payroll::create(['billing_batch_id' => $other['batch']->id, 'reference' => 'Synthetic draft', 'prepared_by' => $g['recorder']->id, 'funding_snapshot' => []]);
        $data = array_replace($line->only($line->getFillable()), ['payroll_id' => $draft->id]);
        $this->assertDatabaseRejects(fn () => PayrollItem::create($data));
        $this->assertDatabaseRejects(fn () => Payroll::create(['billing_batch_id' => $g['batch']->id, 'reference' => 'DUPLICATE', 'prepared_by' => $g['recorder']->id, 'funding_snapshot' => []]));
    }

    public function test_audit_failure_rolls_back_the_entire_register_and_preserves_financial_records(): void
    {
        $g = $this->payrollBatch();
        $this->payrollFunds($g);
        $this->mock(RecordAudit::class)->shouldReceive('record')->andThrow(new RuntimeException('Synthetic payroll audit failure'));
        try {
            app(PreparePayroll::class)->handle($g['batch'], $g['recorder'], 'AUDIT');
            $this->fail('Expected failure');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic payroll audit failure', $error->getMessage());
        }
        $this->assertDatabaseCount('payrolls', 0);
        $this->assertDatabaseCount('payroll_items', 0);
        $this->assertDatabaseCount('funding_receipts', 1);
        $this->assertDatabaseCount('payouts', 0);
    }

    public function test_migration_round_trip_preserves_existing_tables_and_populated_rollback_is_refused(): void
    {
        $g = $this->payrollBatch();
        $this->payrollFunds($g);
        $before = [];
        foreach (['users', 'user_roles', 'students', 'billing_batches', 'billing_items', 'funding_receipts', 'funding_allocations', 'payouts'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $migration = require database_path('migrations/2026_11_13_000010_add_payroll_preparation.php');
        $migration->down();
        $migration->up();
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson());
        }
        $this->prepare($g)->assertSessionHasNoErrors();
        try {
            $migration->down();
            $this->fail('Expected protected payroll history');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Retain prepared payroll history', $error->getMessage());
        }
        $this->assertDatabaseCount('payrolls', 1);
    }
}
