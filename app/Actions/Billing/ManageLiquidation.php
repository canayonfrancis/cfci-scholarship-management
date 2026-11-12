<?php

namespace App\Actions\Billing;

use App\Actions\Records\RecordAudit;
use App\Models\BillingBatch;
use App\Models\Liquidation;
use App\Models\ProgramPeriodTerm;
use App\Models\User;
use App\Services\Billing\LiquidationSummary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageLiquidation
{
    public function __construct(private LiquidationSummary $summary, private RecordAudit $audit) {}

    public function prepare(BillingBatch $batch, User $actor, string $reference): Liquidation
    {
        Gate::forUser($actor)->authorize('financial-prepare');

        return DB::transaction(function () use ($batch, $actor, $reference) {
            ProgramPeriodTerm::whereKey($batch->program_period_term_id)->lockForUpdate()->firstOrFail();
            $batch = BillingBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if (! RecordFinancialActivity::approved($batch) || $batch->liquidations()->where('status', 'accepted')->exists()) {
                $this->fail('Select an approved allocation batch that has not already been liquidated.');
            }
            $draft = $batch->liquidations()->where('status', 'draft')->first();
            if ($draft) {
                return $draft;
            }
            $report = $batch->liquidations()->create(['sequence' => ($batch->liquidations()->max('sequence') ?? 0) + 1, 'reference' => $reference, 'prepared_by' => $actor->id]);
            $this->lines($report);
            $this->audit->record($actor, $report, 'liquidation_draft_prepared');

            return $report;
        }, 3);
    }

    public function refresh(Liquidation $report, User $actor): void
    {
        Gate::forUser($actor)->authorize('financial-prepare');
        $scopeId = $report->batch->program_period_term_id;
        DB::transaction(function () use ($report, $actor, $scopeId) {
            ProgramPeriodTerm::whereKey($scopeId)->lockForUpdate()->firstOrFail();
            $report = Liquidation::whereKey($report->id)->lockForUpdate()->firstOrFail();
            if ($report->status !== 'draft') {
                $this->fail('Only an unsubmitted liquidation draft can be refreshed.');
            }
            $this->lines($report);
            $this->audit->record($actor, $report, 'liquidation_draft_refreshed');
        }, 3);
    }

    public function submit(Liquidation $report, User $actor): void
    {
        Gate::forUser($actor)->authorize('financial-prepare');
        $scopeId = $report->batch->program_period_term_id;
        DB::transaction(function () use ($report, $actor, $scopeId) {
            ProgramPeriodTerm::whereKey($scopeId)->lockForUpdate()->firstOrFail();
            $report = Liquidation::whereKey($report->id)->lockForUpdate()->firstOrFail();
            if ($report->status !== 'draft') {
                $this->fail('Only a draft liquidation may be submitted.');
            }
            $checklist = $this->requireComplete($report);
            $report->update(['status' => 'submitted', 'submitted_at' => now(), 'submitted_by' => $actor->id,
                'compliance_snapshot' => $checklist + ['checked_by' => $actor->id, 'checked_at' => now()->toIso8601String()]]);
            $this->audit->record($actor, $report, 'liquidation_submitted');
        }, 3);
    }

    public function review(Liquidation $report, User $actor, string $decision, string $notes): void
    {
        Gate::forUser($actor)->authorize('financial-verify');
        $scopeId = $report->batch->program_period_term_id;
        DB::transaction(function () use ($report, $actor, $decision, $notes, $scopeId) {
            ProgramPeriodTerm::whereKey($scopeId)->lockForUpdate()->firstOrFail();
            $report = Liquidation::whereKey($report->id)->lockForUpdate()->firstOrFail();
            if ($report->status !== 'submitted' || $report->prepared_by === $actor->id || $report->submitted_by === $actor->id) {
                $this->fail('A different Coordinator must review a submitted liquidation.');
            }
            if (! in_array($decision, ['accepted', 'returned'], true)) {
                $this->fail('Choose acceptance or return for a submitted liquidation.');
            }
            $checklist = $decision === 'accepted' ? $this->requireComplete($report) : null;
            $before = $report->only($report->getFillable());
            $changes = ['status' => $decision, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_notes' => $notes];
            if ($decision === 'accepted' && $report->compliance_snapshot === null) {
                $changes['compliance_snapshot'] = $checklist + ['checked_by' => $actor->id, 'checked_at' => now()->toIso8601String()];
            }
            $report->update($changes);
            $this->audit->record($actor, $report, 'liquidation_'.$decision, $before);
        }, 3);
    }

    private function lines(Liquidation $report): void
    {
        $summary = $this->summary->forBatch($report->batch);
        $report->items()->delete();
        foreach ($summary['expected'] as $line) {
            $report->items()->create($line + ['billing_batch_id' => $report->billing_batch_id]);
        }
    }

    private function requireComplete(Liquidation $report): array
    {
        $summary = $this->summary->forBatch($report->batch, $report);
        if (! $summary['complete']) {
            $this->fail(implode(' ', $summary['issues']));
        }

        return $summary['compliance'];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['liquidation' => $message]);
    }
}
