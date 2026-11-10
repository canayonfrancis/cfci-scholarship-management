<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiquidationItem extends Model
{
    protected $fillable = [
        'liquidation_id',
        'billing_batch_id',
        'payout_id',
        'deduction_id',
        'amount',
        'verification_notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function liquidation(): BelongsTo
    {
        return $this->belongsTo(Liquidation::class, 'liquidation_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BillingBatch::class, 'billing_batch_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class, 'payout_id');
    }

    public function deduction(): BelongsTo
    {
        return $this->belongsTo(Deduction::class, 'deduction_id');
    }
}
