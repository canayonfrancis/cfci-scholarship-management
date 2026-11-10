<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Liquidation extends Model
{
    protected $fillable = [
        'billing_batch_id',
        'sequence',
        'reference',
        'status',
        'prepared_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BillingBatch::class, 'billing_batch_id');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LiquidationItem::class, 'liquidation_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'liquidation_id');
    }
}
