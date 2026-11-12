<?php

namespace App\Services\Billing;

use App\Models\BillingBatch;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipBatch;
use App\Models\ScholarshipBatchMembership;
use Illuminate\Validation\ValidationException;

class BillingRoster
{
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
