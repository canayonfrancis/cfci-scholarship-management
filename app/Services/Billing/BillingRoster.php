<?php

namespace App\Services\Billing;

use App\Models\AcademicRecord;
use App\Models\BillingBatch;
use App\Models\BillingItem;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipBatch;
use App\Models\ScholarshipBatchMembership;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class BillingRoster
{
    /** Read-only readiness; these labels are not scholarship or financial states. */
    public function readiness(BillingBatch $batch, BillingEligibility $eligibility): Collection
    {
        $members = ScholarshipBatchMembership::with(['student.academicProgram', 'assignment'])
            ->where('scholarship_batch_id', $batch->scholarship_batch_id ?? 0)->orderBy('student_id')->get();
        $applications = ScholarshipApplication::with(['student', 'assignment.startPeriod', 'assignment.endPeriod', 'program', 'academicPeriod'])
            ->where('program_period_term_id', $batch->program_period_term_id)
            ->whereIn('student_id', $members->pluck('student_id'))->get()->keyBy('student_id');
        $history = AcademicRecord::with(['documents', 'verifiedBy'])->where('academic_period_id', $batch->academic_period_id)
            ->whereIn('student_id', $members->pluck('student_id'))->orderByDesc('revision')->get()->groupBy('student_id');
        $records = $history->map(fn ($revisions) => $revisions->first());
        $reservations = BillingItem::where('academic_period_id', $batch->academic_period_id)
            ->whereIn('student_id', $members->pluck('student_id'))->get()->keyBy('student_id');

        return $members->map(function ($member) use ($batch, $eligibility, $applications, $records, $reservations, $history) {
            $application = $applications->get($member->student_id);
            $record = $records->get($member->student_id);
            $known = $record && $record->verification_status === 'verified' && $record->verified_by && $record->verified_at;
            $enrollment = $known && in_array($record->enrollment_status, ['enrolled', 'not_enrolled'], true)
                ? ($record->enrollment_status === 'enrolled' ? 'Enrolled' : 'Not enrolled') : 'Unverified';
            $state = 'review';
            $reason = 'Pending review: no semester evaluation is recorded for this roster assignment.';
            $amount = null;
            $eligible = false;
            if ($member->program_id !== $batch->program_id || $member->academic_period_id !== $batch->academic_period_id ||
                ($application && $application->assignment_id !== $member->assignment_id)) {
                $reason = 'The roster membership and semester evaluation do not match this program, period and assignment.';
            } elseif ($enrollment === 'Not enrolled' || $member->assignment->status !== 'active' || $member->student->record_status !== 'active') {
                $state = 'excluded';
                $reason = match (true) {
                    $enrollment === 'Not enrolled' => 'Verified not enrolled for this semester: excluded from ordinary semester billing. Scholarship assignment and roster history are preserved; this is not permanent delisting.',
                    $member->student->record_status !== 'active' => 'The scholar profile is not active. Enrollment verification does not restore the profile or authorize billing.',
                    $member->assignment->status === 'pending' => 'The scholarship assignment is pending. Complete the existing school evaluation and verified external approval, then use Award activation in the evaluation workspace. Enrollment alone cannot activate it.',
                    default => 'The scholarship assignment is not active. Review its retained award history; enrollment does not change its status.',
                };
            } elseif ($application) {
                try {
                    $basis = $eligibility->basis($application, $batch->terms, $batch->appliedRate());
                    $state = 'eligible';
                    $eligible = true;
                    $amount = $basis['gross_amount'];
                    $reason = 'Current eligibility and semester approval meet draft preparation requirements.';
                } catch (ValidationException $exception) {
                    $reason = collect($exception->errors())->flatten()->first();
                }
            }
            if ($enrollment === 'Unverified' && ! $eligible) {
                $reason = 'Enrollment is unverified for this semester. '.$reason;
            }
            $eligibilityLabel = $eligible ? 'Eligible' : ($state === 'excluded' ? 'Excluded' : 'Pending review');
            $reservation = $reservations->get($member->student_id);
            if ($reservation) {
                $state = 'reserved';
                $reason = ($reservation->billing_batch_id === $batch->id
                    ? 'Already included in this billing draft/history.' : 'Already billed or reserved in another batch for this semester.')
                    .(! $eligible ? ' Current validation: '.$reason : '');
                $eligible = false;
            }

            return compact('member', 'application', 'enrollment', 'state', 'reason', 'amount', 'eligible', 'record') + [
                'academic_history' => $history->get($member->student_id, collect()),
                'eligibility' => $eligibilityLabel,
                'availability' => $reservation ? 'Already billed or reserved' : ($eligible ? 'Available for draft' : 'Unavailable'),
            ];
        });
    }

    public function selection(int $rosterId, int $programId, int $periodId): ScholarshipBatch
    {
        $roster = ScholarshipBatch::whereKey($rosterId)->lockForUpdate()->first();
        if (! $roster || $roster->program_id !== $programId || $roster->academic_period_id !== $periodId) {
            throw ValidationException::withMessages(['scholarship_batch_id' => 'Select an existing semester roster matching this scholarship program and academic period.']);
        }

        return $roster;
    }

    public function member(BillingBatch $batch, ScholarshipApplication $application): void
    {
        $this->selection((int) $batch->scholarship_batch_id, $batch->program_id, $batch->academic_period_id);
        $member = ScholarshipBatchMembership::where('scholarship_batch_id', $batch->scholarship_batch_id)
            ->where('student_id', $application->student_id)->where('assignment_id', $application->assignment_id)
            ->where('program_id', $batch->program_id)->where('academic_period_id', $batch->academic_period_id)
            ->lockForUpdate()->first();
        if (! $member || $application->program_id !== $batch->program_id || $application->academic_period_id !== $batch->academic_period_id) {
            throw ValidationException::withMessages(['billing' => 'The scholar and exact scholarship assignment must belong to this roster, program and academic period.']);
        }
    }

    /** Fail closed until approved templates and revision-traceable classification exist (CR-02). */
    public function submissionClassification(BillingBatch $batch): void
    {
        throw ValidationException::withMessages(['classification' => 'Final billing submission is blocked: approved Form 2/Form 3 templates and current payable classification are not yet available. Roster membership does not establish payability.']);
    }
}
