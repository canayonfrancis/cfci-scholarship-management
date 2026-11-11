<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingAdjustment extends Model
{
    protected $fillable = ['billing_item_id', 'sequence', 'direction', 'amount', 'amount_before', 'amount_after', 'reason', 'reference', 'authorized_by', 'authorized_at', 'scholarship_rate_id', 'basis_snapshot'];

    protected function casts(): array
    {
        return ['basis_snapshot' => 'array', 'sequence' => 'integer', 'amount' => 'decimal:2', 'amount_before' => 'decimal:2', 'amount_after' => 'decimal:2', 'authorized_at' => 'immutable_datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(BillingItem::class, 'billing_item_id');
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(ScholarshipRate::class, 'scholarship_rate_id');
    }
}
