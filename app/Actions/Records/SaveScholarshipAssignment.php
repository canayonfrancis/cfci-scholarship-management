<?php

namespace App\Actions\Records;

use App\Models\AcademicPeriod;
use App\Models\DelistingReason;
use App\Models\ScholarshipAssignment;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SaveScholarshipAssignment
{
    public function __construct(private RecordAudit $audit) {}

    public function save(Student $student, User $actor, array $data, ?ScholarshipAssignment $assignment = null): ScholarshipAssignment
    {
        try {
            return DB::transaction(function () use ($student, $actor, $data, $assignment) {
                // All assignment changes serialize on the same student, not just the chosen program.
                $student = Student::whereKey($student->id)->lockForUpdate()->firstOrFail();
                $current = $assignment ? $student->assignments()->whereKey($assignment->id)->lockForUpdate()->firstOrFail() : new ScholarshipAssignment;
                Gate::forUser($actor)->authorize($current->exists ? 'update' : 'create', $current->exists ? $current : ScholarshipAssignment::class);
                if ($current->exists && ! in_array($current->status, ['pending', 'active'], true)) {
                    throw ValidationException::withMessages(['status' => 'Historical and suspended scholarships cannot be rewritten.']);
                }
                if ($current->exists && ((int) $data['program_id'] !== $current->program_id || ($current->start_period_id !== null && (int) $data['start_period_id'] !== $current->start_period_id))) {
                    throw ValidationException::withMessages(['program_id' => 'Keep the original program and starting semester. Record a new assignment to change programs.']);
                }
                $program = ScholarshipProgram::whereKey($data['program_id'])->lockForUpdate()->firstOrFail();
                $periods = AcademicPeriod::whereIn('id', array_filter([$data['start_period_id'], $data['end_period_id'] ?? null]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $start = $periods->get($data['start_period_id']);
                $end = $periods->get($data['end_period_id'] ?? null);
                if ($end && $start->starts_on && $end->ends_on && $end->ends_on->lt($start->starts_on)) {
                    throw ValidationException::withMessages(['end_period_id' => 'The closing semester must not precede the starting semester.']);
                }
                $closed = in_array($data['status'], ['ended', 'relinquished'], true);
                if (! empty($data['delisting_reason_id'])) {
                    if (! $closed || ! Schema::hasTable('delisting_reasons')) {
                        throw ValidationException::withMessages(['delisting_reason_id' => 'A registry reason can accompany only an explicit scholarship closure with the registry available.']);
                    }
                    $reason = DelistingReason::whereKey($data['delisting_reason_id'])->lockForUpdate()->first();
                    if (! $reason || ! $reason->is_active) {
                        throw ValidationException::withMessages(['delisting_reason_id' => 'Select an available Active reason from the registry.']);
                    }
                    $data['status_reason'] = 'Reason for Delisting: '.$reason->name."\nRemarks / school reference: ".trim($data['status_reason'] ?? '');
                    if (Str::length($data['status_reason']) > 5000) {
                        throw ValidationException::withMessages(['status_reason' => 'Keep the reason and remarks together within 5,000 characters. Shorten the remarks without omitting the required reference.']);
                    }
                }
                unset($data['delisting_reason_id']);
                $status = $closed ? 'ended' : $data['status'];
                if ($current->exists && $current->status === 'active' && $status === 'pending') {
                    throw ValidationException::withMessages(['status' => 'Close an active scholarship to end it. Pending is reserved for assignments that have not started.']);
                }
                if ($status === 'active') {
                    if (! $current->exists || $current->status !== 'active') {
                        throw ValidationException::withMessages(['status' => 'Create a pending assignment, then activate the award through its eligible evaluation and verified external approval.']);
                    }
                    if ($student->record_status !== 'active' || (! $program->is_active && ! ($current->exists && $current->status === 'active'))) {
                        throw ValidationException::withMessages(['program_id' => 'An active assignment requires an active scholar record and a program available for assignment.']);
                    }
                    if ($student->assignments()->where('status', 'active')->when($current->exists, fn ($query) => $query->whereKeyNot($current->id))->exists()) {
                        throw ValidationException::withMessages(['status' => 'This scholar already has an active scholarship. Close it before activating another.']);
                    }
                }
                if (! $closed && $student->assignments()->where('program_id', $program->id)->where('start_period_id', $start->id)->whereIn('status', ['pending', 'active'])->when($current->exists, fn ($query) => $query->whereKeyNot($current->id))->exists()) {
                    throw ValidationException::withMessages(['program_id' => 'This scholar already has a pending or active assignment to this program for the starting semester.']);
                }
                $before = $current->exists ? $current->only($current->getFillable()) : [];
                $current->fill($data);
                $current->student_id = $student->id;
                $current->status = $status;
                $current->closure_reason = $closed ? ($data['status'] === 'relinquished' ? 'relinquished' : 'ended') : null;
                if ($status === 'active' && (! $current->exists || $current->getOriginal('status') !== 'active')) {
                    $current->activated_by = $actor->id;
                }
                if ($closed) {
                    $current->closed_by = $actor->id;
                } else {
                    $current->end_period_id = null;
                    $current->ends_on = null;
                }
                $current->save();
                $this->audit->record($actor, $current, $before === [] ? 'scholarship_assigned' : ($closed ? 'scholarship_closed' : 'scholarship_updated'), $before, $data['status_reason'] ?? null);

                return $current;
            }, 3);
        } catch (UniqueConstraintViolationException $error) {
            if (str_contains($error->getMessage(), 'active_student_id') || str_contains($error->getMessage(), 'sms_one_active_assignment_uq')) {
                throw ValidationException::withMessages(['status' => 'This scholar already has an active scholarship. Reload the record before assigning another.']);
            }

            throw $error;
        }
    }
}
