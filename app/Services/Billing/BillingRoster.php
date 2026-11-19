<?php

namespace App\Services\Billing;

use App\Models\AcademicRecord;
use App\Models\BillingBatch;
use App\Models\BillingItem;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipBatch;
use App\Models\ScholarshipBatchMembership;
use App\Services\Eligibility\OfficialRuleCatalog;
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
        $exclusions = ScholarshipBatchMembership::where('program_id', $batch->program_id)->where('academic_period_id', $batch->academic_period_id)
            ->whereIn('student_id', $members->pluck('student_id'))->orderBy('id')->get()
            ->filter(fn ($member) => $member->hasBillingExclusion())->keyBy('assignment_id');

        return $members->map(function ($member) use ($batch, $eligibility, $applications, $records, $reservations, $history, $exclusions) {
            $application = $applications->get($member->student_id);
            $record = $records->get($member->student_id);
            $known = $record && $record->verification_status === 'verified' && $record->verified_by && $record->verified_at;
            $enrollment = $known && in_array($record->enrollment_status, ['enrolled', 'not_enrolled'], true)
                ? ($record->enrollment_status === 'enrolled' ? 'Enrolled' : 'Not enrolled') : 'Unverified';
            $state = 'review';
            $reason = 'Pending review: no semester evaluation is recorded for this roster assignment.';
            $amount = null;
            $eligible = false;
            $exclusionMember = $exclusions->get($member->assignment_id);
            if ($member->program_id !== $batch->program_id || $member->academic_period_id !== $batch->academic_period_id ||
                ($application && $application->assignment_id !== $member->assignment_id)) {
                $reason = 'The roster membership and semester evaluation do not match this program, period and assignment.';
            } elseif ($exclusionMember) {
                $state = 'excluded';
                $reason = 'Temporary semester billing exclusion: '.$exclusionMember->billing_exclusion['reason_name'].'. '
                    .($exclusionMember->billing_exclusion['remarks'] ?? '').' Enrollment and scholarship assignment status are unchanged.';
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
            if ($member->assignment->status === 'ended' && filled($member->assignment->status_reason)) {
                $reason .= ' Recorded scholarship closure reason / remarks: '.$member->assignment->status_reason;
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
                'reservation' => $reservation,
                'exclusion_member' => $exclusionMember,
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

    public function demonstrationAvailable(BillingBatch $batch): bool
    {
        return OfficialRuleCatalog::demonstrationProgram($batch->program->code);
    }

    /** Fictional enrollment classification, never an official Form 2/Form 3 mapping. Caller holds the billing transaction. */
    public function demonstrationClassification(BillingBatch $batch): ?array
    {
        if (! $this->demonstrationAvailable($batch)) {
            return null;
        }
        $this->selection((int) $batch->scholarship_batch_id, $batch->program_id, $batch->academic_period_id);
        $members = ScholarshipBatchMembership::where('scholarship_batch_id', $batch->scholarship_batch_id)
            ->orderBy('student_id')->lockForUpdate()->get();
        $items = $batch->items()->orderBy('student_id')->lockForUpdate()->get();
        $rows = [];
        $enrolled = [];
        $catalog = app(OfficialRuleCatalog::class);
        foreach ($members as $member) {
            $student = $member->student()->lockForUpdate()->firstOrFail();
            $catalog->assertDemonstrationStudent($batch->program->code, $student);
            $record = $student->academicRecords()->where('academic_period_id', $batch->academic_period_id)
                ->orderByDesc('revision')->lockForUpdate()->first();
            if ($member->program_id !== $batch->program_id || $member->academic_period_id !== $batch->academic_period_id
                || ! $record || $record->verification_status !== 'verified' || ! $record->verified_by || ! $record->verified_at
                || ! in_array($record->enrollment_status, ['enrolled', 'not_enrolled'], true)) {
                throw ValidationException::withMessages(['classification' => 'UAT submission requires matching roster records and a current Registrar-verified enrollment finding for every sample scholar.']);
            }
            $exclusion = ScholarshipBatchMembership::billingExclusionFor($student->id, $member->assignment_id, $batch->academic_period_id);
            if ($record->enrollment_status === 'enrolled' && ! $exclusion) {
                $enrolled[] = $student->id;
            }
            $rows[] = [
                'student_id' => $student->id, 'membership_id' => $member->id, 'assignment_id' => $member->assignment_id,
                'enrollment_record_id' => $record->id, 'revision' => $record->revision,
                'lock_version' => $record->lock_version, 'verified_by' => $record->verified_by,
                'verified_at' => $record->verified_at->toIso8601String(),
                'class' => $exclusion ? 'UAT_BILLING_EXCLUDED' : ($record->enrollment_status === 'enrolled' ? 'UAT_ENROLLED' : 'UAT_NOT_ENROLLED'),
                ...($exclusion ? ['exclusion_membership_id' => $exclusion->id, 'exclusion_snapshot' => $exclusion->billing_exclusion] : []),
            ];
        }
        $included = $items->pluck('student_id')->all();
        sort($enrolled);
        sort($included);
        if ($items->isEmpty() || $enrolled !== $included || $items->pluck('student_id')->unique()->count() !== $items->count()) {
            throw ValidationException::withMessages(['classification' => 'The fictional whole-roster scenario must bill every enrolled, non-excluded sample exactly once and exclude not-enrolled or explicitly excluded samples. Resolve remaining eligibility before submission.']);
        }
        foreach ($items as $item) {
            $member = $members->firstWhere('student_id', $item->student_id);
            if (! $member || $member->assignment_id !== $item->assignment_id || $item->program_id !== $batch->program_id || $item->academic_period_id !== $batch->academic_period_id) {
                throw ValidationException::withMessages(['classification' => 'A UAT item no longer matches its exact roster assignment and academic period.']);
            }
        }

        return ['label' => OfficialRuleCatalog::DEMO_LABEL, 'version' => 'UAT-ENROLLMENT-CLASSIFICATION-v1',
            'roster_id' => $batch->scholarship_batch_id, 'program_id' => $batch->program_id,
            'academic_period_id' => $batch->academic_period_id, 'rows' => $rows];
    }

    /** Real submissions fail closed until approved templates and classification exist (CR-02). */
    public function submissionClassification(BillingBatch $batch): void
    {
        if ($this->demonstrationClassification($batch) !== null) {
            return;
        }
        throw ValidationException::withMessages(['classification' => 'Final billing submission is blocked: approved Form 2/Form 3 templates and current payable classification are not yet available. Roster membership does not establish payability.']);
    }
}
