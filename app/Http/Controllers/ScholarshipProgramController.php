<?php

namespace App\Http\Controllers;

use App\Actions\Records\RecordAudit;
use App\Http\Requests\ScholarshipProgramRequest;
use App\Models\ScholarshipProgram;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

        return view('programs.index', ['programs' => $query->orderBy('code')->paginate(15)->withQueryString(), 'filters' => $filters]);
    }

    public function create(): View
    {
        Gate::authorize('create', ScholarshipProgram::class);

        return view('programs.form', ['program' => new ScholarshipProgram(['is_active' => true])]);
    }

    public function store(ScholarshipProgramRequest $request, RecordAudit $audit): RedirectResponse
    {
        try {
            $program = DB::transaction(function () use ($request, $audit) {
                $program = ScholarshipProgram::create($request->validated());
                $audit->record($request->user(), $program, 'program_created');

                return $program;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'This scholarship program already exists.']);
        }

        return to_route('scholarship-programs.show', $program)->with('status', 'Scholarship program added.');
    }

    public function show(ScholarshipProgram $program): View
    {
        Gate::authorize('view', $program);

        return view('programs.show', ['program' => $program, 'assignments' => $program->assignments()->with(['student', 'startPeriod'])->latest('id')->paginate(15)]);
    }

    public function edit(ScholarshipProgram $program): View
    {
        Gate::authorize('update', $program);

        return view('programs.form', compact('program'));
    }

    public function update(ScholarshipProgramRequest $request, ScholarshipProgram $program, RecordAudit $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $program, $audit) {
            $program = ScholarshipProgram::whereKey($program->id)->lockForUpdate()->firstOrFail();
            $before = $program->only($program->getFillable());
            $program->update($request->validated());
            $audit->record($request->user(), $program, 'program_updated', $before, $request->validated('source_notes'));
        }, 3);

        return to_route('scholarship-programs.show', $program)->with('status', 'Scholarship program updated.');
    }
}
