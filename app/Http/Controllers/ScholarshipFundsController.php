<?php

namespace App\Http\Controllers;

use App\Actions\Billing\ManageScholarshipFunds;
use App\Models\AcademicPeriod;
use App\Models\BillingBatch;
use App\Models\BillingItem;
use App\Models\ProgramPeriodTerm;
use App\Models\ScholarshipProgram;
use App\Services\Billing\AllocationSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScholarshipFundsController extends Controller
{
    public function budget(Request $request, ManageScholarshipFunds $funds): RedirectResponse
    {
        Gate::authorize('configure', BillingBatch::class);
        $funds->budget($request->user(), $request->validate([
            'program_id' => ['required', 'integer', 'exists:scholarship_programs,id'],
            'academic_period_id' => ['required', 'integer', 'exists:academic_periods,id'],
            'previous_budget' => ['required', 'integer', 'min:0'],
            'amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/D', 'numeric', 'min:0', 'max:9999999999.99'],
            'source_reference' => ['required', 'string', 'max:3000', 'regex:/\S/'],
        ]));

        return back()->with('status', 'Scholarship budget revision recorded. A budget is not received cash.');
    }

    public function approve(Request $request, BillingBatch $batch, ManageScholarshipFunds $funds): RedirectResponse
    {
        Gate::authorize('approve', $batch);
        $funds->approve($batch, $request->user(), $request->validate(['reference' => ['required', 'string', 'max:255', 'regex:/\S/']]));

        return back()->with('status', 'Scholarship allocation approved. No funds have been marked received, paid or released.');
    }

    public function adjust(Request $request, BillingBatch $batch, BillingItem $item, ManageScholarshipFunds $funds): RedirectResponse
    {
        Gate::authorize('adjust', $batch);
        abort_unless($item->billing_batch_id === $batch->id, 404);
        $funds->adjust($item, $request->user(), $request->validate([
            'previous_adjustment' => ['required', 'integer', 'min:0'],
            'new_amount' => ['required', 'regex:/^\d{1,10}(\.\d{1,2})?$/D', 'numeric', 'min:0', 'max:9999999999.99'],
            'reason' => ['required', 'string', 'max:3000', 'regex:/\S/'], 'reference' => ['required', 'string', 'max:255', 'regex:/\S/'],
        ]));

        return back()->with('status', 'Authorized adjustment retained alongside the original scholarship allocation.');
    }

    public function report(Request $request, AllocationSummary $summary): View|StreamedResponse
    {
        Gate::authorize('viewAny', BillingBatch::class);
        $filters = $request->validate([
            'program_id' => ['nullable', 'integer', 'exists:scholarship_programs,id'], 'academic_period_id' => ['nullable', 'integer', 'exists:academic_periods,id'],
            'academic_year' => ['nullable', 'string', 'max:30'], 'export' => ['nullable', 'in:csv'],
        ]);
        $terms = ProgramPeriodTerm::with(['program', 'academicPeriod'])
            ->when($filters['program_id'] ?? null, fn ($q, $value) => $q->where('program_id', $value))
            ->when($filters['academic_period_id'] ?? null, fn ($q, $value) => $q->where('academic_period_id', $value))
            ->when($filters['academic_year'] ?? null, fn ($q, $value) => $q->whereHas('academicPeriod', fn ($q) => $q->where('academic_year', $value)))
            ->orderBy('academic_period_id')->orderBy('program_id')->get();
        $items = BillingItem::with(['student', 'program', 'academicPeriod', 'batch.approval.approvedBy', 'adjustments'])->whereHas('batch', fn ($q) => $q->whereIn('program_period_term_id', $terms->modelKeys()))->orderBy('id');
        if (($filters['export'] ?? null) === 'csv') {
            return response()->streamDownload(function () use ($terms, $summary, $items) {
                $stream = fopen('php://output', 'w');
                fwrite($stream, "\xEF\xBB\xBF");
                $safe = fn ($value) => preg_match('/^[=+@\-\t\r]/', (string) $value) ? "'".$value : $value;
                $row = fn ($values) => fputcsv($stream, array_map($safe, $values), ',', '"', '');
                $row(['Scholarship fund summary (PHP); budget and allocations are not cash receipts or payments']);
                if (\App\Services\Eligibility\OfficialRuleCatalog::demonstrationDatabase()
                    || $terms->contains(fn ($term) => \App\Services\Eligibility\OfficialRuleCatalog::demonstrationRecord($term->program->code, $term->criteria))) {
                    $row([\App\Services\Eligibility\OfficialRuleCatalog::DEMO_LABEL]);
                }
                $row(['Program', 'Academic year', 'Semester', 'Budget', 'Approved allocations', 'Adjustments', 'Remaining budget', 'Pending approval', 'Actually received', 'School-paid student net', 'Verified direct-to-student net']);
                foreach ($terms as $term) {
                    $s = $summary->forTerms($term);
                    $row([$term->program->code, $term->academicPeriod->academic_year, $term->academicPeriod->semester_code, $s['budget']?->amount ?? 'Unconfigured', $s['allocated'], $s['adjustments'], $s['remaining'] ?? 'Unconfigured', $s['pending'] ?? 'Unconfirmed', $s['received'], $s['released_net'], $s['direct_paid_net']]);
                }
                $row([]);
                $row(['Student number', 'Scholar', 'Program', 'Academic year', 'Semester', 'Batch', 'Status', 'Original allocation', 'Effective allocation', 'Approval reference', 'Approved by']);
                foreach ($items->cursor() as $item) {
                    $row([$item->student->student_number, $item->student->full_name, $item->program->code, $item->academicPeriod->academic_year, $item->academicPeriod->semester_code, $item->batch->batch_number, $item->batch->allocationStatus(), $item->gross_amount ?? 'Unconfirmed', $item->gross_amount === null ? 'Unconfirmed' : $item->effectiveAmount(), $item->batch->approval?->reference ?? '', $item->batch->approval?->approvedBy?->name ?? '']);
                }
                fclose($stream);
            }, 'scholarship-allocations.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return view('billing.report', [
            'filters' => $filters, 'summaries' => $terms->map(fn ($term) => $summary->forTerms($term)), 'items' => $items->tablePaginate('allocations')->withQueryString(),
            'programs' => ScholarshipProgram::orderBy('code')->get(), 'periods' => AcademicPeriod::orderByDesc('academic_year')->orderBy('semester_code')->get(),
        ]);
    }
}
