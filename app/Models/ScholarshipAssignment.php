<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScholarshipAssignment extends Model
{
    protected $fillable = [
        'student_id',
        'program_id',
        'start_period_id',
        'end_period_id',
        'status',
        'starts_on',
        'ends_on',
        'activated_by',
        'closed_by',
        'status_reason',
        'closure_reason',
        'activation_evaluation_id',
        'activation_approval_id',
        'activated_at',
    ];

    protected $hidden = ['active_student_id'];

    public function statusLabel(): string
    {
        return $this->status === 'ended' && $this->closure_reason === 'relinquished'
            ? 'Relinquished' : ucfirst($this->status);
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'activated_at' => 'immutable_datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(ScholarshipProgram::class, 'program_id');
    }

    public function startPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'start_period_id');
    }

    public function endPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'end_period_id');
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ScholarshipApplication::class, 'assignment_id');
    }

    public function activationEvaluation(): BelongsTo
    {
        return $this->belongsTo(EvaluationRun::class, 'activation_evaluation_id');
    }

    public function activationApproval(): BelongsTo
    {
        return $this->belongsTo(ExternalApproval::class, 'activation_approval_id');
    }

    public function externalApprovals(): HasMany
    {
        return $this->hasMany(ExternalApproval::class, 'assignment_id');
    }

    public function billingItems(): HasMany
    {
        return $this->hasMany(BillingItem::class, 'assignment_id');
    }
}
