<?php

namespace App\Http\Controllers;

use App\Actions\Records\RecordAudit;
use App\Http\Requests\ScholarRequest;
use App\Models\AcademicPeriod;
use App\Models\AcademicProgram;
use App\Models\AuditEvent;
use App\Models\ScholarshipAssignment;
use App\Models\ScholarshipBatch;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ScholarController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Student::class);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'record_status' => ['nullable', Rule::in(['active', 'archived'])],
            'program_id' => ['nullable', 'integer', 'exists:scholarship_programs,id'],
            'academic_year' => ['nullable', 'string', 'regex:/^[0-9]{4}-[0-9]{4}$/D', 'exists:academic_periods,academic_year'],
        ]);
        $query = Student::with(['activeAssignment.program', 'academicProgram', 'academicPeriod'])->withCount('assignments');
        foreach (preg_split('/\s+/u', trim($filters['q'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $query->where(function ($query) use ($word) {
                foreach (['student_number', 'first_name', 'middle_name', 'last_name', 'email'] as $column) {
                    $query->orWhereRaw($column." LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%']);
                }
            });
        }
        $query->when($filters['record_status'] ?? null, fn ($query, $status) => $query->where('record_status', $status));
        $query->when($filters['program_id'] ?? null, fn ($query, $id) => $query->whereHas('activeAssignment', fn ($assignment) => $assignment->where('program_id', $id)));
        $query->when($filters['academic_year'] ?? null, fn ($query, $year) => $query->whereHas('academicPeriod', fn ($period) => $period->where('academic_year', $year)));

        return view('scholars.index', [
            'scholars' => $query->orderBy('last_name')->orderBy('first_name')->orderBy('id')->paginate(15)->withQueryString(),
            'programs' => ScholarshipProgram::orderBy('code')->get(),
            'academicYears' => AcademicPeriod::distinct()->orderByDesc('academic_year')->pluck('academic_year'),
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Student::class);

        $scholar = new Student(['record_status' => 'active']);

        return view('scholars.form', ['scholar' => $scholar, ...$this->choices($scholar)]);
    }

    public function store(ScholarRequest $request, RecordAudit $audit): RedirectResponse
    {
        try {
            $scholar = DB::transaction(function () use ($request, $audit) {
                $scholar = Student::create($request->validated());
                $audit->record($request->user(), $scholar, 'scholar_created');

                return $scholar;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['student_number' => 'This student number is already registered.']);
        }

        return to_route('scholars.show', $scholar)->with('status', 'Scholar record added.');
    }

    public function show(Student $scholar): View
    {
        Gate::authorize('view', $scholar);
        $scholar->load(['academicProgram', 'academicPeriod', 'assignments' => fn ($query) => $query->with(['program', 'startPeriod', 'endPeriod', 'activatedBy', 'closedBy'])->orderByDesc('id')]);
        $history = AuditEvent::with('actor')->where('subject_type', ScholarshipAssignment::class)->whereIn('subject_id', $scholar->assignments->modelKeys())->latest('id')->get();
        $profileHistory = AuditEvent::where('subject_type', Student::class)->where('subject_id', $scholar->id)
            ->whereIn('action', ['scholar_created', 'scholar_updated', 'sample_profile_created'])
            ->whereNotNull('after_state->academic_period_id')->latest('id')->paginate(10, ['*'], 'profile_history_page');

        $memberships = $scholar->batchMemberships()->with(['batch.program', 'batch.academicPeriod'])->latest('id')->paginate(10, ['*'], 'roster_page')->withQueryString();
        $rosters = ScholarshipBatch::with(['program', 'academicPeriod'])->whereIn('program_id', $scholar->assignments->whereIn('status', ['pending', 'active'])->pluck('program_id'))->latest('id')->get();

        return view('scholars.show', compact('scholar', 'history', 'profileHistory', 'memberships', 'rosters'));
    }

    public function edit(Student $scholar): View
    {
        Gate::authorize('update', $scholar);

        return view('scholars.form', ['scholar' => $scholar, ...$this->choices($scholar)]);
    }

    public function update(ScholarRequest $request, Student $scholar, RecordAudit $audit): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $scholar, $audit) {
                $scholar = Student::whereKey($scholar->id)->lockForUpdate()->firstOrFail();
                if ($request->validated('record_status') === 'archived' && $scholar->activeAssignment()->exists()) {
                    throw ValidationException::withMessages(['record_status' => 'Close the active scholarship before archiving this scholar.']);
                }
                $before = $scholar->only($scholar->getFillable());
                $scholar->update($request->validated());
                $audit->record($request->user(), $scholar, 'scholar_updated', $before);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['student_number' => 'This student number is already registered.']);
        }

        return to_route('scholars.show', $scholar)->with('status', 'Scholar record updated.');
    }

    private function choices(Student $scholar): array
    {
        return [
            'academicPrograms' => AcademicProgram::where('is_active', true)->orWhere('id', $scholar->academic_program_id)->orderBy('name')->get(),
            'periods' => AcademicPeriod::orderByDesc('academic_year')->orderBy('semester_code')->get(),
        ];
    }
}
