<?php

namespace Tests\Feature\Integration;

use App\Actions\Billing\ManageLiquidation;
use App\Models\BillingBatch;
use App\Models\FundingAllocation;
use App\Models\FundingReceipt;
use App\Services\Billing\BatchFundingStatus;
use App\Services\Billing\LiquidationSummary;
use App\Services\Reporting\ScholarshipReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsAcknowledgmentRecords;
use Tests\Concerns\BuildsPayrollRecords;
use Tests\TestCase;

class FinancialEvidenceConsistencyTest extends TestCase
{
    use BuildsAcknowledgmentRecords, BuildsPayrollRecords, RefreshDatabase;

    public static function corrections(): array
    {
        $cases = [];
        foreach (['reversed_receipt', 'overallocated_receipt'] as $correction) {
            foreach (['draft', 'submitted', 'accepted'] as $stage) {
                $cases[$correction.'_'.$stage] = [$correction, $stage];
            }
        }

        return $cases;
    }

    #[DataProvider('corrections')]
    public function test_liquidation_cannot_certify_funding_sources_that_the_existing_funding_monitor_rejects(string $correction, string $stage): void
    {
        Storage::fake('scholarship');
        $g = $this->payrollBatch();
        $receipt = $this->payrollFunds($g);
        $payment = $this->payrollPayment($g, '7500.25');
        $this->makeSignedAcknowledgment($payment, $g['recorder'], $g['reviewer']);
        $action = app(ManageLiquidation::class);
        $report = $action->prepare($g['batch'], $g['recorder'], 'SYNTHETIC-CASH-SOURCE-REVIEW');
        $this->makeLiquidationSupport($report, $g['recorder'], $g['reviewer']);
        if ($stage !== 'draft') {
            $action->submit($report, $g['recorder']);
        }
        if ($stage === 'accepted') {
            $action->review($report, $g['reviewer'], 'accepted', 'Synthetic initially complete sources');
        }
        $history = $report->fresh()->getAttributes();
        $paymentHistory = $payment->fresh()->getAttributes();
        // Retained correction fixtures, not a new operational reversal/transfer policy.
        if ($correction === 'reversed_receipt') {
            FundingReceipt::create(['program_period_term_id' => $g['terms']->id, 'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id,
                'amount' => $receipt->amount, 'received_on' => now('Asia/Manila')->toDateString(), 'source' => 'Synthetic retained correction', 'reference' => 'SYNTHETIC-RECEIPT-REVERSAL',
                'entry_type' => 'reversal', 'reversal_of_id' => $receipt->id, 'correction_reason' => 'Synthetic retained reversal requiring reconciliation',
                'status' => 'posted', 'posted_by' => $g['reviewer']->id, 'posted_at' => now(), 'posting_key' => 'synthetic-cash-source-reversal']);
        } else {
            $other = BillingBatch::create(['program_period_term_id' => $g['terms']->id, 'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id,
                'batch_number' => 'SYNTHETIC-OTHER-ALLOCATION', 'prepared_by' => $g['recorder']->id]);
            FundingAllocation::create(['funding_receipt_id' => $receipt->id, 'billing_batch_id' => $other->id, 'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id,
                'amount' => '0.01', 'status' => 'posted', 'posted_by' => $g['reviewer']->id, 'posted_at' => now(), 'posting_key' => 'synthetic-retained-oversubscription']);
        }
        $funding = app(BatchFundingStatus::class)->rows($g['terms']->id, [$g['batch']->id])->sole();
        $this->assertSame('review', $funding['state']);
        $summary = app(LiquidationSummary::class)->forBatch($g['batch'], $report->fresh());
        $this->assertFalse($summary['complete'], 'Liquidation must not certify cash that its existing funding monitor rejects.');
        if ($stage === 'draft') {
            $this->actingAs($g['recorder'])->post(route('financial.liquidations.submit', $report))->assertSessionHasErrors('liquidation');
        } elseif ($stage === 'submitted') {
            $this->actingAs($g['reviewer'])->post(route('financial.liquidations.review', $report), ['decision' => 'accepted', 'review_notes' => 'Must reject current funding exception'])->assertSessionHasErrors('liquidation');
        } else {
            $result = app(ScholarshipReports::class)->build('liquidated');
            $this->assertSame(0, $result['counts']['rows']);
            $this->assertSame(1, $result['totals']['Recorded accepted billing histories']);
            $this->assertCount(1, $result['exceptions']);
        }
        $this->assertSame($history, $report->fresh()->getAttributes());
        $this->assertSame($paymentHistory, $payment->fresh()->getAttributes());
        $paid = app(ScholarshipReports::class)->build('paid-signed');
        $this->assertSame('7500.25', $paid['totals']['Signed student net (PHP)'], 'A funding-source exception does not erase an actual signed student payment.');
    }
}
