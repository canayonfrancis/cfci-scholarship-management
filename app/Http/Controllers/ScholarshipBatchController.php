<?php

namespace App\Http\Controllers;

use App\Actions\Records\ManageScholarshipRoster;
use App\Actions\Records\SaveScholarshipAssignment;
use App\Models\AcademicPeriod;
use App\Models\AcademicProgram;
use App\Models\AcademicRecord;
use App\Models\BillingItem;
use App\Models\ScholarshipBatch;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ScholarshipBatchController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', ScholarshipBatch::class);
        $filters = $request->validate(['program_id' => ['nullable', 'integer', 'exists:scholarship_programs,id'], 'academic_period_id' => ['nullable', 'integer', 'exists:academic_periods,id']]);

        return view('scholarship-batches.index', [
            'rosterMode' => $request->routeIs('rosters.index'),
            'filters' => $filters,
            'batches' => ScholarshipBatch::with(['program', 'academicPeriod'])->withCount('memberships')
                ->when($filters['program_id'] ?? null, fn ($q, $id) => $q->where('program_id', $id))
                ->when($filters['academic_period_id'] ?? null, fn ($q, $id) => $q->where('academic_period_id', $id))
                ->latest('id')->tablePaginate('rosters')->withQueryString(),
            'programs' => ScholarshipProgram::orderBy('code')->get(),
            'periods' => AcademicPeriod::orderByDesc('academic_year')->orderBy('semester_code')->get(),
        ]);
    }

    public function store(Request $request, ManageScholarshipRoster $rosters)
    {
        Gate::authorize('create', ScholarshipBatch::class);
        try {
            $data = $request->validate([
                'program_id' => ['required', 'integer', 'exists:scholarship_programs,id'],
                'academic_period_id' => ['required', 'integer', 'exists:academic_periods,id'],
                'batch_number' => ['required', 'string', 'max:30', 'regex:/^[0-9]+(?:\.[0-9]+)?$/D'],
            ]);
            $batch = $rosters->create($request->user(), $data);
        } catch (ValidationException $exception) {
            // Keep creation and duplicate-name errors separate from listing filters.
            throw $exception->errorBag('createBatch');
        }

        return to_route('scholarship-batches.show', $batch)->with('status', 'Semester roster created. No billing or financial amount was recorded.');
    }

    public function join(Request $request, Student $scholar, ManageScholarshipRoster $rosters)
    {
        Gate::authorize('create', ScholarshipBatch::class);
        $data = $request->validate([
            'scholarship_batch_id' => ['required', 'integer', 'exists:scholarship_batches,id'],
            'assignment_id' => ['required', 'integer', 'exists:scholarship_assignments,id'],
        ]);
        $rosters->join($scholar, ScholarshipBatch::findOrFail($data['scholarship_batch_id']), $request->user(), (int) $data['assignment_id']);

        return to_route('scholars.show', $scholar)->with('status', 'Scholar added to the semester roster. Enrollment verification and billing remain separate.');
    }

    public function show(Request $request, ScholarshipBatch $batch, ManageScholarshipRoster $rosters)
    {
        Gate::authorize('view', $batch);
        $filters = $request->validate(['view' => ['nullable', Rule::in(['all', 'awaiting_verification', 'unbilled'])]]);
        $batch->load(['program', 'academicPeriod']);
        $latest = AcademicRecord::select('id')->whereColumn('student_id', 'scholarship_batch_memberships.student_id')
            ->whereColumn('academic_period_id', 'scholarship_batch_memberships.academic_period_id')->orderByDesc('revision')->limit(1);
        $submitted = BillingItem::select('billing_items.id')->join('billing_batches as b', 'b.id', '=', 'billing_items.billing_batch_id')
            ->whereColumn('billing_items.student_id', 'scholarship_batch_memberships.student_id')
            ->whereColumn('billing_items.program_id', 'scholarship_batch_memberships.program_id')
            ->whereColumn('billing_items.academic_period_id', 'scholarship_batch_memberships.academic_period_id')
            ->where('billing_items.status', 'finalized')->where(function ($q) {
                $q->where(fn ($q) => $q->where('b.status', 'submitted')->whereNotNull('b.submitted_at'))
                    ->orWhere(fn ($q) => $q->where('b.status', 'finalized')->whereNotNull('b.finalized_at')->whereNotNull('b.finalized_by'));
            });
        $query = $batch->memberships()->select('scholarship_batch_memberships.*')
            ->addSelect([
                'enrollment_record_id' => $latest, 'submitted_billing_id' => (clone $submitted)->limit(1),
                'reserved_billing_id' => BillingItem::select('id')->whereColumn('student_id', 'scholarship_batch_memberships.student_id')
                    ->whereColumn('academic_period_id', 'scholarship_batch_memberships.academic_period_id')->limit(1),
            ])
            ->with(['student.academicProgram', 'assignment', 'enrollmentRecord.verifiedBy']);
        if (($filters['view'] ?? null) === 'awaiting_verification') {
            $query->whereNotExists(AcademicRecord::selectRaw('1')
                ->whereColumn('student_id', 'scholarship_batch_memberships.student_id')
                ->whereColumn('academic_period_id', 'scholarship_batch_memberships.academic_period_id')
                ->where('verification_status', 'verified')->whereNotNull('verified_by')->whereNotNull('verified_at')
                ->whereIn('enrollment_status', ['enrolled', 'not_enrolled'])
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('academic_records as newer')
                    ->whereColumn('newer.student_id', 'academic_records.student_id')->whereColumn('newer.academic_period_id', 'academic_records.academic_period_id')
                    ->whereColumn('newer.revision', '>', 'academic_records.revision')));
        }
        if (($filters['view'] ?? null) === 'unbilled') {
            $query->whereNotExists((clone $submitted)->selectRaw('1'));
        }

        $numberPattern = '/^'.preg_quote($batch->program->code, '/').'-([0-9]+(?:\.[0-9]+)?)-'.preg_quote($batch->academicPeriod->semester_code.'-'.$batch->academicPeriod->academic_year, '/').'$/D';
        $number = preg_match($numberPattern, $batch->batch_number, $parts) ? $parts[1] : null;
        $selection = $request->routeIs('rosters.show') && Gate::allows('create', ScholarshipBatch::class)
            ? $this->scholarSelection($request, $batch, $rosters) : [];

        return view('scholarship-batches.show', [
            'batch' => $batch, 'batchNumber' => $number, 'rosterMode' => $request->routeIs('rosters.show'),
            'memberCount' => $batch->memberships()->count(),
            'members' => $query->orderBy('student_id')->tablePaginate('members')->withQueryString(), 'filters' => $filters,
            ...$selection,
        ]);
    }

    public function addScholars(Request $request, ScholarshipBatch $batch, ManageScholarshipRoster $rosters, SaveScholarshipAssignment $assignments)
    {
        Gate::authorize('create', ScholarshipBatch::class);
        try {
            $data = $request->validate([
                'student_ids' => ['required', 'array', 'min:1', 'max:200'],
                'student_ids.*' => ['required', 'integer', 'distinct', 'exists:students,id'],
                'starts_on' => ['nullable', 'date_format:Y-m-d'],
            ]);
            $count = $rosters->addSelected($batch, $request->user(), $data['student_ids'], $data['starts_on'] ?? null, $assignments);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            return to_route('rosters.show', ['batch' => $batch, 'add' => 1] + $request->query())
                ->withErrors($exception->errors(), 'rosterSelection')->withInput($request->only('student_ids', 'starts_on'));
        }

        return to_route('rosters.show', $batch)->with('status', $count.' scholar(s) added to the roster. Existing assignments were retained; new assignments are pending. Enrollment, evaluation and billing remain separate.');
    }

    private function scholarSelection(Request $request, ScholarshipBatch $batch, ManageScholarshipRoster $rosters): array
    {
        $search = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'course_id' => ['nullable', 'integer', 'exists:academic_programs,id'],
            'selected' => ['nullable', 'array', 'max:200'], 'selected.*' => ['required', 'integer', 'min:1', 'distinct'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'], 'add' => ['nullable', 'boolean'],
        ]);
        $recover = session('errors')?->getBag('rosterSelection')->any() ?? false;
        $raw = $recover ? old('student_ids', []) : ($search['selected'] ?? []);
        $selectedIds = collect(is_array($raw) ? $raw : [])->filter(fn ($id) => is_scalar($id) && ctype_digit((string) $id) && (int) $id > 0)
            ->map(fn ($id) => (string) $id)->unique()->take(200)->values();
        $base = Student::with(['academicProgram', 'assignments.program', 'assignments.startPeriod', 'assignments.endPeriod',
            'batchMemberships' => fn ($q) => $q->where('scholarship_batch_id', $batch->id)]);
        $query = clone $base;
        if (filled($search['q'] ?? null)) {
            foreach (preg_split('/\s+/u', trim($search['q']), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
                $query->where(fn ($q) => $q->whereRaw("student_number LIKE ? ESCAPE '!'", [$term])
                    ->orWhereRaw("first_name LIKE ? ESCAPE '!'", [$term])->orWhereRaw("middle_name LIKE ? ESCAPE '!'", [$term])
                    ->orWhereRaw("last_name LIKE ? ESCAPE '!'", [$term]));
            }
        }
        $query->when($search['course_id'] ?? null, fn ($q, $id) => $q->where('academic_program_id', $id));
        $candidates = $query->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->tablePaginate('roster_candidates', 'candidate_page', fn ($student) => ['student' => $student, 'selection' => $rosters->selection($student, $batch)]);
        $details = [];
        foreach ((clone $base)->whereIn('id', $selectedIds)->get()->merge($candidates->getCollection()->pluck('student'))->unique('id') as $student) {
            $details[(string) $student->id] = ['id' => (string) $student->id, 'label' => $student->student_number.' · '.$student->full_name,
                ...$rosters->selection($student, $batch)];
        }

        $date = $recover ? old('starts_on', '') : ($search['starts_on'] ?? '');

        return ['candidates' => $candidates, 'candidateFilters' => $search, 'selectionDetails' => $details,
            'selectedIds' => $selectedIds, 'selectionDate' => is_string($date) ? $date : '',
            'selectionOpen' => $recover || $request->boolean('add'),
            'courses' => AcademicProgram::orderBy('name')->get()];
    }
}
