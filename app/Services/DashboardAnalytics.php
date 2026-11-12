<?php

namespace App\Services;

use App\Actions\Billing\RecordFinancialActivity;
use App\Models\AcademicPeriod;
use App\Models\BillingBatch;
use App\Models\BillingItem;
use App\Models\FundingReceipt;
use App\Models\Payout;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipAssignment;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Services\Billing\LiquidationSummary;
use App\Services\Billing\Money;
use App\Services\Eligibility\PrivateEvidence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only dashboard counts without multi-child aggregate joins. */
class DashboardAnalytics
{
    public function build(array $filters, bool $financial = true): array
    {
        return DB::transaction(fn () => $this->snapshot($filters, $financial));
    }

    private function snapshot(array $f, bool $financial): array
    {
        $periods = AcademicPeriod::when($f['academic_year'] ?? null, fn ($q, $v) => $q->where('academic_year', $v))
            ->when($f['semester_code'] ?? null, fn ($q, $v) => $q->where('semester_code', $v))->pluck('id');
        $periodFilter = filled($f['academic_year'] ?? null) || filled($f['semester_code'] ?? null);
        $scope = function (Builder $q) use ($f, $periods, $periodFilter) {
            $q->when($f['program_id'] ?? null, fn ($q, $v) => $q->where('program_id', $v));
            if ($periodFilter) {
                $q->whereIn('academic_period_id', $periods);
            }
        };
        $assignments = ScholarshipAssignment::with(['student', 'program', 'startPeriod', 'endPeriod']);
        if ($f['program_id'] ?? null) {
            $assignments->where('program_id', $f['program_id']);
        }
        if ($periodFilter) {
            $assignments->where(function ($q) use ($periods) {
                $q->whereIn('start_period_id', $periods)->orWhereIn('end_period_id', $periods)->orWhereExists(function ($q) use ($periods) {
                    $q->selectRaw('1')->from('academic_periods as rp')->join('academic_periods as sp', 'sp.id', '=', 'scholarship_assignments.start_period_id')
                        ->leftJoin('academic_periods as ep', 'ep.id', '=', 'scholarship_assignments.end_period_id')->whereIn('rp.id', $periods)
                        ->whereIn('rp.semester_code', ['1', '2'])->whereIn('sp.semester_code', ['1', '2'])
                        ->whereRaw('(sp.academic_year < rp.academic_year OR (sp.academic_year = rp.academic_year AND sp.semester_code <= rp.semester_code))')
                        ->whereRaw('(ep.id IS NULL OR (ep.semester_code IN (\'1\',\'2\') AND (ep.academic_year > rp.academic_year OR (ep.academic_year = rp.academic_year AND ep.semester_code >= rp.semester_code))))');
                });
            });
        }
        $awards = $assignments->get();
        $contexts = ScholarshipApplication::with(['student', 'program', 'academicPeriod', 'latestEvaluation', 'externalApprovals' => fn ($q) => $q->where('scope', 'semester')->orderByDesc('id')])->where('status', '!=', 'withdrawn');
        $scope($contexts);
        $contexts = $contexts->get();
        $studentQuery = Student::with(['academicProgram', 'academicPeriod', 'activeAssignment.program']);
        if ($periodFilter || filled($f['program_id'] ?? null)) {
            $ids = $awards->pluck('student_id')->merge($contexts->pluck('student_id'));
            $billed = BillingItem::query();
            $scope($billed);
            $ids = $ids->merge($billed->pluck('student_id'));
            $studentQuery->where(function ($q) use ($ids, $periods, $f, $periodFilter) {
                $q->whereIn('id', $ids->unique());
                if (empty($f['program_id']) && $periodFilter) {
                    $q->orWhereIn('academic_period_id', $periods)->orWhereHas('academicRecords', fn ($q) => $q->whereIn('academic_period_id', $periods));
                }
            });
        }
        $students = $studentQuery->orderBy('last_name')->orderBy('first_name')->orderBy('id')->get();
        $active = $awards->where('status', 'active')->filter(fn ($a) => $a->student->record_status === 'active');
        $evaluationRows = $contexts->map(function ($app) {
            $run = $app->latestEvaluation;
            $current = $run?->isCurrent() ?? false;
            $state = ! $run ? 'Not evaluated' : ($current && $app->student->record_status === 'active' && $run->automatic_outcome === 'passed' && $run->school_decision === 'qualified' ? 'School eligible' : ($current && $run->school_decision === 'not_qualified' ? 'School ineligible' : 'Pending review'));

            return ['id' => $app->id, 'student_id' => $app->student_id, 'student_number' => $app->student->student_number, 'name' => $app->student->full_name,
                'program' => $app->program->code, 'year' => $app->academicPeriod->academic_year, 'semester' => $app->academicPeriod->semester_code,
                'state' => $state, 'external' => $this->externalState($app), 'run_number' => $run?->run_number,
                'recorded_at' => $run?->completed_at ?? $app->created_at, 'historical_outcome' => $run?->automatic_outcome,
                'current' => $current, 'application' => $app];
        });
        $programs = ScholarshipProgram::when($f['program_id'] ?? null, fn ($q, $v) => $q->whereKey($v))->orderBy('code')->get()->map(function ($p) use ($active) {
            $count = $active->where('program_id', $p->id)->pluck('student_id')->unique()->count();

            return ['name' => $p->code, 'count' => $count, 'percent' => $active->count() ? round($count * 100 / $active->count(), 2) : 0, 'shade' => '100'];
        });
        $data = ['students' => $students, 'awards' => $awards, 'active_awards' => $active, 'evaluations' => $evaluationRows, 'programs' => $programs,
            'metrics' => ['total_scholars' => $students->count(), 'active_awards' => $active->count(),
                'pending_evaluations' => $evaluationRows->whereIn('state', ['Not evaluated', 'Pending review'])->count(),
                'school_eligible' => $evaluationRows->where('state', 'School eligible')->pluck('student_id')->unique()->count(),
                'pending_external' => $evaluationRows->whereIn('external', ['Pending', 'Verification needed'])->count()]];
        if (! $financial) {
            return $data;
        }

        $batchQuery = BillingBatch::with(['program', 'academicPeriod', 'approval', 'liquidations']);
        $scope($batchQuery);
        $batches = $batchQuery->get();
        $itemQuery = BillingItem::with(['student', 'program', 'academicPeriod', 'batch.approval', 'adjustments']);
        $scope($itemQuery);
        $items = $itemQuery->get();
        $receipts = FundingReceipt::where('status', 'posted');
        $scope($receipts);
        $receipts = $receipts->get();
        $payments = Payout::with(['deductions', 'documents'])->where('status', 'posted')->whereIn('billing_batch_id', $batches->modelKeys())->get();
        $submitted = $items->filter(fn ($i) => in_array($i->batch->status, ['submitted', 'finalized'], true));
        $approved = $items->filter(fn ($i) => RecordFinancialActivity::approved($i->batch));
        $incomplete = $batches->filter(fn ($b) => RecordFinancialActivity::approved($b))->filter(function ($batch) {
            $accepted = $batch->liquidations->where('status', 'accepted')->sortByDesc('sequence')->first();

            return ! $accepted || ! app(LiquidationSummary::class)->forBatch($batch, $accepted)['complete'];
        });
        $data += ['batches' => $batches, 'items' => $items, 'receipts' => $receipts, 'payments' => $payments, 'incomplete_batches' => $incomplete];
        $data['metrics'] += ['billing_total' => $this->moneyTotal($submitted, 'gross_amount'), 'approved_effective' => $approved->contains(fn ($i) => $i->gross_amount === null) ? null : Money::decimal($approved->sum(fn ($i) => Money::cents($i->effectiveAmount()))),
            'school_funding' => Money::decimal(Money::signedTotal($receipts)), 'student_payouts' => Money::decimal(Money::signedTotal($payments, 'net_amount')),
            'school_payouts' => Money::decimal(Money::signedTotal($payments->filter(fn ($p) => $p->payment_mode !== 'direct'), 'net_amount')),
            'direct_payouts' => Money::decimal(Money::signedTotal($payments->where('payment_mode', 'direct'), 'net_amount')), 'incomplete_liquidations' => $incomplete->count()];

        return $data;
    }

    private function externalState(ScholarshipApplication $app): string
    {
        $approval = $app->externalApprovals->first();
        if (! $approval || $approval->decision === 'pending') {
            return 'Pending';
        }
        if ($approval->decision !== 'approved') {
            return ucfirst($approval->decision);
        }
        $today = now('Asia/Manila')->toDateString();

        return $app->assignment_id !== null && $approval->assignment_id === $app->assignment_id && $approval->academic_period_id === $app->academic_period_id && $approval->recorded_by && $approval->decided_on
            && filled($approval->reference) && filled($approval->authority) && ($app->program->expectedApprovalAuthority() === null || $approval->authority === $app->program->expectedApprovalAuthority())
            && $approval->decided_on->toDateString() <= $today && (! $approval->valid_from || $approval->valid_from->toDateString() <= $today) && (! $approval->valid_until || $approval->valid_until->toDateString() >= $today)
            && $approval->documents()->get()->contains(fn ($doc) => app(PrivateEvidence::class)->usable($doc)) ? 'Verified approved' : 'Verification needed';
    }

    public function moneyTotal(Collection $rows, string $field): ?string
    {
        return $rows->contains(fn ($row) => $row->{$field} === null) ? null : Money::decimal(Money::signedTotal($rows, $field));
    }
}
