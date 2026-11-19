<?php

namespace App\View;

use App\Models\AcademicValidationContext;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipAssignment;
use App\Services\Eligibility\EligibilityEvaluator;
use App\Services\Eligibility\OfficialRuleCatalog;
use App\Services\Eligibility\PrivateEvidence;
use Illuminate\Support\Facades\Gate;

/** Read-only presentation of existing decisions/guards; never authorizes a write. */
class EvaluationWorkspace
{
    public function __construct(private PrivateEvidence $evidence) {}

    public function present(array $data, OfficialRuleCatalog $catalog): array
    {
        /** @var ScholarshipApplication $application */
        $application = $data['application'];
        /** @var AcademicValidationContext $context */
        $context = $data['context'];
        $latest = $data['latest'];
        $assignment = $application->assignment;
        $approval = $data['approvals']->firstWhere('scope', 'semester');
        $current = $latest?->isCurrent() ?? false;
        $prior = $latest?->input_snapshot['facts_input'] ?? [];
        $officialConfirmed = $data['policy'] && $data['policyApplicable'] && $application->terms->confirmed_at
            && $application->terms->confirmed_by && $application->terms->rule_version === $data['policy']['version']
            && $application->terms->criteria == $data['policy'];
        $academic = $data['academic'];
        $enrollmentReady = $academic && $academic->verification_status === 'verified' && $academic->verified_by
            && $academic->verified_at && $academic->enrollment_status === 'enrolled';
        $activeAwards = $application->student->assignments()->where('status', 'active')->get(['id', 'program_id']);
        $usable = [];
        $usableDocument = function (Document $document) use (&$usable): bool {
            if (! array_key_exists($document->id, $usable)) {
                try {
                    $usable[$document->id] = $this->evidence->usable($document);
                } catch (\Throwable) {
                    // Storage errors cannot produce a positive readiness indicator.
                    $usable[$document->id] = false;
                }
            }

            return $usable[$document->id];
        };
        $latestDocuments = $application->documents->sortBy('id')->groupBy('document_type')->map->last();
        $requiredDocuments = $data['policy'] && $data['policyApplicable'] ? $catalog->requiredDocuments($application->program->code, $prior) : [];
        $missingDocuments = [];
        foreach ($requiredDocuments as $type) {
            $document = $latestDocuments->get($type);
            if (! $document || ! $usableDocument($document)) {
                $missingDocuments[$type] = OfficialRuleCatalog::DOCUMENTS[$type] ?? $type;
            }
        }
        $today = now('Asia/Manila')->toDateString();
        $approvalMatches = $approval && $assignment && $approval->scope === 'semester'
            && $approval->assignment_id === $assignment->id && $approval->academic_period_id === $application->academic_period_id;
        $approvalIdentity = $approval && $approval->decision === 'approved' && $approval->recorded_by
            && filled($approval->reference) && filled($approval->authority)
            && ($application->program->expectedApprovalAuthority() === null || $approval->authority === $application->program->expectedApprovalAuthority());
        $approvalDates = $approval && $approval->decided_on && $approval->decided_on->toDateString() <= $today
            && (! $approval->valid_from || $approval->valid_from->toDateString() <= $today)
            && (! $approval->valid_until || $approval->valid_until->toDateString() >= $today);
        $approvalEvidence = $approval && $approval->documents->contains($usableDocument);
        $externalReady = $approvalMatches && $approvalIdentity && $approvalDates && $approvalEvidence;
        $runEvidence = $latest && collect($latest->input_snapshot['evidence'] ?? [])->every(function ($snapshot) use ($application, $usableDocument) {
            $document = $application->documents->firstWhere('id', $snapshot['id'] ?? null);

            return $document && $document->checksum === ($snapshot['checksum'] ?? null) && $usableDocument($document);
        });
        $runReady = $officialConfirmed && $current && $latest->automatic_outcome === 'passed' && $latest->school_decision === 'qualified'
            && $latest->evaluated_by && ($latest->rule_snapshot['confirmed'] ?? false) === true && $data['policy']
            && ($latest->rule_snapshot['policy'] ?? null) == $data['policy'] && $runEvidence;
        $prerequisites = [
            ['label' => 'Authorized account', 'met' => Gate::allows('evaluate', $application), 'reason' => 'Coordinator or Administrator authorization is required; withdrawn applications cannot be evaluated.', 'tab' => 'award-activation'],
            ['label' => 'Verified current enrollment', 'met' => (bool) $enrollmentReady, 'reason' => 'The latest current-semester enrollment must be Registrar-verified and enrolled.', 'tab' => 'overview'],
            ['label' => 'Pending assignment', 'met' => $assignment?->status === 'pending', 'reason' => 'Activation requires an existing pending assignment.', 'tab' => 'award-activation'],
            ['label' => 'Active scholar and program', 'met' => $application->student->record_status === 'active' && $application->program->is_active, 'reason' => 'The scholar record and scholarship program must both be active.', 'tab' => 'overview'],
            ['label' => 'Free active-scholarship slot', 'met' => $activeAwards->isEmpty(), 'reason' => 'Any existing active scholarship must be resolved before activation.', 'tab' => 'award-activation'],
            ['label' => 'Current eligible school decision', 'met' => (bool) $runReady, 'reason' => 'A current passed/qualified evaluation with confirmed matching rules and unchanged usable evidence is required.', 'tab' => 'school-compliance'],
            ['label' => 'External approval for this award and semester', 'met' => (bool) ($approvalMatches && $approvalIdentity), 'reason' => 'The latest semester decision must be Approved, with the required authority, recorder, reference and exact award/period association.', 'tab' => 'external-approval'],
            ['label' => 'External decision dates', 'met' => (bool) $approvalDates, 'reason' => 'The authority decision cannot be in the future; any validity dates must include today.', 'tab' => 'external-approval'],
            ['label' => 'Verified external evidence', 'met' => (bool) $approvalEvidence, 'reason' => 'Accepted private evidence must retain its reviewer, timestamp and matching available file checksum.', 'tab' => 'external-approval'],
        ];
        $canActivate = collect($prerequisites)->every(fn ($item) => $item['met']);
        $enrollmentStatus = $enrollmentReady ? 'Completed' : 'Pending review';
        $schoolStatus = ! $latest ? 'Not started' : (! $current ? 'Requires reassessment' : ($runReady ? 'Completed' : 'Blocked'));
        $externalStatus = ! $approval ? 'Not started' : ($externalReady ? 'Completed' : ($approval->decision === 'approved' ? 'Pending review' : 'Blocked'));
        $stages = [
            ['title' => 'Enrollment verification', 'status' => $enrollmentStatus, 'reason' => $enrollmentReady ? 'Current-semester enrollment is Registrar-verified. Other compliance requirements still apply.' : 'Review the current semester enrollment through Billing and Validation; unknown or not-enrolled findings cannot establish billing eligibility.', 'tab' => 'overview'],
            ['title' => 'School compliance', 'status' => $schoolStatus, 'reason' => $runReady ? 'The latest school decision is current and qualified.' : ($latest ? 'Review the latest decision and unresolved checks, then reevaluate when its sources are ready.' : 'No school evaluation has been recorded.'), 'tab' => 'school-compliance'],
            ['title' => 'External approval', 'status' => $externalStatus, 'reason' => $externalReady ? 'The latest verified approval matches this award, semester and validity dates.' : 'A recorded decision alone is insufficient; authority, dates, award association and usable evidence must match.', 'tab' => 'external-approval'],
            ['title' => 'Award activation', 'status' => $assignment?->status === 'active' ? 'Completed' : ($canActivate ? 'Pending review' : 'Blocked'), 'reason' => $assignment?->status === 'active' ? 'The existing assignment is active. Retained activation references appear in History.' : ($canActivate ? 'Listed prerequisites are met. An authorized user must explicitly confirm activation.' : 'Review the unmet activation prerequisites; activation is never automatic.'), 'tab' => 'award-activation'],
        ];
        $outstanding = [];
        $add = function (string $label, string $reason, string $tab) use (&$outstanding) {
            $outstanding[] = compact('label', 'reason', 'tab');
        };
        if ($context->active_period_id !== $application->academic_period_id) {
            $add('Validation period needs review', 'Use the configured active validation period. Historical periods cannot authorize a current award.', 'overview');
        }
        if (! $enrollmentReady) {
            $add('Current verified enrollment', 'Record the actual current-semester enrollment finding through Billing and Validation. Enrollment alone does not qualify an award.', 'overview');
        }
        if (! $officialConfirmed) {
            $add('Approved compliance basis', 'The applicable program/semester requirements must have a matching approved confirmation. For an unsupported program, the institution must confirm the school/provider certification basis; unrelated TDP/TES criteria are not substituted.', 'school-compliance');
        }
        foreach ($missingDocuments as $label) {
            $add('Missing usable evidence', $label.' — the latest uploaded version must be accepted and usable; older files are not substituted.', 'supporting-evidence');
        }
        if (! $runReady) {
            $add($schoolStatus, ($latest && ($latest->input_snapshot['assessment_scope'] ?? null) !== EligibilityEvaluator::SCOPE ? 'This retained decision used the previous assessment requirements. Reassess under the current enrollment and school-compliance scope; its original checks remain in History.' : ($latest?->decision_reason ?: 'Record a school evaluation after reviewing the relevant sources.')), 'school-compliance');
        }
        if (! $externalReady) {
            $add('External approval incomplete', 'Review the latest authority decision, validity dates and verified supporting document.', 'external-approval');
        }
        if ($activeAwards->contains(fn ($award) => $award->id !== $assignment?->id)) {
            $add('Another active scholarship', 'Resolve the conflicting active award through the existing authorized workflow.', 'award-activation');
        }

        return [
            'prior' => $prior, 'lastApproval' => $approval, 'current' => $current,
            'officialConfirmed' => (bool) $officialConfirmed, 'enrollmentReady' => (bool) $enrollmentReady,
            'stages' => $stages, 'outstanding' => $outstanding,
            'prerequisites' => $prerequisites, 'canActivate' => $canActivate,
            'externalReady' => (bool) $externalReady, 'missingDocuments' => $missingDocuments,
            'requiredDocuments' => $requiredDocuments,
            'requiredFacts' => $data['policy'] && $data['policyApplicable'] ? ['new' => $catalog->requiredFacts($application->program->code, 'new'), 'continuing' => $catalog->requiredFacts($application->program->code, 'continuing')] : null,
            'assignmentEvents' => $assignment ? AuditEvent::where('subject_type', ScholarshipAssignment::class)->where('subject_id', $assignment->id)
                ->orderByDesc('occurred_at')->orderByDesc('id')->limit(50)->get(['id', 'action', 'actor_snapshot', 'occurred_at', 'reason']) : collect(),
        ];
    }
}
