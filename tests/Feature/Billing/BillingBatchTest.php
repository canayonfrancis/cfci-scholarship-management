<?php

namespace Tests\Feature\Billing;

use App\Actions\Records\RecordAudit;
use App\Models\AcademicRecord;
use App\Models\AcademicValidationContext;
use App\Models\AuditEvent;
use App\Models\BillingBatch;
use App\Models\BillingItem;
use App\Models\ExternalApproval;
use App\Models\User;
use App\Services\Billing\BillingEligibility;
use App\Services\Eligibility\OfficialRuleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsBillableAllocations;
use Tests\Concerns\BuildsScholarshipRecords;
use Tests\TestCase;

class BillingBatchTest extends TestCase
{
    use BuildsBillableAllocations, BuildsScholarshipRecords, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('scholarship');
    }

    public function test_financial_confirmation_is_separate_from_academic_policy_and_immutable(): void
    {
        $g = $this->ready();
        $terms = $g['terms'];
        $this->assertSame('7500.00', $terms->semester_gross_amount);
        $this->assertSame(BillingEligibility::POLICY, $terms->billing_policy);
        $this->assertSame(app(OfficialRuleCatalog::class)->forProgram('TDP'), $terms->criteria);
        $this->assertNotNull($terms->confirmed_at);
        $this->assertNotNull($terms->billing_confirmed_at);
        $this->assertNotSame($terms->confirmed_by, $terms->billing_confirmed_by);
        $this->assertDatabaseRejects(fn () => $terms->update(['semester_gross_amount' => '1.00']));
        $this->assertDatabaseRejects(fn () => $terms->update(['billing_source_reference' => 'Changed']));
        $this->assertDatabaseRejects(fn () => $terms->update(['criteria' => []]));
        $this->assertDatabaseRejects(fn () => $terms->delete());
        $this->assertDatabaseCount('funding_receipts', 0);
    }

    public function test_draft_creation_numbers_batches_and_rejects_unknown_amounts(): void
    {
        $g = $this->ready();
        $data = ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'status' => 'submitted', 'submitted_by' => auth()->id(), 'gross_amount' => '1.00'];
        $this->post(route('billing-batches.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $first = BillingBatch::latest('id')->firstOrFail();
        $this->assertSame('Batch 1', $first->batch_number);
        $this->assertSame('draft', $first->status);
        $this->assertSame(auth()->id(), $first->prepared_by);
        $this->assertNull($first->submitted_by);
        $this->post(route('billing-batches.store'), $data)->assertSessionHasNoErrors();
        $this->assertSame('Batch 2', BillingBatch::latest('id')->value('batch_number'));
        $unknown = $this->applicationContext('EDUKAR');
        $this->post(route('billing-batches.store'), ['program_id' => $unknown['program']->id, 'academic_period_id' => $unknown['period']->id])->assertSessionHasErrors('billing');
        $this->assertNull($unknown['terms']->fresh()->semester_gross_amount);
    }

    public static function amounts(): array
    {
        return [['TDP', '7500.00'], ['TES', '13500.00'], ['TDP', '0.10'], ['TDP', '7500.37']];
    }

    #[DataProvider('amounts')]
    public function test_items_use_approved_amounts_and_total_integer_centavos(string $program, string $amount): void
    {
        $g = $this->ready($program, $amount);
        $second = $this->ready($program, $amount);
        $this->add($g)->assertSessionHasNoErrors();
        $this->add($second, $g['batch'])->assertSessionHasNoErrors();
        $items = $g['batch']->items()->get();
        $this->assertCount(2, $items);
        $this->assertSame($amount, $items[0]->gross_amount);
        $this->assertSame($amount, $items[0]->entitlement_snapshot);
        $this->assertSame($g['evaluation']->id, $items[0]->evaluation_run_id);
        $this->assertSame($g['approval']->id, $items[0]->external_approval_id);
        [$whole, $cents] = explode('.', $amount);
        $expected = ((int) $whole * 100 + (int) $cents) * 2;
        $this->assertSame(intdiv($expected, 100).'.'.str_pad((string) ($expected % 100), 2, '0', STR_PAD_LEFT), $g['batch']->totalAmount());
        $this->get(route('billing-batches.show', $g['batch']))->assertOk()->assertSee('Scholar allocation items')->assertSee('Prepared total');
        $this->get(route('billing-batches.index', ['program_id' => $g['program']->id, 'academic_year' => '2026-2027']))->assertOk()->assertSee('Prepared total');
        $this->assertDatabaseCount('funding_receipts', 0);
        $this->assertDatabaseCount('payouts', 0);
    }

    public function test_duplicate_billing_is_rejected_in_the_same_batch_and_across_batches_even_by_raw_sql(): void
    {
        $g = $this->ready();
        $this->add($g)->assertSessionHasNoErrors();
        $this->add($g)->assertSessionHasErrors('billing');
        $batch = $g['batch']->replicate();
        $batch->batch_number = 'Batch 2';
        $batch->save();
        $this->add($g, $batch)->assertSessionHasErrors('billing');
        $item = $g['batch']->items()->firstOrFail();
        $this->assertDatabaseRejects(fn () => BillingItem::create(array_replace($item->getAttributes(), ['billing_batch_id' => $batch->id])));
        $this->assertDatabaseCount('billing_items', 1);
    }

    public function test_draft_edit_remove_and_stale_forms_preserve_totals_and_audit(): void
    {
        $g = $this->ready();
        $this->add($g)->assertSessionHasNoErrors();
        $this->patch(route('billing-batches.update', $g['batch']), ['lock_version' => 0, 'batch_number' => 'Stale'])->assertSessionHasErrors('billing');
        $this->patch(route('billing-batches.update', $g['batch']), ['lock_version' => 1, 'batch_number' => 'Batch 10', 'reference' => 'Draft memo', 'status' => 'submitted', 'program_id' => 999])->assertSessionHasNoErrors();
        $this->assertSame('draft', $g['batch']->fresh()->status);
        $this->assertSame('Batch 10', $g['batch']->fresh()->batch_number);
        $item = $g['batch']->items()->firstOrFail();
        $this->delete(route('billing-batches.items.destroy', [$g['batch'], $item]), ['lock_version' => 2])->assertSessionHasNoErrors();
        $this->assertSame('0.00', $g['batch']->totalAmount());
        $this->assertTrue(AuditEvent::where('action', 'draft_billing_item_removed')->exists());
        $this->add($g)->assertSessionHasNoErrors();
        $this->assertSame('7500.00', $g['batch']->totalAmount());
    }

    public function test_submit_freezes_batch_and_items_and_never_records_received_or_paid_money(): void
    {
        $g = $this->ready();
        $this->add($g)->assertSessionHasNoErrors();
        $this->submit($g['batch'])->assertSessionHasNoErrors();
        $batch = $g['batch']->fresh();
        $item = $batch->items()->firstOrFail();
        $this->assertSame('submitted', $batch->status);
        $this->assertSame(auth()->id(), $batch->submitted_by);
        $this->assertNotNull($batch->submitted_at);
        $this->assertSame('SYNTHETIC-SUBMISSION', $batch->reference);
        $this->assertSame('finalized', $item->status);
        $this->assertSame(auth()->id(), $item->finalized_by);
        $this->assertSame(BillingEligibility::POLICY, $item->basis_snapshot['policy']);
        $this->get(route('billing-batches.show', $batch))->assertOk()->assertSee('Submitted')->assertDontSee('Submit validated batch');
        $this->submit($batch)->assertForbidden();
        $this->patch(route('billing-batches.update', $batch), ['lock_version' => $batch->lock_version, 'batch_number' => 'Changed'])->assertForbidden();
        $this->add($g)->assertForbidden();
        $this->delete(route('billing-batches.items.destroy', [$batch, $item]), ['lock_version' => $batch->lock_version])->assertForbidden();
        $this->assertDatabaseRejects(fn () => DB::table('billing_batches')->where('id', $batch->id)->update(['status' => 'draft', 'submitted_at' => null]));
        $this->assertDatabaseRejects(fn () => DB::table('billing_batches')->where('id', $batch->id)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('billing_items')->where('id', $item->id)->update(['gross_amount' => '1.00']));
        $this->assertDatabaseRejects(fn () => DB::table('billing_items')->where('id', $item->id)->delete());
        $other = $this->ready();
        $this->assertDatabaseRejects(fn () => BillingItem::create($this->billingItemData(array_replace($other, ['batch' => $batch]))));
        foreach (['funding_receipts', 'funding_allocations', 'payouts', 'deductions', 'liquidations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_empty_batch_and_missing_submission_reference_cannot_submit(): void
    {
        $g = $this->ready();
        $this->submit($g['batch'])->assertSessionHasErrors('billing');
        $this->add($g)->assertSessionHasNoErrors();
        $this->post(route('billing-batches.submit', $g['batch']), ['lock_version' => 1])->assertSessionHasErrors('reference');
        $this->assertDatabaseRejects(fn () => DB::table('billing_batches')->where('id', $g['batch']->id)->update(['status' => 'submitted']));
        $this->assertSame('draft', $g['batch']->fresh()->status);
    }

    public static function invalidRecords(): array
    {
        return [['pending_assignment'], ['archived_student'], ['inactive_program'], ['withdrawn_context'], ['academic_correction'], ['changed_context'], ['revoked_approval'], ['expired_approval'], ['missing_evidence'], ['new_pending_run']];
    }

    #[DataProvider('invalidRecords')]
    public function test_invalid_or_stale_records_are_blocked_on_add_and_rechecked_on_submit(string $kind): void
    {
        $g = $this->ready();
        $this->add($g)->assertSessionHasNoErrors();
        match ($kind) {
            'pending_assignment' => $g['assignment']->update(['status' => 'pending']),
            'archived_student' => $g['student']->update(['record_status' => 'archived']),
            'inactive_program' => $g['program']->update(['is_active' => false]),
            'withdrawn_context' => $g['application']->update(['status' => 'withdrawn']),
            'academic_correction' => AcademicRecord::create(['student_id' => $g['student']->id, 'academic_period_id' => $g['period']->id, 'revision' => 2, 'supersedes_id' => $g['record']->id]),
            'changed_context' => AcademicValidationContext::current()->increment('revision'),
            'revoked_approval' => ExternalApproval::create(array_replace($g['approval']->getAttributes(), ['decision' => 'revoked', 'supersedes_id' => $g['approval']->id])),
            'expired_approval' => ExternalApproval::create(array_replace($g['approval']->getAttributes(), ['supersedes_id' => $g['approval']->id, 'valid_until' => now('Asia/Manila')->subDay()->toDateString()])),
            'missing_evidence' => Storage::disk('scholarship')->delete($g['approval']->documents()->firstOrFail()->storage_path),
            'new_pending_run' => $g['application']->evaluationRuns()->create(['student_id' => $g['student']->id, 'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'run_number' => 2, 'application_revision' => 1]),
        };
        $this->submit($g['batch'])->assertSessionHasErrors('billing');
        $this->assertSame('draft', $g['batch']->fresh()->status);
        $this->assertSame('draft', $g['batch']->items()->firstOrFail()->status);
        $item = $g['batch']->items()->firstOrFail();
        $item->delete();
        $this->add($g)->assertSessionHasErrors('billing');
        $this->assertDatabaseCount('billing_items', 0);
    }

    public function test_wrong_program_semester_nested_item_and_tampered_amounts_are_rejected(): void
    {
        $g = $this->ready();
        $other = $this->ready('TES', '13500.00');
        $this->add($other, $g['batch'])->assertSessionHasErrors('billing');
        $this->add($g)->assertSessionHasNoErrors();
        $item = $g['batch']->items()->firstOrFail();
        $this->delete(route('billing-batches.items.destroy', [$other['batch'], $item]), ['lock_version' => 0])->assertNotFound();
        $item->update(['gross_amount' => '1.00']);
        $this->submit($g['batch'])->assertSessionHasErrors('billing');
        $this->assertSame('draft', $g['batch']->fresh()->status);
    }

    public static function roles(): array
    {
        return [['finance', true, true, false], ['administrator', true, false, true], ['coordinator', true, false, false], ['viewer', true, false, false], ['registrar', false, false, false], ['none', false, false, false]];
    }

    #[DataProvider('roles')]
    public function test_financial_permissions_apply_to_pages_and_direct_mutations(string $role, bool $read, bool $write, bool $configure): void
    {
        $g = $this->ready();
        $user = $role === 'none' ? User::factory()->create() : $this->staff($role);
        $this->actingAs($user);
        $this->get(route('billing-batches.index'))->assertStatus($read ? 200 : 403);
        $this->get(route('billing-batches.show', $g['batch']))->assertStatus($read ? 200 : 403);
        $this->add($g)->assertStatus($write ? 302 : 403);
        $this->patch(route('billing-batches.update', $g['batch']), ['lock_version' => $g['batch']->fresh()->lock_version, 'batch_number' => 'Renamed'])->assertStatus($write ? 302 : 403);
        $this->post(route('billing-batches.terms'), [])->assertStatus($configure ? 302 : 403);
        $this->submit($g['batch'])->assertStatus($write ? 302 : 403);
    }

    public function test_amount_confirmation_rejects_invalid_values_and_requires_explicit_policy(): void
    {
        $g = $this->applicationContext('EDUKAR');
        $this->actingAs($this->staff('administrator'));
        $data = ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'billing_source_reference' => 'Synthetic approval reference', 'confirm_billing_policy' => 1];
        foreach ([null, '0', '-1.00', '1.001', '1e3', '10000000000.00'] as $amount) {
            $this->post(route('billing-batches.terms'), $data + ['semester_gross_amount' => $amount])->assertSessionHasErrors('semester_gross_amount');
        }
        $this->post(route('billing-batches.terms'), array_replace($data, ['semester_gross_amount' => '9000', 'confirm_billing_policy' => 0]))->assertSessionHasErrors('confirm_billing_policy');
        $this->post(route('billing-batches.terms'), array_replace($data, ['semester_gross_amount' => '9000', 'billing_source_reference' => ' ']))->assertSessionHasErrors('billing_source_reference');
        $this->assertNull($g['terms']->fresh()->semester_gross_amount);
        $this->post(route('billing-batches.terms'), $data + ['semester_gross_amount' => '9000.25'])->assertSessionHasNoErrors();
        $this->assertSame('9000.25', $g['terms']->fresh()->semester_gross_amount);
        $this->post(route('billing-batches.terms'), $data + ['semester_gross_amount' => '9000.26'])->assertSessionHasErrors('billing');
    }

    public function test_an_individual_authorized_cap_cannot_be_exceeded_or_forged(): void
    {
        $g = $this->ready();
        $g['application']->update(['approved_gross_amount' => '7499.99', 'amount_authorized_by' => auth()->id(), 'amount_authorized_at' => now(), 'amount_basis' => 'Synthetic individual award limit']);
        $this->add($g)->assertSessionHasErrors('billing');
        $g['application']->update(['approved_gross_amount' => '7500.00', 'amount_authorized_by' => null]);
        $this->add($g)->assertSessionHasErrors('billing');
        $g['application']->update(['amount_authorized_by' => auth()->id()]);
        $this->add($g)->assertSessionHasNoErrors();
        $g['application']->update(['approved_gross_amount' => '7000.00']);
        $this->submit($g['batch'])->assertSessionHasErrors('billing');
        $this->assertSame('draft', $g['batch']->fresh()->status);
    }

    public function test_one_invalid_scholar_rolls_back_every_item_in_a_batch(): void
    {
        $g = $this->ready();
        $second = $this->ready();
        $this->add($g)->assertSessionHasNoErrors();
        $this->add($second, $g['batch'])->assertSessionHasNoErrors();
        $second['assignment']->update(['status' => 'pending']);
        $this->submit($g['batch'])->assertSessionHasErrors('billing');
        $this->assertSame('draft', $g['batch']->fresh()->status);
        $this->assertSame(2, $g['batch']->items()->where('status', 'draft')->count());
        $this->assertFalse(AuditEvent::where('action', 'billing_item_submitted')->exists());
    }

    public function test_another_semester_cannot_be_added_and_guest_routes_require_login(): void
    {
        $g = $this->ready();
        $other = $this->applicationContext('TDP', '2');
        $this->add($other, $g['batch'])->assertSessionHasErrors('billing');
        $this->assertDatabaseCount('billing_items', 0);
        auth()->logout();
        $this->get(route('billing-batches.index'))->assertRedirect(route('login'));
        $this->post(route('billing-batches.store'), [])->assertRedirect(route('login'));
    }

    public function test_financial_confirmation_can_precede_academic_confirmation_without_authorizing_eligibility(): void
    {
        $g = $this->applicationContext();
        $admin = $this->staff('administrator');
        $this->actingAs($admin)->post(route('billing-batches.terms'), [
            'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id,
            'semester_gross_amount' => '7500', 'billing_source_reference' => 'Synthetic financial approval only', 'confirm_billing_policy' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertNull($g['terms']->fresh()->confirmed_at);
        $this->assertNull($g['application']->currentSchoolDecision());
        $this->actingAs($this->staff('coordinator'))->post(route('evaluations.policy', $g['application']), ['confirm_policy' => 1])->assertSessionHasNoErrors();
        $this->assertNotNull($g['terms']->fresh()->confirmed_at);
        $this->assertSame('7500.00', $g['terms']->fresh()->semester_gross_amount);
        $this->actingAs($this->staff('finance'));
        $this->add($g)->assertSessionHasErrors('billing');
    }

    public function test_billing_migration_rollback_and_reapply_preserve_existing_rows_and_guards(): void
    {
        $g = $this->applicationContext();
        $g['terms']->update(['confirmed_by' => $g['actor']->id, 'confirmed_at' => now()]);
        $counts = DB::table('users')->count();
        $migration = require database_path('migrations/2026_11_11_000005_add_semester_billing_management.php');
        $allocations = require database_path('migrations/2026_11_11_000006_add_scholarship_allocation_approvals.php');
        $allocations->down();
        $migration->down();
        $this->assertSame($counts, DB::table('users')->count());
        $this->assertSame($g['assignment']->id, $g['application']->fresh()->assignment_id);
        $this->assertDatabaseRejects(fn () => DB::table('program_period_terms')->where('id', $g['terms']->id)->update(['semester_gross_amount' => '1.00']));
        $migration->up();
        $allocations->up();
        $this->assertNull($g['terms']->fresh()->billing_confirmed_at);
        $this->assertDatabaseRejects(fn () => DB::table('program_period_terms')->where('id', $g['terms']->id)->update(['criteria' => '{}']));
        $this->assertDatabaseRejects(fn () => DB::table('billing_batches')->where('id', $g['batch']->id)->update(['status' => 'submitted']));
    }

    public function test_legacy_unknown_item_amounts_are_not_displayed_as_zero_or_submitted(): void
    {
        $g = $this->applicationContext('EDUKAR');
        BillingItem::create($this->billingItemData($g));
        $this->assertNull($g['batch']->totalAmount());
        $this->actingAs($this->staff('finance'));
        $this->get(route('billing-batches.show', $g['batch']))->assertOk()->assertSee('Unconfirmed total');
        $this->get(route('billing-batches.index'))->assertOk()->assertSee('Unconfirmed total');
        $this->submit($g['batch'])->assertSessionHasErrors('billing');
        $this->assertSame('draft', $g['batch']->fresh()->status);
        $this->assertNull($g['batch']->items()->firstOrFail()->gross_amount);
    }

    public function test_audit_failure_rolls_back_submission_and_finalization(): void
    {
        $g = $this->ready();
        $this->add($g)->assertSessionHasNoErrors();
        $this->mock(RecordAudit::class)->shouldReceive('record')->andThrow(new RuntimeException('Synthetic audit failure'));
        $this->withoutExceptionHandling();
        try {
            $this->submit($g['batch']);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        }
        $this->assertSame('draft', $g['batch']->fresh()->status);
        $this->assertNull($g['batch']->fresh()->submitted_at);
        $this->assertSame('draft', $g['batch']->items()->firstOrFail()->status);
        $this->assertNull($g['batch']->items()->firstOrFail()->finalized_at);
    }
}
