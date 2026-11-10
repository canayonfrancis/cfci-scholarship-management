<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalApproval extends Model
{
    protected $fillable = [
        'student_id',
        'program_id',
        'scope',
        'assignment_id',
        'academic_period_id',
        'application_id',
        'decision',
        'authority',
        'reference',
        'decided_on',
        'valid_from',
        'valid_until',
        'recorded_by',
        'supersedes_id',
    ];

    protected function casts(): array
    {
        return [
            'decided_on' => 'immutable_date',
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
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

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ScholarshipAssignment::class, 'assignment_id');
    }

    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'academic_period_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'application_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(ExternalApproval::class, 'supersedes_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(ExternalApproval::class, 'supersedes_id');
    }

    public function billingItems(): HasMany
    {
        return $this->hasMany(BillingItem::class, 'external_approval_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'external_approval_id');
    }
}
