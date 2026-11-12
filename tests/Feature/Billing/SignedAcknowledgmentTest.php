<?php

namespace Tests\Feature\Billing;

use App\Actions\Billing\ManageLiquidation;
use App\Actions\Billing\RecordFinancialActivity;
use App\Actions\Billing\VerifyPaymentAcknowledgment;
use App\Actions\Records\RecordAudit;
use App\Models\Liquidation;
use App\Models\PaymentAcknowledgment;
use App\Models\User;
use App\Services\Billing\AllocationSummary;
use App\Services\Billing\FinancialEvidence;
use App\Services\Billing\LiquidationSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsAcknowledgmentRecords;
use Tests\Concerns\BuildsPayrollRecords;
use Tests\TestCase;

class SignedAcknowledgmentTest extends TestCase
{
    use BuildsAcknowledgmentRecords, BuildsPayrollRecords, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('scholarship');
    }

    private function paid(string $path = 'school'): array
    {
        $g = $this->payrollBatch();
        if ($path === 'school') {
            $this->payrollFunds($g);
        }
        $g['payment'] = $this->payrollPayment($g, '7500.25', $path)->fresh();

        return $g;
    }

    private function report(array $g): Liquidation
    {
        return app(ManageLiquidation::class)->prepare($g['batch'], $g['recorder'], 'SYNTHETIC-LIQUIDATION');
    }

    private function resumeTestTransactionAfterDdl(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // DDL commits MariaDB's transaction; reset the test harness's stale nesting level.
            DB::rollBack(0);
            RefreshDatabaseState::$migrated = false;
            DB::beginTransaction();
        }
    }

    public static function paths(): array
    {
        return [['school'], ['direct']];
    }

    #[DataProvider('paths')]
    public function test_generic_proof_and_generic_acceptance_of_a_signed_file_do_not_certify_a_signature(string $path): void
    {
        $g = $this->paid($path);
        $report = $this->report($g);
        $this->makeLiquidationSupport($report, $g['recorder'], $g['reviewer']);
        $document = $this->signedDocument($g['payment'], $g['recorder']);
        app(FinancialEvidence::class)->review($document, $g['reviewer'], 'accepted', 'Generic evidence review only');
        $this->actingAs($g['recorder'])->post(route('financial.liquidations.submit', $report))->assertSessionHasErrors('liquidation');
        $this->assertDatabaseCount('payment_acknowledgments', 0);
        $this->assertSame('draft', $report->fresh()->status);
        $this->get(route('financial.payout', $g['payment']))->assertOk()->assertSee('No explicit signature verification recorded');
        $this->assertSame('posted', $g['payment']->fresh()->status);
    }

    #[DataProvider('paths')]
    public function test_confirmed_checklist_is_retained_and_required_again_at_acceptance_without_changing_payments(string $path): void
    {
        $g = $this->paid($path);
        $before = $g['payment']->getAttributes();
        $totals = app(AllocationSummary::class)->forTerms($g['terms']);
        $ack = $this->makeSignedAcknowledgment($g['payment'], $g['recorder'], $g['reviewer']);
        $report = $this->report($g);
        $this->actingAs($g['recorder'])->post(route('financial.liquidations.submit', $report))->assertSessionHasErrors('liquidation');
        $support = $this->makeLiquidationSupport($report, $g['recorder'], $g['reviewer']);
        $this->post(route('financial.liquidations.submit', $report))->assertSessionHasNoErrors();
        $snapshot = $report->fresh()->compliance_snapshot;
        $this->assertSame($ack->id, $snapshot['acknowledgments'][0]['id']);
        $this->assertSame($support->id, $snapshot['support_document']['id']);
        $this->assertSame($g['recorder']->id, $snapshot['checked_by']);
        $this->actingAs($g['reviewer'])->post(route('financial.liquidations.review', $report), ['decision' => 'accepted', 'review_notes' => 'Synthetic confirmed checklist reviewed'])->assertSessionHasNoErrors();
        $this->assertSame('accepted', $report->fresh()->status);
        $this->assertSame($snapshot, $report->fresh()->compliance_snapshot);
        $this->assertSame($before, $g['payment']->fresh()->getAttributes());
        foreach (['allocated', 'received', 'released_net', 'direct_paid_net', 'remaining'] as $field) {
            $this->assertSame($totals[$field], app(AllocationSummary::class)->forTerms($g['terms'])[$field]);
        }
        $this->assertDatabaseCount('payouts', 1);
        $this->get(route('financial.payout', $g['payment']))->assertOk()->assertSee($ack->document_reference)->assertSee($g['reviewer']->name);
        $this->get(route('financial.liquidations.print', $report))->assertOk()->assertSee('ACCEPTED')->assertSee('Retained checklist');
    }

    public static function wrongCoverage(): array
    {
        return [['institutional_student_id', 'ANOTHER-ID'], ['beneficiary_name', 'Another Beneficiary'], ['paid_on', '2020-01-01'], ['amount_received', '1.00'], ['payment_reference', 'ANOTHER-PAYMENT'], ['document_reference', ' '], ['page_line', ''], ['signature_confirmed', 0], ['verified_by', 999], ['verified_at', '2026-01-01'], ['payout_id', 999], ['student_id', 999]];
    }

    #[DataProvider('wrongCoverage')]
    public function test_wrong_or_incomplete_signature_coverage_rolls_back_without_accepting_a_file(string $field, mixed $value): void
    {
        $g = $this->paid();
        $document = $this->signedDocument($g['payment'], $g['recorder']);
        $data = array_replace($this->acknowledgmentData($g['payment'], $document->id), [$field => $value]);
        $this->actingAs($g['reviewer'])->post(route('financial.acknowledgments.store', $g['payment']), $data)->assertSessionHasErrors();
        $this->assertDatabaseCount('payment_acknowledgments', 0);
        $this->assertSame('pending', $document->fresh()->review_status);
    }

    public function test_another_payouts_document_and_ordinary_payment_proof_cannot_be_used(): void
    {
        $g = $this->payrollBatch();
        $this->payrollFunds($g);
        $g['payment'] = $this->payrollPayment($g, '3500.00');
        $other = $this->payrollPayment($g, '4000.25');
        $wrong = $this->signedDocument($other, $g['recorder']);
        $generic = $g['payment']->documents()->where('document_type', 'payment_evidence')->firstOrFail();
        foreach ([$wrong, $generic] as $document) {
            $this->actingAs($g['reviewer'])->post(route('financial.acknowledgments.store', $g['payment']), $this->acknowledgmentData($g['payment'], $document->id))->assertSessionHasErrors('acknowledgment');
        }
        $this->assertDatabaseCount('payment_acknowledgments', 0);
    }

    public static function deniedRoles(): array
    {
        return [['registrar'], ['finance'], ['viewer'], ['unknown']];
    }

    #[DataProvider('deniedRoles')]
    public function test_signature_verification_requires_a_coordinator_or_retained_test_administrator(string $role): void
    {
        $g = $this->paid();
        $document = $this->signedDocument($g['payment'], $g['recorder']);
        $actor = $role === 'unknown' ? User::factory()->create() : $this->staff($role);
        $this->actingAs($actor)->post(route('financial.acknowledgments.store', $g['payment']), $this->acknowledgmentData($g['payment'], $document->id))->assertForbidden();
        $this->assertDatabaseCount('payment_acknowledgments', 0);
    }

    public function test_no_self_verification_unposted_payments_or_corrupt_documents_and_retries_preserve_history(): void
    {
        $g = $this->paid();
        $document = $this->signedDocument($g['payment'], $g['reviewer']);
        $this->actingAs($g['reviewer'])->post(route('financial.acknowledgments.store', $g['payment']), $this->acknowledgmentData($g['payment'], $document->id))->assertSessionHasErrors('acknowledgment');
        $good = $this->signedDocument($g['payment'], $g['recorder']);
        $data = $this->acknowledgmentData($g['payment'], $good->id);
        $this->post(route('financial.acknowledgments.store', $g['payment']), $data)->assertSessionHasNoErrors();
        $ack = PaymentAcknowledgment::sole();
        $before = $ack->getAttributes();
        $this->post(route('financial.acknowledgments.store', $g['payment']), $data)->assertSessionHasNoErrors();
        $this->post(route('financial.acknowledgments.store', $g['payment']), array_replace($data, ['page_line' => 'Different page']))->assertSessionHasErrors('acknowledgment');
        $this->assertSame($before, $ack->fresh()->getAttributes());
        $this->assertDatabaseRejects(fn () => $ack->update(['page_line' => 'Changed']));
        $this->assertDatabaseRejects(fn () => $ack->delete());
        Storage::disk('scholarship')->put($good->storage_path, 'tampered');
        $this->post(route('financial.acknowledgments.store', $g['payment']), $data)->assertSessionHasErrors('acknowledgment');
        $this->get(route('financial.evidence.download', ['payout', $g['payment']->id, $good]))->assertNotFound();
    }

    public static function lostSources(): array
    {
        return [['signature'], ['support'], ['receipt']];
    }

    #[DataProvider('lostSources')]
    public function test_missing_frozen_evidence_blocks_acceptance_but_does_not_erase_accepted_history(string $source): void
    {
        $g = $this->paid();
        $ack = $this->makeSignedAcknowledgment($g['payment'], $g['recorder'], $g['reviewer']);
        $report = $this->report($g);
        $support = $this->makeLiquidationSupport($report, $g['recorder'], $g['reviewer']);
        app(ManageLiquidation::class)->submit($report, $g['recorder']);
        $path = match ($source) {
            'signature' => $ack->document->storage_path,
            'receipt' => $g['batch']->fundingAllocations()->firstOrFail()->receipt->documents()->firstOrFail()->storage_path,
            default => $support->storage_path,
        };
        $content = Storage::disk('scholarship')->get($path);
        Storage::disk('scholarship')->delete($path);
        $this->actingAs($g['reviewer'])->post(route('financial.liquidations.review', $report), ['decision' => 'accepted', 'review_notes' => 'Must not accept missing proof'])->assertSessionHasErrors('liquidation');
        $this->assertSame('submitted', $report->fresh()->status);
        Storage::disk('scholarship')->put($path, $content);
        $this->post(route('financial.liquidations.review', $report), ['decision' => 'accepted', 'review_notes' => 'Synthetic evidence intact'])->assertSessionHasNoErrors();
        $before = $report->fresh()->getAttributes();
        Storage::disk('scholarship')->put($path, 'later corrupt evidence');
        $this->assertFalse(app(LiquidationSummary::class)->forBatch($g['batch'], $report->fresh())['complete']);
        $this->assertSame($before, $report->fresh()->getAttributes());
        $this->assertSame('posted', $g['payment']->fresh()->status);
    }

    public function test_legacy_accepted_history_and_generic_evidence_survive_additive_migration_without_certification(): void
    {
        $g = $this->paid('direct');
        $migration = require database_path('migrations/2026_11_13_000011_add_signed_payment_acknowledgments.php');
        $migration->down();
        $this->resumeTestTransactionAfterDdl();
        $report = Liquidation::create(['billing_batch_id' => $g['batch']->id, 'sequence' => 1, 'reference' => 'LEGACY', 'prepared_by' => $g['recorder']->id]);
        $report->items()->create(['billing_batch_id' => $g['batch']->id, 'payout_id' => $g['payment']->id, 'amount' => '7500.25']);
        $report->update(['status' => 'submitted', 'submitted_by' => $g['recorder']->id, 'submitted_at' => now()]);
        $report->update(['status' => 'accepted', 'reviewed_by' => $g['reviewer']->id, 'reviewed_at' => now(), 'review_notes' => 'Historical generic-proof decision']);
        $before = $report->fresh()->getAttributes();
        $payment = $g['payment']->getAttributes();
        $migration->up();
        $this->resumeTestTransactionAfterDdl();
        $this->assertSame($before, array_diff_key($report->fresh()->getAttributes(), ['compliance_snapshot' => true]));
        $this->assertSame($payment, $g['payment']->fresh()->getAttributes());
        $this->assertNull($report->fresh()->compliance_snapshot);
        $this->assertDatabaseCount('payment_acknowledgments', 0);
        $this->actingAs($g['reviewer'])->get(route('financial.liquidation', $report))->assertOk()->assertSee('Historical acceptance retained')->assertSee('unconfirmed');
        $this->assertSame('accepted', $report->fresh()->status);
    }

    public function test_database_transition_guards_and_populated_rollback_protect_the_new_facts(): void
    {
        $g = $this->paid();
        $report = $this->report($g);
        $this->assertDatabaseRejects(fn () => $report->update(['status' => 'submitted', 'submitted_by' => $g['recorder']->id, 'submitted_at' => now()]));
        $this->makeSignedAcknowledgment($g['payment'], $g['recorder'], $g['reviewer']);
        $this->makeLiquidationSupport($report, $g['recorder'], $g['reviewer']);
        app(ManageLiquidation::class)->submit($report, $g['recorder']);
        $this->assertDatabaseRejects(fn () => $report->fresh()->update(['compliance_snapshot' => []]));
        $migration = require database_path('migrations/2026_11_13_000011_add_signed_payment_acknowledgments.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    public function test_payment_drafts_are_not_eligible_for_signature_verification(): void
    {
        $g = $this->payrollBatch();
        $payment = app(RecordFinancialActivity::class)->payout($g['recorder'], ['billing_item_id' => $g['item']->id, 'gross_amount' => '7500.25', 'net_amount' => '7500.25', 'payment_mode' => 'direct', 'paid_on' => now('Asia/Manila')->toDateString(), 'method' => 'cash', 'reference' => 'UNPOSTED']);
        $document = $this->signedDocument($payment, $g['recorder']);
        $this->actingAs($g['reviewer'])->post(route('financial.acknowledgments.store', $payment), $this->acknowledgmentData($payment, $document->id))->assertSessionHasErrors('acknowledgment');
        $this->assertDatabaseCount('payment_acknowledgments', 0);
        $this->assertSame('draft', $payment->fresh()->status);
    }

    public function test_legacy_submitted_report_must_satisfy_the_new_checks_before_a_future_acceptance(): void
    {
        $g = $this->paid('direct');
        $migration = require database_path('migrations/2026_11_13_000011_add_signed_payment_acknowledgments.php');
        $migration->down();
        $this->resumeTestTransactionAfterDdl();
        $report = Liquidation::create(['billing_batch_id' => $g['batch']->id, 'sequence' => 1, 'reference' => 'LEGACY-SUBMITTED', 'prepared_by' => $g['recorder']->id]);
        $report->items()->create(['billing_batch_id' => $g['batch']->id, 'payout_id' => $g['payment']->id, 'amount' => '7500.25']);
        $this->makeLiquidationSupport($report, $g['recorder'], $g['reviewer']);
        $report->update(['status' => 'submitted', 'submitted_by' => $g['recorder']->id, 'submitted_at' => now()]);
        $migration->up();
        $this->resumeTestTransactionAfterDdl();
        $this->actingAs($g['reviewer'])->post(route('financial.liquidations.review', $report), ['decision' => 'accepted', 'review_notes' => 'New rules required'])->assertSessionHasErrors('liquidation');
        $this->assertSame('submitted', $report->fresh()->status);
        $this->makeSignedAcknowledgment($g['payment'], $g['recorder'], $g['reviewer']);
        $this->post(route('financial.liquidations.review', $report), ['decision' => 'accepted', 'review_notes' => 'New checklist verified for legacy submission'])->assertSessionHasNoErrors();
        $this->assertNotNull($report->fresh()->compliance_snapshot);
        $this->assertSame('accepted', $report->fresh()->status);
    }

    public function test_support_owned_by_another_report_does_not_complete_this_report(): void
    {
        $g = $this->paid();
        $this->makeSignedAcknowledgment($g['payment'], $g['recorder'], $g['reviewer']);
        $report = $this->report($g);
        $other = Liquidation::create(['billing_batch_id' => $g['batch']->id, 'sequence' => 99, 'reference' => 'ANOTHER-REPORT', 'prepared_by' => $g['recorder']->id]);
        $this->makeLiquidationSupport($other, $g['recorder'], $g['reviewer']);
        $this->actingAs($g['recorder'])->post(route('financial.liquidations.submit', $report))->assertSessionHasErrors('liquidation');
        $this->assertNull($report->fresh()->compliance_snapshot);
    }

    public function test_failed_audit_rolls_back_human_verification_and_document_acceptance(): void
    {
        $g = $this->paid();
        $document = $this->signedDocument($g['payment'], $g['recorder']);
        $this->mock(RecordAudit::class)->shouldReceive('record')->andThrow(new RuntimeException('Synthetic failed acknowledgment audit'));
        try {
            app(VerifyPaymentAcknowledgment::class)->handle($g['payment'], $g['reviewer'], $this->acknowledgmentData($g['payment'], $document->id));
            $this->fail('Expected rollback');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic failed acknowledgment audit', $error->getMessage());
        }
        $this->assertSame('pending', $document->fresh()->review_status);
        $this->assertDatabaseCount('payment_acknowledgments', 0);
    }
}
