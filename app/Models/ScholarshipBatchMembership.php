<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScholarshipBatchMembership extends Model
{
    protected $fillable = ['scholarship_batch_id', 'assignment_id', 'student_id', 'program_id', 'academic_period_id', 'assigned_by', 'assigned_at'];

    protected function casts(): array
    {
        return ['assigned_at' => 'immutable_datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ScholarshipBatch::class, 'scholarship_batch_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ScholarshipAssignment::class, 'assignment_id');
    }

    /** enrollment_record_id is a read-only latest-revision subquery, not stored membership state. */
    public function enrollmentRecord(): BelongsTo
    {
        return $this->belongsTo(AcademicRecord::class, 'enrollment_record_id');
    }
}
