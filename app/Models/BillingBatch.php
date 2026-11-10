<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingBatch extends Model
{
    protected $fillable = [
        'program_period_term_id',
        'program_id',
        'academic_period_id',
        'batch_number',
        'reference',
        'status',
        'prepared_by',
        'submitted_at',
        'finalized_by',
        'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
        ];
    }

    public function terms(): BelongsTo
    {
        return $this->belongsTo(ProgramPeriodTerm::class, 'program_period_term_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(ScholarshipProgram::class, 'program_id');
    }

    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingItem::class, 'billing_batch_id');
    }

    public function fundingAllocations(): HasMany
    {
        return $this->hasMany(FundingAllocation::class, 'billing_batch_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'billing_batch_id');
    }

    public function liquidations(): HasMany
    {
        return $this->hasMany(Liquidation::class, 'billing_batch_id');
    }
}
