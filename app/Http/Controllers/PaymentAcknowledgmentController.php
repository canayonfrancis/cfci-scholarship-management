<?php

namespace App\Http\Controllers;

use App\Actions\Billing\VerifyPaymentAcknowledgment;
use App\Models\Payout;
use Illuminate\Http\Request;

class PaymentAcknowledgmentController extends Controller
{
    public function store(Request $request, Payout $payout, VerifyPaymentAcknowledgment $verify)
    {
        $verify->handle($payout, $request->user(), $request->all());

        return back()->with('status', 'Beneficiary signature and exact payout coverage verified. The actual payment and liquidation history are unchanged.');
    }
}
