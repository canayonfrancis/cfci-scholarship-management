<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payout extends Model
{
    protected $fillable = [
        'billing_item_id',
        'billing_batch_id',
        'gross_amount',
        'net_amount',
        'paid_on',
        'payee',
        'method',
        'reference',
        'status',
        'entry_type',
        'posting_key',
        'posted_by',
        'posted_at',
        'reversal_of_id',
        'correction_reason',
        'recorded_by', 'record_key', 'payment_mode', 'institutional_student_id', 'basis_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'paid_on' => 'immutable_date',
            'posted_at' => 'immutable_datetime',
            'basis_snapshot' => 'array',
        ];
    }

    public function billingItem(): BelongsTo
    {
        return $this->belongsTo(BillingItem::class, 'billing_item_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BillingBatch::class, 'billing_batch_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(Payout::class, 'reversal_of_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(Payout::class, 'reversal_of_id');
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(Deduction::class, 'payout_id');
    }

    public function liquidationItems(): HasMany
    {
        return $this->hasMany(LiquidationItem::class, 'payout_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'payout_id');
    }

    public function acknowledgments(): HasMany
    {
        return $this->hasMany(PaymentAcknowledgment::class);
    }
}
