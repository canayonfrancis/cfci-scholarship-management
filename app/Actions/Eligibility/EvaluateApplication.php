<?php

namespace App\Actions\Eligibility;

use App\Actions\Records\RecordAudit;
use App\Models\AcademicValidationContext;
use App\Models\EvaluationRun;
use App\Models\ProgramAcademicRequirement;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Models\User;
use App\Services\Eligibility\EligibilityEvaluator;
use App\Services\Eligibility\OfficialRuleCatalog;
use App\Services\Eligibility\PrivateEvidence;
use App\Services\Eligibility\SchoolGradingPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EvaluateApplication
{
    public function __construct(private OfficialRuleCatalog $catalog, private EligibilityEvaluator $evaluator, private PrivateEvidence $evidence, private RecordAudit $audit, private SchoolGradingPolicy $gradingPolicy) {}

    public function handle(ScholarshipApplication $application, User $actor, array $data): EvaluationRun
    {
        if (! ProgramAcademicRequirement::storageReady()) {
            throw ValidationException::withMessages(['previous_run' => 'The developer must apply the academic computation migration before evaluating percentage requirements.']);
        }

        return DB::transaction(function () use ($application, $actor, $data) {
            Student::whereKey($application->student_id)->lockForUpdate()->firstOrFail();
            $application = ScholarshipApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('evaluate', $application);
            $context = AcademicValidationContext::whereKey(1)->lockForUpdate()->firstOrFail();
            if ($application->submission_revision !== (int) $data['submission_revision'] ||
                (int) ($application->evaluationRuns()->max('run_number') ?? 0) !== (int) $data['previous_run']) {
                throw ValidationException::withMessages(['previous_run' => 'This assessment changed. Reload before evaluating again.']);
            }
            $program = ScholarshipProgram::whereKey($application->program_id)->lockForUpdate()->firstOrFail();
            $terms = $application->terms()->lockForUpdate()->firstOrFail();
            $academicRequirements = ProgramAcademicRequirement::where('program_period_term_id', $terms->id)->orderByDesc('revision')->lockForUpdate()->first()?->snapshot();
            $policy = $this->catalog->forProgram($program->code);
            $confirmed = $policy && $terms->confirmed_by && $terms->confirmed_at && $terms->rule_version === $policy['version'] && $terms->criteria == $policy && $this->catalog->appliesTo($application->academicPeriod);
            $record = $application->student->academicRecords()->where('academic_period_id', $application->academic_period_id)->orderByDesc('revision')->lockForUpdate()->first();
            if (! $record || $record->verification_status !== 'verified' || ! $record->verified_at || ! $record->verified_by) {
                $record = null;
            }
            $gradeRecord = $context->source_period_id && $context->confirmed_by && $context->confirmed_at
                ? $application->student->academicRecords()->where('academic_period_id', $context->source_period_id)->orderByDesc('revision')->lockForUpdate()->first() : null;
            if (! $gradeRecord || $gradeRecord->verification_status !== 'verified' || ! $gradeRecord->verified_by || ! $gradeRecord->verified_at) {
                $gradeRecord = null;
            }
            $documents = [];
            foreach ($application->documents()->orderBy('id')->get()->groupBy('document_type') as $type => $versions) {
                $document = $versions->last();
                if ($this->evidence->usable($document)) {
                    $documents[$type] = $this->evidence->snapshot($document);
                }
            }
            $source = $record?->only(['id', 'revision', 'lock_version', 'verification_status', 'verified_by', 'verified_at', 'course', 'year_level', 'enrollment_status', 'academic_standing', 'grade_average', 'units_enrolled', 'units_completed', 'academic_summary', 'source_reference']);
            $gradeSource = $gradeRecord?->only(['id', 'revision', 'lock_version', 'verification_status', 'verified_by', 'verified_at', 'academic_period_id', 'academic_standing', 'grade_average', 'source_reference', 'certified_percentage_gwa', 'certified_gwa_reference']);
            if ($gradeSource) {
                $gradeSource['subjects'] = $gradeRecord->grades()->orderBy('id')->get()->map(fn ($grade) => $grade->only(array_diff($grade->getFillable(), ['equivalent'])))->all();
            }
            $input = [
                'program' => $program->code, 'assignment_id' => $application->assignment_id, 'academic_period_id' => $application->academic_period_id,
                'facts_input' => $data, 'academic' => $source, 'evidence' => $documents,
                'source_records' => ['grade' => $gradeSource, 'enrollment' => $source],
                'grading_academic' => $gradeSource, 'academic_context' => $context->snapshot(),
                'assessment_scope' => SchoolGradingPolicy::SCOPE, 'grading_policy_check' => $this->gradingPolicy->check($gradeSource, $academicRequirements, $source),
                'academic_requirements' => $academicRequirements,
                'conflicting_assignment_id' => $application->student->assignments()->where('status', 'active')->whereKeyNot($application->assignment_id)->value('id'),
            ];
            $result = $this->evaluator->evaluate($input, (bool) $confirmed);
            $run = $application->evaluationRuns()->create([
                'student_id' => $application->student_id, 'program_id' => $application->program_id, 'academic_period_id' => $application->academic_period_id,
                'run_number' => (int) $data['previous_run'] + 1, 'application_revision' => $application->submission_revision,
                'grade_record_id' => $gradeRecord?->id, 'enrollment_record_id' => $record?->id,
                'rule_version' => $terms->rule_version, 'input_snapshot' => $input, 'rule_snapshot' => [
                    'confirmed' => (bool) $confirmed, 'policy' => $terms->criteria, 'confirmed_by' => $terms->confirmed_by, 'confirmed_at' => $terms->confirmed_at,
                    'assessment_scope' => SchoolGradingPolicy::SCOPE,
                    'academic_requirements' => $academicRequirements,
                    'applied_facts' => $confirmed ? $this->catalog->requiredFacts($program->code, $data['applicant_type']) : [],
                    'required_documents' => $confirmed ? $this->catalog->requiredDocuments($program->code, $data) : [],
                ],
                'check_results' => $result['checks'], 'automatic_outcome' => $result['outcome'],
                'school_decision' => ['passed' => 'qualified', 'failed' => 'not_qualified', 'needs_review' => 'needs_information'][$result['outcome']],
                'evaluated_by' => $actor->id, 'evaluated_at' => now(), 'coordinator_id' => $actor->id, 'decided_at' => now(), 'completed_at' => now(),
                'decision_reason' => $result['reason'],
            ]);
            $this->audit->record($actor, $run, 'scholarship_evaluated', reason: $data['evaluator_notes'] ?? null);

            return $run;
        }, 3);
    }
}
