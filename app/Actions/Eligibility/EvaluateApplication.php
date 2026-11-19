<?php

namespace App\Actions\Eligibility;

use App\Actions\Records\RecordAudit;
use App\Models\AcademicValidationContext;
use App\Models\EvaluationRun;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Models\User;
use App\Services\Eligibility\EligibilityEvaluator;
use App\Services\Eligibility\OfficialRuleCatalog;
use App\Services\Eligibility\PrivateEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EvaluateApplication
{
    public function __construct(private OfficialRuleCatalog $catalog, private EligibilityEvaluator $evaluator, private PrivateEvidence $evidence, private RecordAudit $audit) {}

    public function handle(ScholarshipApplication $application, User $actor, array $data): EvaluationRun
    {
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
            $this->catalog->assertDemonstrationStudent($program->code, $application->student);
            $terms = $application->terms()->lockForUpdate()->firstOrFail();
            $policy = $this->catalog->forProgram($program->code, $terms);
            $institutional = ($policy['mode'] ?? null) === OfficialRuleCatalog::INSTITUTIONAL_MODE;
            if (($policy['demonstration'] ?? false) === true || $institutional) {
                Validator::make($data, [
                    'school_decision' => ['required', Rule::in(['qualified', 'not_qualified', 'needs_information'])],
                    'certification_reference' => ['required', 'string', 'max:1000', 'regex:/\S/', ...($institutional ? [] : ['starts_with:UAT-'])],
                    'evaluator_notes' => ['required', 'string', 'max:3000', 'regex:/\S/'],
                    'certification_verified' => ['accepted'],
                ])->validate();
                if ($institutional) {
                    $this->catalog->assertInstitutionalStudent($application->student);
                    if (OfficialRuleCatalog::sampleText($data['certification_reference'], $data['evaluator_notes'])) {
                        throw ValidationException::withMessages(['certification_reference' => 'Use genuine institutional certification and references, not fictional demonstration evidence.']);
                    }
                }
                // A requested finding is input to the existing checks, never an override of their outcome.
                $data['facts'] = ['authenticity' => ['qualified' => 'yes', 'not_qualified' => 'no', 'needs_information' => 'unverified'][$data['school_decision']]];
                $data['fact_sources'] = ['authenticity' => $data['certification_reference']];
                $data['certification_verified'] = true;
            }
            $confirmed = $policy && $terms->confirmed_by && $terms->confirmed_at && $terms->rule_version === $policy['version'] && $terms->criteria == $policy && $this->catalog->appliesTo($application->academicPeriod);
            $record = $application->student->academicRecords()->where('academic_period_id', $application->academic_period_id)->orderByDesc('revision')->lockForUpdate()->first();
            if (! $record || $record->verification_status !== 'verified' || ! $record->verified_at || ! $record->verified_by) {
                $record = null;
            }
            $documents = [];
            foreach ($application->documents()->orderBy('id')->get()->groupBy('document_type') as $type => $versions) {
                $document = $versions->last();
                if ($this->evidence->usable($document)) {
                    if ($institutional && in_array($type, ['eligibility_support', 'enrollment_certificate'], true) && OfficialRuleCatalog::sampleText($document->original_name)) {
                        throw ValidationException::withMessages(['certification_reference' => 'A required certification/enrollment file is labelled as sample evidence. Review genuine original evidence before evaluating.']);
                    }
                    $documents[$type] = $this->evidence->snapshot($document);
                }
            }
            $source = $record?->only(['id', 'revision', 'lock_version', 'verification_status', 'verified_by', 'verified_at', 'course', 'year_level', 'enrollment_status', 'source_reference']);
            $input = [
                'program' => $program->code, 'assignment_id' => $application->assignment_id, 'academic_period_id' => $application->academic_period_id,
                'facts_input' => $data, 'academic' => $source, 'evidence' => $documents,
                'source_records' => ['enrollment' => $source],
                'academic_context' => $context->only(['active_period_id', 'revision']),
                'assessment_scope' => EligibilityEvaluator::SCOPE,
                'conflicting_assignment_id' => $application->student->assignments()->where('status', 'active')->whereKeyNot($application->assignment_id)->value('id'),
            ];
            $result = $this->evaluator->evaluate($input, (bool) $confirmed, $policy);
            $run = $application->evaluationRuns()->create([
                'student_id' => $application->student_id, 'program_id' => $application->program_id, 'academic_period_id' => $application->academic_period_id,
                'run_number' => (int) $data['previous_run'] + 1, 'application_revision' => $application->submission_revision,
                'enrollment_record_id' => $record?->id,
                'rule_version' => $terms->rule_version, 'input_snapshot' => $input, 'rule_snapshot' => [
                    'confirmed' => (bool) $confirmed, 'policy' => $terms->criteria, 'confirmed_by' => $terms->confirmed_by, 'confirmed_at' => $terms->confirmed_at,
                    'assessment_scope' => EligibilityEvaluator::SCOPE,
                    'applied_facts' => $confirmed ? $this->catalog->requiredFacts($program->code, $data['applicant_type'] ?? 'new', $terms) : [],
                    'required_documents' => $confirmed ? $this->catalog->requiredDocuments($program->code, $data, $terms) : [],
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
