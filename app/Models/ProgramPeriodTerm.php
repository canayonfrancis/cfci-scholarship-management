<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProgramPeriodTerm extends Model
{
    protected $fillable = [
        'program_id',
        'academic_period_id',
        'semester_gross_amount',
        'criteria',
        'rule_version',
        'source_reference',
        'confirmed_by',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'semester_gross_amount' => 'decimal:2',
            'criteria' => 'array',
            'confirmed_at' => 'immutable_datetime',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(ScholarshipProgram::class, 'program_id');
    }

    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ScholarshipApplication::class, 'program_period_term_id');
    }

    public function billingBatches(): HasMany
    {
        return $this->hasMany(BillingBatch::class, 'program_period_term_id');
    }

    public function fundingReceipts(): HasMany
    {
        return $this->hasMany(FundingReceipt::class, 'program_period_term_id');
    }
}
