<?php

namespace App\Http\Controllers;

use App\Actions\Eligibility\ActivateAward;
use App\Actions\Eligibility\EvaluateApplication;
use App\Actions\Eligibility\RecordExternalApproval;
use App\Actions\Records\RecordAudit;
use App\Http\Requests\EvaluateScholarshipRequest;
use App\Http\Requests\ExternalApprovalRequest;
use App\Http\Requests\ScholarshipEvidenceRequest;
use App\Models\AcademicPeriod;
use App\Models\AcademicValidationContext;
use App\Models\Document;
use App\Models\ProgramPeriodTerm;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipAssignment;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Services\Eligibility\OfficialRuleCatalog;
use App\Services\Eligibility\PrivateEvidence;
use App\View\EvaluationWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ScholarshipEvaluationController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ScholarshipApplication::class);
        $filters = $request->validate(['program_id' => ['nullable', 'integer', 'exists:scholarship_programs,id'], 'academic_period_id' => ['nullable', 'integer', 'exists:academic_periods,id']]);

        return view('evaluations.index', [
            'applications' => ScholarshipApplication::with(['student', 'program', 'academicPeriod', 'evaluationRuns' => fn ($q) => $q->orderByDesc('run_number')])
                ->when($filters['program_id'] ?? null, fn ($q, $id) => $q->where('program_id', $id))
                ->when($filters['academic_period_id'] ?? null, fn ($q, $id) => $q->where('academic_period_id', $id))->latest('id')->tablePaginate('evaluations')->withQueryString(),
            ...$this->choices(),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', ScholarshipApplication::class);
        $filters = $request->validate([
            'program_id' => ['nullable', 'integer', 'exists:scholarship_programs,id'],
            'academic_period_id' => ['nullable', 'integer', 'exists:academic_periods,id'],
            'assignment_id' => ['nullable', 'integer', 'exists:scholarship_assignments,id'],
        ]);
        $context = AcademicValidationContext::current()->load('activePeriod');
        if (filled($filters['academic_period_id'] ?? null) && (int) $filters['academic_period_id'] !== $context->active_period_id) {
            throw ValidationException::withMessages(['academic_period_id' => 'This roster semester is not the configured active validation period. A Coordinator must review the existing validation context before starting its evaluation. No other semester has been substituted.']);
        }
        if (filled($filters['assignment_id'] ?? null)) {
            $selected = ScholarshipAssignment::findOrFail($filters['assignment_id']);
            if (! filled($filters['academic_period_id'] ?? null) || $selected->program_id !== (int) ($filters['program_id'] ?? 0) || ! in_array($selected->status, ['pending', 'active'], true)) {
                throw ValidationException::withMessages(['assignment_id' => 'Select the roster assignment with its matching scholarship program and semester. Only pending or active assignments can start validation.']);
            }
        }

        return view('evaluations.create', [
            'context' => $context,
            'assignments' => isset($filters['program_id']) && $context->active_period_id ? ScholarshipAssignment::with('student')->where('program_id', $filters['program_id'])->whereIn('status', ['pending', 'active'])->get() : collect(),
            ...$this->choices(),
        ]);
    }

    public function store(Request $request, RecordAudit $audit): RedirectResponse
    {
        Gate::authorize('create', ScholarshipApplication::class);
        $data = $request->validate(['program_id' => ['required', 'integer', 'exists:scholarship_programs,id'], 'academic_period_id' => ['required', 'integer', 'exists:academic_periods,id'], 'assignment_id' => ['required', 'integer', 'exists:scholarship_assignments,id']]);
        $application = DB::transaction(function () use ($data, $request, $audit) {
            $assignment = ScholarshipAssignment::findOrFail($data['assignment_id']);
            Student::whereKey($assignment->student_id)->lockForUpdate()->firstOrFail();
            $context = AcademicValidationContext::whereKey(1)->lockForUpdate()->firstOrFail();
            if (! $context->active_period_id || $context->active_period_id !== (int) $data['academic_period_id']) {
                throw ValidationException::withMessages(['academic_period_id' => 'Use the currently configured active period. A Coordinator must configure it before validation.']);
            }
            $assignment = $assignment->fresh();
            if ($assignment->program_id !== (int) $data['program_id'] || ! in_array($assignment->status, ['pending', 'active'], true)) {
                throw ValidationException::withMessages(['assignment_id' => 'Select a pending or active assignment to the chosen program.']);
            }
            // Catalog creation serializes by the program/semester, including different scholars.
            ScholarshipProgram::whereKey($data['program_id'])->lockForUpdate()->firstOrFail();
            $terms = ProgramPeriodTerm::firstOrCreate(['program_id' => $data['program_id'], 'academic_period_id' => $data['academic_period_id']]);
            $application = ScholarshipApplication::firstOrCreate(['student_id' => $assignment->student_id, 'program_period_term_id' => $terms->id], [
                'program_id' => $assignment->program_id, 'academic_period_id' => $data['academic_period_id'], 'assignment_id' => $assignment->id,
            ]);
            if ($application->assignment_id !== $assignment->id) {
                throw ValidationException::withMessages(['assignment_id' => 'This scholar already has a semester assessment linked to another assignment. Open its retained history.']);
            }
            if ($application->wasRecentlyCreated) {
                $audit->record($request->user(), $application, 'semester_assessment_created');
            }

            return $application;
        }, 3);

        return to_route('evaluations.show', $application);
    }

    public function show(ScholarshipApplication $application, OfficialRuleCatalog $catalog, EvaluationWorkspace $workspace): View
    {
        Gate::authorize('view', $application);
        $application->load(['student.academicProgram', 'program', 'academicPeriod', 'assignment.activatedBy', 'assignment.closedBy', 'terms.confirmedBy', 'documents.reviewedBy', 'documents.uploadedBy', 'evaluationRuns.evaluator', 'evaluationRuns.coordinator', 'evaluationRuns.gradeRecord.documents', 'evaluationRuns.enrollmentRecord.documents', 'externalApprovals.documents', 'externalApprovals.recordedBy']);
        $runs = $application->evaluationRuns->sortByDesc('run_number');
        $context = AcademicValidationContext::current()->load('activePeriod');
        $academic = $application->student->academicRecords()->with('documents')->where('academic_period_id', $application->academic_period_id)->orderByDesc('revision')->first();
        $policy = $catalog->forProgram($application->program->code);
        $policyApplicable = $policy && $catalog->appliesTo($application->academicPeriod);
        $factKeys = $policyApplicable ? array_unique([
            ...$catalog->requiredFacts($application->program->code, 'new'),
            ...$catalog->requiredFacts($application->program->code, 'continuing'),
        ]) : [];

        $data = [
            'context' => $context,
            'application' => $application, 'runs' => $runs, 'latest' => $runs->first(),
            'academic' => $academic,
            // Read-only History data; never assembled into the current evaluation input.
            'academicHistory' => $application->student->academicRecords()->with(['academicPeriod', 'grades', 'documents'])->orderByDesc('academic_period_id')->orderByDesc('revision')->get(),
            'policy' => $policy, 'policyApplicable' => (bool) $policyApplicable,
            'facts' => array_intersect_key(OfficialRuleCatalog::FACTS, array_flip($factKeys)),
            'documentTypes' => $application->program->code === 'EDUKAR' ? [] : $catalog->validationDocuments($application->program->code),
            'approvals' => $application->externalApprovals->sortByDesc('id'),
        ];

        return view('evaluations.show', $data + ['workspace' => $workspace->present($data, $catalog)]);
    }

    public function confirmPolicy(Request $request, ScholarshipApplication $application, OfficialRuleCatalog $catalog, RecordAudit $audit): RedirectResponse
    {
        Gate::authorize('evaluate', $application);
        $request->validate(['confirm_policy' => ['accepted']]);
        DB::transaction(function () use ($application, $catalog, $request, $audit) {
            $terms = $application->terms()->lockForUpdate()->firstOrFail();
            $policy = $catalog->forProgram($application->program->code);
            if (! $policy || ! $catalog->appliesTo($application->academicPeriod)) {
                throw ValidationException::withMessages(['confirm_policy' => 'No verified rule set is available for this program/period. Record needs_review; do not invent criteria.']);
            }
            if ($terms->confirmed_at) {
                throw ValidationException::withMessages(['confirm_policy' => 'The semester policy is already confirmed and immutable.']);
            }
            $terms->update(['criteria' => $policy, 'rule_version' => $policy['version'], 'source_reference' => $policy['source'], 'confirmed_by' => $request->user()->id, 'confirmed_at' => now()]);
            $audit->record($request->user(), $terms, 'semester_policy_confirmed');
        }, 3);

        return to_route('evaluations.show', ['application' => $application, 'tab' => 'school-compliance'])->with('status',
            $application->program->code === OfficialRuleCatalog::DEMO_PROGRAM
                ? OfficialRuleCatalog::DEMO_LABEL.' — fictional semester requirements confirmed.'
                : 'Official semester rules confirmed. Existing evaluation snapshots remain unchanged.');
    }

    public function evaluate(EvaluateScholarshipRequest $request, ScholarshipApplication $application, EvaluateApplication $evaluate): RedirectResponse
    {
        $evaluate->handle($application, $request->user(), $request->validated());

        return to_route('evaluations.show', ['application' => $application, 'tab' => 'school-compliance'])->with('status', 'A new school evaluation was recorded. External approval remains separate.');
    }

    public function evidence(ScholarshipEvidenceRequest $request, ScholarshipApplication $application, PrivateEvidence $evidence, RecordAudit $audit): RedirectResponse
    {
        $document = null;
        try {
            DB::transaction(function () use ($request, $application, $evidence, $audit, &$document) {
                Student::whereKey($application->student_id)->lockForUpdate()->firstOrFail();
                $application = ScholarshipApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
                Gate::authorize('evaluate', $application);
                if ($application->submission_revision !== (int) $request->validated('submission_revision')) {
                    throw ValidationException::withMessages(['submission_revision' => 'This application changed. Reload before uploading.']);
                }
                $document = $evidence->store($application, $request->user(), $request->file('certificate'), $request->validated('document_type'), $request->boolean('verified'));
                $application->increment('submission_revision');
                $audit->record($request->user(), $document, 'evaluation_evidence_uploaded');
            });
        } catch (Throwable $exception) {
            if ($document) {
                Storage::disk('scholarship')->delete($document->storage_path);
            }
            throw $exception;
        }

        return to_route('evaluations.show', ['application' => $application, 'tab' => 'supporting-evidence'])->with('status', 'Private evidence saved. Run a new evaluation to use this submission revision.');
    }

    public function reviewEvidence(Request $request, ScholarshipApplication $application, Document $document, RecordAudit $audit): RedirectResponse
    {
        Gate::authorize('evaluate', $application);
        abort_if($application->program->code === 'EDUKAR', 403);
        abort_unless($document->application_id === $application->id, 404);
        $data = $request->validate(['review_status' => ['required', 'in:accepted,rejected'], 'review_notes' => ['required', 'string', 'max:3000']]);
        DB::transaction(function () use ($request, $application, $document, $data, $audit) {
            Student::whereKey($application->student_id)->lockForUpdate()->firstOrFail();
            $application = ScholarshipApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('evaluate', $application);
            $document = $application->documents()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($document->review_status !== 'pending') {
                throw ValidationException::withMessages(['review_status' => 'Reviewed evidence is retained. Upload a replacement to correct it.']);
            }
            $document->update($data + ['reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            $application->increment('submission_revision');
            $audit->record($request->user(), $document, 'evaluation_evidence_reviewed');
        });

        return to_route('evaluations.show', ['application' => $application, 'tab' => 'supporting-evidence'])->with('status', 'Evidence review recorded. Reevaluate this submission.');
    }

    public function download(ScholarshipApplication $application, Document $document): StreamedResponse
    {
        Gate::authorize('view', $application);
        $owned = $document->application_id === $application->id || ($document->external_approval_id && $application->externalApprovals()->whereKey($document->external_approval_id)->exists());
        abort_unless($owned && $document->disk === 'scholarship' && str_starts_with($document->storage_path, 'evidence/'), 404);
        $disk = Storage::disk('scholarship');
        abort_unless($disk->exists($document->storage_path), 404);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $document->original_name))) ?: 'evidence';

        return $disk->download($document->storage_path, $name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function externalApproval(ExternalApprovalRequest $request, ScholarshipApplication $application, RecordExternalApproval $record): RedirectResponse
    {
        $record->handle($application, $request->user(), $request->validated(), $request->file('certificate'));

        return to_route('evaluations.show', ['application' => $application, 'tab' => 'external-approval'])->with('status', 'External decision and verified evidence recorded separately from school eligibility.');
    }

    public function activate(Request $request, ScholarshipApplication $application, ActivateAward $activate): RedirectResponse
    {
        Gate::authorize('evaluate', $application);
        $data = $request->validate(['evaluation_id' => ['required', 'integer'], 'approval_id' => ['required', 'integer']]);
        $activate->handle($application, $request->user(), $data['evaluation_id'], $data['approval_id']);

        return to_route('evaluations.show', ['application' => $application, 'tab' => 'award-activation'])->with('status', 'Scholarship award activated. Evaluation and external approval references were retained.');
    }

    private function choices(): array
    {
        return ['programs' => ScholarshipProgram::orderBy('code')->get(), 'periods' => AcademicPeriod::orderByDesc('academic_year')->orderBy('semester_code')->get()];
    }
}
