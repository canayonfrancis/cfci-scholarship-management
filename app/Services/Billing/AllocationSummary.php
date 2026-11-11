<?php

namespace App\Services\Billing;

use App\Models\BillingAdjustment;
use App\Models\BillingItem;
use App\Models\Payout;
use App\Models\ProgramPeriodTerm;

class AllocationSummary
{
    public function forTerms(ProgramPeriodTerm $terms): array
    {
        $items = BillingItem::whereHas('batch', fn ($query) => $query->where('program_period_term_id', $terms->id)->where(fn ($q) => $q->whereHas('approval')->orWhere('status', 'finalized')))->get();
        $ids = $items->modelKeys();
        $original = Money::signedTotal($items, 'gross_amount');
        $adjustments = Money::signedTotal(BillingAdjustment::whereIn('billing_item_id', $ids)->get());
        $allocated = $original + $adjustments;
        $budget = $terms->budgets()->orderByDesc('revision')->first();
        $received = Money::signedTotal($terms->fundingReceipts()->where('status', 'posted')->get());
        $payouts = Payout::where('status', 'posted')->whereHas('batch', fn ($query) => $query->where('program_period_term_id', $terms->id))->get();
        $pendingItems = BillingItem::whereHas('batch', fn ($query) => $query->where('program_period_term_id', $terms->id)->where('status', 'submitted')->whereDoesntHave('approval'))->get();

        return [
            'terms' => $terms, 'budget' => $budget, 'rate' => $terms->rates()->orderByDesc('revision')->first(),
            'original_allocations' => Money::decimal($original), 'adjustments' => Money::decimal($adjustments),
            'allocated' => Money::decimal($allocated), 'scholar_count' => $items->count(),
            'remaining' => $budget ? Money::decimal(Money::cents($budget->amount) - $allocated) : null,
            'pending' => $pendingItems->contains(fn ($item) => $item->gross_amount === null) ? null : Money::decimal(Money::signedTotal($pendingItems, 'gross_amount')),
            'received' => Money::decimal($received),
            'released_net' => Money::decimal(Money::signedTotal($payouts->where('payment_mode', '!=', 'direct'), 'net_amount')),
            'direct_paid_net' => Money::decimal(Money::signedTotal($payouts->where('payment_mode', 'direct'), 'net_amount')),
        ];
    }
}
