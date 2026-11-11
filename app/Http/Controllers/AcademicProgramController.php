<?php

namespace App\Http\Controllers;

use App\Actions\Records\RecordAudit;
use App\Http\Requests\AcademicProgramRequest;
use App\Models\AcademicProgram;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AcademicProgramController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', AcademicProgram::class);

        return view('academic-programs.index', ['programs' => AcademicProgram::withCount('students')->orderBy('name')->paginate(15)]);
    }

    public function store(AcademicProgramRequest $request, RecordAudit $audit): RedirectResponse
    {
        return $this->save($request, $audit);
    }

    public function update(AcademicProgramRequest $request, AcademicProgram $academicProgram, RecordAudit $audit): RedirectResponse
    {
        return $this->save($request, $audit, $academicProgram);
    }

    private function save(AcademicProgramRequest $request, RecordAudit $audit, ?AcademicProgram $program = null): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $audit, $program) {
                $program = $program ? AcademicProgram::whereKey($program->id)->lockForUpdate()->firstOrFail() : new AcademicProgram;
                if ($program->exists && $program->name !== $request->validated('name') && $program->students()->exists()) {
                    throw ValidationException::withMessages(['name' => 'This program is linked to student profiles. Retain its name and register a new program if needed.']);
                }
                $before = $program->only($program->getFillable());
                $program->fill($request->validated())->save();
                $audit->record($request->user(), $program, 'academic_program_saved', $before);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'This academic program is already registered.']);
        }

        return to_route('academic-programs.index')->with('status', 'Academic program saved.');
    }
}
