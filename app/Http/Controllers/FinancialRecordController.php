<?php

namespace App\Http\Controllers;

use App\Actions\Billing\ManageLiquidation;
use App\Actions\Billing\RecordFinancialActivity;
use App\Http\Requests\FinancialRecordRequest;
use App\Models\BillingBatch;
use App\Models\Deduction;
use App\Models\Document;
use App\Models\FundingReceipt;
use App\Models\Liquidation;
use App\Models\Payout;
use App\Models\ProgramPeriodTerm;
use App\Services\Billing\BatchFundingStatus;
use App\Services\Billing\FinancialEvidence;
use App\Services\Billing\LiquidationSummary;
use App\Services\Billing\Money;
use App\Support\TablePagination;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class FinancialRecordController extends Controller
{
    public function fundingStatus(Request $request, BatchFundingStatus $status)
    {
        Gate::authorize('financial-view');
        $filters = $request->validate([
            'program_period_term_id' => ['nullable', 'integer', 'exists:program_period_terms,id'],
            'stage' => ['nullable', Rule::in(array_keys(BatchFundingStatus::LABELS))],
        ]);
        $rows = $status->rows(isset($filters['program_period_term_id']) ? (int) $filters['program_period_term_id'] : null);
        if (filled($filters['stage'] ?? null)) {
            $rows = $rows->where('state', $filters['stage'])->values();
        }
        $shortfall = $rows->where('state', 'awaiting_funds')->sum(fn ($row) => Money::cents($row['shortfall']));

        return view('financial.funding-status', [
            'filters' => $filters, 'stages' => BatchFundingStatus::LABELS,
            'rows' => TablePagination::collection($rows, 'funding_status'),
            'shortfall' => Money::decimal($shortfall),
            'terms' => ProgramPeriodTerm::with(['program', 'academicPeriod'])->orderByDesc('academic_period_id')->get(),
        ]);
    }

    public function index(Request $request)
    {
        Gate::authorize('financial-view');
        $filters = $request->validate(['program_period_term_id' => ['nullable', 'integer', 'exists:program_period_terms,id']]);
        $scope = $filters['program_period_term_id'] ?? null;

        return view('financial.index', [
            'filters' => $filters,
            'terms' => ProgramPeriodTerm::with(['program', 'academicPeriod'])->orderByDesc('academic_period_id')->get(),
            'batches' => BillingBatch::with(['program', 'academicPeriod', 'approval'])->whereIn('status', ['submitted', 'finalized'])->when($scope, fn ($q) => $q->where('program_period_term_id', $scope))->orderByDesc('id')->tablePaginate('financial_batches', 'batch_page')->withQueryString(),
            'receipts' => FundingReceipt::with(['program', 'academicPeriod'])->when($scope, fn ($q) => $q->where('program_period_term_id', $scope))->orderByDesc('id')->tablePaginate('receipts', 'receipt_page')->withQueryString(),
        ]);
    }

    public function batch(BillingBatch $batch, LiquidationSummary $summary)
    {
        Gate::authorize('financial-view');
        abort_unless(in_array($batch->status, ['submitted', 'finalized'], true), 404);
        $batch->load(['program', 'academicPeriod', 'fundingAllocations.receipt', 'liquidations', 'payroll']);

        return view('financial.batch', ['batch' => $batch, 'summary' => $summary->forBatch($batch), 'receipts' => FundingReceipt::where('program_period_term_id', $batch->program_period_term_id)->where('status', 'posted')->where('entry_type', 'original')->get()]);
    }

    public function createReceipt(FinancialRecordRequest $request, RecordFinancialActivity $action)
    {
        return to_route('financial.receipt', $action->receipt($request->user(), $request->validated()))->with('status', 'Actual receipt recorded as a draft; independent verification and evidence are required.');
    }

    public function receipt(FundingReceipt $receipt)
    {
        Gate::authorize('financial-view');
        $receipt->load(['program', 'academicPeriod', 'documents', 'allocations.batch']);

        return view('financial.receipt', compact('receipt'));
    }

    public function postReceipt(FinancialRecordRequest $request, FundingReceipt $receipt, RecordFinancialActivity $action)
    {
        $action->postReceipt($receipt, $request->user());

        return back()->with('status', 'Actual school receipt verified and posted. No student payment was created.');
    }

    public function allocate(FinancialRecordRequest $request, BillingBatch $batch, RecordFinancialActivity $action)
    {
        $action->allocate($batch, $request->user(), $request->validated());

        return back()->with('status', 'Received school cash allocated to this batch; this is not a student payment.');
    }

    public function createPayout(FinancialRecordRequest $request, RecordFinancialActivity $action)
    {
        return to_route('financial.payout', $action->payout($request->user(), $request->validated()))->with('status', 'Payment draft saved. It is not counted as paid until verified.');
    }

    public function payout(Payout $payout)
    {
        Gate::authorize('financial-view');
        $payout->load(['batch.program', 'billingItem.student', 'documents', 'deductions.documents', 'acknowledgments.document', 'acknowledgments.verifiedBy']);

        return view('financial.payout', compact('payout'));
    }

    public function postPayout(FinancialRecordRequest $request, Payout $payout, RecordFinancialActivity $action)
    {
        $action->postPayout($payout, $request->user());

        return back()->with('status', 'External payment record verified. No money was sent by this system.');
    }

    public function deduct(FinancialRecordRequest $request, Payout $payout, RecordFinancialActivity $action)
    {
        $action->deduct($payout, $request->user(), $request->validated(), $request->file('authority_evidence'));

        return back()->with('status', 'Specific deduction authorized with its program authority and supporting evidence.');
    }

    public function settle(FinancialRecordRequest $request, Deduction $deduction, RecordFinancialActivity $action)
    {
        $action->settle($deduction, $request->user(), $request->validated());

        return back()->with('status', 'Actual deduction settlement verified.');
    }

    public function prepareLiquidation(FinancialRecordRequest $request, BillingBatch $batch, ManageLiquidation $action)
    {
        return to_route('financial.liquidation', $action->prepare($batch, $request->user(), $request->validated('reference')));
    }

    public function liquidation(Liquidation $liquidation, LiquidationSummary $summary)
    {
        Gate::authorize('financial-view');

        return view('financial.liquidation', ['report' => $liquidation->load(['batch.program', 'batch.academicPeriod', 'items.payout', 'items.deduction.payout']), 'summary' => $summary->forBatch($liquidation->batch, $liquidation), 'printing' => false]);
    }

    public function printLiquidation(Liquidation $liquidation, LiquidationSummary $summary)
    {
        Gate::authorize('financial-view');

        return view('financial.print', ['report' => $liquidation->load(['batch.program', 'batch.academicPeriod', 'items.payout', 'items.deduction.payout']), 'summary' => $summary->forBatch($liquidation->batch, $liquidation), 'printing' => true]);
    }

    public function refreshLiquidation(FinancialRecordRequest $request, Liquidation $liquidation, ManageLiquidation $action)
    {
        $action->refresh($liquidation, $request->user());

        return back()->with('status', 'Liquidation draft refreshed from verified payment and settlement records.');
    }

    public function submitLiquidation(FinancialRecordRequest $request, Liquidation $liquidation, ManageLiquidation $action)
    {
        $action->submit($liquidation, $request->user());

        return back()->with('status', 'Complete liquidation submitted for independent review.');
    }

    public function reviewLiquidation(FinancialRecordRequest $request, Liquidation $liquidation, ManageLiquidation $action)
    {
        $action->review($liquidation, $request->user(), $request->validated('decision'), $request->validated('review_notes'));

        return back()->with('status', 'Liquidation review recorded.');
    }

    public function uploadEvidence(FinancialRecordRequest $request, string $type, int $id, FinancialEvidence $evidence)
    {
        $owner = $this->owner($type, $id);
        abort_if($owner instanceof Liquidation && $owner->status !== 'draft', 403);
        $evidence->upload($owner, $request->user(), $request->file('evidence'), $request->validated('document_type'));

        return back()->with('status', 'Private evidence uploaded for independent review.');
    }

    public function reviewEvidence(FinancialRecordRequest $request, string $type, int $id, Document $document, FinancialEvidence $evidence)
    {
        abort_unless($this->owner($type, $id)->documents()->whereKey($document->id)->exists(), 404);
        $evidence->review($document, $request->user(), $request->validated('decision'), $request->validated('review_notes'));

        return back()->with('status', 'Evidence review recorded.');
    }

    public function download(string $type, int $id, Document $document, FinancialEvidence $evidence)
    {
        Gate::authorize('financial-evidence');
        abort_unless($this->owner($type, $id)->documents()->whereKey($document->id)->exists() && $evidence->intact($document), 404);

        return Storage::disk('scholarship')->download($document->storage_path, $document->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function owner(string $type, int $id): Model
    {
        $class = match ($type) {
            'receipt' => FundingReceipt::class, 'payout' => Payout::class, 'deduction' => Deduction::class, 'liquidation' => Liquidation::class, default => abort(404)
        };

        return $class::findOrFail($id);
    }
}
