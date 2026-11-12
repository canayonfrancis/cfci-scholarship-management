<?php

namespace App\Http\Controllers;

use App\Actions\Billing\PreparePayroll;
use App\Models\BillingBatch;
use App\Models\Payroll;
use App\Services\Billing\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PayrollController extends Controller
{
    public function store(Request $request, BillingBatch $batch, PreparePayroll $prepare)
    {
        Gate::authorize('financial-prepare');
        $rules = ['reference' => ['required', 'string', 'max:255', 'regex:/\S/']];
        foreach (['items', 'gross_amount', 'net_amount', 'payment_mode', 'status', 'prepared_by', 'prepared_at', 'payout_id'] as $field) {
            $rules[$field] = ['prohibited'];
        }
        $data = $request->validate($rules);
        $payroll = $prepare->handle($batch, $request->user(), $data['reference']);

        return to_route('financial.payrolls.show', $payroll)->with('status', 'Internal payroll register retained. No student payment, deduction or cash movement was created.');
    }

    public function show(Payroll $payroll)
    {
        Gate::authorize('financial-view');
        abort_unless($payroll->status === 'prepared', 404);
        $payroll->load(['preparedBy', 'batch.program', 'batch.academicPeriod', 'items.billingItem.adjustments', 'items.billingItem.payouts']);
        $current = $payroll->items->mapWithKeys(function ($line) {
            $item = $line->billingItem;
            $effective = $item->effectiveAmount();
            $applied = Money::signedTotal($item->payouts->where('status', 'posted'), 'gross_amount');

            return [$line->id => ['effective' => $effective, 'applied' => Money::decimal($applied), 'remaining' => Money::decimal(Money::cents($effective) - $applied)]];
        });

        return view('financial.payroll', ['payroll' => $payroll, 'current' => $current, 'total' => $payroll->totalPlanned()]);
    }

    public function print(Payroll $payroll)
    {
        Gate::authorize('financial-view');
        abort_unless($payroll->status === 'prepared', 404);
        $payroll->load(['preparedBy', 'batch.program', 'batch.academicPeriod', 'items']);

        return view('financial.payroll-print', ['payroll' => $payroll, 'total' => $payroll->totalPlanned()]);
    }
}
