<?php

namespace App\Services\Billing;

use App\Actions\Billing\RecordFinancialActivity;
use App\Models\BillingBatch;
use App\Models\Liquidation;

class LiquidationSummary
{
    public function __construct(private FinancialEvidence $evidence) {}

    public function forBatch(BillingBatch $batch, ?Liquidation $report = null): array
    {
        $batch = BillingBatch::findOrFail($batch->id);
        $issues = [];
        $expected = [];
        $gross = $school = $direct = $withheld = $settled = $effective = 0;
        if (! RecordFinancialActivity::approved($batch)) {
            $issues[] = 'The billing batch has not received Administrator approval.';
        }
        $items = $batch->items()->with(['student', 'payouts.deductions', 'payouts.documents'])->orderBy('id')->get();
        if ($items->isEmpty()) {
            $issues[] = 'No billing items are present.';
        }
        foreach ($items as $item) {
            $target = $item->gross_amount === null ? 0 : Money::cents($item->effectiveAmount());
            $effective += $target;
            $posted = $item->payouts->where('status', 'posted');
            $paid = Money::signedTotal($posted, 'gross_amount');
            $gross += $paid;
            if ($paid !== $target) {
                $issues[] = $item->student->student_number.': recorded benefit does not yet equal the effective allocation.';
            }
            foreach ($posted as $payout) {
                if ($payout->entry_type !== 'original' || $payout->reversals()->where('status', 'posted')->exists()) {
                    $issues[] = 'Payment '.$payout->id.': reversal history requires a reviewed correction before liquidation.';

                    continue;
                }
                $net = Money::cents($payout->net_amount);
                if ($payout->payment_mode === 'direct') {
                    $direct += $net;
                } else {
                    $school += $net;
                }
                if (! $payout->institutional_student_id || ! $payout->paid_on || ! $payout->method || ! $payout->reference || ! $payout->posted_by) {
                    $issues[] = 'Payment '.$payout->id.': institutional ID, payment details or verifier is missing.';
                }
                if (! $this->evidence->approved($payout, 'payment_evidence')) {
                    $issues[] = 'Payment '.$payout->id.': accepted signed acknowledgment/approved payment proof is missing or unavailable.';
                }
                $deductions = $payout->deductions;
                if ($net + Money::signedTotal($deductions) !== Money::cents($payout->gross_amount)) {
                    $issues[] = 'Payment '.$payout->id.': gross, student amount and deductions do not reconcile.';
                }
                if ($net > 0) {
                    $expected['p:'.$payout->id] = ['payout_id' => $payout->id, 'amount' => Money::decimal($net)];
                }
                foreach ($deductions as $deduction) {
                    $amount = Money::cents($deduction->amount);
                    if (! $deduction->authorized_by || ! $deduction->authority_reference || ! $this->evidence->approved($deduction, 'deduction_authority')) {
                        $issues[] = 'Deduction '.$deduction->id.': verified program authority is missing.';
                    }
                    if ($deduction->settlement_status !== 'settled') {
                        $withheld += $amount;
                        $issues[] = 'Deduction '.$deduction->id.': retained funds have not been settled and evidenced.';
                    } else {
                        $settled += $amount;
                        if (! $deduction->settled_on || ! $deduction->settlement_reference || ! $this->evidence->approved($deduction, 'deduction_settlement')) {
                            $issues[] = 'Deduction '.$deduction->id.': accepted actual settlement proof is missing.';
                        }
                        $expected['d:'.$deduction->id] = ['deduction_id' => $deduction->id, 'amount' => Money::decimal($amount)];
                    }
                }
            }
        }
        if ($gross <= 0) {
            $issues[] = 'No verified student payments have been recorded.';
        }
        if ($report) {
            $actual = $report->items()->get()->keyBy(fn ($line) => $line->payout_id ? 'p:'.$line->payout_id : 'd:'.$line->deduction_id);
            if ($actual->count() !== count($expected)) {
                $issues[] = 'The liquidation draft needs refreshing to include the current payment/settlement lines.';
            }
            foreach ($expected as $key => $line) {
                if (! $actual->has($key) || $actual[$key]->amount !== $line['amount']) {
                    $issues[] = 'Liquidation coverage does not match payment/settlement '.$key.'.';
                }
            }
            $alreadyAccepted = $batch->liquidations()->where('status', 'accepted')->whereKeyNot($report->id)->exists();
            if ($alreadyAccepted) {
                $issues[] = 'This batch is already covered by an accepted liquidation; it cannot be claimed twice.';
            }
        }
        $funded = Money::signedTotal($batch->fundingAllocations()->where('status', 'posted')->get());
        if ($funded - $school - $settled !== 0) {
            $issues[] = 'Allocated school cash remains unspent or does not reconcile. A reviewed return/reallocation is required before this batch can be fully liquidated.';
        }

        return [
            'issues' => array_values(array_unique($issues)), 'complete' => $issues === [], 'expected' => $expected, 'items' => $items,
            'effective' => Money::decimal($effective), 'gross' => Money::decimal($gross), 'school_net' => Money::decimal($school), 'direct_net' => Money::decimal($direct),
            'retained' => Money::decimal($withheld), 'settled' => Money::decimal($settled), 'funded' => Money::decimal($funded),
            'school_cash_remaining' => Money::decimal($funded - $school - $settled),
        ];
    }
}
