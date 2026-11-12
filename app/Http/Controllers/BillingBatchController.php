<?php

namespace App\Http\Controllers;

use App\Actions\Billing\ManageBillingBatch;
use App\Models\AcademicPeriod;
use App\Models\BillingBatch;
use App\Models\BillingItem;
use App\Models\ProgramPeriodTerm;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipProgram;
use App\Rules\PositiveBatchNumber;
use App\Services\Billing\AllocationSummary;
use App\Services\Billing\BillingBatchOptions;
use App\Services\Billing\BillingEligibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BillingBatchController extends Controller
{
    public function index(Request $request, AllocationSummary $summary, BillingBatchOptions $options): View
    {
        Gate::authorize('viewAny', BillingBatch::class);
        $filters = $request->validate([...$this->filterRules(), 'billing_batch_id' => ['nullable', 'integer', 'exists:billing_batches,id']]);
        $query = $options->query($filters)->with(['program', 'academicPeriod', 'approval'])->withCount('items');
        if (filled($filters['billing_batch_id'] ?? null)) {
            $batch = (clone $query)->whereKey($filters['billing_batch_id'])->first();
            if (! $batch) {
                throw ValidationException::withMessages(['billing_batch_id' => 'Select a billing batch matching the selected program, academic year, semester and status.']);
            }
            Gate::authorize('view', $batch);
            $query->whereKey($batch->id);
        }
        $scopes = ProgramPeriodTerm::with(['program', 'academicPeriod', 'rates.approvedBy', 'budgets.approvedBy'])
            ->when($filters['program_id'] ?? null, fn ($q, $value) => $q->where('program_id', $value))
            ->when($filters['academic_period_id'] ?? null, fn ($q, $value) => $q->where('academic_period_id', $value))
            ->when($filters['academic_year'] ?? null, fn ($q, $year) => $q->whereHas('academicPeriod', fn ($q) => $q->where('academic_year', $year)))
            ->orderByDesc('academic_period_id')->orderBy('program_id')->paginate(10, ['*'], 'scopes')->withQueryString();

        return view('billing.index', ['batches' => $query->latest('id')->paginate(15)->withQueryString(), 'batchOptions' => $options->forScope($filters), 'filters' => $filters, 'scopes' => $scopes, 'summaries' => $scopes->map(fn ($terms) => $summary->forTerms($terms)), ...$this->choices()]);
    }

    public function options(Request $request, BillingBatchOptions $options): JsonResponse
    {
        Gate::authorize('viewAny', BillingBatch::class);

        return response()->json(['options' => $options->forScope($request->validate($this->filterRules()))]);
    }

    public function store(Request $request, ManageBillingBatch $manage): RedirectResponse
    {
        Gate::authorize('create', BillingBatch::class);
        $data = $request->validate([...$this->scopeRules(), 'reference' => ['nullable', 'string', 'max:255'], 'batch_number' => ['nullable', new PositiveBatchNumber], 'billing_batch_id' => ['prohibited']], ['billing_batch_id.prohibited' => 'An existing batch cannot be used to create a new batch. Select it in the semester filters to view its records.']);

        return to_route('billing-batches.show', $manage->create($request->user(), $data));
    }

    public function configure(Request $request, ManageBillingBatch $manage): RedirectResponse
    {
        Gate::authorize('configure', BillingBatch::class);
        $data = $request->validate([
            ...$this->scopeRules(), 'previous_rate' => ['nullable', 'integer', 'min:0'], 'semester_gross_amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/', 'numeric', 'gt:0', 'max:9999999999.99'],
            'billing_source_reference' => ['required', 'string', 'max:3000', 'regex:/\S/'], 'confirm_billing_policy' => ['accepted'],
        ]);
        $manage->configure($request->user(), $data);

        return back()->with('status', 'Semester scholarship rate approved as a new historical revision. Existing allocations and actual receipts/releases are unchanged.');
    }

    public function show(Request $request, BillingBatch $batch, BillingEligibility $eligibility): View
    {
        Gate::authorize('view', $batch);
        $search = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $batch->load(['program', 'academicPeriod', 'terms.billingConfirmedBy', 'preparedBy', 'submittedBy', 'rate.approvedBy', 'approval.approvedBy']);
        $candidates = collect();
        if (Gate::allows('update', $batch)) {
            $query = ScholarshipApplication::with(['student', 'assignment', 'program', 'academicPeriod'])
                ->where('program_period_term_id', $batch->program_period_term_id)
                ->whereDoesntHave('billingItems');
            if (filled($search['q'] ?? null)) {
                $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($search['q'])).'%';
                $query->whereHas('student', fn ($query) => $query->whereRaw("student_number LIKE ? ESCAPE '!'", [$term])->orWhereRaw("first_name LIKE ? ESCAPE '!'", [$term])->orWhereRaw("last_name LIKE ? ESCAPE '!'", [$term]));
            }
            $candidates = $query->orderBy('student_id')->paginate(15, ['*'], 'candidates')->withQueryString();
            $candidates->getCollection()->transform(function ($application) use ($eligibility, $batch) {
                try {
                    $eligibility->basis($application, $batch->terms, $batch->appliedRate());
                    $reason = null;
                } catch (ValidationException $exception) {
                    $reason = collect($exception->errors())->flatten()->first();
                }

                return ['application' => $application, 'reason' => $reason];
            });
        }

        return view('billing.show', ['batch' => $batch, 'items' => $batch->items()->with(['student', 'adjustments.authorizedBy'])->orderBy('id')->paginate(25), 'candidates' => $candidates, 'search' => $search]);
    }

    public function update(Request $request, BillingBatch $batch, ManageBillingBatch $manage): RedirectResponse
    {
        Gate::authorize('update', $batch);
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'batch_number' => ['required', 'string', 'max:60', 'regex:/\S/'], 'reference' => ['nullable', 'string', 'max:255']]);
        $manage->change($batch, $request->user(), (int) $data['lock_version'], 'edit', $data);

        return back()->with('status', 'Draft batch saved.');
    }

    public function add(Request $request, BillingBatch $batch, ManageBillingBatch $manage): RedirectResponse
    {
        Gate::authorize('update', $batch);
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'application_id' => ['required', 'integer', 'exists:scholarship_applications,id']]);
        $manage->change($batch, $request->user(), (int) $data['lock_version'], 'add', $data);

        return back()->with('status', 'Scholar added to the draft batch.');
    }

    public function remove(Request $request, BillingBatch $batch, BillingItem $item, ManageBillingBatch $manage): RedirectResponse
    {
        Gate::authorize('update', $batch);
        abort_unless($item->billing_batch_id === $batch->id, 404);
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0']]);
        $manage->change($batch, $request->user(), (int) $data['lock_version'], 'remove', ['item_id' => $item->id]);

        return back()->with('status', 'Draft item removed. Its audit history is retained.');
    }

    public function submit(Request $request, BillingBatch $batch, ManageBillingBatch $manage): RedirectResponse
    {
        Gate::authorize('submit', $batch);
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'reference' => ['required', 'string', 'max:255', 'regex:/\S/']]);
        $manage->change($batch, $request->user(), (int) $data['lock_version'], 'submit', $data);

        return back()->with('status', 'Scholarship allocation batch submitted for Coordinator approval. Submission does not approve or release funds.');
    }

    private function scopeRules(): array
    {
        return ['program_id' => ['required', 'integer', 'exists:scholarship_programs,id'], 'academic_period_id' => ['required', 'integer', 'exists:academic_periods,id']];
    }

    private function filterRules(): array
    {
        return [
            'program_id' => ['nullable', 'integer', 'exists:scholarship_programs,id'],
            'academic_period_id' => ['nullable', 'integer', 'exists:academic_periods,id'],
            'academic_year' => ['nullable', 'string', 'max:30'],
            'status' => ['nullable', 'in:draft,submitted,approved,finalized,void'],
        ];
    }

    private function choices(): array
    {
        return [
            'programs' => ScholarshipProgram::orderBy('code')->get(),
            'periods' => AcademicPeriod::orderByDesc('academic_year')->orderBy('semester_code')->get(),
            'years' => AcademicPeriod::distinct()->orderByDesc('academic_year')->pluck('academic_year'),
            'terms' => ProgramPeriodTerm::with(['program', 'academicPeriod', 'billingConfirmedBy'])->whereNotNull('billing_confirmed_at')->get(),
        ];
    }
}
