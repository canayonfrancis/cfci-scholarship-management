<?php

namespace Tests\Feature;

use App\Models\BillingItem;
use App\Models\ScholarshipBatch;
use App\Models\Student;
use App\Models\User;
use App\Support\TablePagination;
use App\Support\TableSort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsScholarshipRecords;
use Tests\TestCase;

class TableControlsTest extends TestCase
{
    use BuildsScholarshipRecords, RefreshDatabase;

    public function test_sorting_runs_before_pagination_and_preserves_scope_filters_and_links(): void
    {
        $this->scholars(210);
        $this->actingAs($this->staff());
        $parameters = ['q' => 'Table', 'tables' => ['scholars' => ['size' => 10, 'sort' => ['column' => 0, 'direction' => 'desc']]]];
        $response = $this->get(route('scholars.index', $parameters))->assertOk()
            ->assertViewHas('scholars', fn ($rows) => $rows->total() === 210 && $rows->first()->student_number === 'TABLE-210' && $rows->last()->student_number === 'TABLE-201');
        parse_str(parse_url($response->viewData('scholars')->nextPageUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('desc', $query['tables']['scholars']['sort']['direction']);
        $this->assertSame('Table', $query['q']);
        $this->get(route('scholars.index', $parameters + ['page' => 2]))->assertOk()
            ->assertViewHas('scholars', fn ($rows) => $rows->first()->student_number === 'TABLE-200');
        $parameters['tables']['scholars']['sort']['direction'] = 'asc';
        $parameters['tables']['scholars']['filters'] = [0 => 'TABLE-20'];
        $this->get(route('scholars.index', $parameters))->assertOk()
            ->assertViewHas('scholars', fn ($rows) => $rows->total() === 10 && $rows->first()->student_number === 'TABLE-200' && $rows->last()->student_number === 'TABLE-209');
    }

    public function test_sorting_compares_displayed_numbers_and_dates_and_keeps_unknown_values_last(): void
    {
        $this->assertLessThan(0, TableSort::compare('Scholar 2', 'Scholar 10'));
        $this->assertGreaterThan(0, TableSort::compare('Scholar 2', 'Scholar 10', 'desc'));
        $this->assertLessThan(0, TableSort::compare('₱950.00', '₱1,000.00'));
        $this->assertLessThan(0, TableSort::compare('100.25', '100.50'));
        $this->assertLessThan(0, TableSort::compare('Dec 31, 2025', 'Jan 1, 2026'));
        $this->assertLessThan(0, TableSort::compare('2026-01-02', '2025-12-31', 'desc'));
        $this->assertLessThan(0, TableSort::compare('2026-01-02T08:00:00+08:00', '2026-01-02T01:00:00Z'));
        $this->assertSame(0, TableSort::compare('ANA', 'ana'));
        $this->assertGreaterThan(0, TableSort::compare('Unconfirmed', '₱100.00', 'desc'));
        $this->assertGreaterThan(0, TableSort::compare('Not recorded', 'Jan 1, 2026'));
    }

    public function test_invalid_sort_parameters_are_ignored(): void
    {
        $this->scholars(12);
        $this->actingAs($this->staff());
        foreach (['bad', ['column' => 'student_number', 'direction' => 'asc'], ['column' => 0, 'direction' => 'sideways'], ['column' => [], 'direction' => []], ['direction' => 'asc']] as $sort) {
            $this->get(route('scholars.index', ['tables' => ['scholars' => ['sort' => $sort]]]))->assertOk()
                ->assertViewHas('scholars', fn ($rows) => $rows->first()->student_number === 'TABLE-001' && $rows->tableSettings['sort'] === null);
        }
    }

    private function staff(): User
    {
        $user = User::factory()->create();
        $user->roles()->create(['role' => 'administrator']);

        return $user;
    }

    private function scholars(int $count): void
    {
        foreach (range(1, $count) as $index) {
            Student::create(['student_number' => 'TABLE-'.sprintf('%03d', $index), 'first_name' => 'Table', 'last_name' => sprintf('Scholar%03d', $index), 'record_status' => 'active']);
        }
    }

    public function test_every_requested_page_size_and_pagination_links_work(): void
    {
        $this->scholars(205);
        $this->actingAs($this->staff());
        foreach (TablePagination::SIZES as $size) {
            $response = $this->get(route('scholars.index', ['record_status' => 'active', 'tables' => ['scholars' => ['size' => $size]]]))->assertOk();
            $response->assertViewHas('scholars', fn ($rows) => $rows->count() === $size && $rows->total() === 205 && $rows->perPage() === $size);
            $paginator = $response->viewData('scholars');
            parse_str(parse_url($paginator->nextPageUrl(), PHP_URL_QUERY), $parameters);
            $this->assertSame((string) $size, $parameters['tables']['scholars']['size']);
            $this->assertSame('active', $parameters['record_status']);
        }
    }

    public function test_combined_header_filters_search_beyond_the_first_database_chunk(): void
    {
        $this->scholars(210);
        $this->actingAs($this->staff());
        $parameters = ['q' => 'Table', 'tables' => ['scholars' => ['size' => 20, 'filters' => [0 => 'TABLE-21', 5 => 'ACTIVE']]]];
        $this->get(route('scholars.index', $parameters))->assertOk()
            ->assertViewHas('scholars', fn ($rows) => $rows->total() === 1 && $rows->first()->student_number === 'TABLE-210')
            ->assertSee('data-table-state', false);
        $parameters['tables']['scholars']['filters'][6] = 'Complete profile that does not exist';
        $this->get(route('scholars.index', $parameters))->assertOk()
            ->assertViewHas('scholars', fn ($rows) => $rows->total() === 0)
            ->assertSeeText('No scholars found');
    }

    public function test_malformed_options_fall_back_and_filter_metacharacters_are_literal(): void
    {
        $this->scholars(12);
        Student::create(['student_number' => '100%_LITERAL', 'first_name' => 'Literal', 'last_name' => 'Scholar']);
        $this->actingAs($this->staff());
        foreach (['bad', ['size' => 999, 'filters' => 'bad'], ['size' => [], 'filters' => [0 => ['bad']]]] as $invalid) {
            $this->get(route('scholars.index', ['tables' => ['scholars' => $invalid]]))->assertOk()
                ->assertViewHas('scholars', fn ($rows) => $rows->perPage() === 10 && $rows->total() === 13);
        }
        $this->get(route('scholars.index', ['tables' => ['scholars' => ['filters' => [0 => '%_']]]]))->assertOk()
            ->assertViewHas('scholars', fn ($rows) => $rows->total() === 1 && $rows->first()->student_number === '100%_LITERAL');
    }

    public function test_paginated_modules_filter_their_displayed_columns(): void
    {
        $g = $this->applicationContext();
        $item = BillingItem::create($this->billingItemData($g) + ['gross_amount' => '7500.00']);
        $roster = ScholarshipBatch::create(['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'batch_number' => 'TABLE-ROSTER', 'created_by' => $g['actor']->id]);
        $roster->memberships()->create(['student_id' => $g['student']->id, 'assignment_id' => $g['assignment']->id, 'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'assigned_by' => $g['actor']->id, 'assigned_at' => now()]);
        $this->actingAs($this->staff());
        $cases = [
            ['scholarship-programs.index', [], 'programs', 'programs', 0, 'TDP'],
            ['scholarship-programs.show', ['program' => $g['program']], 'program_assignments', 'assignments', 3, 'Active'],
            ['scholarship-programs.show', ['program' => $g['program']], 'program_assignments', 'assignments', 2, '2026-2027 · 1'],
            ['academic-periods.index', [], 'periods', 'periods', 0, '2026-2027'],
            ['academic-periods.show', ['period' => $g['period']], 'period_assignments', 'assignments', 2, 'TDP'],
            ['academic-verifications.index', [], 'academic_records', 'records', 2, 'Latest revision'],
            ['evaluations.index', [], 'evaluations', 'applications', 0, 'Test Scholar'],
            ['scholarship-batches.index', [], 'rosters', 'batches', 3, '1'],
            ['scholarship-batches.show', ['batch' => $roster], 'members', 'members', 4, 'Unbilled'],
            ['billing-batches.index', [], 'billing_batches', 'batches', 5, '7,500.00'],
            ['billing-batches.index', [], 'budget_scopes', 'scopes', 0, 'TDP'],
            ['billing-batches.show', ['batch' => $g['batch']], 'billing_items', 'items', 2, '7,500.00'],
            ['billing-batches.report', [], 'allocations', 'items', 5, '7,500.00'],
            ['financial.funding-status', [], 'funding_status', 'rows', 2, 'Draft'],
        ];
        foreach ($cases as [$route, $arguments, $table, $variable, $column, $value]) {
            $parameters = $arguments + ['tables' => [$table => ['size' => 20, 'filters' => [$column => $value]]]];
            $this->get(route($route, $parameters))->assertOk()->assertViewHas($variable, fn ($rows) => $rows->total() === 1 && $rows->perPage() === 20);
            $parameters['tables'][$table]['filters'][$column] = 'Nonexistent table value';
            $this->get(route($route, $parameters))->assertOk()->assertViewHas($variable, fn ($rows) => $rows->total() === 0);
        }
        $this->assertSame('7500.00', $item->fresh()->gross_amount);
    }

    public function test_multiple_tables_keep_independent_filters_sizes_and_page_names(): void
    {
        $g = $this->financialContext();
        $this->actingAs($this->staff());
        $parameters = ['tables' => [
            'financial_batches' => ['size' => 20, 'filters' => [1 => 'Not a batch']],
            'receipts' => ['size' => 50, 'filters' => [3 => '7500']],
        ]];
        $this->get(route('financial.index', $parameters))->assertOk()
            ->assertViewHas('batches', fn ($rows) => $rows->total() === 0 && $rows->perPage() === 20 && $rows->getPageName() === 'batch_page')
            ->assertViewHas('receipts', fn ($rows) => $rows->total() === 1 && $rows->perPage() === 50 && $rows->getPageName() === 'receipt_page');
        $this->assertSame('7500.00', $g['receipt']->fresh()->amount);
    }

    public function test_report_header_filters_keep_whole_report_totals_and_exports(): void
    {
        $g = $this->applicationContext();
        $this->actingAs($this->staff());
        $roster = ScholarshipBatch::create(['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'batch_number' => 'TABLE-REPORT', 'created_by' => $g['actor']->id]);
        $roster->memberships()->create(['student_id' => $g['student']->id, 'assignment_id' => $g['assignment']->id, 'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'assigned_by' => $g['actor']->id, 'assigned_at' => now()]);
        $parameters = ['report' => 'grants', 'tables' => ['report_rows' => ['size' => 100, 'filters' => [0 => 'No such record']]]];
        $this->get(route('scholarship-reports.index', $parameters))->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 0 && $rows->perPage() === 100)
            ->assertViewHas('result', fn ($result) => $result['counts']['rows'] === 1);
        $csv = $this->get(route('scholarship-reports.index', $parameters + ['format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString($g['student']->student_number, $csv);
    }

    public function test_candidate_filters_include_computed_eligibility_reasons(): void
    {
        $g = $this->applicationContext();
        $roster = ScholarshipBatch::create(['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'batch_number' => 'TABLE-CANDIDATES', 'created_by' => $g['actor']->id]);
        $roster->memberships()->create(['student_id' => $g['student']->id, 'assignment_id' => $g['assignment']->id, 'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'assigned_by' => $g['actor']->id, 'assigned_at' => now()]);
        $g['batch']->update(['scholarship_batch_id' => $roster->id]);
        $this->actingAs($this->staff());
        $parameters = ['batch' => $g['batch'], 'tables' => ['candidates' => ['size' => 20, 'filters' => [0 => $g['student']->student_number]]]];
        $response = $this->get(route('billing-batches.show', $parameters))->assertOk()
            ->assertViewHas('candidates', fn ($rows) => $rows->total() === 1 && $rows->perPage() === 20 && $rows->getPageName() === 'candidates');
        $reason = $response->viewData('candidates')->first()['reason'];
        $this->assertNotEmpty($reason);
        $parameters['tables']['candidates']['filters'] = [2 => mb_substr($reason, 0, 100)];
        $this->get(route('billing-batches.show', $parameters))->assertOk()
            ->assertViewHas('candidates', fn ($rows) => $rows->total() === 1);
    }

    public function test_dashboard_supports_more_than_five_rows_and_retains_empty_filter_controls(): void
    {
        foreach (range(1, 7) as $index) {
            $g = $this->applicationContext();
        }
        $this->actingAs($this->staff());
        $this->get(route('dashboard'))->assertOk()
            ->assertViewHas('recentApplications', fn ($rows) => $rows->total() === 7 && $rows->count() === 7);
        $parameters = ['tables' => ['dashboard_validations' => ['size' => 20, 'filters' => [0 => $g['student']->student_number]]]];
        $this->get(route('dashboard', $parameters))->assertOk()
            ->assertViewHas('recentApplications', fn ($rows) => $rows->total() === 1 && $rows->perPage() === 20);
        $parameters['tables']['dashboard_validations']['filters'][0] = 'No such student';
        $this->get(route('dashboard', $parameters))->assertOk()
            ->assertViewHas('recentApplications', fn ($rows) => $rows->total() === 0)
            ->assertSeeText('No validations match these column filters.')
            ->assertSee('data-table-state', false);
    }
}
