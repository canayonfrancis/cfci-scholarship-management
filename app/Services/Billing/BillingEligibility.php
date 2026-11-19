<?php

namespace App\Services\Billing;

use App\Models\AcademicRecord;
use App\Models\ProgramPeriodTerm;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipBatchMembership;
use App\Models\ScholarshipRate;
use App\Services\Eligibility\OfficialRuleCatalog;
use App\Services\Eligibility\PrivateEvidence;
use Illuminate\Validation\ValidationException;

class BillingEligibility
{
    public const POLICY = 'current_eligible_active_exact_semester_v1';

    public function __construct(private PrivateEvidence $evidence, private OfficialRuleCatalog $catalog) {}

    public function basis(ScholarshipApplication $application, ProgramPeriodTerm $terms, ?ScholarshipRate $rate = null): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['billing' => $message]);
        $this->catalog->assertDemonstrationStudent($application->program->code, $application->student);
        $exclusion = $application->assignment_id
            ? ScholarshipBatchMembership::billingExclusionFor($application->student_id, $application->assignment_id, $application->academic_period_id) : null;
        if ($exclusion) {
            $fail('Temporary semester billing exclusion: '.$exclusion->billing_exclusion['reason_name'].'. '.($exclusion->billing_exclusion['remarks'] ?? '').' Enrollment and scholarship status are retained.');
        }
        if (strtoupper(trim($application->program->code)) === 'EDUKAR') {
            $fail('EDUKAR requires an approved individual variable entitlement workflow. A uniform semester rate cannot establish its payable amount.');
        }
        $rate ??= $terms->rates()->orderByDesc('revision')->first();
        if (! $rate || $rate->program_period_term_id !== $terms->id || ! $rate->approved_by || ! $rate->approved_at || Money::cents($rate->amount) <= 0) {
            $fail('A Coordinator-approved scholarship amount for the exact semester is required.');
        }
        $amount = $rate->amount;
        if ($application->program_period_term_id !== $terms->id || $application->program_id !== $terms->program_id || $application->academic_period_id !== $terms->academic_period_id) {
            $fail('The scholar validation must match this program and semester.');
        }
        $enrollment = AcademicRecord::where('student_id', $application->student_id)->where('academic_period_id', $application->academic_period_id)->orderByDesc('revision')->first();
        if (! $enrollment || $enrollment->verification_status !== 'verified' || ! $enrollment->verified_by || ! $enrollment->verified_at || $enrollment->enrollment_status !== 'enrolled') {
            $fail('Verified enrollment for this semester is required. Missing, unverified or not-enrolled records cannot authorize billing.');
        }
        if ($application->approved_gross_amount !== null) {
            $centavos = function (string $amount): int {
                [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');

                return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
            };
            if (! $application->amount_authorized_by || ! $application->amount_authorized_at || ! trim($application->amount_basis ?? '') ||
                $centavos($amount) > $centavos($application->approved_gross_amount)) {
                $fail('The semester bill exceeds or lacks the recorded individual amount authorization. Resolve the approved entitlement before billing.');
            }
        }
        $assignment = $application->assignment;
        if (! $assignment || $assignment->status !== 'active' || $assignment->student_id !== $application->student_id || $assignment->program_id !== $terms->program_id ||
            $application->student->record_status !== 'active' || ! $application->program->is_active) {
            $fail('Billing requires an active scholarship assignment, active scholar and available program.');
        }
        $period = $application->academicPeriod;
        $start = $assignment->startPeriod;
        $end = $assignment->endPeriod;
        $ordinal = function ($period): ?int {
            return $period && preg_match('/^(\d{4})-\d{4}$/', $period->academic_year, $parts) && in_array($period->semester_code, ['1', '2'], true)
                ? (int) $parts[1] * 2 + (int) $period->semester_code : null;
        };
        $targetOrdinal = $ordinal($period);
        if (! $start || ($targetOrdinal !== null && $ordinal($start) !== null && $ordinal($start) > $targetOrdinal) ||
            ($end && $targetOrdinal !== null && $ordinal($end) !== null && $ordinal($end) < $targetOrdinal) ||
            ($start->id !== $period->id && ($targetOrdinal === null || $ordinal($start) === null) && (! $start->starts_on || ! $period->ends_on || $start->starts_on->gt($period->ends_on))) ||
            ($end && $end->id !== $period->id && ($targetOrdinal === null || $ordinal($end) === null) && (! $end->ends_on || ! $period->starts_on || $end->ends_on->lt($period->starts_on))) ||
            ($assignment->starts_on && $period->ends_on && $assignment->starts_on->gt($period->ends_on)) ||
            ($assignment->ends_on && $period->starts_on && $assignment->ends_on->lt($period->starts_on))) {
            $fail('The active scholarship assignment must cover the selected academic year and semester. Unknown period ordering requires confirmed dates.');
        }
        $run = $application->currentSchoolDecision();
        $policy = $this->catalog->forProgram($application->program->code, $terms);
        if (($policy['mode'] ?? null) === OfficialRuleCatalog::INSTITUTIONAL_MODE) {
            $this->catalog->assertInstitutionalStudent($application->student);
        }
        if (! $run || $run->automatic_outcome !== 'passed' || $run->school_decision !== 'qualified' || ! $run->evaluated_by ||
            ($run->rule_snapshot['confirmed'] ?? false) !== true || ! $policy || ($run->rule_snapshot['policy'] ?? null) != $policy ||
            ! $this->catalog->appliesTo($application->academicPeriod)) {
            $fail('A current eligible semester validation is required. Pending, failed or stale validations cannot be billed.');
        }
        $approval = $application->externalApprovals()->where('scope', 'semester')->orderByDesc('id')->first();
        $today = now('Asia/Manila')->toDateString();
        if (! $approval || $approval->decision !== 'approved' || ! $approval->recorded_by || ! $approval->decided_on || ! trim($approval->reference ?? '') ||
            (! trim($approval->authority ?? '') || ($application->program->expectedApprovalAuthority() !== null && $approval->authority !== $application->program->expectedApprovalAuthority())) ||
            $approval->assignment_id !== $assignment->id || $approval->academic_period_id !== $terms->academic_period_id ||
            $approval->decided_on->toDateString() > $today || ($approval->valid_from && $approval->valid_from->toDateString() > $today) ||
            ($approval->valid_until && $approval->valid_until->toDateString() < $today) ||
            ! $approval->documents()->get()->contains(fn ($document) => $this->evidence->usable($document))) {
            $fail('A current verified external approval for the exact assignment and semester is required.');
        }

        return [
            'evaluation_run_id' => $run->id,
            'external_approval_id' => $approval->id,
            'gross_amount' => $amount,
            'entitlement_snapshot' => $amount,
            'basis_snapshot' => [
                'policy' => self::POLICY, 'policy_reference' => $rate->source_reference, 'rate_id' => $rate->id, 'rate_revision' => $rate->revision,
                'amount_approved_by' => $rate->approved_by, 'amount_approved_at' => $rate->approved_at->toIso8601String(),
                'semester_amount' => $amount, 'evaluation_id' => $run->id,
                'evaluation_completed_at' => $run->completed_at->toIso8601String(),
                'academic_context' => $run->input_snapshot['academic_context'] ?? [],
                'external_approval_id' => $approval->id, 'external_reference' => $approval->reference,
                'checked_at' => now()->toIso8601String(),
            ],
        ];
    }
}
