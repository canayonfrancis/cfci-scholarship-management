<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingBatchApproval extends Model
{
    protected $fillable = ['billing_batch_id', 'approved_by', 'approved_at', 'reference', 'amount_snapshot', 'scholarship_budget_id'];

    protected function casts(): array
    {
        return ['amount_snapshot' => 'decimal:2', 'approved_at' => 'immutable_datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BillingBatch::class, 'billing_batch_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(ScholarshipBudget::class, 'scholarship_budget_id');
    }
}
