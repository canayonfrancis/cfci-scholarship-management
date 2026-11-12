<?php

namespace Tests\Feature\Billing;

use App\Actions\Records\ManageScholarshipRoster;
use App\Actions\Records\RecordAudit;
use App\Models\BillingBatch;
use App\Models\Deduction;
use App\Models\Document;
use App\Models\FundingAllocation;
use App\Models\FundingReceipt;
use App\Models\Liquidation;
use App\Models\Payout;
use App\Models\User;
use App\Services\Billing\AllocationSummary;
use App\Services\Billing\BatchFundingStatus;
use App\Services\Billing\LiquidationSummary;
use App\Services\Billing\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsAcknowledgmentRecords;
use Tests\Concerns\BuildsBillableAllocations;
use Tests\Concerns\BuildsScholarshipRecords;
use Tests\TestCase;

class FinancialRecordingTest extends TestCase
{
    use BuildsAcknowledgmentRecords, BuildsBillableAllocations, BuildsScholarshipRecords, RefreshDatabase;

    public function test_unbilled_roster_includes_draft_reservations_until_actual_submission(): void
    {
        $g = $this->ready('TES', '7500.25');
        $coordinator = $this->staff('coordinator');
        $roster = app(ManageScholarshipRoster::class)->create($coordinator, ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'batch_number' => 'Semester roster']);
        app(ManageScholarshipRoster::class)->join($g['student'], $roster, $coordinator, $g['assignment']->id);
        $this->add($g)->assertSessionHasNoErrors();
        $url = route('scholarship-batches.show', [$roster, 'view' => 'unbilled']);
        $this->actingAs($coordinator)->get($url)->assertOk()->assertViewHas('members', fn ($m) => $m->total() === 1);
        $this->submit($g['batch'])->assertSessionHasNoErrors();
        $this->get($url)->assertViewHas('members', fn ($m) => $m->total() === 0);
        $this->assertDatabaseCount('scholarship_batch_memberships', 1);
    }

    public function test_funding_monitor_applies_retained_allocation_reversal_once_and_effective_adjustments(): void
    {
        $g = $this->approved();
        $this->funds($g, '1000.00');
        $allocation = FundingAllocation::sole();
        FundingAllocation::create([
            'funding_receipt_id' => $allocation->funding_receipt_id, 'billing_batch_id' => $allocation->billing_batch_id,
            'program_id' => $allocation->program_id, 'academic_period_id' => $allocation->academic_period_id,
            'amount' => '200.10', 'entry_type' => 'reversal', 'reversal_of_id' => $allocation->id,
            'correction_reason' => 'Synthetic retained correction fixture', 'status' => 'posted',
            'posting_key' => 'synthetic-reversal', 'posted_by' => $g['admin']->id, 'posted_at' => now(),
        ]);
        $this->actingAs($g['admin'])->post(route('billing-batches.items.adjust', [$g['batch'], $g['item']]), ['previous_adjustment' => 0, 'new_amount' => '7000.00', 'reason' => 'Synthetic authorized correction', 'reference' => 'TEST-ADJUST'])->assertSessionHasNoErrors();
        $row = app(BatchFundingStatus::class)->rows()->firstWhere('batch.id', $g['batch']->id);
        $this->assertSame('799.90', $row['allocated']);
        $this->assertSame('799.90', $row['usable_funding']);
        $this->assertSame('7000.00', $row['effective']);
        $this->assertSame('6200.10', $row['shortfall']);
        $this->assertSame('7500.25', $g['item']->fresh()->gross_amount);
    }

    public function test_roster_migration_round_trip_retains_submitted_history_credentials_and_history_triggers(): void
    {
        $g = $this->approved();
        $before = $g['batch']->fresh()->getAttributes();
        $users = DB::table('users')->orderBy('id')->get()->toJson();
        $students = DB::table('students')->orderBy('id')->get()->toJson();
        $item = $g['item']->fresh()->getAttributes();
        $migration = require database_path('migrations/2026_11_13_000009_add_independent_scholarship_rosters.php');
        $migration->down();
        $migration->up();
        $this->assertSame($before, $g['batch']->fresh()->getAttributes());
        $this->assertSame($item, $g['item']->fresh()->getAttributes());
        $this->assertSame($users, DB::table('users')->orderBy('id')->get()->toJson());
        $this->assertSame($students, DB::table('students')->orderBy('id')->get()->toJson());
        $this->assertDatabaseRejects(fn () => DB::table('billing_batches')->where('id', $g['batch']->id)->update(['reference' => 'Changed history']));
        $this->assertDatabaseRejects(fn () => DB::table('billing_items')->where('id', $g['item']->id)->delete());
    }

    public function test_legacy_overallocated_receipt_is_flagged_instead_of_overstating_funding(): void
    {
        $g = $this->approved();
        $receipt = $this->funds($g, '1000.00');
        FundingAllocation::create([
            'funding_receipt_id' => $receipt->id, 'billing_batch_id' => $g['batch']->id, 'program_id' => $g['program']->id,
            'academic_period_id' => $g['period']->id, 'amount' => '1000.00', 'status' => 'posted',
            'posting_key' => 'synthetic-legacy-capacity', 'posted_by' => $g['admin']->id, 'posted_at' => now(),
        ]);
        $row = app(BatchFundingStatus::class)->rows()->firstWhere('batch.id', $g['batch']->id);
        $this->assertSame('review', $row['state']);
        $this->assertSame('2000.00', $row['allocated']);
        $this->assertSame('0.00', $row['usable_funding']);
        $this->assertStringContainsString('allocation capacity', implode(' ', $row['issues']));
    }

    public function test_funding_monitor_separates_draft_submitted_and_approved_from_budget_and_actual_cash(): void
    {
        $g = $this->approved();
        $rows = app(BatchFundingStatus::class)->rows();
        $this->assertSame('awaiting_funds', $rows->firstWhere('batch.id', $g['batch']->id)['state']);
        $this->assertSame('7500.25', $rows->firstWhere('batch.id', $g['batch']->id)['shortfall']);
        $this->assertSame('draft', $rows->firstWhere('batch.id', $g['batch']->id - 1)['state']);
        $this->get(route('financial.funding-status', ['stage' => 'awaiting_funds']))->assertOk()->assertViewHas('rows', fn ($r) => $r->total() === 1)->assertViewHas('shortfall', '7500.25');
        $this->assertDatabaseCount('funding_receipts', 0);
        $this->assertDatabaseCount('payouts', 0);
        $other = $this->ready('TDP', '13500.00');
        $this->add($other)->assertSessionHasNoErrors();
        $this->submit($other['batch'])->assertSessionHasNoErrors();
        $row = app(BatchFundingStatus::class)->rows()->firstWhere('batch.id', $other['batch']->id);
        $this->assertSame('submitted', $row['state']);
        $this->assertNull($row['shortfall']);
    }

    public function test_only_allocated_verified_cash_counts_and_school_payments_do_not_reduce_the_funding_target_twice(): void
    {
        $g = $this->approved();
        $receipt = $this->funds($g, '4000.00');
        $payment = $this->payment($g, ['payment_mode' => 'school', 'gross_amount' => '1000.10', 'net_amount' => '1000.10']);
        $this->proof($g, 'payout', $payment, 'payment_evidence');
        $this->post(route('financial.payouts.post', $payment))->assertSessionHasNoErrors();
        $this->proof($g, 'receipt', $receipt, 'receipt_evidence');
        $row = app(BatchFundingStatus::class)->rows()->firstWhere('batch.id', $g['batch']->id);
        $this->assertSame('4000.00', $row['usable_funding']);
        $this->assertSame('3500.25', $row['shortfall']);
        $this->assertSame('6500.15', $row['outstanding']);
        $this->funds($g, '3500.25');
        $row = app(BatchFundingStatus::class)->rows()->firstWhere('batch.id', $g['batch']->id);
        $this->assertSame('funded', $row['state']);
        $this->assertSame('0.00', $row['shortfall']);
    }

    public function test_unallocated_posted_receipt_and_draft_allocation_do_not_fund_a_batch(): void
    {
        $g = $this->approved();
        $this->actingAs($g['finance'])->post(route('financial.receipts.store'), ['program_period_term_id' => $g['terms']->id, 'amount' => '7500.25', 'received_on' => now('Asia/Manila')->toDateString(), 'source' => 'Synthetic provider', 'reference' => 'UNALLOCATED'])->assertSessionHasNoErrors();
        $receipt = FundingReceipt::sole();
        $this->proof($g, 'receipt', $receipt, 'receipt_evidence');
        $this->post(route('financial.receipts.post', $receipt))->assertSessionHasNoErrors();
        FundingAllocation::create(['funding_receipt_id' => $receipt->id, 'billing_batch_id' => $g['batch']->id, 'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'amount' => '7500.25']);
        $row = app(BatchFundingStatus::class)->rows()->firstWhere('batch.id', $g['batch']->id);
        $this->assertSame('0.00', $row['usable_funding']);
        $this->assertSame('7500.25', $row['shortfall']);
    }

    public function test_direct_payments_reduce_school_funding_need_without_inventing_school_cash(): void
    {
        $g = $this->approved();
        $payment = $this->payment($g);
        $this->proof($g, 'payout', $payment, 'payment_evidence');
        $this->post(route('financial.payouts.post', $payment))->assertSessionHasNoErrors();
        $row = app(BatchFundingStatus::class)->rows()->firstWhere('batch.id', $g['batch']->id);
        $this->assertSame('applied', $row['state']);
        $this->assertSame('0.00', $row['usable_funding']);
        $this->assertSame('0.00', $row['shortfall']);
        $this->assertSame('7500.25', $row['direct_applied']);
        $this->assertDatabaseCount('funding_receipts', 0);
    }

    public function test_lost_receipt_proof_requires_review_without_rewriting_posted_history(): void
    {
        $g = $this->approved();
        $receipt = $this->funds($g);
        $attributes = $receipt->getAttributes();
        Storage::disk('scholarship')->put($receipt->documents()->firstOrFail()->storage_path, 'corrupted');
        $row = app(BatchFundingStatus::class)->rows()->firstWhere('batch.id', $g['batch']->id);
        $this->assertSame('review', $row['state']);
        $this->assertSame('7500.25', $row['allocated']);
        $this->assertSame('0.00', $row['usable_funding']);
        $this->assertSame($attributes, $receipt->fresh()->getAttributes());
    }

    public function test_funding_view_validates_scope_and_authority_and_does_not_write_financial_records(): void
    {
        $g = $this->approved();
        $before = DB::table('audit_events')->count();
        foreach (['registrar', 'coordinator', 'administrator', 'viewer'] as $role) {
            $this->actingAs($this->staff($role))->get(route('financial.funding-status', ['program_period_term_id' => $g['terms']->id]))->assertOk()->assertViewHas('rows', fn ($r) => $r->total() === 2)->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->get(route('financial.funding-status', ['stage' => 'paid']))->assertSessionHasErrors('stage');
        $this->get(route('financial.funding-status', ['program_period_term_id' => 999999]))->assertSessionHasErrors('program_period_term_id');
        $this->actingAs(User::factory()->create())->get(route('financial.funding-status'))->assertForbidden();
        $this->assertSame($before, DB::table('audit_events')->count());
        $this->assertDatabaseCount('funding_receipts', 0);
        $this->assertDatabaseCount('payouts', 0);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('scholarship');
    }

    private function approved(): array
    {
        $g = $this->ready('TES', '7500.25');
        $g['finance'] = auth()->user();
        $this->post(route('billing-batches.store'), ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id])->assertSessionHasNoErrors();
        $g['batch'] = BillingBatch::latest('id')->firstOrFail();
        $this->add($g)->assertSessionHasNoErrors();
        $this->submit($g['batch'])->assertSessionHasNoErrors();
        $g['admin'] = $this->staff('administrator');
        $this->actingAs($g['admin'])->post(route('billing-batches.budget'), ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'previous_budget' => 0, 'amount' => '30000.00', 'source_reference' => 'SAMPLE approved test budget'])->assertSessionHasNoErrors();
        $this->post(route('billing-batches.approve', $g['batch']), ['reference' => 'SAMPLE batch approval'])->assertSessionHasNoErrors();
        $g['item'] = $g['batch']->items()->firstOrFail();

        return $g;
    }

    private function proof(array $g, string $kind, $owner, string $type): Document
    {
        $this->actingAs($g['finance'])->post(route('financial.evidence.store', [$kind, $owner->id]), ['document_type' => $type, 'evidence' => UploadedFile::fake()->createWithContent('sample-proof.pdf', "%PDF-1.4\nFictional financial test evidence\n%%EOF")])->assertSessionHasNoErrors();
        $document = $owner->documents()->latest('id')->firstOrFail();
        $this->actingAs($g['admin'])->post(route('financial.evidence.review', [$kind, $owner->id, $document]), ['decision' => 'accepted', 'review_notes' => 'Verified fictional source for test'])->assertSessionHasNoErrors();

        return $document->fresh();
    }

    private function funds(array $g, string $amount = '7500.25'): FundingReceipt
    {
        $this->actingAs($g['finance'])->post(route('financial.receipts.store'), ['program_period_term_id' => $g['terms']->id, 'amount' => $amount, 'received_on' => now('Asia/Manila')->toDateString(), 'source' => 'SAMPLE UniFAST', 'reference' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $receipt = FundingReceipt::latest('id')->firstOrFail();
        $this->proof($g, 'receipt', $receipt, 'receipt_evidence');
        $this->post(route('financial.receipts.post', $receipt))->assertSessionHasNoErrors();
        $this->actingAs($g['finance'])->post(route('financial.allocate', $g['batch']), ['funding_receipt_id' => $receipt->id, 'amount' => $amount, 'operation_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        return $receipt->fresh();
    }

    private function payment(array $g, array $overrides = []): Payout
    {
        $data = array_replace(['billing_item_id' => $g['item']->id, 'payment_mode' => 'direct', 'gross_amount' => '7500.25', 'net_amount' => '7500.25', 'paid_on' => now('Asia/Manila')->toDateString(), 'method' => 'bank_transfer', 'reference' => (string) Str::uuid()], $overrides);
        $this->actingAs($g['finance'])->post(route('financial.payouts.store'), $data)->assertSessionHasNoErrors();

        return Payout::latest('id')->firstOrFail();
    }

    private function deductionData(array $overrides = []): array
    {
        return array_replace(['type' => 'SAMPLE individually permitted item', 'amount' => '500.25', 'basis' => 'Specific fictional program permission and beneficiary instruction; no automatic benefit deduction.', 'authority_reference' => 'SAMPLE provider authorization', 'consent_reference' => 'SAMPLE beneficiary instruction', 'destination' => 'SAMPLE permitted destination', 'permitted_by_program' => 1, 'authority_evidence' => UploadedFile::fake()->createWithContent('specific-authority.pdf', "%PDF-1.4\nFictional written authority\n%%EOF")], $overrides);
    }

    public function test_verified_direct_payment_is_separate_from_school_cash_and_retains_institutional_id(): void
    {
        $g = $this->approved();
        $payment = $this->payment($g);
        $this->assertSame($g['student']->student_number, $payment->institutional_student_id);
        $this->actingAs($g['admin'])->post(route('financial.payouts.post', $payment))->assertSessionHasErrors('finance');
        $this->proof($g, 'payout', $payment, 'payment_evidence');
        $this->post(route('financial.payouts.post', $payment))->assertSessionHasNoErrors();
        $this->post(route('financial.payouts.post', $payment))->assertSessionHasNoErrors();
        $summary = app(AllocationSummary::class)->forTerms($g['terms']);
        $this->assertSame('0.00', $summary['received']);
        $this->assertSame('0.00', $summary['released_net']);
        $this->assertSame('7500.25', $summary['direct_paid_net']);
        $this->assertDatabaseCount('funding_receipts', 0);
        $this->assertDatabaseCount('funding_allocations', 0);
        $this->assertDatabaseCount('payouts', 1);
        $id = $payment->institutional_student_id;
        $g['student']->update(['student_number' => 'UPDATED-STUDENT-ID']);
        $this->assertSame($id, $payment->fresh()->institutional_student_id);
        $this->get(route('financial.payout', $payment))->assertOk()->assertSee($id)->assertSee('Direct to student');
    }

    public function test_school_payment_cannot_treat_billed_or_budgeted_funds_as_received(): void
    {
        $g = $this->approved();
        $payment = $this->payment($g, ['payment_mode' => 'school']);
        $this->proof($g, 'payout', $payment, 'payment_evidence');
        $this->post(route('financial.payouts.post', $payment))->assertSessionHasErrors('finance');
        $this->assertSame('draft', $payment->fresh()->status);
        $receipt = $this->funds($g);
        $this->actingAs($g['admin'])->post(route('financial.payouts.post', $payment))->assertSessionHasNoErrors();
        $summary = app(AllocationSummary::class)->forTerms($g['terms']);
        $this->assertSame('7500.25', $summary['received']);
        $this->assertSame('7500.25', $summary['released_net']);
        $this->assertSame('0.00', $summary['direct_paid_net']);
        $this->assertSame('posted', $receipt->status);
    }

    public function test_receipts_require_evidence_and_independent_verification_and_cannot_be_recorded_twice(): void
    {
        $g = $this->approved();
        $data = ['program_period_term_id' => $g['terms']->id, 'amount' => '7500.25', 'received_on' => now('Asia/Manila')->toDateString(), 'source' => 'SAMPLE Provider', 'reference' => 'SAMPLE-RECEIPT-001'];
        $this->actingAs($g['finance'])->post(route('financial.receipts.store'), $data)->assertSessionHasNoErrors();
        $receipt = FundingReceipt::sole();
        $this->post(route('financial.receipts.store'), $data)->assertSessionHasErrors('finance');
        $this->post(route('financial.receipts.post', $receipt))->assertForbidden();
        $g['finance']->roles()->create(['role' => 'administrator']);
        $this->actingAs($g['finance']->fresh());
        $this->post(route('financial.receipts.post', $receipt))->assertSessionHasErrors('finance');
        $this->actingAs($g['admin'])->post(route('financial.receipts.post', $receipt))->assertSessionHasErrors('finance');
        $this->assertSame('0.00', app(AllocationSummary::class)->forTerms($g['terms'])['received']);
        $this->proof($g, 'receipt', $receipt, 'receipt_evidence');
        $this->post(route('financial.receipts.post', $receipt))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('funding_receipts', 1);
        $this->assertDatabaseCount('payouts', 0);
    }

    public function test_cash_allocations_are_capped_and_operation_retries_are_idempotent(): void
    {
        $g = $this->approved();
        $receipt = $this->funds($g, '5000.00');
        $allocation = FundingAllocation::sole();
        $data = ['funding_receipt_id' => $receipt->id, 'amount' => '5000.00', 'operation_key' => substr($allocation->posting_key, strlen('funding:'))];
        $this->post(route('financial.allocate', $g['batch']), $data)->assertSessionHasNoErrors();
        $data['operation_key'] = (string) Str::uuid();
        $data['amount'] = '1.00';
        $this->post(route('financial.allocate', $g['batch']), $data)->assertSessionHasErrors('finance');
        $this->assertDatabaseCount('funding_allocations', 1);
        $this->assertSame('5000.00', FundingAllocation::sole()->amount);
    }

    public function test_competing_payment_drafts_cannot_spend_the_same_remaining_student_allocation(): void
    {
        $g = $this->approved();
        $first = $this->payment($g, ['gross_amount' => '4000.00', 'net_amount' => '4000.00']);
        $second = $this->payment($g, ['gross_amount' => '4000.00', 'net_amount' => '4000.00']);
        $this->proof($g, 'payout', $first, 'payment_evidence');
        $this->post(route('financial.payouts.post', $first))->assertSessionHasNoErrors();
        $this->proof($g, 'payout', $second, 'payment_evidence');
        $this->post(route('financial.payouts.post', $second))->assertSessionHasErrors('finance');
        $this->assertSame('draft', $second->fresh()->status);
        $this->assertSame('4000.00', app(AllocationSummary::class)->forTerms($g['terms'])['direct_paid_net']);
    }

    public function test_unauthorized_unsupported_and_excess_deductions_are_rejected(): void
    {
        $g = $this->approved();
        $payment = $this->payment($g, ['payment_mode' => 'school', 'net_amount' => '7000.00']);
        $this->post(route('financial.deduct', $payment), $this->deductionData())->assertForbidden();
        $this->actingAs($g['admin'])->post(route('financial.deduct', $payment), $this->deductionData(['authority_reference' => null]))->assertSessionHasErrors('authority_reference');
        $this->post(route('financial.deduct', $payment), $this->deductionData(['permitted_by_program' => 0]))->assertSessionHasErrors('permitted_by_program');
        $this->post(route('financial.deduct', $payment), $this->deductionData(['amount' => '501.00']))->assertSessionHasErrors('finance');
        $direct = $this->payment($g);
        $this->actingAs($g['admin'])->post(route('financial.deduct', $direct), $this->deductionData())->assertSessionHasErrors('finance');
        $this->assertDatabaseCount('deductions', 0);
        $this->proof($g, 'payout', $payment, 'payment_evidence');
        $this->post(route('financial.payouts.post', $payment))->assertSessionHasErrors('finance');
    }

    public function test_authorized_deductions_settlement_and_complete_liquidation_reconcile_without_double_counting(): void
    {
        $g = $this->approved();
        $this->funds($g);
        $payment = $this->payment($g, ['payment_mode' => 'school', 'net_amount' => '7000.00']);
        $this->actingAs($g['admin'])->post(route('financial.deduct', $payment), $this->deductionData())->assertSessionHasNoErrors();
        $deduction = Deduction::sole();
        $this->proof($g, 'payout', $payment, 'payment_evidence');
        $this->post(route('financial.payouts.post', $payment))->assertSessionHasNoErrors();
        $s = app(LiquidationSummary::class)->forBatch($g['batch']);
        $this->assertFalse($s['complete']);
        $this->assertSame('7000.00', $s['school_net']);
        $this->assertSame('500.25', $s['retained']);
        $this->actingAs($g['finance'])->post(route('financial.liquidations.store', $g['batch']), ['reference' => 'SAMPLE-LIQUIDATION'])->assertSessionHasNoErrors();
        $report = Liquidation::sole();
        $this->post(route('financial.liquidations.submit', $report))->assertSessionHasErrors('liquidation');
        $this->makeSignedAcknowledgment($payment, $g['finance'], $g['admin']);
        $this->makeLiquidationSupport($report, $g['finance'], $g['admin']);
        $this->actingAs($g['admin'])->post(route('financial.settle', $deduction), ['settled_on' => now('Asia/Manila')->toDateString(), 'settlement_reference' => 'SAMPLE-SETTLED'])->assertSessionHasErrors('finance');
        $this->proof($g, 'deduction', $deduction, 'deduction_settlement');
        $this->post(route('financial.settle', $deduction), ['settled_on' => now('Asia/Manila')->toDateString(), 'settlement_reference' => 'SAMPLE-SETTLED'])->assertSessionHasNoErrors();
        $this->actingAs($g['finance'])->post(route('financial.liquidations.refresh', $report))->assertSessionHasNoErrors();
        $this->assertSame(2, $report->items()->count());
        $this->assertSame('7500.25', Money::decimal(Money::signedTotal($report->items()->get())));
        $this->post(route('financial.liquidations.submit', $report))->assertSessionHasNoErrors();
        $this->post(route('financial.liquidations.review', $report), ['decision' => 'accepted', 'review_notes' => 'SAMPLE complete'])->assertForbidden();
        $this->actingAs($g['admin'])->post(route('financial.liquidations.review', $report), ['decision' => 'accepted', 'review_notes' => 'SAMPLE independent complete review'])->assertSessionHasNoErrors();
        $this->assertSame('accepted', $report->fresh()->status);
        $this->get(route('financial.liquidations.print', $report))->assertOk()->assertSee('ACCEPTED')->assertSee('7000.00')->assertSee('500.25')->assertSee($payment->institutional_student_id);
        $s = app(LiquidationSummary::class)->forBatch($g['batch'], $report->fresh());
        $this->assertTrue($s['complete']);
        $this->assertSame('0.00', $s['school_cash_remaining']);
        $this->assertSame('0.00', $s['retained']);
        $this->actingAs($g['finance'])->post(route('financial.liquidations.store', $g['batch']), ['reference' => 'DUPLICATE'])->assertSessionHasErrors('liquidation');
    }

    public function test_evidence_is_private_scoped_and_checksum_verified(): void
    {
        $g = $this->approved();
        $payment = $this->payment($g);
        $document = $this->proof($g, 'payout', $payment, 'payment_evidence');
        $this->get(route('financial.evidence.download', ['payout', $payment->id, $document]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('financial.evidence.download', ['receipt', 99999, $document]))->assertNotFound();
        $this->get(route('financial.evidence.download', ['application', $g['application']->id, $document]))->assertNotFound();
        $this->actingAs($this->staff('coordinator'))->get(route('financial.evidence.download', ['payout', $payment->id, $document]))->assertOk();
        $this->actingAs($this->staff('registrar'))->get(route('financial.evidence.download', ['payout', $payment->id, $document]))->assertOk();
        foreach (['viewer'] as $role) {
            $this->actingAs($this->staff($role))->get(route('financial.evidence.download', ['payout', $payment->id, $document]))->assertForbidden();
        }
        Storage::disk('scholarship')->put($document->storage_path, 'tampered');
        $this->actingAs($g['admin'])->get(route('financial.evidence.download', ['payout', $payment->id, $document]))->assertNotFound();
        $this->post(route('financial.payouts.post', $payment))->assertSessionHasErrors('finance');
    }

    public function test_accepted_evidence_cannot_be_borrowed_by_another_actual_payment_for_the_same_student(): void
    {
        $g = $this->approved();
        $first = $this->payment($g, ['gross_amount' => '4000.00', 'net_amount' => '4000.00']);
        $document = $this->proof($g, 'payout', $first, 'payment_evidence');
        $this->post(route('financial.payouts.post', $first))->assertSessionHasNoErrors();
        $second = $this->payment($g, ['gross_amount' => '3500.25', 'net_amount' => '3500.25']);
        $before = $document->getAttributes();
        $posted = $first->fresh()->getAttributes();

        $this->actingAs($g['admin'])->get(route('financial.evidence.download', ['payout', $second->id, $document]))->assertNotFound();
        $this->post(route('financial.evidence.review', ['payout', $second->id, $document]), ['decision' => 'accepted', 'review_notes' => 'Wrong payment coverage'])->assertNotFound();
        $this->post(route('financial.payouts.post', $second))->assertSessionHasErrors('finance');
        $this->assertSame('draft', $second->fresh()->status);
        $this->assertSame(0, $second->documents()->count());
        $this->assertSame($before, $document->fresh()->getAttributes());
        $this->assertSame($posted, $first->fresh()->getAttributes());
        $this->assertSame('payment_evidence', $document->fresh()->document_type);
        $this->assertFalse(app(LiquidationSummary::class)->forBatch($g['batch'])['complete']);

        $this->actingAs($this->staff('registrar'))->post(route('financial.evidence.review', ['payout', $first->id, $document]), ['decision' => 'accepted', 'review_notes' => 'Not an authorized reviewer'])->assertForbidden();
        $this->actingAs($this->staff('viewer'))->get(route('financial.evidence.download', ['payout', $first->id, $document]))->assertForbidden();
        auth()->logout();
        $this->get(route('financial.evidence.download', ['payout', $first->id, $document]))->assertRedirect(route('login'));
    }

    public function test_unpaid_batch_is_incomplete_and_draft_print_never_claims_payment_or_acceptance(): void
    {
        $g = $this->approved();
        $this->actingAs($g['finance'])->post(route('financial.liquidations.store', $g['batch']), ['reference' => 'SAMPLE-INCOMPLETE'])->assertSessionHasNoErrors();
        $report = Liquidation::sole();
        $this->get(route('financial.liquidation', $report))->assertOk()->assertSee('INCOMPLETE')->assertSee('No verified student payments');
        $this->get(route('financial.liquidations.print', $report))->assertOk()->assertSee('DRAFT')->assertSee('INCOMPLETE');
        $this->post(route('financial.liquidations.submit', $report))->assertSessionHasErrors('liquidation');
        $this->assertSame('draft', $report->fresh()->status);
        $this->assertSame(0, $report->items()->count());
    }

    public static function invalidPayments(): array
    {
        return [['gross_amount', '-1.00'], ['gross_amount', '0.00'], ['gross_amount', '7500.251'], ['net_amount', '-1.00'], ['payment_mode', 'invented'], ['paid_on', '2099-01-01'], ['method', 'invented'], ['reference', ''], ['billing_item_id', 99999]];
    }

    #[DataProvider('invalidPayments')]
    public function test_invalid_payment_records_are_rejected(string $field, mixed $value): void
    {
        $g = $this->approved();
        $data = ['billing_item_id' => $g['item']->id, 'payment_mode' => 'direct', 'gross_amount' => '7500.25', 'net_amount' => '7500.25', 'paid_on' => now('Asia/Manila')->toDateString(), 'method' => 'cash', 'reference' => 'SAMPLE'];
        $data[$field] = $value;
        $this->actingAs($g['finance'])->post(route('financial.payouts.store'), $data)->assertSessionHasErrors($field);
        $this->assertDatabaseCount('payouts', 0);
    }

    public function test_posted_payment_history_is_immutable_even_by_direct_database_writes(): void
    {
        $g = $this->approved();
        $payment = $this->payment($g);
        $this->proof($g, 'payout', $payment, 'payment_evidence');
        $this->post(route('financial.payouts.post', $payment))->assertSessionHasNoErrors();
        $this->expectException(QueryException::class);
        DB::table('payouts')->where('id', $payment->id)->update(['net_amount' => '1.00']);
    }

    public function test_deduction_file_and_database_work_roll_back_when_audit_fails(): void
    {
        $g = $this->approved();
        $payment = $this->payment($g, ['payment_mode' => 'school', 'net_amount' => '7000.00']);
        $before = Storage::disk('scholarship')->allFiles();
        $this->mock(RecordAudit::class, fn ($mock) => $mock->shouldReceive('record')->andThrow(new RuntimeException('Audit failed')));
        $this->actingAs($g['admin'])->withoutExceptionHandling();
        try {
            $this->post(route('financial.deduct', $payment), $this->deductionData());
            $this->fail('Expected atomic rollback');
        } catch (RuntimeException $error) {
            $this->assertSame('Audit failed', $error->getMessage());
        }
        $this->assertDatabaseCount('deductions', 0);
        $this->assertSame($before, Storage::disk('scholarship')->allFiles());
    }

    public function test_financial_pages_render_and_permissions_apply_to_direct_posts(): void
    {
        $g = $this->approved();
        $receipt = $this->funds($g);
        $this->actingAs($g['finance'])->post(route('financial.liquidations.store', $g['batch']), ['reference' => 'SAMPLE-PAGES'])->assertSessionHasNoErrors();
        $report = Liquidation::sole();
        foreach (['finance', 'administrator', 'coordinator', 'registrar', 'viewer'] as $role) {
            $this->actingAs($this->staff($role));
            $this->get(route('financial.index'))->assertOk();
            $this->get(route('financial.batch', $g['batch']))->assertOk();
            $this->get(route('financial.receipt', $receipt))->assertOk();
            $this->get(route('financial.liquidation', $report))->assertOk();
            if ($role === 'viewer') {
                $this->post(route('financial.payouts.store'), [])->assertForbidden();
            }
        }
        $this->actingAs(User::factory()->create())->get(route('financial.index'))->assertForbidden();
    }

    public function test_returned_liquidation_keeps_original_lines_and_requires_a_new_report(): void
    {
        $g = $this->approved();
        $payment = $this->payment($g);
        $this->proof($g, 'payout', $payment, 'payment_evidence');
        $this->post(route('financial.payouts.post', $payment))->assertSessionHasNoErrors();
        $this->actingAs($g['finance'])->post(route('financial.liquidations.store', $g['batch']), ['reference' => 'SAMPLE-FIRST'])->assertSessionHasNoErrors();
        $first = Liquidation::sole();
        $this->makeSignedAcknowledgment($payment, $g['finance'], $g['admin']);
        $this->makeLiquidationSupport($first, $g['finance'], $g['admin']);
        $lines = $first->items()->get()->toJson();
        $this->post(route('financial.liquidations.submit', $first))->assertSessionHasNoErrors();
        $g['finance']->roles()->create(['role' => 'administrator']);
        $this->actingAs($g['finance']->fresh())->post(route('financial.liquidations.review', $first), ['decision' => 'accepted', 'review_notes' => 'Cannot self approve'])->assertSessionHasErrors('liquidation');
        $this->actingAs($g['admin'])->post(route('financial.liquidations.review', $first), ['decision' => 'returned', 'review_notes' => 'SAMPLE provider clarification required'])->assertSessionHasNoErrors();
        $this->actingAs($g['finance']->fresh())->post(route('financial.liquidations.refresh', $first))->assertSessionHasErrors('liquidation');
        $this->post(route('financial.liquidations.store', $g['batch']), ['reference' => 'SAMPLE-REVISED'])->assertSessionHasNoErrors();
        $second = Liquidation::latest('id')->firstOrFail();
        $this->makeLiquidationSupport($second, $g['finance'], $g['admin']);
        $this->assertSame(2, $second->sequence);
        $this->assertSame('returned', $first->fresh()->status);
        $this->assertSame($lines, $first->items()->get()->toJson());
        $this->post(route('financial.liquidations.submit', $second))->assertSessionHasNoErrors();
        $this->actingAs($g['admin'])->post(route('financial.liquidations.review', $second), ['decision' => 'accepted', 'review_notes' => 'SAMPLE revised report accepted'])->assertSessionHasNoErrors();
        $this->assertSame('accepted', $second->fresh()->status);
    }

    public function test_submitted_liquidation_lines_cannot_be_changed_by_direct_database_writes(): void
    {
        $g = $this->approved();
        $payment = $this->payment($g);
        $this->proof($g, 'payout', $payment, 'payment_evidence');
        $this->post(route('financial.payouts.post', $payment))->assertSessionHasNoErrors();
        $this->actingAs($g['finance'])->post(route('financial.liquidations.store', $g['batch']), ['reference' => 'SAMPLE-FROZEN'])->assertSessionHasNoErrors();
        $report = Liquidation::sole();
        $this->makeSignedAcknowledgment($payment, $g['finance'], $g['admin']);
        $this->makeLiquidationSupport($report, $g['finance'], $g['admin']);
        $this->post(route('financial.liquidations.submit', $report))->assertSessionHasNoErrors();
        $this->expectException(QueryException::class);
        DB::table('liquidation_items')->where('liquidation_id', $report->id)->update(['amount' => '1.00']);
    }

    public function test_missing_proof_after_posting_is_reported_as_incomplete_liquidation(): void
    {
        $g = $this->approved();
        $payment = $this->payment($g);
        $document = $this->proof($g, 'payout', $payment, 'payment_evidence');
        $this->post(route('financial.payouts.post', $payment))->assertSessionHasNoErrors();
        Storage::disk('scholarship')->put($document->storage_path, 'modified evidence');
        $summary = app(LiquidationSummary::class)->forBatch($g['batch']);
        $this->assertFalse($summary['complete']);
        $this->assertStringContainsString('missing or unavailable', implode(' ', $summary['issues']));
        $this->assertSame('posted', $payment->fresh()->status);
    }

    public function test_payment_reference_retries_cannot_create_duplicate_financial_records(): void
    {
        $g = $this->approved();
        $payment = $this->payment($g);
        $data = $payment->only(['billing_item_id', 'payment_mode', 'gross_amount', 'net_amount', 'method', 'reference']);
        $data['paid_on'] = $payment->paid_on->toDateString();
        $this->post(route('financial.payouts.store'), $data)->assertSessionHasErrors('finance');
        $this->assertDatabaseCount('payouts', 1);
    }

    public function test_payment_posting_rechecks_current_student_and_validation_eligibility(): void
    {
        $g = $this->approved();
        $payment = $this->payment($g);
        $this->proof($g, 'payout', $payment, 'payment_evidence');
        $g['student']->update(['record_status' => 'archived']);
        $this->post(route('financial.payouts.post', $payment))->assertSessionHasErrors('billing');
        $this->assertSame('draft', $payment->fresh()->status);
    }

    public function test_unapproved_billing_and_invalid_evidence_cannot_create_posted_money(): void
    {
        $g = $this->ready();
        $this->add($g)->assertSessionHasNoErrors();
        $item = $g['batch']->items()->firstOrFail();
        $this->post(route('financial.payouts.store'), ['billing_item_id' => $item->id, 'payment_mode' => 'school', 'gross_amount' => '100.00', 'net_amount' => '100.00', 'paid_on' => now('Asia/Manila')->toDateString(), 'method' => 'cash', 'reference' => 'SAMPLE'])->assertSessionHasErrors('finance');
        $this->assertDatabaseCount('payouts', 0);
        $this->post(route('financial.receipts.store'), ['program_period_term_id' => $g['terms']->id, 'amount' => '100.001', 'received_on' => '2099-01-01', 'source' => 'SAMPLE', 'reference' => 'SAMPLE'])->assertSessionHasErrors(['amount', 'received_on']);
        $this->assertDatabaseCount('funding_receipts', 0);
    }

    public function test_payment_draft_ignores_forged_verifier_posting_status_and_reversal_fields(): void
    {
        $g = $this->approved();
        $payment = $this->payment($g, ['status' => 'posted', 'entry_type' => 'reversal', 'posted_by' => $g['admin']->id, 'recorded_by' => $g['admin']->id, 'institutional_student_id' => 'FORGED', 'net_amount' => '7500.25']);
        $this->assertSame('draft', $payment->status);
        $this->assertSame('original', $payment->entry_type);
        $this->assertSame($g['finance']->id, $payment->recorded_by);
        $this->assertNull($payment->posted_by);
        $this->assertSame($g['student']->student_number, $payment->institutional_student_id);
        $this->assertSame('0.00', app(AllocationSummary::class)->forTerms($g['terms'])['direct_paid_net']);
    }

    public function test_financial_evidence_rejects_executable_and_oversized_uploads(): void
    {
        $g = $this->approved();
        $payment = $this->payment($g);
        $before = $payment->documents()->count();
        $this->post(route('financial.evidence.store', ['payout', $payment->id]), ['document_type' => 'payment_evidence', 'evidence' => UploadedFile::fake()->createWithContent('payload.php', '<?php echo "SAMPLE";')])->assertSessionHasErrors('evidence');
        $this->post(route('financial.evidence.store', ['payout', $payment->id]), ['document_type' => 'payment_evidence', 'evidence' => UploadedFile::fake()->create('too-large.pdf', 2049, 'application/pdf')])->assertSessionHasErrors('evidence');
        $this->assertSame($before, $payment->documents()->count());
    }
}
