<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScholarshipBatch extends Model
{
    protected $fillable = ['program_id', 'academic_period_id', 'batch_number', 'created_by'];

    public function program(): BelongsTo
    {
        return $this->belongsTo(ScholarshipProgram::class, 'program_id');
    }

    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(ScholarshipBatchMembership::class);
    }

    public function billingBatches(): HasMany
    {
        return $this->hasMany(BillingBatch::class);
    }
}
