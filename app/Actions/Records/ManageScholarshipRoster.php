<?php

namespace App\Actions\Records;

use App\Models\AcademicPeriod;
use App\Models\ScholarshipAssignment;
use App\Models\ScholarshipBatch;
use App\Models\ScholarshipBatchMembership;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageScholarshipRoster
{
    public function __construct(private RecordAudit $audit) {}

    public function create(User $actor, array $data): ScholarshipBatch
    {
        Gate::forUser($actor)->authorize('create', ScholarshipBatch::class);
        try {
            return DB::transaction(function () use ($actor, $data) {
                ScholarshipProgram::whereKey($data['program_id'])->lockForUpdate()->firstOrFail();
                AcademicPeriod::whereKey($data['academic_period_id'])->lockForUpdate()->firstOrFail();
                $batch = ScholarshipBatch::create([
                    'program_id' => $data['program_id'], 'academic_period_id' => $data['academic_period_id'],
                    'batch_number' => trim($data['batch_number']), 'created_by' => $actor->id,
                ]);
                $this->audit->record($actor, $batch, 'scholarship_roster_created');

                return $batch;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['batch_number' => 'This roster batch already exists for the program and semester.']);
        }
    }

    public function join(Student $student, ScholarshipBatch $batch, User $actor, int $assignmentId): ScholarshipBatchMembership
    {
        Gate::forUser($actor)->authorize('create', ScholarshipBatch::class);
        try {
            return DB::transaction(function () use ($student, $batch, $actor, $assignmentId) {
                Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
                $assignment = ScholarshipAssignment::whereKey($assignmentId)->lockForUpdate()->firstOrFail();
                $batch = ScholarshipBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
                if ($assignment->student_id !== $student->id || $assignment->program_id !== $batch->program_id ||
                    ! in_array($assignment->status, ['pending', 'active'], true) || ! $this->covers($assignment, $batch->academicPeriod)) {
                    throw ValidationException::withMessages(['assignment_id' => 'Select this scholar’s pending or active assignment covering the roster program and semester.']);
                }
                if ($batch->memberships()->where('student_id', $student->id)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['scholarship_batch_id' => 'This scholar is already a member of this roster.']);
                }
                $member = $batch->memberships()->create([
                    'assignment_id' => $assignment->id, 'student_id' => $student->id, 'program_id' => $batch->program_id,
                    'academic_period_id' => $batch->academic_period_id, 'assigned_by' => $actor->id, 'assigned_at' => now(),
                ]);
                $this->audit->record($actor, $member, 'scholarship_roster_member_assigned');

                return $member;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['scholarship_batch_id' => 'This scholar is already a member of this roster. Reload before retrying.']);
        }
    }

    private function covers(ScholarshipAssignment $assignment, AcademicPeriod $period): bool
    {
        $start = $assignment->startPeriod;
        $end = $assignment->endPeriod;
        $ordinal = fn ($p) => $p && preg_match('/^(\d{4})-\d{4}$/', $p->academic_year, $parts) && in_array($p->semester_code, ['1', '2'], true)
            ? (int) $parts[1] * 2 + (int) $p->semester_code : null;
        $target = $ordinal($period);
        if (! $start || ($target !== null && $ordinal($start) !== null && $ordinal($start) > $target) ||
            ($end && $target !== null && $ordinal($end) !== null && $ordinal($end) < $target) ||
            ($start->id !== $period->id && ($target === null || $ordinal($start) === null) && (! $start->starts_on || ! $period->ends_on || $start->starts_on->gt($period->ends_on))) ||
            ($end && $end->id !== $period->id && ($target === null || $ordinal($end) === null) && (! $end->ends_on || ! $period->starts_on || $end->ends_on->lt($period->starts_on))) ||
            ($assignment->starts_on && $period->ends_on && $assignment->starts_on->gt($period->ends_on)) ||
            ($assignment->ends_on && $period->starts_on && $assignment->ends_on->lt($period->starts_on))) {
            return false;
        }

        return true;
    }
}
