<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScholarshipProgram extends Model
{
    protected $fillable = [
        'code',
        'name',
        'school_stated_semester_amount',
        'planning_duration_years',
        'source_notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'school_stated_semester_amount' => 'decimal:2',
            'planning_duration_years' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function terms(): HasMany
    {
        return $this->hasMany(ProgramPeriodTerm::class, 'program_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ScholarshipAssignment::class, 'program_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ScholarshipApplication::class, 'program_id');
    }

    public function externalApprovals(): HasMany
    {
        return $this->hasMany(ExternalApproval::class, 'program_id');
    }

    public function billingBatches(): HasMany
    {
        return $this->hasMany(BillingBatch::class, 'program_id');
    }

    public function fundingReceipts(): HasMany
    {
        return $this->hasMany(FundingReceipt::class, 'program_id');
    }
}
