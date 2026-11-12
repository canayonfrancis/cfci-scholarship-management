<?php

namespace Tests\Feature\Billing;

use App\Models\AcademicPeriod;
use App\Models\BillingBatch;
use App\Models\ProgramPeriodTerm;
use App\Models\ScholarshipProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingBatchOptionsTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $actor = User::factory()->create();
        $actor->roles()->create(['role' => 'coordinator']);
        $this->actingAs($actor);
        $alpha = ScholarshipProgram::create(['code' => 'ALPHA', 'name' => 'Synthetic Alpha', 'is_active' => true]);
        $zeta = ScholarshipProgram::create(['code' => 'ZETA', 'name' => 'Synthetic Zeta', 'is_active' => true]);
        $first = AcademicPeriod::create(['academic_year' => '2026-2027', 'semester_code' => '1']);
        $second = AcademicPeriod::create(['academic_year' => '2026-2027', 'semester_code' => '2']);
        $nextYear = AcademicPeriod::create(['academic_year' => '2027-2028', 'semester_code' => '1']);
        $zetaOne = $this->batch($zeta, $first, 'Batch 1');
        $alphaTen = $this->batch($alpha, $first, 'Batch 10');
        $alphaTwo = $this->batch($alpha, $first, 'Batch 2');
        $alphaOne = $this->batch($alpha, $first, 'Batch 1');
        $alphaSecond = $this->batch($alpha, $second, 'Batch 1');
        $alphaNext = $this->batch($alpha, $nextYear, 'Batch 1');

        return compact('alpha', 'zeta', 'first', 'second', 'nextYear', 'zetaOne', 'alphaTen', 'alphaTwo', 'alphaOne', 'alphaSecond', 'alphaNext');
    }

    private function batch(ScholarshipProgram $program, AcademicPeriod $period, string $label): BillingBatch
    {
        $terms = ProgramPeriodTerm::firstOrCreate(['program_id' => $program->id, 'academic_period_id' => $period->id]);

        return $program->billingBatches()->create(['program_period_term_id' => $terms->id, 'academic_period_id' => $period->id, 'batch_number' => $label, 'prepared_by' => auth()->id()]);
    }

    public function test_options_use_real_ids_related_codes_natural_number_sort_and_distinct_period_labels(): void
    {
        $g = $this->fixtures();
        $expected = [
            ['id' => $g['alphaNext']->id, 'label' => 'Batch 1 - ALPHA (2027-2028 / Semester 1)'],
            ['id' => $g['alphaOne']->id, 'label' => 'Batch 1 - ALPHA (2026-2027 / Semester 1)'],
            ['id' => $g['alphaSecond']->id, 'label' => 'Batch 1 - ALPHA (2026-2027 / Semester 2)'],
            ['id' => $g['zetaOne']->id, 'label' => 'Batch 1 - ZETA'],
            ['id' => $g['alphaTwo']->id, 'label' => 'Batch 2 - ALPHA'],
            ['id' => $g['alphaTen']->id, 'label' => 'Batch 10 - ALPHA'],
        ];
        $this->getJson(route('billing-batches.options'))->assertOk()->assertExactJson(['options' => $expected])->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('billing-batches.index'))->assertOk()->assertViewHas('batchOptions', fn ($options) => $options->all() === $expected)->assertSee('Batch 10 - ALPHA')->assertDontSee('Batch Batch')->assertDontSee('Batch 20');
    }

    public function test_program_year_semester_and_status_filters_scope_both_options_and_monitoring_records(): void
    {
        $g = $this->fixtures();
        foreach ([
            [['program_id' => $g['zeta']->id], [$g['zetaOne']->id]],
            [['academic_year' => '2027-2028'], [$g['alphaNext']->id]],
            [['academic_period_id' => $g['second']->id], [$g['alphaSecond']->id]],
            [['program_id' => $g['alpha']->id, 'academic_year' => '2026-2027', 'academic_period_id' => $g['first']->id], [$g['alphaOne']->id, $g['alphaTwo']->id, $g['alphaTen']->id]],
            [['status' => 'submitted'], []],
        ] as [$scope, $ids]) {
            $response = $this->getJson(route('billing-batches.options', $scope))->assertOk();
            $this->assertSame($ids, array_column($response->json('options'), 'id'));
            $this->get(route('billing-batches.index', $scope))->assertOk()->assertViewHas('batches', function ($batches) use ($ids) {
                return $batches->getCollection()->pluck('id')->sort()->values()->all() === collect($ids)->sort()->values()->all();
            });
        }
        $this->getJson(route('billing-batches.options', ['academic_period_id' => $g['second']->id]))->assertJsonPath('options.0.label', 'Batch 1 - ALPHA');
    }

    public function test_selection_uses_batch_id_and_survives_filter_validation_errors_without_changing_history(): void
    {
        $g = $this->fixtures();
        $before = BillingBatch::orderBy('id')->get()->toArray();
        $scope = ['program_id' => $g['alpha']->id, 'academic_period_id' => $g['first']->id];
        $selected = $g['alphaOne'];
        $this->assertNotSame(1, $selected->id);
        $this->get(route('billing-batches.index', $scope + ['billing_batch_id' => $selected->id]))->assertOk()->assertSee('value="'.$selected->id.'" selected', false)->assertViewHas('batches', fn ($batches) => $batches->getCollection()->modelKeys() === [$selected->id]);
        $from = route('billing-batches.index', $scope);
        $this->from($from)->get(route('billing-batches.index', $scope + ['billing_batch_id' => $selected->id, 'academic_year' => str_repeat('x', 31)]))->assertSessionHasErrors('academic_year');
        $this->get($from)->assertOk()->assertSee('value="'.$selected->id.'" selected', false);
        $this->assertSame($before, BillingBatch::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertDatabaseCount('scholarship_rates', 0);
        $this->assertDatabaseCount('funding_receipts', 0);
        $this->assertDatabaseCount('payouts', 0);
    }

    public function test_incompatible_missing_and_malformed_batch_ids_cannot_be_selected_or_used_to_create_records(): void
    {
        $g = $this->fixtures();
        foreach ([
            ['program_id' => $g['zeta']->id],
            ['academic_period_id' => $g['second']->id],
            ['academic_year' => '2027-2028'],
            ['status' => 'submitted'],
        ] as $scope) {
            $this->getJson(route('billing-batches.index', $scope + ['billing_batch_id' => $g['alphaOne']->id]))->assertUnprocessable()->assertJsonValidationErrors('billing_batch_id');
        }
        foreach ([999999, 'Batch 1', ['invalid']] as $id) {
            $this->getJson(route('billing-batches.index', ['billing_batch_id' => $id]))->assertUnprocessable()->assertJsonValidationErrors('billing_batch_id');
        }
        $scope = ['program_id' => $g['alpha']->id, 'academic_period_id' => $g['first']->id, 'billing_batch_id' => $g['zetaOne']->id];
        $this->postJson(route('billing-batches.store'), $scope)->assertUnprocessable()->assertJsonValidationErrors('billing_batch_id');
        $programData = ['code' => 'NEWCODE', 'name' => 'Synthetic new program', 'is_active' => 1, 'batch_program_id' => $g['alpha']->id, 'academic_period_id' => $g['first']->id, 'billing_batch_id' => $g['zetaOne']->id];
        $this->postJson(route('scholarship-programs.store'), $programData)->assertUnprocessable()->assertJsonValidationErrors('billing_batch_id');
        $this->patchJson(route('scholarship-programs.update', $g['alpha']), array_replace($programData, ['code' => $g['alpha']->code]))->assertUnprocessable()->assertJsonValidationErrors('billing_batch_id');
        $this->assertDatabaseCount('billing_batches', 6);
        $this->assertDatabaseCount('scholarship_programs', 2);
        $this->assertSame($g['alpha']->id, $g['alphaOne']->fresh()->program_id);
        $this->assertSame($g['first']->id, $g['alphaOne']->fresh()->academic_period_id);
    }

    public function test_empty_scope_has_clear_message_and_legacy_batch_labels_remain_intact(): void
    {
        $g = $this->fixtures();
        $this->get(route('billing-batches.index', ['program_id' => $g['zeta']->id, 'academic_period_id' => $g['second']->id]))->assertOk()->assertSee('No matching billing batches')->assertSee('No matching billing batches exist for these filters.');
        $numeric = $this->batch($g['zeta'], $g['second'], '42');
        $legacy = $this->batch($g['zeta'], $g['second'], 'Batch 01');
        $custom = $this->batch($g['zeta'], $g['second'], 'Supplemental <review>');
        $this->getJson(route('billing-batches.options', ['academic_period_id' => $g['second']->id]))->assertOk()->assertJsonFragment(['id' => $numeric->id, 'label' => 'Batch 42 - ZETA'])->assertJsonFragment(['id' => $custom->id, 'label' => 'Supplemental <review> - ZETA']);
        $this->get(route('billing-batches.index', ['academic_period_id' => $g['second']->id]))->assertSee('Supplemental &lt;review&gt; - ZETA', false);
        $this->assertSame([$g['alphaSecond']->id, $legacy->id, $numeric->id, $custom->id], array_column($this->getJson(route('billing-batches.options', ['academic_period_id' => $g['second']->id]))->json('options'), 'id'));
        $this->assertSame('42', $numeric->fresh()->batch_number);
        $this->assertSame('Batch 01', $legacy->fresh()->batch_number);
        $this->assertSame('Supplemental <review>', $custom->fresh()->batch_number);
    }

    public function test_add_program_contains_real_batch_options_inside_the_existing_form_and_filters_by_reference_scope(): void
    {
        $g = $this->fixtures();
        $response = $this->get(route('scholarship-programs.create'))->assertOk()->assertSee('Batch Number')->assertSee('Select Billing Batch')->assertSee('Batch 1 - ALPHA (2027-2028 / Semester 1)')->assertSee('Batch 1 - ZETA')->assertDontSee('Batch 20');
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $fields = $xpath->query('//form[@method="POST"]//select[@name="billing_batch_id"]');
        $this->assertCount(1, $fields);
        $this->assertSame(route('scholarship-programs.store'), $xpath->evaluate('string(ancestor::form[1]/@action)', $fields->item(0)));
        foreach ([
            [['batch_program_id' => $g['zeta']->id], [$g['zetaOne']->id]],
            [['academic_period_id' => $g['second']->id], [$g['alphaSecond']->id]],
            [['academic_period_id' => $g['nextYear']->id], [$g['alphaNext']->id]],
            [['batch_program_id' => $g['alpha']->id, 'academic_period_id' => $g['first']->id], [$g['alphaOne']->id, $g['alphaTwo']->id, $g['alphaTen']->id]],
        ] as [$scope, $ids]) {
            $this->get(route('scholarship-programs.create', $scope))->assertOk()->assertViewHas('batchOptions', fn ($options) => $options->pluck('id')->all() === $ids);
        }
    }

    public function test_valid_context_saves_only_the_new_program_without_reassigning_or_copying_existing_batches(): void
    {
        $g = $this->fixtures();
        $before = BillingBatch::orderBy('id')->get()->toArray();
        $context = ['batch_program_id' => $g['alpha']->id, 'academic_period_id' => $g['first']->id, 'billing_batch_id' => $g['alphaOne']->id];
        $this->post(route('scholarship-programs.store'), ['code' => 'NEWREF', 'name' => 'Synthetic new program with reference', 'is_active' => 1] + $context)->assertSessionHasNoErrors()->assertRedirect(route('scholarship-programs.show', ScholarshipProgram::where('code', 'NEWREF')->sole()));
        $program = ScholarshipProgram::where('code', 'NEWREF')->sole();
        foreach (array_keys($context) as $field) {
            $this->assertArrayNotHasKey($field, $program->getAttributes());
        }
        $this->assertSame(0, $program->billingBatches()->count());
        $this->assertSame(0, $program->terms()->count());
        $this->assertSame($before, BillingBatch::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('billing_batches', 6);
        $this->assertDatabaseCount('scholarship_programs', 3);
        $this->assertDatabaseCount('scholarship_rates', 0);
        $this->assertDatabaseCount('funding_receipts', 0);
        $this->assertDatabaseCount('payouts', 0);
    }

    public function test_program_reference_rejects_wrong_program_period_missing_scope_and_invalid_ids_without_partial_saves(): void
    {
        $g = $this->fixtures();
        $data = ['code' => 'INVALIDREF', 'name' => 'Synthetic invalid reference', 'is_active' => 1, 'batch_program_id' => $g['alpha']->id, 'academic_period_id' => $g['first']->id, 'billing_batch_id' => $g['alphaOne']->id];
        foreach ([
            [['batch_program_id' => $g['zeta']->id], 'billing_batch_id'],
            [['academic_period_id' => $g['second']->id], 'billing_batch_id'],
            [['academic_period_id' => $g['nextYear']->id], 'billing_batch_id'],
            [['billing_batch_id' => 999999], 'billing_batch_id'],
            [['billing_batch_id' => 'Batch 1'], 'billing_batch_id'],
            [['billing_batch_id' => ['invalid']], 'billing_batch_id'],
            [['batch_program_id' => null], 'batch_program_id'],
            [['batch_program_id' => 999999], 'batch_program_id'],
            [['academic_period_id' => null], 'academic_period_id'],
        ] as [$overrides, $field]) {
            $this->postJson(route('scholarship-programs.store'), array_replace($data, $overrides))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('scholarship_programs', 2);
        $this->assertDatabaseCount('billing_batches', 6);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_add_program_retains_valid_context_on_form_errors_and_handles_malformed_old_input(): void
    {
        $g = $this->fixtures();
        $context = ['batch_program_id' => $g['alpha']->id, 'academic_period_id' => $g['first']->id, 'billing_batch_id' => $g['alphaOne']->id];
        $create = route('scholarship-programs.create');
        $this->from($create)->post(route('scholarship-programs.store'), ['code' => 'VALIDREF', 'name' => '', 'is_active' => 1] + $context)->assertSessionHasErrors('name');
        $this->get($create)->assertOk()->assertSee('value="'.$g['alphaOne']->id.'" selected', false)->assertViewHas('batchContext', fn ($actual) => $actual === $context)->assertViewHas('batchOptions', fn ($options) => $options->pluck('id')->all() === [$g['alphaOne']->id, $g['alphaTwo']->id, $g['alphaTen']->id]);
        $this->from($create)->post(route('scholarship-programs.store'), ['code' => 'VALIDREF', 'name' => '', 'is_active' => 1, 'batch_program_id' => ['invalid'], 'academic_period_id' => ['invalid'], 'billing_batch_id' => ['invalid']])->assertSessionHasErrors(['batch_program_id', 'academic_period_id', 'billing_batch_id']);
        $this->get($create)->assertOk()->assertSee('Select Billing Batch');
        $this->assertDatabaseCount('scholarship_programs', 2);
    }

    public function test_shared_edit_form_shows_own_batches_retains_selection_and_never_changes_ownership(): void
    {
        $g = $this->fixtures();
        $before = BillingBatch::orderBy('id')->get()->toArray();
        $edit = route('scholarship-programs.edit', $g['alpha']);
        $this->get($edit)->assertOk()->assertSee('<select id="billing_batch_id" name="billing_batch_id"', false)->assertViewHas('batchOptions', fn ($options) => ! $options->pluck('id')->contains($g['zetaOne']->id));
        $data = ['code' => $g['alpha']->code, 'name' => 'Synthetic edited Alpha', 'is_active' => 1, 'batch_program_id' => $g['alpha']->id, 'academic_period_id' => $g['first']->id, 'billing_batch_id' => $g['alphaOne']->id];
        $this->from($edit)->patch(route('scholarship-programs.update', $g['alpha']), array_replace($data, ['name' => '']))->assertSessionHasErrors('name');
        $this->get($edit)->assertOk()->assertSee('value="'.$g['alphaOne']->id.'" selected', false);
        $this->patchJson(route('scholarship-programs.update', $g['alpha']), array_replace($data, ['billing_batch_id' => $g['zetaOne']->id]))->assertUnprocessable()->assertJsonValidationErrors('billing_batch_id');
        $this->patchJson(route('scholarship-programs.update', $g['alpha']), array_replace($data, ['batch_program_id' => $g['zeta']->id, 'billing_batch_id' => $g['zetaOne']->id]))->assertUnprocessable()->assertJsonValidationErrors('batch_program_id');
        $this->patch(route('scholarship-programs.update', $g['alpha']), $data)->assertSessionHasNoErrors();
        $this->assertSame('Synthetic edited Alpha', $g['alpha']->fresh()->name);
        $this->assertArrayNotHasKey('billing_batch_id', $g['alpha']->fresh()->getAttributes());
        $this->assertSame($before, BillingBatch::orderBy('id')->get()->toArray());
    }

    public function test_option_endpoint_requires_authorized_staff_and_validates_scope(): void
    {
        $this->get(route('billing-batches.options'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->getJson(route('billing-batches.options'))->assertForbidden();
        $g = $this->fixtures();
        foreach (['administrator', 'registrar', 'coordinator', 'finance', 'viewer'] as $role) {
            $user = User::factory()->create();
            $user->roles()->create(['role' => $role]);
            $this->actingAs($user)->getJson(route('billing-batches.options'))->assertOk()->assertJsonCount(6, 'options');
        }
        foreach ([['program_id' => 999999], ['academic_period_id' => 999999], ['status' => 'invalid'], ['academic_year' => ['invalid']]] as $scope) {
            $this->getJson(route('billing-batches.options', $scope))->assertUnprocessable()->assertJsonValidationErrors(array_keys($scope));
        }
    }
}
