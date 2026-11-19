<?php

namespace App\View;

use App\Models\AcademicValidationContext;
use App\Models\AuditEvent;
use App\Models\Document;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipAssignment;
use App\Services\Eligibility\AcademicGradeComputation;
use App\Services\Eligibility\OfficialRuleCatalog;
use App\Services\Eligibility\PrivateEvidence;
use Illuminate\Support\Facades\Gate;

/** Read-only presentation of existing decisions/guards; never authorizes a write. */
class EvaluationWorkspace
{
    public function __construct(private PrivateEvidence $evidence, private AcademicGradeComputation $grades) {}

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
        $policyConfirmed = $data['academicRequirements']?->status === 'confirmed'
            && $data['academicRequirements']->confirmed_by && $data['academicRequirements']->confirmed_at
            && filled($data['academicRequirements']->source_reference) && filled($data['academicRequirements']->version_reference);
        $gradeSummary = $data['gradeSource'] ? $this->grades->forRecord($data['gradeSource']) : $data['academicCheck']['summary'];
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
        $runReady = $current && $latest->automatic_outcome === 'passed' && $latest->school_decision === 'qualified'
            && $latest->evaluated_by && ($latest->rule_snapshot['confirmed'] ?? false) === true && $data['policy']
            && ($latest->rule_snapshot['policy'] ?? null) == $data['policy'] && $runEvidence;
        $prerequisites = [
            ['label' => 'Authorized account', 'met' => Gate::allows('evaluate', $application), 'reason' => 'Coordinator or Administrator authorization is required; withdrawn applications cannot be evaluated.', 'tab' => 'award-activation'],
            ['label' => 'Pending assignment', 'met' => $assignment?->status === 'pending', 'reason' => 'Activation requires an existing pending assignment.', 'tab' => 'award-activation'],
            ['label' => 'Active scholar and program', 'met' => $application->student->record_status === 'active' && $application->program->is_active, 'reason' => 'The scholar record and scholarship program must both be active.', 'tab' => 'overview'],
            ['label' => 'Free active-scholarship slot', 'met' => $activeAwards->isEmpty(), 'reason' => 'Any existing active scholarship must be resolved before activation.', 'tab' => 'award-activation'],
            ['label' => 'Current eligible school decision', 'met' => (bool) $runReady, 'reason' => 'A current passed/qualified evaluation with confirmed matching rules and unchanged usable evidence is required.', 'tab' => 'school-compliance'],
            ['label' => 'External approval for this award and semester', 'met' => (bool) ($approvalMatches && $approvalIdentity), 'reason' => 'The latest semester decision must be Approved, with the required authority, recorder, reference and exact award/period association.', 'tab' => 'external-approval'],
            ['label' => 'External decision dates', 'met' => (bool) $approvalDates, 'reason' => 'The authority decision cannot be in the future; any validity dates must include today.', 'tab' => 'external-approval'],
            ['label' => 'Verified external evidence', 'met' => (bool) $approvalEvidence, 'reason' => 'Accepted private evidence must retain its reviewer, timestamp and matching available file checksum.', 'tab' => 'external-approval'],
        ];
        $canActivate = collect($prerequisites)->every(fn ($item) => $item['met']);
        $academicStatus = match ($data['academicCheck']['status']) {
            'pass' => 'Completed', 'fail' => 'Blocked', default => 'Pending review'
        };
        $schoolStatus = ! $latest ? 'Not started' : (! $current ? 'Requires reassessment' : ($runReady ? 'Completed' : 'Blocked'));
        $externalStatus = ! $approval ? 'Not started' : ($externalReady ? 'Completed' : ($approval->decision === 'approved' ? 'Pending review' : 'Blocked'));
        $stages = [
            ['title' => 'Academic requirements', 'status' => $academicStatus, 'reason' => $data['academicCheck']['status'] === 'pass' ? 'The existing academic check is met. Other compliance gates still apply.' : $data['academicCheck']['reason'], 'tab' => 'academic-records'],
            ['title' => 'School compliance', 'status' => $schoolStatus, 'reason' => $runReady ? 'The latest school decision is current and qualified.' : ($latest ? 'Review the latest decision and unresolved checks, then reevaluate when its sources are ready.' : 'No school evaluation has been recorded.'), 'tab' => 'school-compliance'],
            ['title' => 'External approval', 'status' => $externalStatus, 'reason' => $externalReady ? 'The latest verified approval matches this award, semester and validity dates.' : 'A recorded decision alone is insufficient; authority, dates, award association and usable evidence must match.', 'tab' => 'external-approval'],
            ['title' => 'Award activation', 'status' => $assignment?->status === 'active' ? 'Completed' : ($canActivate ? 'Pending review' : 'Blocked'), 'reason' => $assignment?->status === 'active' ? 'The existing assignment is active. Retained activation references appear in History.' : ($canActivate ? 'Listed prerequisites are met. An authorized user must explicitly confirm activation.' : 'Review the unmet activation prerequisites; activation is never automatic.'), 'tab' => 'award-activation'],
        ];
        $outstanding = [];
        $add = function (string $label, string $reason, string $tab) use (&$outstanding) {
            $outstanding[] = compact('label', 'reason', 'tab');
        };
        if ($context->active_period_id !== $application->academic_period_id || ! $context->source_period_id || ! $context->confirmed_by || ! $context->confirmed_at || ! filled($context->source_policy_reference)) {
            $add('Validation context needs review', 'Use the configured active period and confirmed source grading semester. Historical periods cannot authorize a current award.', 'academic-records');
        }
        $academic = $data['academic'];
        if (! $academic || $academic->verification_status !== 'verified' || ! $academic->verified_by || ! $academic->verified_at || $academic->enrollment_status !== 'enrolled') {
            $add('Current verified enrollment', 'The latest current-semester Registrar source must be reviewed; continuing LOA cases retain individual review.', 'academic-records');
        }
        if (! $data['academicCheck']['summary']['verified']) {
            $add('Verified source grades', 'The configured grading source is missing, unverified or unavailable for this validation context.', 'academic-records');
        }
        if (! $policyConfirmed) {
            $add('Academic policy unconfirmed', 'Confirm applicable program-period academic requirements through the existing program policy page.', 'academic-records');
        }
        if (($data['academicRequirements']?->criteria['minimum_gwa_required'] ?? null) === true && $data['academicCheck']['summary']['certified_gwa'] === null) {
            $add('Registrar-certified percentage GWA', 'A required official minimum cannot be assessed using provisional GWA.', 'academic-records');
        }
        if ($data['academicCheck']['status'] !== 'pass') {
            $add('Academic requirements require review', $data['academicCheck']['reason'], 'academic-records');
        }
        if (! $officialConfirmed) {
            $add('Official semester rules', 'An applicable official rule set must match its recorded confirmation. Unsupported program rules remain pending review.', 'school-compliance');
        }
        foreach ($missingDocuments as $label) {
            $add('Missing usable evidence', $label.' — the latest uploaded version must be accepted and usable; older files are not substituted.', 'supporting-evidence');
        }
        if (! $runReady) {
            $add($schoolStatus, $latest?->decision_reason ?: 'Record a school evaluation after reviewing the relevant sources.', 'school-compliance');
        }
        if (! $externalReady) {
            $add('External approval incomplete', 'Review the latest authority decision, validity dates and verified supporting document.', 'external-approval');
        }
        if ($activeAwards->contains(fn ($award) => $award->id !== $assignment?->id)) {
            $add('Another active scholarship', 'Resolve the conflicting active award through the existing authorized workflow.', 'award-activation');
        }

        return [
            'prior' => $prior, 'lastApproval' => $approval, 'current' => $current,
            'officialConfirmed' => (bool) $officialConfirmed, 'policyConfirmed' => (bool) $policyConfirmed,
            'gradeSummary' => $gradeSummary, 'stages' => $stages, 'outstanding' => $outstanding,
            'prerequisites' => $prerequisites, 'canActivate' => $canActivate,
            'externalReady' => (bool) $externalReady, 'missingDocuments' => $missingDocuments,
            'requiredDocuments' => $requiredDocuments,
            'requiredFacts' => $data['policy'] && $data['policyApplicable'] ? ['new' => $catalog->requiredFacts($application->program->code, 'new'), 'continuing' => $catalog->requiredFacts($application->program->code, 'continuing')] : null,
            'assignmentEvents' => $assignment ? AuditEvent::where('subject_type', ScholarshipAssignment::class)->where('subject_id', $assignment->id)
                ->orderByDesc('occurred_at')->orderByDesc('id')->limit(50)->get(['id', 'action', 'actor_snapshot', 'occurred_at', 'reason']) : collect(),
        ];
    }
}
