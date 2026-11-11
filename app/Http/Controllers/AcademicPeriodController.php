<?php

namespace App\Http\Controllers;

use App\Actions\Records\RecordAudit;
use App\Http\Requests\AcademicContextRequest;
use App\Http\Requests\AcademicPeriodRequest;
use App\Models\AcademicPeriod;
use App\Models\AcademicValidationContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AcademicPeriodController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', AcademicPeriod::class);
        $filters = $request->validate(['academic_year' => ['nullable', 'string', 'max:30']]);

        return view('periods.index', [
            'periods' => AcademicPeriod::when($filters['academic_year'] ?? null, fn ($query, $year) => $query->where('academic_year', $year))->orderByDesc('academic_year')->orderBy('semester_code')->paginate(15)->withQueryString(),
            'years' => AcademicPeriod::distinct()->orderByDesc('academic_year')->pluck('academic_year'), 'filters' => $filters,
            'context' => AcademicValidationContext::current()->load(['activePeriod', 'sourcePeriod']),
            'allPeriods' => AcademicPeriod::orderByDesc('academic_year')->orderBy('semester_code')->get(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', AcademicPeriod::class);

        return view('periods.form', ['period' => new AcademicPeriod(['is_open' => false]), 'identityLocked' => false]);
    }

    public function saveContext(AcademicContextRequest $request, RecordAudit $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $audit) {
            $context = AcademicValidationContext::whereKey(1)->lockForUpdate()->firstOrFail();
            if ($context->revision !== (int) $request->validated('revision')) {
                throw ValidationException::withMessages(['revision' => 'The academic context changed. Reload before saving.']);
            }
            $before = $context->only($context->getFillable());
            $source = $request->validated('source_period_id');
            $context->update([
                'active_period_id' => $request->validated('active_period_id'), 'source_period_id' => $source,
                'source_policy_reference' => $source ? $request->validated('source_policy_reference') : null,
                'confirmed_by' => $source ? $request->user()->id : null, 'confirmed_at' => $source ? now() : null,
                'revision' => $context->revision + 1,
            ]);
            $audit->record($request->user(), $context, 'academic_validation_context_updated', $before, $context->source_policy_reference);
        }, 3);

        return to_route('academic-periods.index')->with('status', 'Active validation period and grading-source configuration saved. Prior decisions require reassessment.');
    }

    public function store(AcademicPeriodRequest $request, RecordAudit $audit): RedirectResponse
    {
        try {
            $period = DB::transaction(function () use ($request, $audit) {
                $period = AcademicPeriod::create($request->validated());
                $audit->record($request->user(), $period, 'academic_period_created');

                return $period;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['semester_code' => 'This academic year and semester already exist.']);
        }

        return to_route('academic-periods.show', $period)->with('status', 'Academic period added.');
    }

    public function show(AcademicPeriod $period): View
    {
        Gate::authorize('view', $period);

        return view('periods.show', ['period' => $period, 'assignments' => $period->startingAssignments()->with(['student', 'program'])->latest('id')->paginate(15)]);
    }

    public function edit(AcademicPeriod $period): View
    {
        Gate::authorize('update', $period);

        return view('periods.form', ['period' => $period, 'identityLocked' => $period->isInUse()]);
    }

    public function update(AcademicPeriodRequest $request, AcademicPeriod $period, RecordAudit $audit): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $period, $audit) {
                $period = AcademicPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
                $data = $request->validated();
                if ($period->isInUse() && ($data['academic_year'] !== $period->academic_year || $data['semester_code'] !== $period->semester_code)) {
                    throw ValidationException::withMessages(['academic_year' => 'A semester linked to existing records keeps its original academic year and code.']);
                }
                $before = $period->only($period->getFillable());
                $period->update($data);
                $audit->record($request->user(), $period, 'academic_period_updated', $before);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['semester_code' => 'This academic year and semester already exist.']);
        }

        return to_route('academic-periods.show', $period)->with('status', 'Academic period updated.');
    }
}
