<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicPeriod extends Model
{
    protected $fillable = [
        'academic_year',
        'semester_code',
        'starts_on',
        'ends_on',
        'is_open',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'is_open' => 'boolean',
        ];
    }

    public function terms(): HasMany
    {
        return $this->hasMany(ProgramPeriodTerm::class, 'academic_period_id');
    }

    public function startingAssignments(): HasMany
    {
        return $this->hasMany(ScholarshipAssignment::class, 'start_period_id');
    }

    public function endingAssignments(): HasMany
    {
        return $this->hasMany(ScholarshipAssignment::class, 'end_period_id');
    }

    public function isInUse(): bool
    {
        return AcademicValidationContext::where('active_period_id', $this->id)->orWhere('source_period_id', $this->id)->exists()
            || $this->terms()->exists() || $this->academicRecords()->exists()
            || $this->applications()->exists() || $this->startingAssignments()->exists()
            || $this->endingAssignments()->exists();
    }

    public function academicRecords(): HasMany
    {
        return $this->hasMany(AcademicRecord::class, 'academic_period_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ScholarshipApplication::class, 'academic_period_id');
    }

    public function billingBatches(): HasMany
    {
        return $this->hasMany(BillingBatch::class, 'academic_period_id');
    }

    public function fundingReceipts(): HasMany
    {
        return $this->hasMany(FundingReceipt::class, 'academic_period_id');
    }
}
