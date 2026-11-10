<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationRun extends Model
{
    protected $fillable = [
        'application_id',
        'student_id',
        'program_id',
        'academic_period_id',
        'run_number',
        'application_revision',
        'grade_record_id',
        'enrollment_record_id',
        'rule_version',
        'input_snapshot',
        'rule_snapshot',
        'check_results',
        'automatic_outcome',
        'school_decision',
        'coordinator_id',
        'decided_at',
        'completed_at',
        'decision_reason',
        'override_reason',
    ];

    protected function casts(): array
    {
        return [
            'run_number' => 'integer',
            'application_revision' => 'integer',
            'input_snapshot' => 'array',
            'rule_snapshot' => 'array',
            'check_results' => 'array',
            'decided_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'application_id');
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

    public function gradeRecord(): BelongsTo
    {
        return $this->belongsTo(AcademicRecord::class, 'grade_record_id');
    }

    public function enrollmentRecord(): BelongsTo
    {
        return $this->belongsTo(AcademicRecord::class, 'enrollment_record_id');
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    public function billingItems(): HasMany
    {
        return $this->hasMany(BillingItem::class, 'evaluation_run_id');
    }
}
