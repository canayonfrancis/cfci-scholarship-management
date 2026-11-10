<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FundingAllocation extends Model
{
    protected $fillable = [
        'funding_receipt_id',
        'billing_batch_id',
        'program_id',
        'academic_period_id',
        'amount',
        'status',
        'entry_type',
        'posting_key',
        'posted_by',
        'posted_at',
        'reversal_of_id',
        'correction_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'posted_at' => 'immutable_datetime',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(FundingReceipt::class, 'funding_receipt_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BillingBatch::class, 'billing_batch_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(ScholarshipProgram::class, 'program_id');
    }

    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(FundingAllocation::class, 'reversal_of_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(FundingAllocation::class, 'reversal_of_id');
    }
}
