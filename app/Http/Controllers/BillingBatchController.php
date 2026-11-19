<?php

namespace App\Http\Controllers;

use App\Actions\Billing\ManageBillingBatch;
use App\Actions\Records\ManageScholarshipRoster;
use App\Actions\Records\SaveAcademicVerification;
use App\Models\AcademicPeriod;
use App\Models\AcademicRecord;
use App\Models\AcademicValidationContext;
use App\Models\BillingBatch;
use App\Models\BillingItem;
use App\Models\DelistingReason;
use App\Models\ProgramPeriodTerm;
use App\Models\ScholarshipBatch;
use App\Models\ScholarshipBatchMembership;
use App\Models\ScholarshipProgram;
use App\Rules\PositiveBatchNumber;
use App\Services\Billing\AllocationSummary;
use App\Services\Billing\BillingBatchOptions;
use App\Services\Billing\BillingEligibility;
use App\Services\Billing\BillingRoster;
use App\Services\Billing\Money;
use App\Services\Eligibility\OfficialRuleCatalog;
use App\Services\Records\ScholarImportTemplate;
use App\Services\Records\ScholarProfile;
use App\Support\TablePagination;
use App\View\EvaluationWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class BillingBatchController extends Controller
{
    public function index(Request $request, AllocationSummary $summary, BillingBatchOptions $options): View
    {
        Gate::authorize('viewAny', BillingBatch::class);
        $filters = $request->validate([...$this->filterRules(), 'billing_batch_id' => ['nullable', 'integer', 'exists:billing_batches,id']]);
        $query = $options->query($filters)->with(['program', 'academicPeriod', 'approval', 'scholarshipBatch'])->withCount('items');
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
            ->orderByDesc('academic_period_id')->orderBy('program_id')->tablePaginate('budget_scopes', 'scopes')->withQueryString();

        return view('billing.index', ['batches' => $query->latest('id')->tablePaginate('billing_batches')->withQueryString(), 'batchOptions' => $options->forScope($filters), 'filters' => $filters, 'scopes' => $scopes, 'summaries' => $scopes->map(fn ($terms) => $summary->forTerms($terms)), ...$this->choices()]);
    }

    public function options(Request $request, BillingBatchOptions $options): JsonResponse
    {
        Gate::authorize('viewAny', BillingBatch::class);

        return response()->json(['options' => $options->forScope($request->validate($this->filterRules()))]);
    }

    public function store(Request $request, ManageBillingBatch $manage): RedirectResponse
    {
        Gate::authorize('create', BillingBatch::class);
        try {
            $data = $request->validate([
                'program_id' => ['nullable', 'integer', 'exists:scholarship_programs,id'],
                'academic_period_id' => ['nullable', 'integer', 'exists:academic_periods,id'],
                'scholarship_batch_id' => ['required', 'integer', 'exists:scholarship_batches,id'],
                'reference' => ['nullable', 'string', 'max:255'], 'batch_number' => ['nullable', new PositiveBatchNumber],
                'billing_batch_id' => ['prohibited'],
            ]);
            $batch = $manage->create($request->user(), $data);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            return to_route('billing-batches.index')->withErrors($exception->errors(), 'createBilling')
                ->withInput($request->only('scholarship_batch_id', 'reference'));
        }

        return to_route('billing-batches.show', $batch)->with('status', app(BillingRoster::class)->demonstrationAvailable($batch)
            ? 'UAT sample draft created. Review enrollment, compliance, external approvals and eligible scholars before fictional submission.'
            : 'Billing draft created. Review the retrieved roster members and select eligible scholars. Final submission is not yet available.');
    }

    public function configure(Request $request, ManageBillingBatch $manage): RedirectResponse
    {
        Gate::authorize('configure', BillingBatch::class);
        $fromReference = $request->boolean('use_program_reference');
        try {
            $data = $request->validate([
                ...($fromReference ? [
                    'use_program_reference' => ['accepted'],
                    'scholarship_batch_id' => ['required', 'integer', 'exists:scholarship_batches,id'],
                    'expected_reference_amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/', 'numeric', 'gt:0', 'max:9999999999.99'],
                ] : [
                    ...$this->scopeRules(),
                    'semester_gross_amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/', 'numeric', 'gt:0', 'max:9999999999.99'],
                ]),
                'previous_rate' => [$fromReference ? 'required' : 'nullable', 'integer', 'min:0'],
                'billing_source_reference' => ['required', 'string', 'max:3000', 'regex:/\S/'],
                'confirm_billing_policy' => ['accepted'],
            ]);
            $manage->configure($request->user(), $data);
        } catch (ValidationException $exception) {
            if (! $fromReference || $request->expectsJson()) {
                throw $exception;
            }

            return to_route('billing-batches.index')->withErrors($exception->errors(), 'createBilling')
                ->withInput($request->only('scholarship_batch_id', 'reference', 'billing_source_reference'));
        }

        if ($fromReference) {
            return to_route('billing-batches.index')->with('reopen_create_billing', true)
                ->withInput($request->only('scholarship_batch_id', 'reference'))
                ->with('status', 'Semester rate confirmed from the configured program amount. You can now create the billing draft using that approved rate.');
        }

        return back()->with('status', 'Semester scholarship rate approved as a new historical revision. Existing allocations and actual receipts/releases are unchanged.');
    }

    public function show(Request $request, BillingBatch $batch, BillingEligibility $eligibility, BillingRoster $roster): View
    {
        Gate::authorize('view', $batch);
        $search = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $batch->load(['program', 'academicPeriod', 'scholarshipBatch', 'terms.billingConfirmedBy', 'preparedBy', 'submittedBy', 'rate.approvedBy', 'approval.approvedBy']);
        $rows = $roster->readiness($batch, $eligibility);
        $readinessSummary = ['total' => $rows->count()] + collect(['eligible', 'review', 'excluded', 'reserved'])
            ->mapWithKeys(fn ($state) => [$state => $rows->where('state', $state)->count()])->all();
        if (filled($search['q'] ?? null)) {
            $needle = mb_strtolower(trim($search['q']));
            $rows = $rows->filter(fn ($row) => str_contains(mb_strtolower($row['member']->student->student_number.' '.$row['member']->student->full_name), $needle));
        }
        $members = TablePagination::collection($rows, 'billing_members', 'members')->appends(['tab' => 'roster']);
        $selectable = collect($members->items())->where('eligible', true)->map(fn ($row) => [
            'id' => (string) $row['application']->id,
            'label' => $row['member']->student->student_number.' / '.$row['member']->student->full_name,
            'amount' => $row['amount'],
        ])->values();
        $canVerifyEnrollment = Gate::allows('create', AcademicRecord::class) && $batch->scholarshipBatch !== null;
        $enrollmentRows = $canVerifyEnrollment ? collect($members->items())->map(fn ($row) => [
            'id' => (string) $row['member']->student_id,
            'correction' => $row['record'] && ($row['record']->verification_status !== 'pending' || $row['record']->verified_at !== null),
        ])->values() : collect();
        $canRecordExclusion = Gate::allows('recordExclusion', $batch) && $batch->scholarshipBatch !== null;
        $exclusionReady = Schema::hasColumn('scholarship_batch_memberships', 'billing_exclusion') && Schema::hasTable('delisting_reasons');
        $exclusionChoices = $canRecordExclusion ? collect($members->items())->mapWithKeys(function ($row) {
            $member = $row['member'];
            $other = $row['exclusion_member'] && $row['exclusion_member']->id !== $member->id;

            return [(string) $member->id => ['label' => $member->student->student_number.' / '.$member->student->full_name,
                'enrollment' => $row['enrollment'], 'revision' => $member->billing_exclusion['revision'] ?? 0,
                'decision' => $row['exclusion_member']?->billing_exclusion ?? $member->billing_exclusion,
                'blocked' => (bool) ($row['reservation'] || $other),
                'block_reason' => $row['reservation'] ? 'Already included or reserved in a semester bill. Remove an editable draft item first; submitted financial history is preserved.'
                    : ($other ? 'An exclusion is recorded in '.$row['exclusion_member']->batch->batch_number.'. Open a draft linked to that original roster to manage its decision.' : ''),
            ]];
        })->all() : [];

        return view('billing.show', [
            'batch' => $batch, 'items' => $batch->items()->with(['student', 'adjustments.authorizedBy'])->orderBy('id')->tablePaginate('billing_items')->appends(['tab' => 'items']),
            'members' => $members, 'readinessSummary' => $readinessSummary, 'selectable' => $selectable, 'search' => $search,
            'canVerifyEnrollment' => $canVerifyEnrollment, 'enrollmentRows' => $enrollmentRows,
            'enrollmentReady' => $canVerifyEnrollment,
            'canRecordExclusion' => $canRecordExclusion, 'exclusionReady' => $exclusionReady, 'exclusionChoices' => $exclusionChoices,
            'delistingReasons' => $canRecordExclusion && $exclusionReady ? DelistingReason::where('is_active', true)->orderBy('name')->get() : collect(),
        ]);
    }

    public function exclusion(Request $request, BillingBatch $batch, ManageScholarshipRoster $roster): RedirectResponse
    {
        Gate::authorize('recordExclusion', $batch);
        try {
            $request->validate(['membership_id' => ['required', 'integer', 'min:1']]);
            $member = ScholarshipBatchMembership::whereKey($request->input('membership_id'))->where('scholarship_batch_id', $batch->scholarship_batch_id)
                ->where('program_id', $batch->program_id)->where('academic_period_id', $batch->academic_period_id)->firstOrFail();
            $roster->recordBillingExclusion($batch, $member, $request->user(), $request->only('operation', 'exclusion_revision', 'reason_id', 'remarks', 'confirm_exclusion_review'));
        } catch (ValidationException $exception) {
            return to_route('billing-batches.show', ['batch' => $batch, 'tab' => 'roster'] + $request->query())
                ->withErrors($exception->errors(), 'billingExclusion')->withInput($request->only('membership_id', 'operation', 'reason_id', 'remarks'));
        }

        return to_route('billing-batches.show', ['batch' => $batch, 'tab' => 'roster'] + $request->query())
            ->with('status', 'Semester billing decision saved. Validation refreshed; enrollment and scholarship assignment statuses are unchanged.');
    }

    public function exportScholars(Request $request, BillingBatch $batch, BillingRoster $roster, BillingEligibility $eligibility, EvaluationWorkspace $workspace, OfficialRuleCatalog $catalog, ScholarImportTemplate $workbook): BinaryFileResponse
    {
        Gate::authorize('view', $batch);
        $scope = $request->validate(['scope' => ['required', Rule::in(['roster', 'items'])]])['scope'];
        // Collect all rows in a read-only reporting transaction, before creating the download.
        $report = DB::transaction(function () use ($batch, $scope, $roster, $eligibility, $workspace, $catalog) {
            $batch = BillingBatch::with(['program', 'academicPeriod', 'scholarshipBatch', 'terms', 'preparedBy', 'rate', 'approval'])->findOrFail($batch->id);
            Gate::authorize('view', $batch);
            if ($batch->scholarshipBatch && ($batch->scholarshipBatch->program_id !== $batch->program_id || $batch->scholarshipBatch->academic_period_id !== $batch->academic_period_id)) {
                throw ValidationException::withMessages(['scope' => 'The recorded roster does not match this billing program and semester. No other roster can be inferred for export.']);
            }
            $members = $roster->readiness($batch, $eligibility);
            $items = $batch->items()->with(['student.academicProgram', 'assignment', 'application'])->orderBy('id')->get();
            $byStudent = $members->keyBy('member.student_id');
            $included = $items->keyBy('student_id');
            $rows = [];
            if ($scope === 'roster') {
                foreach ($members as $row) {
                    $rows[] = $this->scholarExportRow($batch, $row, $included->get($row['member']->student_id), count($rows) + 1, $workspace, $catalog);
                }
            } else {
                foreach ($items as $item) {
                    $row = $byStudent->get($item->student_id);
                    if (! $row || $row['member']->assignment_id !== $item->assignment_id) {
                        $record = $item->student->academicRecords()->where('academic_period_id', $batch->academic_period_id)->orderByDesc('revision')->first();
                        $known = $record && $record->verification_status === 'verified' && $record->verified_by && $record->verified_at;
                        $row = ['member' => null, 'application' => $item->application, 'record' => $record,
                            'enrollment' => $known ? Str::headline($record->enrollment_status) : 'Unverified',
                            'eligibility' => 'Pending review', 'reservation' => $item,
                            'reason' => 'No matching assignment membership in the recorded semester roster. Stored billing history is retained; no current roster association or payability is inferred.'];
                    }
                    $rows[] = $this->scholarExportRow($batch, $row, $item, count($rows) + 1, $workspace, $catalog);
                }
            }
            $generated = now('Asia/Manila');
            $rate = $batch->appliedRate();
            $approvedRate = $rate && $rate->program_period_term_id === $batch->program_period_term_id && $rate->approved_by && $rate->approved_at;
            $total = $items->contains(fn ($item) => $item->gross_amount === null)
                ? null : Money::decimal(Money::signedTotal($items, 'gross_amount'));
            $status = $batch->allocationStatus();
            $demonstration = OfficialRuleCatalog::demonstrationDatabase() || OfficialRuleCatalog::demonstrationRecord($batch->program->code, $batch->terms?->criteria)
                || $items->contains(fn ($item) => isset($item->basis_snapshot['uat_classification']));
            $information = [
                ['Cronasia Foundation College, Inc.'], ['Scholarship Billing and Validation Report'],
                ['Internal scholar list — not an official Form 2/Form 3 submission. Exporting changes no records.'],
                ...($batch->status === 'draft' ? [['DRAFT — for internal review only']] : []),
                ...($demonstration ? [[OfficialRuleCatalog::DEMO_LABEL]] : []),
                ['Scholarship program', $batch->program->name.' ('.$batch->program->code.')'],
                ['Roster batch name', $batch->scholarshipBatch?->batch_number ?? 'No recorded roster link — none inferred'],
                ['Billing batch / reference', $batch->batch_number.' / '.($batch->reference ?: 'No reference recorded')],
                ['Academic year', $batch->academicPeriod->academic_year], ['Semester', $batch->academicPeriod->semester_code],
                ['Billing status', $status === 'submitted' ? 'Awaiting Coordinator approval' : Str::headline($status)],
                ['Approved semester rate', $approvedRate ? 'PHP '.$rate->amount : 'Unconfirmed — no approved recorded rate'],
                ['Date generated (Asia/Manila)', $generated->toIso8601String()],
                ['Prepared by', $batch->preparedBy?->name ?? 'Not recorded'],
                ['Export scope', $scope === 'roster' ? 'All roster members (all pages)' : 'Billing items in this batch only (all pages)'],
                ['Total roster members', (string) $members->count()],
                ['Total eligible scholars', (string) $members->where('eligibility', 'Eligible')->count()],
                ['Total pending review', (string) $members->where('eligibility', 'Pending review')->count()],
                ['Total excluded scholars', (string) $members->where('eligibility', 'Excluded')->count()],
                ['Total already billed or reserved (roster)', (string) $members->whereNotNull('reservation')->count()],
                ['Total scholars included in this batch', (string) $items->count()],
                ['Total prepared billing amount', $total === null ? 'Unconfirmed — stored amount missing' : 'PHP '.$total],
                ['Eligibility counts describe current roster validation, independently of inclusion. Reservations include this and other batches; counts overlap.'],
                ['Individual amounts and prepared total use stored gross amounts in this batch only, before later adjustments. Blank amounts mean not included or unconfirmed; eligible does not mean included.'],
                ['Current enrollment/compliance/approval may differ from retained billing history; this export never replaces its original rate, decision or amount snapshots.'],
                ...($rows === [] ? [['No scholars in the selected export scope.']] : []),
            ];
            $name = ($batch->status === 'draft' ? 'DRAFT-' : '').($demonstration ? 'UAT-' : '').'scholar-list-'
                .Str::slug(Str::limit($batch->scholarshipBatch?->batch_number ?? 'unlinked-roster', 60, '')).'-'
                .Str::slug(Str::limit($batch->batch_number.'-'.($batch->reference ?? ''), 60, '')).'-'
                .($scope === 'roster' ? 'all-roster' : 'billing-items').'-'.$generated->format('Ymd-His').'.xlsx';

            return ['information' => $information, 'rows' => $rows, 'filename' => $name];
        });
        $path = tempnam(sys_get_temp_dir(), 'cfci-billing-scholars-');
        if ($path === false) {
            throw new \RuntimeException('Cannot allocate scholar export file.');
        }
        try {
            @chmod($path, 0600);
            $workbook->writeReport($path, $report['information'], [
                'Number', 'Student ID', 'Full name', 'Course / Program', 'Year level', 'Scholarship assignment status',
                'Current semester enrollment status', 'School compliance result', 'External approval status', 'Billing eligibility',
                'Billing inclusion status', 'Individual billing amount (PHP)', 'Exclusion or review reason / remarks',
            ], $report['rows'], [8, 22, 30, 46, 14, 24, 26, 32, 30, 24, 48, 24, 85], [11]);

            return response()->download($path, $report['filename'], [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            ])->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            if (is_file($path)) {
                unlink($path);
            }
            throw $exception;
        }
    }

    private function scholarExportRow(BillingBatch $batch, array $row, ?BillingItem $item, int $number, EvaluationWorkspace $workspace, OfficialRuleCatalog $catalog): array
    {
        $student = $row['member']?->student ?? $item->student;
        $assignment = $row['member']?->assignment ?? $item->assignment;
        $application = $row['application'];
        $school = 'Not evaluated';
        $external = 'Not recorded';
        if ($application && $assignment && $application->student_id === $student->id && $application->assignment_id === $assignment->id
            && $application->program_id === $batch->program_id && $application->academic_period_id === $batch->academic_period_id) {
            $application->loadMissing(['program', 'academicPeriod', 'terms', 'assignment', 'latestEvaluation', 'documents', 'externalApprovals.documents']);
            $policy = $catalog->forProgram($application->program->code);
            $state = $workspace->present(['application' => $application, 'context' => AcademicValidationContext::current(),
                'latest' => $application->latestEvaluation, 'academic' => $row['record'], 'policy' => $policy,
                'policyApplicable' => $policy && $catalog->appliesTo($application->academicPeriod)
                    && (! ($policy['demonstration'] ?? false) || OfficialRuleCatalog::fictionalStudent($student)),
                'approvals' => $application->externalApprovals->sortByDesc('id'),
            ], $catalog);
            if ($application->latestEvaluation) {
                $school = match ($application->latestEvaluation->school_decision) {
                    'qualified' => 'Eligible', 'not_qualified' => 'Ineligible', 'needs_information' => 'Needs review', default => 'Pending',
                };
                if ($state['stages'][1]['status'] !== 'Completed') {
                    $school .= ' (recorded) / '.$state['stages'][1]['status'];
                }
            }
            $external = $state['externalReady'] ? 'Approved — verified and applicable'
                : ($state['lastApproval'] ? ucfirst($state['lastApproval']->decision).($state['lastApproval']->decision === 'approved' ? ' — requires review' : '') : 'Not recorded');
        }
        $inclusion = $item ? 'Included in this batch / '.Str::headline($item->status)
            : ($row['reservation'] ? 'Already billed/reserved in another transaction / billing #'.$row['reservation']->billing_batch_id : 'Not included in this batch');

        $remarks = $row['reason'];
        $decision = ($row['exclusion_member'] ?? null)?->billing_exclusion ?? $row['member']?->billing_exclusion;
        if ($decision && ! ($decision['excluded'] ?? false)) {
            $remarks .= ' Released semester exclusion (historical reason): '.$decision['reason_name'].'. Original remarks: '.($decision['remarks'] ?? '')
                .'. Release remarks: '.($decision['release_remarks'] ?? '');
        }
        if ($assignment?->status === 'ended' && filled($assignment->status_reason) && ! str_contains($remarks, $assignment->status_reason)) {
            $remarks .= ' Recorded scholarship closure reason / remarks: '.$assignment->status_reason;
        }

        return [(string) $number, (string) $student->student_number, $student->full_name, $student->academicProgram?->name ?? 'Not provided',
            ScholarProfile::yearLevels()[$student->year_level] ?? ($student->year_level ? 'Year '.$student->year_level : 'Not provided'),
            $assignment ? $assignment->statusLabel() : 'Missing assignment', $row['enrollment'], $school, $external,
            $row['eligibility'], $inclusion, $item?->gross_amount, $remarks];
    }

    public function enrollment(Request $request, BillingBatch $batch, SaveAcademicVerification $academic): RedirectResponse
    {
        Gate::authorize('view', $batch);
        Gate::authorize('create', AcademicRecord::class);
        try {
            $request->validate(['certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:2048']]);
            $roster = $batch->scholarshipBatch;
            if (! $roster || $roster->program_id !== $batch->program_id || $roster->academic_period_id !== $batch->academic_period_id) {
                throw ValidationException::withMessages(['enrollment' => 'This billing record needs a valid matching semester roster before enrollment can be recorded.']);
            }
            $data = $request->only('enrollment_student_ids', 'enrollment_records', 'enrollment_status', 'source_reference', 'verification_notes', 'correction_reason', 'confirm_enrollment_review');
            $count = $academic->enrollment($request->user(), $roster, $data, $request->hasFile('certificate') ? [$request->file('certificate')] : []);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            return back()->withErrors($exception->errors(), 'enrollment')
                ->withInput($request->only('enrollment_student_ids', 'enrollment_status', 'source_reference', 'verification_notes', 'correction_reason'));
        }

        return back()->with('status', $count.' enrollment finding(s) recorded. Roster validation has refreshed; enrollment alone does not establish eligibility or payability.');
    }

    public function update(Request $request, BillingBatch $batch, ManageBillingBatch $manage): RedirectResponse
    {
        Gate::authorize('update', $batch);
        try {
            $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'batch_number' => ['required', 'string', 'max:60', 'regex:/\S/'], 'reference' => ['nullable', 'string', 'max:255']]);
            $manage->change($batch, $request->user(), (int) $data['lock_version'], 'edit', $data);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            return back()->withErrors($exception->errors(), 'editBilling')
                ->withInput($request->only('batch_number', 'reference', 'lock_version', 'billing_tab'));
        }

        return back()->with('status', 'Draft batch saved.');
    }

    public function add(Request $request, BillingBatch $batch, ManageBillingBatch $manage): RedirectResponse
    {
        Gate::authorize('update', $batch);
        $data = $request->validate(['lock_version' => ['required', 'integer', 'min:0'], 'application_id' => ['required', 'integer', 'exists:scholarship_applications,id']]);
        $manage->change($batch, $request->user(), (int) $data['lock_version'], 'add', $data);

        return back()->with('status', 'Scholar added to the draft batch.');
    }

    public function addMany(Request $request, BillingBatch $batch, ManageBillingBatch $manage): RedirectResponse
    {
        Gate::authorize('update', $batch);
        $data = $request->validate([
            'lock_version' => ['required', 'integer', 'min:0'],
            'application_ids' => ['required', 'array', 'min:1', 'max:200'],
            'application_ids.*' => ['required', 'integer', 'distinct', 'exists:scholarship_applications,id'],
            'confirm_review' => ['accepted'],
        ]);
        $manage->change($batch, $request->user(), (int) $data['lock_version'], 'add_many', $data);

        return back()->with('status', count($data['application_ids']).' scholar(s) added to the draft. Final submission remains blocked pending approved classification.');
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
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }

    private function choices(): array
    {
        $terms = ProgramPeriodTerm::with('rates')->get()->keyBy(fn ($term) => $term->program_id.':'.$term->academic_period_id);
        $rosters = ScholarshipBatch::with(['program', 'academicPeriod'])->withCount('memberships')
            ->whereHas('academicPeriod', fn ($q) => $q->where('is_open', true))->orderBy('batch_number')->get();
        $rosterChoices = $rosters->mapWithKeys(function ($roster) use ($terms) {
            $rates = $terms->get($roster->program_id.':'.$roster->academic_period_id)?->rates->sortByDesc('revision') ?? collect();
            $rate = $rates->first(fn ($rate) => $rate->approved_by && $rate->approved_at && Money::cents($rate->amount) > 0);
            $variable = strtoupper(trim($roster->program->code)) === 'EDUKAR';
            $fixed = $roster->program->usesFixedSemesterRate();
            $reference = $fixed ? $roster->program->school_stated_semester_amount : null;
            $referenceAvailable = $reference !== null && Money::cents($reference) > 0;
            $ready = $roster->program->is_active && ! $variable && $rate !== null;
            $canConfirm = $roster->program->is_active && $fixed && ! $rate && $referenceAvailable && Gate::allows('configure', BillingBatch::class);
            $reason = match (true) {
                ! $roster->program->is_active => 'This program is unavailable for new billing.',
                $variable => 'EDUKAR requires approved variable entitlement rules; fixed-rate creation is unavailable.',
                $ready => 'The approved exact-semester rate is retrieved automatically. No new rate confirmation is required. Scholar eligibility is reviewed next.',
                ! $fixed => 'No approved exact-semester rate is available. The simplified reference confirmation applies only to TDP, TES and CoScho.',
                ! $referenceAvailable => 'No approved semester rate or positive program reference amount is available. Ask an authorized Coordinator or Administrator to configure the program amount and confirm the semester rate.',
                $canConfirm => 'No approved rate exists for this exact program and semester. Review the configured reference amount and confirm it below; no amount re-entry is needed.',
                default => 'The program amount is reference information only. A Coordinator or Administrator must confirm the rate for this exact semester before you can create billing. Your role cannot approve rates.',
            };
            $pattern = '/^'.preg_quote($roster->program->code, '/').'-([0-9]+(?:\\.[0-9]+)?)-'.preg_quote($roster->academicPeriod->semester_code.'-'.$roster->academicPeriod->academic_year, '/').'$/D';

            return [(string) $roster->id => [
                'name' => $roster->batch_number, 'program' => $roster->program->name.' ('.$roster->program->code.')',
                'number' => preg_match($pattern, $roster->batch_number, $parts) ? $parts[1] : 'Not recorded separately',
                'year' => $roster->academicPeriod->academic_year, 'semester' => $roster->academicPeriod->semester_code,
                'members' => $roster->memberships_count, 'rate' => ! $variable ? $rate?->amount : null, 'ready' => (bool) $ready,
                'reference_amount' => $reference, 'can_confirm' => (bool) $canConfirm,
                'previous_rate' => $rates->first()?->id ?? 0, 'variable' => $variable, 'reason' => $reason,
            ]];
        });

        return [
            'rosterChoices' => $rosterChoices,
            'programs' => ScholarshipProgram::orderBy('code')->get(),
            'rosters' => $rosters,
            'periods' => AcademicPeriod::orderByDesc('academic_year')->orderBy('semester_code')->get(),
            'years' => AcademicPeriod::distinct()->orderByDesc('academic_year')->pluck('academic_year'),
            'terms' => ProgramPeriodTerm::with(['program', 'academicPeriod', 'billingConfirmedBy'])->whereNotNull('billing_confirmed_at')->get(),
        ];
    }
}
