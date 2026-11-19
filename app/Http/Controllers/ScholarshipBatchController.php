<?php

namespace App\Http\Controllers;

use App\Actions\Records\ManageScholarshipRoster;
use App\Models\AcademicPeriod;
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
                'batch_number' => ['required', 'string', 'max:60', 'regex:/\S/'],
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

    public function show(Request $request, ScholarshipBatch $batch)
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
            ->with(['student', 'assignment', 'enrollmentRecord.verifiedBy']);
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

        return view('scholarship-batches.show', ['batch' => $batch, 'members' => $query->orderBy('student_id')->tablePaginate('members')->withQueryString(), 'filters' => $filters]);
    }
}
