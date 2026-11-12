<?php

namespace App\Services\Billing;

use App\Actions\Billing\RecordFinancialActivity;
use App\Models\BillingBatch;
use App\Services\Eligibility\PrivateEvidence;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BatchFundingStatus
{
    public const LABELS = [
        'draft' => 'Draft — not billed', 'submitted' => 'Submitted — awaiting approval',
        'awaiting_funds' => 'Approved — awaiting funds / receipt allocation',
        'funded' => 'Approved — funded, benefit still outstanding',
        'applied' => 'Approved — allocation fully applied', 'review' => 'Financial source review required', 'void' => 'Void history',
    ];

    public function __construct(private PrivateEvidence $evidence) {}

    public function rows(?int $termsId = null, ?array $batchIds = null): Collection
    {
        return DB::transaction(function () use ($termsId, $batchIds) {
            $receiptValidity = [];

            return BillingBatch::with(['program', 'academicPeriod', 'approval', 'items.adjustments', 'payouts', 'fundingAllocations.receipt.documents', 'fundingAllocations.receipt.reversals', 'fundingAllocations.receipt.allocations'])
                ->when($termsId, fn ($q) => $q->where('program_period_term_id', $termsId))
                ->when($batchIds !== null, fn ($q) => $q->whereIn('id', $batchIds))->latest('id')->get()
                ->map(function ($batch) use (&$receiptValidity) {
                    $issues = [];
                    $unknown = $batch->items->contains(fn ($item) => $item->gross_amount === null);
                    $effective = $unknown ? null : $batch->items->sum(fn ($item) => Money::cents($item->gross_amount) + Money::signedTotal($item->adjustments));
                    $posted = $batch->payouts->where('status', 'posted');
                    $paymentUnknown = $posted->contains(fn ($p) => $p->gross_amount === null);
                    $applied = $paymentUnknown ? null : Money::signedTotal($posted, 'gross_amount');
                    $direct = $paymentUnknown ? null : Money::signedTotal($posted->where('payment_mode', 'direct'), 'gross_amount');
                    $allocations = $batch->fundingAllocations->where('status', 'posted');
                    $usable = $allocations->filter(function ($allocation) use ($batch, &$receiptValidity, &$issues) {
                        $receipt = $allocation->receipt;
                        if (! array_key_exists($receipt->id, $receiptValidity)) {
                            $allocated = Money::signedTotal($receipt->allocations->where('status', 'posted'));
                            $receiptValidity[$receipt->id] = $receipt->status === 'posted' && $receipt->entry_type === 'original'
                                && $receipt->amount !== null && $allocated >= 0 && $allocated <= Money::cents($receipt->amount)
                                && $receipt->received_on && $receipt->received_on->toDateString() <= now('Asia/Manila')->toDateString()
                                && ! $receipt->reversals->contains('status', 'posted')
                                && $receipt->documents->contains(fn ($d) => $d->document_type === 'receipt_evidence' && $this->evidence->usable($d));
                        }
                        $valid = $receiptValidity[$receipt->id] && $receipt->program_period_term_id === $batch->program_period_term_id;
                        if (! $valid) {
                            $issues[] = 'Allocated receipt #'.$receipt->id.' is not currently usable; review its amount, date, scope, allocation capacity, reversal or evidence.';
                        }

                        return $valid;
                    });
                    $funded = Money::signedTotal($usable);
                    $shortfall = $effective !== null && $direct !== null ? max(0, $effective - $direct - $funded) : null;
                    $outstanding = $effective !== null && $applied !== null ? $effective - $applied : null;
                    $approved = RecordFinancialActivity::approved($batch);
                    if ($effective === null || $applied === null) {
                        $issues[] = 'A source amount is unconfirmed.';
                    }
                    if ($funded < 0 || ($outstanding !== null && $outstanding < 0) || ($effective !== null && $direct !== null && $funded + $direct > $effective)) {
                        $issues[] = 'Amounts exceed or conflict with the effective allocation; reconciliation is required.';
                    }
                    $state = match (true) {
                        $batch->status === 'void' => 'void',
                        $batch->status === 'draft' => 'draft',
                        ! $approved => 'submitted',
                        $issues !== [] => 'review',
                        $outstanding === 0 => 'applied',
                        $shortfall > 0 => 'awaiting_funds',
                        default => 'funded',
                    };

                    return [
                        'batch' => $batch, 'state' => $state, 'label' => self::LABELS[$state], 'issues' => array_values(array_unique($issues)),
                        'effective' => $effective === null ? null : Money::decimal($effective),
                        'allocated' => Money::decimal(Money::signedTotal($allocations)), 'usable_funding' => Money::decimal($funded),
                        'direct_applied' => $direct === null ? null : Money::decimal($direct),
                        'outstanding' => $outstanding === null ? null : Money::decimal($outstanding),
                        'shortfall' => $approved && $shortfall !== null ? Money::decimal($shortfall) : null,
                    ];
                });
        });
    }
}
