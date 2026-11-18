<?php

namespace App\Http\Controllers;

use App\Actions\Records\RecordAudit;
use App\Http\Requests\ScholarshipProgramRequest;
use App\Models\AcademicPeriod;
use App\Models\ProgramAcademicRequirement;
use App\Models\ProgramPeriodTerm;
use App\Models\ScholarshipProgram;
use App\Services\Billing\BillingBatchOptions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ScholarshipProgramController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ScholarshipProgram::class);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $query = ScholarshipProgram::withCount('assignments');
        if (filled($filters['q'] ?? null)) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filters['q'])).'%';
            $query->where(fn ($query) => $query->whereRaw("code LIKE ? ESCAPE '!'", [$term])->orWhereRaw("name LIKE ? ESCAPE '!'", [$term]));
        }

        return view('programs.index', ['programs' => $query->orderBy('code')->tablePaginate('programs')->withQueryString(), 'filters' => $filters]);
    }

    public function create(Request $request, BillingBatchOptions $options): View
    {
        Gate::authorize('create', ScholarshipProgram::class);

        $program = new ScholarshipProgram(['is_active' => true]);

        return view('programs.form', ['program' => $program, ...$this->batchContext($request, $program, $options)]);
    }

    public function store(ScholarshipProgramRequest $request, RecordAudit $audit): RedirectResponse
    {
        try {
            $program = DB::transaction(function () use ($request, $audit) {
                $program = ScholarshipProgram::create($request->safe()->except(['batch_number', 'batch_program_id', 'academic_period_id', 'billing_batch_id']));
                $audit->record($request->user(), $program, 'program_created');

                return $program;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'This scholarship program already exists.']);
        }

        return to_route('scholarship-programs.show', $program)->with('status', 'Scholarship program added.');
    }

    public function show(Request $request, ScholarshipProgram $program): View
    {
        Gate::authorize('view', $program);

        $filters = $request->validate(['academic_policy_period_id' => ['nullable', 'integer', 'exists:academic_periods,id']]);
        $policyPeriod = isset($filters['academic_policy_period_id']) ? AcademicPeriod::findOrFail($filters['academic_policy_period_id']) : null;
        $policyTerms = $policyPeriod ? ProgramPeriodTerm::where('program_id', $program->id)->where('academic_period_id', $policyPeriod->id)->first() : null;

        return view('programs.show', ['program' => $program, 'assignments' => $program->assignments()->with(['student', 'startPeriod'])->latest('id')->tablePaginate('program_assignments')->withQueryString(),
            'academicPolicyPeriods' => AcademicPeriod::orderByDesc('academic_year')->orderBy('semester_code')->get(),
            'academicPolicyPeriod' => $policyPeriod,
            'academicPolicyReady' => $academicPolicyReady = ProgramAcademicRequirement::storageReady(),
            'academicPolicyVersions' => $policyTerms && $academicPolicyReady ? $policyTerms->academicRequirements()->with('confirmedBy')->orderByDesc('revision')->get() : collect(),
        ]);
    }

    public function edit(Request $request, ScholarshipProgram $program, BillingBatchOptions $options): View
    {
        Gate::authorize('update', $program);

        return view('programs.form', ['program' => $program, ...$this->batchContext($request, $program, $options)]);
    }

    public function update(ScholarshipProgramRequest $request, ScholarshipProgram $program, RecordAudit $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $program, $audit) {
            $program = ScholarshipProgram::whereKey($program->id)->lockForUpdate()->firstOrFail();
            $before = $program->only($program->getFillable());
            $program->update($request->safe()->except(['batch_number', 'batch_program_id', 'academic_period_id', 'billing_batch_id']));
            $audit->record($request->user(), $program, 'program_updated', $before, $request->validated('source_notes'));
        }, 3);

        return to_route('scholarship-programs.show', $program)->with('status', 'Scholarship program updated.');
    }

    private function batchContext(Request $request, ScholarshipProgram $program, BillingBatchOptions $options): array
    {
        $rules = ScholarshipProgramRequest::batchContextRules($program);
        $query = $request->validate($rules);
        $input = array_replace($query, $request->session()->getOldInput());
        $validator = Validator::make($input, $rules);
        $validator->passes();
        $context = array_intersect_key($validator->valid(), $rules);
        if ($program->exists) {
            $context['batch_program_id'] = $program->id;
        }

        return [
            'batchContext' => $context,
            'batchOptions' => $options->forScope(['program_id' => $context['batch_program_id'] ?? null, 'academic_period_id' => $context['academic_period_id'] ?? null]),
            'batchPrograms' => $program->exists ? collect([$program]) : ScholarshipProgram::orderBy('code')->get(['id', 'code', 'name']),
            'batchPeriods' => AcademicPeriod::orderByDesc('academic_year')->orderBy('semester_code')->get(),
        ];
    }
}
