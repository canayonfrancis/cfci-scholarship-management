<?php

namespace App\Models;

use App\Services\Billing\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingItem extends Model
{
    protected $fillable = [
        'billing_batch_id',
        'application_id',
        'assignment_id',
        'student_id',
        'program_id',
        'academic_period_id',
        'evaluation_run_id',
        'external_approval_id',
        'gross_amount',
        'entitlement_snapshot',
        'basis_snapshot',
        'status',
        'finalized_by',
        'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'entitlement_snapshot' => 'decimal:2',
            'basis_snapshot' => 'array',
            'finalized_at' => 'immutable_datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BillingBatch::class, 'billing_batch_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'application_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ScholarshipAssignment::class, 'assignment_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(ScholarshipProgram::class, 'program_id');
    }

    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }

    public function evaluationRun(): BelongsTo
    {
        return $this->belongsTo(EvaluationRun::class, 'evaluation_run_id');
    }

    public function externalApproval(): BelongsTo
    {
        return $this->belongsTo(ExternalApproval::class, 'external_approval_id');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'billing_item_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(BillingAdjustment::class, 'billing_item_id');
    }

    public function effectiveAmount(): string
    {
        return Money::decimal(Money::cents($this->gross_amount) + Money::signedTotal($this->adjustments()->get()));
    }
}
