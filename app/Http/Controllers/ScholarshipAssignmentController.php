<?php

namespace App\Http\Controllers;

use App\Actions\Records\SaveScholarshipAssignment;
use App\Http\Requests\ScholarshipAssignmentRequest;
use App\Models\AcademicPeriod;
use App\Models\ScholarshipAssignment;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ScholarshipAssignmentController extends Controller
{
    public function create(Student $scholar): View
    {
        Gate::authorize('create', ScholarshipAssignment::class);

        return $this->form($scholar, new ScholarshipAssignment(['status' => 'pending']));
    }

    public function store(ScholarshipAssignmentRequest $request, Student $scholar, SaveScholarshipAssignment $action): RedirectResponse
    {
        $action->save($scholar, $request->user(), $request->validated());

        return to_route('scholars.show', $scholar)->with('status', 'Scholarship assignment recorded.');
    }

    public function edit(Student $scholar, ScholarshipAssignment $assignment): View
    {
        abort_unless($assignment->student_id === $scholar->id, 404);
        Gate::authorize('update', $assignment);

        return $this->form($scholar, $assignment);
    }

    public function update(ScholarshipAssignmentRequest $request, Student $scholar, ScholarshipAssignment $assignment, SaveScholarshipAssignment $action): RedirectResponse
    {
        abort_unless($assignment->student_id === $scholar->id, 404);
        $action->save($scholar, $request->user(), $request->validated(), $assignment);

        return to_route('scholars.show', $scholar)->with('status', 'Scholarship history updated.');
    }

    private function form(Student $scholar, ScholarshipAssignment $assignment): View
    {
        return view('assignments.form', [
            'scholar' => $scholar, 'assignment' => $assignment,
            'programs' => ScholarshipProgram::whereIn('code', ['TDP', 'TES', 'EDUKAR'])->orderBy('code')->get(),
            'periods' => AcademicPeriod::orderByDesc('academic_year')->orderBy('semester_code')->get(),
        ]);
    }
}
