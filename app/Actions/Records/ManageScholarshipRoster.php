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
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageScholarshipRoster
{
    public function __construct(private RecordAudit $audit) {}

    public function create(User $actor, array $data): ScholarshipBatch
    {
        Gate::forUser($actor)->authorize('create', ScholarshipBatch::class);
        try {
            return DB::transaction(function () use ($actor, $data) {
                Validator::make($data, [
                    'batch_number' => ['required', 'string', 'max:30', 'regex:/^[0-9]+(?:\.[0-9]+)?$/D'],
                ])->validate();
                $program = ScholarshipProgram::whereKey($data['program_id'])->lockForUpdate()->firstOrFail();
                $period = AcademicPeriod::whereKey($data['academic_period_id'])->lockForUpdate()->firstOrFail();
                // Keep the entered number as text: 1.0 must not become 1.
                $name = $program->code.'-'.$data['batch_number'].'-'.$period->semester_code.'-'.$period->academic_year;
                if (Str::length($name) > 60) {
                    throw ValidationException::withMessages(['batch_number' => 'The generated roster name exceeds the existing 60-character limit. Use a shorter batch number.']);
                }
                $batch = ScholarshipBatch::create([
                    'program_id' => $data['program_id'], 'academic_period_id' => $data['academic_period_id'],
                    'batch_number' => $name, 'created_by' => $actor->id,
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

    /** Read-only selection explanation; the same checks are repeated under locks on save. */
    public function selection(Student $student, ScholarshipBatch $batch): array
    {
        if ($student->batchMemberships->contains('scholarship_batch_id', $batch->id)) {
            return ['eligible' => false, 'assignment_id' => null, 'needs_assignment' => false, 'reason' => 'Already belongs to this roster.'];
        }
        $current = $student->assignments->filter(fn ($assignment) => $assignment->program_id === $batch->program_id
            && in_array($assignment->status, ['pending', 'active'], true));
        $applicable = $current->filter(fn ($assignment) => $this->covers($assignment, $batch->academicPeriod));
        if ($applicable->count() > 1) {
            return ['eligible' => false, 'assignment_id' => null, 'needs_assignment' => false, 'reason' => 'Multiple pending or active assignments cover this roster. Review the scholar’s existing assignments first.'];
        }
        if ($assignment = $applicable->first()) {
            return ['eligible' => true, 'assignment_id' => $assignment->id, 'needs_assignment' => false,
                'reason' => 'Reuse '.$assignment->statusLabel().' assignment #'.$assignment->id.'; original start '.($assignment->startPeriod?->academic_year ?? 'not recorded').' / '.($assignment->startPeriod?->semester_code ?? 'not recorded').' · '.($assignment->starts_on?->format('Y-m-d') ?? 'date not recorded').'.'];
        }
        if ($current->isNotEmpty()) {
            return ['eligible' => false, 'assignment_id' => null, 'needs_assignment' => false, 'reason' => 'An existing assignment to this program does not cover this roster period. Review its original period and dates on the scholar profile.'];
        }
        $otherActive = $student->assignments->first(fn ($assignment) => $assignment->status === 'active');

        return ['eligible' => true, 'assignment_id' => null, 'needs_assignment' => true,
            'reason' => 'Create a pending assignment starting in this roster’s academic period; a starting date is required.'
                .($otherActive ? ' Another program has an active award; it remains unchanged. This pending assignment will not be activated.' : '')
                .($student->record_status !== 'active' ? ' The scholar profile is archived; roster selection does not restore it or establish billing eligibility.' : '')];
    }

    public function addSelected(ScholarshipBatch $batch, User $actor, array $studentIds, ?string $startsOn, SaveScholarshipAssignment $assignments): int
    {
        Gate::forUser($actor)->authorize('create', ScholarshipBatch::class);
        Validator::make(['student_ids' => $studentIds, 'starts_on' => $startsOn], [
            'student_ids' => ['required', 'array', 'min:1', 'max:200'],
            'student_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
        ])->validate();

        return DB::transaction(function () use ($batch, $actor, $studentIds, $startsOn, $assignments) {
            $students = Student::whereIn('id', $studentIds)->orderBy('id')->lockForUpdate()->get();
            if ($students->count() !== count($studentIds)) {
                throw ValidationException::withMessages(['student_ids' => 'A selected scholar is no longer available. Review the selection again.']);
            }
            // Use current locked reads, including when another writer committed after validation.
            foreach ($students as $student) {
                $student->setRelation('assignments', $student->assignments()->with(['program', 'startPeriod', 'endPeriod'])->orderBy('id')->lockForUpdate()->get());
                $student->setRelation('batchMemberships', $student->batchMemberships()->where('scholarship_batch_id', $batch->id)->lockForUpdate()->get());
            }
            ScholarshipProgram::whereKey($batch->program_id)->lockForUpdate()->firstOrFail();
            $period = AcademicPeriod::whereKey($batch->academic_period_id)->lockForUpdate()->firstOrFail();
            $batch = ScholarshipBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $batch->setRelation('academicPeriod', $period);
            foreach ($students as $student) {
                $choice = $this->selection($student, $batch);
                if (! $choice['eligible']) {
                    throw ValidationException::withMessages(['student_ids' => $student->student_number.': '.$choice['reason'].' Nothing was saved.']);
                }
                $assignmentId = $choice['assignment_id'];
                if ($choice['needs_assignment']) {
                    if (! $startsOn) {
                        throw ValidationException::withMessages(['starts_on' => 'Enter the scholarship starting date for selected scholars who need a new pending assignment.']);
                    }
                    $assignmentId = $assignments->save($student, $actor, [
                        'program_id' => $batch->program_id, 'start_period_id' => $batch->academic_period_id,
                        'status' => 'pending', 'starts_on' => $startsOn,
                        'status_reason' => 'Pending scholarship assignment recorded through roster selection.',
                    ])->id;
                }
                $this->join($student, $batch, $actor, $assignmentId);
            }

            return $students->count();
        }, 3);
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
