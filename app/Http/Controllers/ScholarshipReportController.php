<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\BillingBatch;
use App\Models\ProgramPeriodTerm;
use App\Models\ScholarshipBatch;
use App\Models\ScholarshipProgram;
use App\Services\Reporting\ScholarshipReports;
use App\Support\TablePagination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ScholarshipReportController extends Controller
{
    public function index(Request $request, ScholarshipReports $reports, string $report = 'grants')
    {
        abort_unless(array_key_exists($report, ScholarshipReports::TITLES), 404);
        $roster = in_array($report, ['grants', 'not-billed'], true);
        if ($roster) {
            Gate::authorize('viewAny', ScholarshipBatch::class);
        } else {
            Gate::authorize('financial-view');
        }
        $filters = $request->validate([
            'program_id' => ['nullable', 'integer', 'exists:scholarship_programs,id'],
            'scholarship_batch_id' => ['nullable', 'integer', 'exists:scholarship_batches,id'],
            'billing_batch_id' => $roster ? ['prohibited'] : ['nullable', 'integer', 'exists:billing_batches,id'],
            'academic_period_id' => ['nullable', 'integer', 'exists:academic_periods,id'],
            'academic_year' => ['nullable', 'string', Rule::exists('academic_periods', 'academic_year')],
            'semester_code' => ['nullable', 'string', Rule::exists('academic_periods', 'semester_code')],
            'format' => ['nullable', Rule::in(['html', 'csv', 'print'])], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $this->validateScope($filters);
        $result = $reports->build($report, $filters);
        $demoScopes = ProgramPeriodTerm::with(['program', 'academicPeriod'])->where('criteria->demonstration', true)->get()
            ->mapWithKeys(fn ($terms) => [json_encode([$terms->program->code, (string) $terms->academicPeriod->academic_year, (string) $terms->academicPeriod->semester_code]) => true]);
        $result['demonstration'] = \App\Services\Eligibility\OfficialRuleCatalog::demonstrationDatabase()
            || $result['rows']->contains('program', \App\Services\Eligibility\OfficialRuleCatalog::DEMO_PROGRAM)
            || $result['rows']->contains(fn ($row) => $demoScopes->has(json_encode([$row['program'], (string) $row['academic_year'], (string) $row['semester']])));
        $result['filters'] = array_diff_key($filters, ['format' => true, 'page' => true]);
        if (($filters['format'] ?? null) === 'csv') {
            return $this->csv($result);
        }
        if (($filters['format'] ?? null) === 'print') {
            return view('scholarship-reports.print', ['result' => $result]);
        }

        return view('scholarship-reports.index', [
            'result' => $result, 'titles' => ScholarshipReports::TITLES,
            'rows' => TablePagination::collection($result['rows'], 'report_rows', columns: fn ($row) => array_map(fn ($key) => $row[$key] ?? 'Unconfirmed', array_keys($result['columns']))),
            'programs' => ScholarshipProgram::orderBy('code')->get(), 'rosters' => ScholarshipBatch::with(['program', 'academicPeriod'])->orderBy('program_id')->orderBy('batch_number')->get(),
            'billings' => $roster ? collect() : BillingBatch::with(['program', 'academicPeriod'])->orderByDesc('id')->get(),
            'periods' => AcademicPeriod::orderByDesc('academic_year')->orderBy('semester_code')->get(),
        ]);
    }

    private function validateScope(array $filters): void
    {
        $periods = AcademicPeriod::query();
        foreach (['academic_year', 'semester_code'] as $field) {
            $periods->when($filters[$field] ?? null, fn ($q, $v) => $q->where($field, $v));
        }
        $periods->when($filters['academic_period_id'] ?? null, fn ($q, $v) => $q->whereKey($v));
        $ids = $periods->pluck('id');
        if ($ids->isEmpty() && collect(['academic_year', 'semester_code', 'academic_period_id'])->contains(fn ($f) => filled($filters[$f] ?? null))) {
            throw ValidationException::withMessages(['academic_year' => 'The selected year and semester do not identify a registered academic period.']);
        }
        foreach (['scholarship_batch_id' => ScholarshipBatch::class, 'billing_batch_id' => BillingBatch::class] as $field => $model) {
            if (! filled($filters[$field] ?? null)) {
                continue;
            }
            $batch = $model::findOrFail($filters[$field]);
            if (! $ids->contains($batch->academic_period_id) || (filled($filters['program_id'] ?? null) && (int) $filters['program_id'] !== $batch->program_id)
                || ($field === 'billing_batch_id' && filled($filters['scholarship_batch_id'] ?? null) && (int) $filters['scholarship_batch_id'] !== $batch->scholarship_batch_id)) {
                throw ValidationException::withMessages([$field => 'The selected batch must match the program, recorded roster link and academic-period filters.']);
            }
        }
    }

    private function csv(array $result)
    {
        return response()->streamDownload(function () use ($result) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            $safe = fn ($value) => preg_match('/^[\s\x{FEFF}]*[=+@-]|^[\t\r\n]/u', (string) $value) ? "'".$value : $value;
            $write = fn ($values) => fputcsv($stream, array_map($safe, $values), ',', '"', '');
            $write(['CFCI scholarship report', $result['title']]);
            if ($result['demonstration']) {
                $write([\App\Services\Eligibility\OfficialRuleCatalog::DEMO_LABEL]);
            }
            $write(['Generated (Asia/Manila)', $result['generated_at']]);
            $write(['Definition', $result['definition']]);
            foreach ($result['filters'] as $key => $value) {
                $write(['Filter: '.$key, $value]);
            }
            foreach ($result['counts'] as $key => $value) {
                $write(['Whole-result count: '.$key, $value]);
            }
            foreach ($result['totals'] as $key => $value) {
                $write([$key, $value ?? 'Unconfirmed']);
            }
            $write([]);
            $write(array_values($result['columns']));
            foreach ($result['rows'] as $row) {
                $write(array_map(fn ($key) => $row[$key] ?? 'Unconfirmed', array_keys($result['columns'])));
            }
            $write([]);
            $write(['Program', 'Semester roster', 'Period', 'Listed rows', 'Distinct scholars in group']);
            foreach ($result['groups'] as $group) {
                $write(array_values($group));
            }
            if ($result['stages']->isNotEmpty()) {
                $write([]);
                $write(['Billing submission', 'Funding stage', 'Effective batch gross (PHP)', 'Usable school allocation (PHP)', 'Previously posted direct gross (PHP)', 'Unpaid benefit (PHP)', 'Batch shortfall (PHP)', 'Exceptions']);
                foreach ($result['stages'] as $stage) {
                    $write([$stage['batch']->batch_number, $stage['label'], $stage['effective'] ?? 'Unconfirmed', $stage['usable_funding'], $stage['direct_applied'] ?? 'Unconfirmed', $stage['outstanding'] ?? 'Unconfirmed', $stage['shortfall'] ?? 'Unconfirmed', implode('; ', $stage['issues'])]);
                }
            }
            if ($result['exceptions']->isNotEmpty()) {
                $write([]);
                $write(['Exceptions excluded from verified report totals']);
                $write(['Program', 'Roster', 'Academic year', 'Semester', 'Billing submission', 'Issue']);
                foreach ($result['exceptions'] as $row) {
                    $write([$row['program'], $row['roster'], $row['academic_year'], $row['semester'], $row['billing'], $row['issue']]);
                }
            }
            fclose($stream);
        }, 'cfci-'.$result['report'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
