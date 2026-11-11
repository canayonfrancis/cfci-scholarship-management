<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicRecord extends Model
{
    protected $fillable = [
        'student_id',
        'academic_period_id',
        'revision',
        'course',
        'year_level',
        'enrollment_status',
        'grade_average',
        'units_enrolled',
        'units_completed',
        'academic_summary',
        'source_reference',
        'verification_status',
        'verified_by',
        'verified_at',
        'verification_notes',
        'supersedes_id',
        'academic_standing',
        'correction_reason',
        'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'lock_version' => 'integer',
            'grade_average' => 'decimal:3',
            'units_enrolled' => 'decimal:2',
            'units_completed' => 'decimal:2',
            'academic_summary' => 'array',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function isLatestRevision(): bool
    {
        return ! static::where('student_id', $this->student_id)->where('academic_period_id', $this->academic_period_id)->where('revision', '>', $this->revision)->exists();
    }

    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(AcademicRecord::class, 'supersedes_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AcademicRecord::class, 'supersedes_id');
    }

    public function gradeEvaluations(): HasMany
    {
        return $this->hasMany(EvaluationRun::class, 'grade_record_id');
    }

    public function enrollmentEvaluations(): HasMany
    {
        return $this->hasMany(EvaluationRun::class, 'enrollment_record_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'academic_record_id');
    }

    public function grades(): HasMany
    {
        return $this->hasMany(AcademicRecordGrade::class);
    }
}
