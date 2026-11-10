<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScholarshipApplication extends Model
{
    protected $fillable = [
        'student_id',
        'program_id',
        'academic_period_id',
        'program_period_term_id',
        'assignment_id',
        'submission_revision',
        'status',
        'submitted_at',
        'approved_gross_amount',
        'amount_authorized_by',
        'amount_authorized_at',
        'amount_basis',
    ];

    protected function casts(): array
    {
        return [
            'submission_revision' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'approved_gross_amount' => 'decimal:2',
            'amount_authorized_at' => 'immutable_datetime',
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

    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }

    public function terms(): BelongsTo
    {
        return $this->belongsTo(ProgramPeriodTerm::class, 'program_period_term_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ScholarshipAssignment::class, 'assignment_id');
    }

    public function amountAuthorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'amount_authorized_by');
    }

    public function evaluationRuns(): HasMany
    {
        return $this->hasMany(EvaluationRun::class, 'application_id');
    }

    public function externalApprovals(): HasMany
    {
        return $this->hasMany(ExternalApproval::class, 'application_id');
    }

    public function billingItems(): HasMany
    {
        return $this->hasMany(BillingItem::class, 'application_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'application_id');
    }
}
