<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FundingReceipt extends Model
{
    protected $fillable = [
        'program_period_term_id',
        'program_id',
        'academic_period_id',
        'amount',
        'received_on',
        'source',
        'reference',
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
            'received_on' => 'immutable_date',
            'posted_at' => 'immutable_datetime',
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

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(FundingReceipt::class, 'reversal_of_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(FundingAllocation::class, 'funding_receipt_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(FundingReceipt::class, 'reversal_of_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'funding_receipt_id');
    }
}
