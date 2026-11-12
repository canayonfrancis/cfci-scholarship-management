<?php

namespace App\Models;

use App\Services\Billing\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payroll extends Model
{
    protected $fillable = ['billing_batch_id', 'reference', 'status', 'prepared_by', 'prepared_at', 'funding_snapshot'];

    protected function casts(): array
    {
        return ['prepared_at' => 'immutable_datetime', 'funding_snapshot' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BillingBatch::class, 'billing_batch_id');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function totalPlanned(): string
    {
        return Money::decimal(Money::signedTotal($this->items()->get(), 'planned_gross_amount'));
    }
}
