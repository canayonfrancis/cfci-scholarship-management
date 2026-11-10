<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deduction extends Model
{
    protected $fillable = [
        'payout_id',
        'billing_batch_id',
        'type',
        'amount',
        'basis',
        'authorized_by',
        'authorized_at',
        'destination',
        'settlement_status',
        'settled_on',
        'settlement_reference',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'authorized_at' => 'immutable_datetime',
            'settled_on' => 'immutable_date',
        ];
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class, 'payout_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BillingBatch::class, 'billing_batch_id');
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    public function liquidationItems(): HasMany
    {
        return $this->hasMany(LiquidationItem::class, 'deduction_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'deduction_id');
    }
}
