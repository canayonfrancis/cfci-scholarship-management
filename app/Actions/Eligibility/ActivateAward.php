<?php

namespace App\Actions\Eligibility;

use App\Actions\Records\RecordAudit;
use App\Models\AcademicValidationContext;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipAssignment;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Models\User;
use App\Services\Eligibility\OfficialRuleCatalog;
use App\Services\Eligibility\PrivateEvidence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ActivateAward
{
    public function __construct(private PrivateEvidence $evidence, private RecordAudit $audit) {}

    public function handle(ScholarshipApplication $application, User $actor, int $expectedRun, int $expectedApproval): ScholarshipAssignment
    {
        try {
            return DB::transaction(function () use ($application, $actor, $expectedRun, $expectedApproval) {
                $student = Student::whereKey($application->student_id)->lockForUpdate()->firstOrFail();
                AcademicValidationContext::whereKey(1)->lockForUpdate()->firstOrFail();
                $application = ScholarshipApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('evaluate', $application);
                $application->setRelation('program', ScholarshipProgram::whereKey($application->program_id)->lockForUpdate()->firstOrFail());
                app(OfficialRuleCatalog::class)->assertDemonstrationStudent($application->program->code, $student);
                $assignment = $application->assignment()->lockForUpdate()->first();
                $fail = fn (string $message) => throw ValidationException::withMessages(['award' => $message]);
                if (! $assignment || $assignment->status !== 'pending' || $student->record_status !== 'active' || ! $application->program->is_active) {
                    $fail('Activation requires a pending assignment, an active scholar, and an available program.');
                }
                if ($student->assignments()->where('status', 'active')->exists()) {
                    $fail('This scholar already has an active scholarship. Resolve that award before activation.');
                }
                $run = $application->currentSchoolDecision();
                $terms = $application->terms()->lockForUpdate()->firstOrFail();
                $policy = app(OfficialRuleCatalog::class)->forProgram($application->program->code, $terms);
                if (($policy['mode'] ?? null) === OfficialRuleCatalog::INSTITUTIONAL_MODE) {
                    app(OfficialRuleCatalog::class)->assertInstitutionalStudent($student);
                }
                if (! $run || $run->id !== $expectedRun || $run->automatic_outcome !== 'passed' || $run->school_decision !== 'qualified' ||
                    ! $run->evaluated_by || ($run->rule_snapshot['confirmed'] ?? false) !== true || ! $policy || $run->rule_snapshot['policy'] != $policy) {
                    $fail('A current eligible school evaluation is required. Reevaluate changed or incomplete records.');
                }
                $enrollment = $run->enrollmentRecord()->first();
                if (! $enrollment || $enrollment->verification_status !== 'verified' || ! $enrollment->verified_by || ! $enrollment->verified_at || $enrollment->enrollment_status !== 'enrolled') {
                    $fail('Current Registrar-verified enrolled status is required before award activation.');
                }
                foreach ($run->input_snapshot['evidence'] ?? [] as $snapshot) {
                    $document = $application->documents()->find($snapshot['id']);
                    if (! $document || ! $this->evidence->usable($document) || $document->checksum !== $snapshot['checksum']) {
                        $fail('Evaluation evidence is unavailable or changed. Reevaluate before activation.');
                    }
                }
                $approval = $application->externalApprovals()->where('scope', 'semester')->orderByDesc('id')->lockForUpdate()->first();
                $today = now('Asia/Manila')->toDateString();
                if (! $approval || $approval->id !== $expectedApproval || $approval->decision !== 'approved' || ! $approval->recorded_by || ! $approval->decided_on || ! trim($approval->reference ?? '') ||
                    (! trim($approval->authority ?? '') || ($application->program->expectedApprovalAuthority() !== null && $approval->authority !== $application->program->expectedApprovalAuthority())) || $approval->decided_on->toDateString() > $today ||
                    $approval->assignment_id !== $assignment->id || $approval->academic_period_id !== $application->academic_period_id ||
                    ($approval->valid_from && $approval->valid_from->toDateString() > $today) || ($approval->valid_until && $approval->valid_until->toDateString() < $today) ||
                    ! $approval->documents()->get()->contains(fn ($document) => $this->evidence->usable($document))) {
                    $fail('A current, verified external approval for this exact award and semester is required.');
                }
                $before = $assignment->only($assignment->getFillable());
                $assignment->update(['status' => 'active', 'activated_by' => $actor->id, 'activated_at' => now(), 'activation_evaluation_id' => $run->id, 'activation_approval_id' => $approval->id, 'status_reason' => 'Activated from verified school evaluation and external approval.']);
                $this->audit->record($actor, $assignment, 'scholarship_award_activated', $before);

                return $assignment;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['award' => 'Another active scholarship already occupies this scholar’s award slot.']);
        }
    }
}
