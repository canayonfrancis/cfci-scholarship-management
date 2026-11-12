<?php

namespace App\Services\Reporting;

use App\Models\AcademicRecord;
use App\Models\BillingBatch;
use App\Models\BillingItem;
use App\Models\Liquidation;
use App\Models\ScholarshipBatchMembership;
use App\Services\Billing\BatchFundingStatus;
use App\Services\Billing\FinancialEvidence;
use App\Services\Billing\LiquidationSummary;
use App\Services\Billing\Money;
use App\Services\Billing\SignedAcknowledgment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ScholarshipReports
{
    public const TITLES = [
        'grants' => 'Grants by Scholarship Program and Batch', 'billed' => 'Billed Scholars',
        'not-billed' => 'Not Billed Scholars', 'awaiting-funds' => 'Grants Awaiting Funds for Payroll',
        'paid-signed' => 'Paid Grants with Verified Signed Payroll', 'liquidated' => 'Liquidated Billings',
    ];

    public const DEFINITIONS = [
        'grants' => 'One row per recorded roster membership, including never-billed and non-enrolled members. Distinct scholars and student/program/period grants are counted separately. Form classification remains unknown until approved templates exist.',
        'billed' => 'Finalized items in submitted billing with submission provenance, or supported legacy finalized billing. Drafts and voids are excluded. Original billing, adjustments and effective amounts are separate; approval is not a second amount.',
        'not-billed' => 'Recorded roster members without submitted billing for the same student/program/period in any submission. Draft reservations remain not billed. Financial filters cannot redefine this denominator.',
        'awaiting-funds' => 'Unpaid items under approved submissions with an actual usable batch funding shortfall. Shortfall is effective approved gross minus posted direct gross minus usable allocated school receipts, counted once per batch. Funded-but-unpaid and approval-pending stages are shown separately.',
        'paid-signed' => 'One row per billing item with actual posted, unreversed student payments supported by intact ordinary proof and explicit human signature verification. Each payment is counted once, regardless of documents or acknowledgments. Signed portions and full-benefit application are distinguished; zero-net applications are disclosed separately.',
        'liquidated' => 'One row per independently accepted billing with retained confirmed checklist and currently complete source evidence/reconciliation. Historical acceptance and later evidence exceptions remain visible separately and are not included in currently verified liquidation totals.',
    ];

    public function __construct(private BatchFundingStatus $funding, private SignedAcknowledgment $signatures, private FinancialEvidence $evidence, private LiquidationSummary $liquidation) {}

    /** Current recorded state, not a historical as-of reconstruction. No write actions are invoked. */
    public function build(string $report, array $filters = []): array
    {
        return DB::transaction(function () use ($report, $filters) {
            $data = match ($report) {
                'grants', 'not-billed' => $this->roster($filters, $report === 'not-billed'),
                'billed' => $this->billed($filters),
                'awaiting-funds' => $this->awaiting($filters),
                'paid-signed' => $this->paid($filters),
                'liquidated' => $this->liquidated($filters),
            };
            $rows = $data['rows']->values();
            $groups = $rows->groupBy(fn ($r) => json_encode([$r['program'], $r['_roster_id'] ?? null, $r['academic_year'], $r['semester']]))->map(fn ($members) => [
                'program' => $members->first()['program'], 'roster' => $members->first()['roster'],
                'period' => $members->first()['academic_year'].' / '.$members->first()['semester'],
                'rows' => $members->count(), 'scholars' => $members->flatMap(fn ($r) => isset($r['_student_id']) ? [$r['_student_id']] : ($r['_student_ids'] ?? []))->unique()->count(),
            ])->values();

            return $data + ['title' => self::TITLES[$report], 'definition' => self::DEFINITIONS[$report], 'report' => $report,
                'generated_at' => now('Asia/Manila')->toIso8601String(), 'groups' => $groups,
                'counts' => ['rows' => $rows->count(), 'scholars' => $rows->flatMap(fn ($r) => isset($r['_student_id']) ? [$r['_student_id']] : ($r['_student_ids'] ?? []))->unique()->count(),
                    'grants' => $rows->flatMap(fn ($r) => isset($r['_grant_key']) ? [$r['_grant_key']] : ($r['_grant_keys'] ?? []))->unique()->count(), 'billing_batches' => $rows->pluck('_billing_id')->filter()->unique()->count()],
            ];
        });
    }

    private function scope(Builder $query, array $filters, bool $roster = false): Builder
    {
        foreach (['program_id', 'academic_period_id'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }
        foreach (['academic_year', 'semester_code'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->whereHas('academicPeriod', fn ($q) => $q->where($field, $filters[$field]));
            }
        }
        if (filled($filters['scholarship_batch_id'] ?? null)) {
            $query->where('scholarship_batch_id', $filters['scholarship_batch_id']);
        }
        if (! $roster && filled($filters['billing_batch_id'] ?? null)) {
            $query->whereKey($filters['billing_batch_id']);
        }

        return $query;
    }

    private function submitted(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->where(fn ($q) => $q->where('status', 'submitted')->whereNotNull('submitted_at')->whereNotNull('submitted_by'))
            ->orWhere(fn ($q) => $q->where('status', 'finalized')->whereNotNull('finalized_at')->whereNotNull('finalized_by')));
    }

    private function financialItems(array $filters): Collection
    {
        return BillingItem::where('status', 'finalized')->whereHas('batch', fn ($q) => $this->submitted($this->scope($q, $filters)))
            ->with(['student', 'program', 'academicPeriod', 'adjustments', 'batch.scholarshipBatch', 'batch.approval', 'payouts.documents', 'payouts.reversals', 'payouts.acknowledgments.document', 'payouts.acknowledgments.verifiedBy', 'payouts.deductions.documents'])
            ->orderBy('program_id')->orderBy('academic_period_id')->orderBy('billing_batch_id')->orderBy('id')->get();
    }

    private function base(BillingItem $item): array
    {
        return ['program' => $item->program->code, 'roster' => $item->batch->scholarshipBatch?->batch_number ?? 'Unlinked historical submission',
            'academic_year' => $item->academicPeriod->academic_year, 'semester' => $item->academicPeriod->semester_code,
            'student_number' => $item->student->student_number, 'scholar' => $item->student->full_name,
            'billing' => $item->batch->batch_number, 'billing_item' => $item->id,
            '_student_id' => $item->student_id, '_grant_key' => $item->student_id.':'.$item->program_id.':'.$item->academic_period_id,
            '_roster_id' => $item->batch->scholarship_batch_id, '_billing_id' => $item->billing_batch_id,
            '_url' => route('billing-batches.show', $item->billing_batch_id)];
    }

    private function amount(BillingItem $item): ?string
    {
        return $item->gross_amount === null ? null : Money::decimal(Money::cents($item->gross_amount) + Money::signedTotal($item->adjustments));
    }

    private function total(Collection $rows, string $field): ?string
    {
        if ($rows->contains(fn ($r) => ! isset($r[$field]))) {
            return null;
        }

        return Money::decimal($rows->sum(fn ($r) => $this->cents($r[$field])));
    }

    private function cents(string $amount): int
    {
        return str_starts_with($amount, '-') ? -Money::cents(substr($amount, 1)) : Money::cents($amount);
    }

    private function commonColumns(bool $student = true): array
    {
        return ['program' => 'Program', 'roster' => 'Semester roster', 'academic_year' => 'Academic year', 'semester' => 'Semester']
            + ($student ? ['student_number' => 'Student ID (current profile)', 'scholar' => 'Scholar (current profile)'] : []);
    }

    private function roster(array $filters, bool $unbilled): array
    {
        $latest = AcademicRecord::select('id')->whereColumn('student_id', 'scholarship_batch_memberships.student_id')
            ->whereColumn('academic_period_id', 'scholarship_batch_memberships.academic_period_id')->orderByDesc('revision')->limit(1);
        $members = ScholarshipBatchMembership::select('scholarship_batch_memberships.*')->addSelect(['enrollment_record_id' => $latest])
            ->whereHas('batch', function ($q) use ($filters) {
                foreach (['program_id', 'academic_period_id'] as $f) {
                    $q->when($filters[$f] ?? null, fn ($q, $v) => $q->where($f, $v));
                }
                foreach (['academic_year', 'semester_code'] as $f) {
                    $q->when($filters[$f] ?? null, fn ($q, $v) => $q->whereHas('academicPeriod', fn ($p) => $p->where($f, $v)));
                }
            })->when($filters['scholarship_batch_id'] ?? null, fn ($q, $v) => $q->where('scholarship_batch_id', $v))
            ->with(['batch.program', 'batch.academicPeriod', 'student', 'assignment', 'enrollmentRecord'])->orderBy('program_id')->orderBy('scholarship_batch_id')->orderBy('student_id')->get();
        $bills = BillingItem::whereIn('student_id', $members->pluck('student_id'))->whereIn('academic_period_id', $members->pluck('academic_period_id'))
            ->with('batch.scholarshipBatch')->get()->groupBy(fn ($i) => $i->student_id.':'.$i->academic_period_id);
        $rows = collect();
        foreach ($members as $member) {
            $reservations = $bills->get($member->student_id.':'.$member->academic_period_id, collect());
            $submitted = $reservations->filter(fn ($i) => $i->program_id === $member->program_id && $i->status === 'finalized' && $this->isSubmitted($i->batch));
            if ($unbilled && $submitted->isNotEmpty()) {
                continue;
            }
            $record = $member->enrollmentRecord;
            $verified = $record && $record->verification_status === 'verified' && $record->verified_by && $record->verified_at && in_array($record->enrollment_status, ['enrolled', 'not_enrolled'], true);
            $enrollment = $verified ? ($record->enrollment_status === 'enrolled' ? 'Verified enrolled' : 'Verified not enrolled') : 'Awaiting Registrar verification';
            $rows->push(['program' => $member->batch->program->code, 'roster' => $member->batch->batch_number,
                'academic_year' => $member->batch->academicPeriod->academic_year, 'semester' => $member->batch->academicPeriod->semester_code,
                'student_number' => $member->student->student_number, 'scholar' => $member->student->full_name,
                'assignment' => $member->assignment->status, 'enrollment' => $enrollment, 'source_revision' => $record ? '#'.$record->id.' / revision '.$record->revision : 'No source record',
                'classification' => 'Pending approved Form 2/Form 3 template', 'billing_state' => $submitted->isNotEmpty() ? 'Submitted billing recorded' : ($reservations->isNotEmpty() ? 'No matching submitted bill; reservation/history exists' : 'Never billed; no reservation'),
                'reservations' => $reservations->map(fn ($i) => '#'.$i->id.' / '.$i->batch->batch_number.' / '.$i->batch->status.($i->program_id !== $member->program_id ? ' / other program' : '').($i->batch->scholarship_batch_id !== $member->scholarship_batch_id ? ' / other or unlinked roster' : ''))->implode('; '),
                '_student_id' => $member->student_id, '_grant_key' => $member->student_id.':'.$member->program_id.':'.$member->academic_period_id,
                '_roster_id' => $member->scholarship_batch_id, '_billing_id' => null, '_url' => route('scholars.show', $member->student_id)]);
        }

        return ['rows' => $rows, 'columns' => $this->commonColumns() + ['assignment' => 'Assignment', 'enrollment' => 'Registrar enrollment', 'source_revision' => 'Source revision', 'classification' => 'Form classification', 'billing_state' => 'Submitted billing state', 'reservations' => 'Financial reservation/history'],
            'totals' => [], 'exceptions' => collect(), 'stages' => collect()];
    }

    private function isSubmitted(BillingBatch $batch): bool
    {
        return ($batch->status === 'submitted' && $batch->submitted_at && $batch->submitted_by)
            || ($batch->status === 'finalized' && $batch->finalized_at && $batch->finalized_by);
    }

    private function billed(array $filters): array
    {
        $rows = $this->financialItems($filters)->map(fn ($i) => $this->base($i) + ['original' => $i->gross_amount, 'adjustment' => Money::decimal(Money::signedTotal($i->adjustments)), 'effective' => $this->amount($i),
            'approval' => $i->batch->approval ? 'Independently approved: '.$i->batch->approval->reference : ($i->batch->status === 'finalized' ? 'Legacy finalized authorization' : 'Awaiting independent approval')]);

        return ['rows' => $rows, 'columns' => $this->commonColumns() + ['billing' => 'Billing submission', 'billing_item' => 'Billing item', 'original' => 'Original billed (PHP)', 'adjustment' => 'Signed adjustments (PHP)', 'effective' => 'Effective amount (PHP)', 'approval' => 'Approval'],
            'totals' => ['Total billing amount (original PHP)' => $this->total($rows, 'original'), 'Signed adjustments (PHP)' => $this->total($rows, 'adjustment'), 'Effective billing (PHP)' => $this->total($rows, 'effective')], 'exceptions' => collect(), 'stages' => collect()];
    }

    private function awaiting(array $filters): array
    {
        $batchIds = $this->scope(BillingBatch::query(), $filters)->pluck('id')->all();
        $stages = $this->funding->rows(null, $batchIds);
        $awaiting = $stages->where('state', 'awaiting_funds')->keyBy('batch.id');
        $rows = collect();
        foreach ($this->financialItems($filters) as $item) {
            if (! $awaiting->has($item->billing_batch_id)) {
                continue;
            }
            $effective = $this->amount($item);
            $posted = $item->payouts->where('status', 'posted');
            if ($effective === null || $posted->contains(fn ($p) => $p->gross_amount === null)) {
                continue;
            }
            $remaining = $this->cents($effective) - Money::signedTotal($posted, 'gross_amount');
            if ($remaining > 0) {
                $rows->push($this->base($item) + ['effective' => $effective, 'remaining' => Money::decimal($remaining), 'state' => 'Approved; awaiting usable batch funding']);
            }
        }

        return ['rows' => $rows, 'columns' => $this->commonColumns() + ['billing' => 'Billing submission', 'billing_item' => 'Billing item', 'effective' => 'Effective benefit (PHP)', 'remaining' => 'Unpaid benefit (PHP)', 'state' => 'Funding stage'],
            'totals' => ['Batch funding shortfall counted once (PHP)' => Money::decimal($awaiting->sum(fn ($r) => Money::cents($r['shortfall']))), 'Listed unpaid benefit (PHP)' => $this->total($rows, 'remaining')],
            'exceptions' => collect(), 'stages' => $stages];
    }

    private function paid(array $filters): array
    {
        $rows = collect();
        $exceptions = collect();
        foreach ($this->financialItems($filters) as $item) {
            $base = $this->base($item);
            $posted = $item->payouts->where('status', 'posted');
            $unknown = $posted->contains(fn ($p) => $p->gross_amount === null || $p->net_amount === null);
            $effective = $this->amount($item);
            if ($unknown || $effective === null) {
                $exceptions->push($base + ['issue' => 'Unconfirmed historical payment or benefit amount']);

                continue;
            }
            $applied = Money::signedTotal($posted, 'gross_amount');
            $gross = $net = $school = $direct = 0;
            $lines = [];
            foreach ($posted as $payout) {
                $payout->setRelation('billingItem', $item);
                if ($payout->deductions->contains(fn ($d) => $d->amount === null)) {
                    $exceptions->push($base + ['issue' => 'Payout #'.$payout->id.': unconfirmed deduction amount']);

                    continue;
                }
                $ack = $payout->acknowledgments->first(fn ($a) => $this->signatures->usable($a, $payout));
                $deductions = Money::signedTotal($payout->deductions);
                $validDeductions = $payout->deductions->every(fn ($d) => $d->authorized_by && $d->authorized_at && filled($d->authority_reference) && filled($d->consent_reference) && filled($d->basis) && filled($d->destination) && $this->evidence->approved($d, 'deduction_authority'));
                $valid = $payout->entry_type === 'original' && ! $payout->reversals->contains('status', 'posted')
                    && $payout->posted_by && $payout->posted_at && $payout->paid_on && filled($payout->method) && filled($payout->reference)
                    && in_array($payout->payment_mode, ['school', 'direct'], true) && $ack && $this->evidence->approved($payout, 'payment_evidence')
                    && $validDeductions && Money::cents($payout->net_amount) + $deductions === Money::cents($payout->gross_amount)
                    && ($payout->payment_mode !== 'direct' || $deductions === 0) && Money::cents($payout->net_amount) > 0;
                if (! $valid || $applied < 0 || $applied > $this->cents($effective)) {
                    $exceptions->push($base + ['issue' => 'Payout #'.$payout->id.': signature/payment proof, positive student net, authorization, reversal or reconciliation needs review']);

                    continue;
                }
                $gross += Money::cents($payout->gross_amount);
                $net += Money::cents($payout->net_amount);
                if ($payout->payment_mode === 'school') {
                    $school += Money::cents($payout->net_amount);
                } else {
                    $direct += Money::cents($payout->net_amount);
                }
                $lines[] = 'Payout #'.$payout->id.' / '.$payout->institutional_student_id.' / '.$payout->payee.' / '.$payout->paid_on->toDateString().' / '.$payout->method.' / '.$payout->reference.' / gross '.$payout->gross_amount.' / net '.$payout->net_amount.' / '.$ack->document_reference.' / '.$ack->page_line.' / verified by '.$ack->verifiedBy->name.' at '.$ack->verified_at->timezone('Asia/Manila')->toIso8601String();
            }
            if ($lines !== []) {
                $rows->push($base + ['effective' => $effective, 'signed_gross' => Money::decimal($gross), 'signed_net' => Money::decimal($net), 'authorized_deductions' => Money::decimal($gross - $net), 'school_net' => Money::decimal($school), 'direct_net' => Money::decimal($direct),
                    'state' => $applied === $this->cents($effective) && $gross === $applied ? 'Benefit fully applied; all payment portions signed' : 'Signed payment portion only; review remaining benefit or excluded application exceptions', 'payments' => implode("\n", $lines)]);
            }
        }

        return ['rows' => $rows, 'columns' => $this->commonColumns() + ['billing' => 'Billing submission', 'billing_item' => 'Billing item', 'effective' => 'Effective benefit (PHP)', 'signed_gross' => 'Signed gross applied (PHP)', 'signed_net' => 'Signed student net (PHP)', 'authorized_deductions' => 'Authorized deductions (PHP; settlement separate)', 'school_net' => 'School student net (PHP)', 'direct_net' => 'Direct student net (PHP)', 'state' => 'Coverage', 'payments' => 'Exact payment and signed acknowledgment coverage'],
            'totals' => ['Signed gross applied (PHP)' => $this->total($rows, 'signed_gross'), 'Signed student net (PHP)' => $this->total($rows, 'signed_net'), 'Authorized deductions (PHP; settlement separate)' => $this->total($rows, 'authorized_deductions'), 'School student net (PHP)' => $this->total($rows, 'school_net'), 'Direct student net (PHP)' => $this->total($rows, 'direct_net')], 'exceptions' => $exceptions, 'stages' => collect()];
    }

    private function liquidated(array $filters): array
    {
        $rows = collect();
        $exceptions = collect();
        $reports = Liquidation::where('status', 'accepted')->whereHas('batch', fn ($q) => $this->submitted($this->scope($q, $filters)))
            ->with(['batch.program', 'batch.academicPeriod', 'batch.scholarshipBatch', 'items', 'reviewedBy'])->orderBy('billing_batch_id')->orderBy('id')->get();
        foreach ($reports as $report) {
            $batch = $report->batch;
            $base = ['program' => $batch->program->code, 'roster' => $batch->scholarshipBatch?->batch_number ?? 'Unlinked historical submission',
                'academic_year' => $batch->academicPeriod->academic_year, 'semester' => $batch->academicPeriod->semester_code, 'billing' => $batch->batch_number,
                '_roster_id' => $batch->scholarship_batch_id, '_billing_id' => $batch->id, '_url' => route('financial.liquidation', $report)];
            $issues = [];
            if (! $report->reviewed_by || ! $report->reviewed_at || ! $report->prepared_by || ! $report->submitted_by || ! $report->submitted_at || ! filled($report->reference) || ! filled($report->review_notes) || $report->reviewed_by === $report->prepared_by || $report->reviewed_by === $report->submitted_by || ! $report->compliance_snapshot) {
                $issues[] = 'Accepted history lacks confirmed checklist or independent decision provenance';
            }
            try {
                $summary = $this->liquidation->forBatch($batch, $report);
                $issues = array_merge($issues, $summary['issues']);
            } catch (\InvalidArgumentException $error) {
                $issues[] = 'Unconfirmed historical source amount requires review';
            }
            if ($issues !== []) {
                $exceptions->push($base + ['issue' => 'Accepted report #'.$report->id.': '.implode('; ', array_unique($issues))]);

                continue;
            }
            $rows->push($base + ['liquidation' => '#'.$report->id.' / '.$report->reference, 'reviewer' => $report->reviewedBy->name, 'accepted_at' => $report->reviewed_at->timezone('Asia/Manila')->toIso8601String(),
                '_student_ids' => $summary['items']->pluck('student_id')->unique()->all(), '_grant_keys' => $summary['items']->map(fn ($i) => $i->student_id.':'.$i->program_id.':'.$i->academic_period_id)->unique()->all(),
                'covered' => Money::decimal(Money::signedTotal($report->items)), 'school_net' => $summary['school_net'], 'direct_net' => $summary['direct_net'], 'settled' => $summary['settled']]);
        }

        return ['rows' => $rows, 'columns' => $this->commonColumns(false) + ['billing' => 'Billing submission', 'liquidation' => 'Accepted liquidation', 'reviewer' => 'Independent reviewer', 'accepted_at' => 'Acceptance time', 'covered' => 'Covered net + settled deductions (PHP)', 'school_net' => 'School student net (PHP)', 'direct_net' => 'Direct student net (PHP)', 'settled' => 'Settled deductions (PHP)'],
            'totals' => ['Recorded accepted billing histories' => $reports->pluck('billing_batch_id')->unique()->count(), 'Currently verified liquidated billings' => $rows->pluck('_billing_id')->unique()->count(), 'Verified liquidated coverage (PHP)' => $this->total($rows, 'covered')], 'exceptions' => $exceptions, 'stages' => collect()];
    }
}
