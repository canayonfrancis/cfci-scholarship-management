<?php

namespace Tests\Feature\Database;

use App\Models\AcademicPeriod;
use App\Models\AcademicRecord;
use App\Models\AuditEvent;
use App\Models\BillingBatch;
use App\Models\BillingItem;
use App\Models\Deduction;
use App\Models\Document;
use App\Models\EvaluationRun;
use App\Models\ExternalApproval;
use App\Models\FundingAllocation;
use App\Models\FundingReceipt;
use App\Models\Liquidation;
use App\Models\LiquidationItem;
use App\Models\Payout;
use App\Models\ProgramPeriodTerm;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipAssignment;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Models\UserRole;
use Database\Seeders\ScholarshipProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsScholarshipRecords;
use Tests\TestCase;

class ScholarshipFoundationTest extends TestCase
{
    use BuildsScholarshipRecords, RefreshDatabase;

    public function test_reference_seed_is_idempotent_and_does_not_authorize_amounts_or_overwrite_existing_data(): void
    {
        $this->seed(ScholarshipProgramSeeder::class);
        $this->seed(ScholarshipProgramSeeder::class);

        $this->assertSame(3, ScholarshipProgram::count());
        $this->assertSame('7500.00', ScholarshipProgram::where('code', 'TDP')->first()->school_stated_semester_amount);
        $this->assertSame('13500.00', ScholarshipProgram::where('code', 'TES')->first()->school_stated_semester_amount);
        $edukar = ScholarshipProgram::where('code', 'EDUKAR')->first();
        $this->assertNull($edukar->school_stated_semester_amount);
        $this->assertSame(5, $edukar->planning_duration_years);
        $edukar->update(['source_notes' => 'Locally reviewed source']);
        $this->seed(ScholarshipProgramSeeder::class);
        $this->assertSame('Locally reviewed source', $edukar->fresh()->source_notes);
    }

    public function test_models_expose_the_relational_graph_and_decimal_json_date_casts(): void
    {
        $g = $this->financialContext();
        $g['student']->update(['user_id' => $g['actor']->id]);
        $g['actor']->roles()->create(['role' => 'registrar']);
        $g['actor']->roles()->create(['role' => 'coordinator']);
        $line = $g['liquidation']->items()->create(['billing_batch_id' => $g['batch']->id, 'payout_id' => $g['payout']->id, 'amount' => '7000.00']);
        $document = $g['payout']->documents()->create(['storage_path' => 'private/test.pdf', 'original_name' => 'proof.pdf', 'document_type' => 'payout_proof']);

        $this->assertTrue($g['actor']->student->is($g['student']));
        $this->assertCount(2, $g['actor']->roles);
        $this->assertTrue($g['student']->assignments->first()->program->is($g['program']));
        $this->assertTrue($g['student']->payouts->first()->billingItem->is($g['item']));
        $this->assertTrue($g['evaluation']->application->terms->academicPeriod->is($g['period']));
        $this->assertTrue($g['evaluation']->gradeRecord->is($g['record']));
        $this->assertSame(['source_revision' => 1], $g['evaluation']->fresh()->input_snapshot);
        $this->assertTrue($g['approval']->application->student->is($g['student']));
        $this->assertTrue($g['receipt']->allocations->first()->batch->is($g['batch']));
        $this->assertTrue($g['payout']->deductions->first()->is($g['deduction']));
        $this->assertTrue($line->payout->billingItem->student->is($g['student']));
        $this->assertTrue($document->payout->is($g['payout']));
        $this->assertSame('7500.00', $g['receipt']->fresh()->amount);
        $this->assertSame('7000.00', $g['payout']->fresh()->net_amount);
        $this->assertNull($g['terms']->semester_gross_amount);
        $this->assertNull($g['application']->approved_gross_amount);
        $this->assertArrayNotHasKey('active_student_id', $g['assignment']->toArray());
        $g['period']->update(['starts_on' => '2026-08-01', 'ends_on' => '2026-12-31']);
        $this->assertSame('2026-08-01', $g['period']->fresh()->starts_on->toDateString());
    }

    public function test_one_active_assignment_is_enforced_on_inserts_and_updates_but_history_is_allowed(): void
    {
        $g = $this->applicationContext();
        $tes = ScholarshipProgram::create(['code' => 'TES', 'name' => 'TES']);
        $data = ['student_id' => $g['student']->id, 'program_id' => $tes->id];
        $this->assertDatabaseRejects(fn () => ScholarshipAssignment::create($data + ['status' => 'active']));
        $pending = ScholarshipAssignment::create($data);
        $this->assertDatabaseRejects(fn () => DB::table('scholarship_assignments')->where('id', $pending->id)->update(['status' => 'active']));
        ScholarshipAssignment::create($data + ['status' => 'ended']);
        ScholarshipAssignment::create($data + ['status' => 'ended']);
        $g['assignment']->update(['status' => 'ended']);
        $pending->update(['status' => 'active']);
        $this->assertSame(1, ScholarshipAssignment::where('student_id', $g['student']->id)->where('status', 'active')->count());
        $this->assertSame(3, ScholarshipAssignment::where('student_id', $g['student']->id)->where('status', 'ended')->count());
    }

    public function test_duplicate_billing_for_the_assignment_and_semester_is_rejected_across_batches(): void
    {
        $g = $this->financialContext();
        $otherBatch = $g['batch']->replicate()->fill(['batch_number' => 'SECOND-BATCH']);
        $otherBatch->save();
        $this->assertDatabaseRejects(fn () => BillingItem::create(array_replace($this->billingItemData($g), ['billing_batch_id' => $otherBatch->id])));
        $this->assertSame(1, $g['assignment']->billingItems()->count());
    }

    public function test_the_same_assignment_can_be_billed_for_a_different_semester(): void
    {
        $g = $this->financialContext();
        $period = AcademicPeriod::create(['academic_year' => '2026-2027', 'semester_code' => '2']);
        $terms = ProgramPeriodTerm::create(['program_id' => $g['program']->id, 'academic_period_id' => $period->id]);
        $application = ScholarshipApplication::create(['student_id' => $g['student']->id, 'program_id' => $g['program']->id, 'academic_period_id' => $period->id, 'program_period_term_id' => $terms->id, 'assignment_id' => $g['assignment']->id]);
        $batch = BillingBatch::create(['program_period_term_id' => $terms->id, 'program_id' => $g['program']->id, 'academic_period_id' => $period->id, 'batch_number' => 'FIRST-BATCH']);
        BillingItem::create(['billing_batch_id' => $batch->id, 'application_id' => $application->id, 'assignment_id' => $g['assignment']->id, 'student_id' => $g['student']->id, 'program_id' => $g['program']->id, 'academic_period_id' => $period->id]);
        $this->assertSame(2, $g['assignment']->billingItems()->count());
    }

    public function test_multiple_scholars_and_funding_receipts_can_share_a_batch(): void
    {
        $g = $this->financialContext();
        $other = $this->applicationContext();
        BillingItem::create(array_replace($this->billingItemData($other), ['billing_batch_id' => $g['batch']->id]));
        $receipt = $g['receipt']->replicate();
        $receipt->save();
        FundingAllocation::create(['funding_receipt_id' => $receipt->id, 'billing_batch_id' => $g['batch']->id, 'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'amount' => '100.25']);
        $this->assertSame(2, $g['batch']->items()->count());
        $this->assertSame(2, $g['batch']->fundingAllocations()->count());
    }

    public function test_applications_reject_another_students_assignment_and_mismatched_semester_terms(): void
    {
        $g = $this->applicationContext();
        $other = Student::create(['student_number' => 'OTHER', 'first_name' => 'Other', 'last_name' => 'Student']);
        $data = ['student_id' => $other->id, 'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'program_period_term_id' => $g['terms']->id];
        $this->assertDatabaseRejects(fn () => ScholarshipApplication::create($data + ['assignment_id' => $g['assignment']->id]));
        $period = AcademicPeriod::create(['academic_year' => '2027-2028', 'semester_code' => '1']);
        $this->assertDatabaseRejects(fn () => ScholarshipApplication::create(array_replace($data, ['academic_period_id' => $period->id])));
    }

    public function test_evaluations_reject_another_students_grades_and_wrong_enrollment_period(): void
    {
        $g = $this->applicationContext();
        $other = $this->applicationContext();
        $data = $g['evaluation']->replicate()->getAttributes();
        $data['run_number'] = 2;
        $this->assertDatabaseRejects(fn () => EvaluationRun::create(array_replace($data, ['grade_record_id' => $other['record']->id])));
        $period = AcademicPeriod::create(['academic_year' => '2025-2026', 'semester_code' => '2']);
        $previous = AcademicRecord::create(['student_id' => $g['student']->id, 'academic_period_id' => $period->id]);
        $this->assertDatabaseRejects(fn () => EvaluationRun::create(array_replace($data, ['enrollment_record_id' => $previous->id])));
        $valid = EvaluationRun::create(array_replace($data, ['grade_record_id' => $previous->id]));
        $this->assertTrue($valid->gradeRecord->academicPeriod->is($period));
    }

    public function test_billing_rejects_the_wrong_program_batch_and_another_applications_evaluation(): void
    {
        $g = $this->applicationContext();
        $other = $this->applicationContext('TES');
        $this->assertDatabaseRejects(fn () => BillingItem::create(array_replace($this->billingItemData($g), ['billing_batch_id' => $other['batch']->id])));
        $this->assertDatabaseRejects(fn () => BillingItem::create(array_replace($this->billingItemData($g), ['evaluation_run_id' => $other['evaluation']->id])));
        $this->assertDatabaseRejects(fn () => BillingItem::create(array_replace($this->billingItemData($g), ['external_approval_id' => $other['approval']->id])));
    }

    public function test_funding_and_liquidation_cannot_cross_program_or_batch_boundaries(): void
    {
        $g = $this->financialContext();
        $other = $this->applicationContext('TES');
        $this->assertDatabaseRejects(fn () => FundingAllocation::create(['funding_receipt_id' => $g['receipt']->id, 'billing_batch_id' => $other['batch']->id, 'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'amount' => '1.00']));
        $report = Liquidation::create(['billing_batch_id' => $other['batch']->id, 'sequence' => 1]);
        $this->assertDatabaseRejects(fn () => LiquidationItem::create(['liquidation_id' => $report->id, 'billing_batch_id' => $other['batch']->id, 'payout_id' => $g['payout']->id, 'amount' => '1.00']));
        $this->assertDatabaseRejects(fn () => Payout::create(['billing_item_id' => $g['item']->id, 'billing_batch_id' => $other['batch']->id]));
    }

    public function test_billing_approval_scope_is_preserved_on_child_insert_and_parent_update(): void
    {
        $g = $this->applicationContext();
        $otherPeriod = AcademicPeriod::create(['academic_year' => '2026-2027', 'semester_code' => '2']);
        $approval = ExternalApproval::create([
            'student_id' => $g['student']->id, 'program_id' => $g['program']->id,
            'scope' => 'semester', 'academic_period_id' => $otherPeriod->id,
        ]);
        $this->assertDatabaseRejects(fn () => BillingItem::create(array_replace($this->billingItemData($g), ['external_approval_id' => $approval->id])), 'SMS approval scope mismatch');
        $otherAssignment = ScholarshipAssignment::create(['student_id' => $g['student']->id, 'program_id' => $g['program']->id]);
        $assignmentApproval = ExternalApproval::create([
            'student_id' => $g['student']->id, 'program_id' => $g['program']->id,
            'scope' => 'assignment', 'assignment_id' => $otherAssignment->id,
        ]);
        $this->assertDatabaseRejects(fn () => BillingItem::create(array_replace($this->billingItemData($g), ['external_approval_id' => $assignmentApproval->id])), 'SMS approval scope mismatch');
        BillingItem::create($this->billingItemData($g));
        $this->assertDatabaseRejects(fn () => $g['approval']->update(['academic_period_id' => $otherPeriod->id]), 'SMS approval scope mismatch');
    }

    public function test_existing_deductions_cannot_be_moved_onto_a_posted_payout(): void
    {
        $g = $this->financialContext();
        $other = Payout::create(['billing_item_id' => $g['item']->id, 'billing_batch_id' => $g['batch']->id]);
        $g['payout']->update(['status' => 'posted', 'posted_by' => $g['actor']->id, 'posted_at' => now(), 'posting_key' => 'immutable-payout', 'paid_on' => '2026-11-11']);
        $draft = Deduction::create(['payout_id' => $other->id, 'billing_batch_id' => $g['batch']->id, 'type' => 'draft', 'amount' => '1.00']);
        $this->assertDatabaseRejects(fn () => $draft->update(['payout_id' => $g['payout']->id]), 'SMS parent history is immutable');
    }

    public function test_primary_business_keys_and_orphan_references_are_rejected(): void
    {
        $g = $this->applicationContext();
        $this->assertDatabaseRejects(fn () => Student::create(['student_number' => $g['student']->student_number, 'first_name' => 'Duplicate', 'last_name' => 'Record']));
        $this->assertDatabaseRejects(fn () => AcademicPeriod::create(['academic_year' => $g['period']->academic_year, 'semester_code' => $g['period']->semester_code]));
        $this->assertDatabaseRejects(fn () => ScholarshipApplication::create($g['application']->replicate()->getAttributes()));
        $this->assertDatabaseRejects(fn () => ScholarshipAssignment::create(['student_id' => 9999999, 'program_id' => $g['program']->id]));
        $g['actor']->roles()->create(['role' => 'registrar']);
        $this->assertDatabaseRejects(fn () => UserRole::create(['user_id' => $g['actor']->id, 'role' => 'registrar']));
    }

    #[DataProvider('moneyColumns')]
    public function test_money_columns_reject_negative_amounts(string $table, string $column, string $fixture): void
    {
        $g = $this->financialContext();
        $this->assertDatabaseRejects(fn () => DB::table($table)->where('id', $g[$fixture]->id)->update([$column => '-0.01']));
    }

    public static function moneyColumns(): array
    {
        return [
            ['scholarship_programs', 'school_stated_semester_amount', 'program'],
            ['program_period_terms', 'semester_gross_amount', 'terms'],
            ['scholarship_applications', 'approved_gross_amount', 'application'],
            ['billing_items', 'gross_amount', 'item'],
            ['funding_receipts', 'amount', 'receipt'],
            ['funding_allocations', 'amount', 'allocation'],
            ['payouts', 'gross_amount', 'payout'],
            ['payouts', 'net_amount', 'payout'],
            ['deductions', 'amount', 'deduction'],
        ];
    }

    public function test_unknown_amounts_remain_null_and_cannot_be_finalized_or_posted(): void
    {
        $g = $this->applicationContext('EDUKAR');
        $item = BillingItem::create($this->billingItemData($g));
        $this->assertNull($g['program']->school_stated_semester_amount);
        $this->assertNull($item->fresh()->gross_amount);
        $this->assertDatabaseRejects(fn () => $item->update(['status' => 'finalized', 'finalized_by' => $g['actor']->id, 'finalized_at' => now()]));
        $payout = Payout::create(['billing_item_id' => $item->id, 'billing_batch_id' => $g['batch']->id]);
        $this->assertDatabaseRejects(fn () => $payout->update(['status' => 'posted', 'posted_by' => $g['actor']->id, 'posted_at' => now(), 'posting_key' => 'unknown-amount', 'paid_on' => '2026-11-11']));
        $this->assertDatabaseRejects(fn () => $payout->update(['gross_amount' => '100.00', 'net_amount' => '100.01']));
    }

    public function test_verified_academic_records_and_completed_evaluations_preserve_revisions(): void
    {
        $g = $this->applicationContext();
        $g['record']->update(['verification_status' => 'verified', 'verified_by' => $g['actor']->id, 'verified_at' => now()]);
        $g['evaluation']->update(['school_decision' => 'qualified', 'coordinator_id' => $g['actor']->id, 'decided_at' => now()]);
        $this->assertDatabaseRejects(fn () => DB::table('academic_records')->where('id', $g['record']->id)->update(['grade_average' => '1.00']), 'SMS history is immutable');
        $this->assertDatabaseRejects(fn () => DB::table('evaluation_runs')->where('id', $g['evaluation']->id)->update(['school_decision' => 'not_qualified']), 'SMS history is immutable');
        $this->assertDatabaseRejects(fn () => DB::table('evaluation_runs')->where('id', $g['evaluation']->id)->delete(), 'SMS history is immutable');
        $revision = AcademicRecord::create(['student_id' => $g['student']->id, 'academic_period_id' => $g['period']->id, 'revision' => 2, 'supersedes_id' => $g['record']->id]);
        $run = EvaluationRun::create(array_replace($g['evaluation']->replicate()->getAttributes(), ['run_number' => 2, 'school_decision' => 'pending', 'coordinator_id' => null, 'decided_at' => null, 'grade_record_id' => $revision->id]));
        $this->assertTrue($g['evaluation']->fresh()->gradeRecord->is($g['record']));
        $this->assertTrue($run->gradeRecord->supersedes->is($g['record']));
        $this->assertSame('pending', $g['approval']->fresh()->decision);
    }

    public function test_posted_financial_records_are_immutable_and_corrections_link_to_originals(): void
    {
        $g = $this->financialContext();
        $posting = ['status' => 'posted', 'posted_by' => $g['actor']->id, 'posted_at' => now(), 'posting_key' => 'receipt-1'];
        $g['receipt']->update($posting);
        $this->assertDatabaseRejects(fn () => DB::table('funding_receipts')->where('id', $g['receipt']->id)->update(['amount' => '1.00']), 'SMS history is immutable');
        $this->assertDatabaseRejects(fn () => DB::table('funding_receipts')->where('id', $g['receipt']->id)->delete(), 'SMS history is immutable');
        $reversalData = array_replace($g['receipt']->replicate()->getAttributes(), ['entry_type' => 'reversal', 'reversal_of_id' => $g['receipt']->id, 'correction_reason' => 'Test correction', 'posting_key' => 'receipt-reversal']);
        $reversal = FundingReceipt::create($reversalData);
        $this->assertTrue($reversal->reversalOf->is($g['receipt']));
        $this->assertSame('7500.00', $g['receipt']->fresh()->amount);
        $this->assertDatabaseRejects(fn () => FundingReceipt::create(array_replace($reversalData, ['posting_key' => 'another-reversal'])));
        $this->assertDatabaseRejects(fn () => FundingReceipt::create(array_replace($g['receipt']->replicate()->getAttributes(), ['status' => 'draft', 'posted_at' => null])));

        $g['payout']->update(array_replace($posting, ['posting_key' => 'payout-1', 'paid_on' => '2026-11-11']));
        $this->assertDatabaseRejects(fn () => DB::table('payouts')->where('id', $g['payout']->id)->update(['net_amount' => '6999.00']), 'SMS history is immutable');
        $this->assertDatabaseRejects(fn () => $g['deduction']->update(['amount' => '499.00']), 'SMS history is immutable');
        $this->assertDatabaseRejects(fn () => Deduction::create(['payout_id' => $g['payout']->id, 'billing_batch_id' => $g['batch']->id, 'type' => 'late', 'amount' => '1.00']), 'SMS parent history is immutable');
        $g['deduction']->refresh()->update(['settlement_status' => 'settled', 'settled_on' => '2026-11-11', 'settlement_reference' => 'TEST-SETTLEMENT']);
        $this->assertDatabaseRejects(fn () => $g['deduction']->update(['settlement_reference' => 'CHANGED']), 'SMS history is immutable');
    }

    public function test_external_approval_is_separate_scoped_history(): void
    {
        $g = $this->applicationContext();
        $this->assertDatabaseRejects(fn () => ExternalApproval::create(['student_id' => $g['student']->id, 'program_id' => $g['program']->id, 'scope' => 'assignment']));
        $other = $this->applicationContext();
        $this->assertDatabaseRejects(fn () => ExternalApproval::create(['student_id' => $g['student']->id, 'program_id' => $g['program']->id, 'scope' => 'assignment', 'assignment_id' => $other['assignment']->id]));
        $g['approval']->update(['decision' => 'approved', 'decided_on' => '2026-11-11']);
        $this->assertDatabaseRejects(fn () => $g['approval']->update(['decision' => 'revoked']), 'SMS history is immutable');
        $this->assertSame('pending', $g['evaluation']->fresh()->school_decision);
    }

    public function test_revision_and_reversal_links_cannot_point_to_another_student_or_financial_scope(): void
    {
        $g = $this->financialContext();
        $other = $this->applicationContext('TES');
        $this->assertDatabaseRejects(fn () => AcademicRecord::create([
            'student_id' => $g['student']->id, 'academic_period_id' => $g['period']->id,
            'revision' => 2, 'supersedes_id' => $other['record']->id,
        ]));
        $this->assertDatabaseRejects(fn () => ExternalApproval::create([
            'student_id' => $g['student']->id, 'program_id' => $g['program']->id,
            'scope' => 'semester', 'academic_period_id' => $g['period']->id,
            'supersedes_id' => $other['approval']->id,
        ]));
        $this->assertDatabaseRejects(fn () => FundingReceipt::create([
            'program_period_term_id' => $other['terms']->id, 'program_id' => $other['program']->id,
            'academic_period_id' => $other['period']->id, 'amount' => '1.00',
            'entry_type' => 'reversal', 'reversal_of_id' => $g['receipt']->id, 'correction_reason' => 'wrong scope',
        ]));
    }

    public function test_planning_duration_is_descriptive_and_invalid_dates_or_revisions_are_rejected(): void
    {
        $g = $this->applicationContext();
        $g['program']->update(['planning_duration_years' => 4]);
        $g['assignment']->update(['starts_on' => '2019-06-01']);
        $this->assertSame('active', $g['assignment']->fresh()->status);
        $this->assertNull($g['assignment']->fresh()->ends_on);
        $this->assertDatabaseRejects(fn () => DB::table('scholarship_assignments')->where('id', $g['assignment']->id)->update(['ends_on' => '2019-05-31']));
        $this->assertDatabaseRejects(fn () => DB::table('academic_records')->where('id', $g['record']->id)->update(['revision' => 0]));
        $this->assertDatabaseRejects(fn () => DB::table('academic_periods')->where('id', $g['period']->id)->update(['starts_on' => '2026-12-01', 'ends_on' => '2026-11-01']));
    }

    public function test_liquidation_supports_student_payout_and_deduction_evidence_without_ambiguous_targets(): void
    {
        $g = $this->financialContext();
        $base = ['liquidation_id' => $g['liquidation']->id, 'billing_batch_id' => $g['batch']->id, 'amount' => '1.00'];
        $this->assertDatabaseRejects(fn () => LiquidationItem::create($base));
        $this->assertDatabaseRejects(fn () => LiquidationItem::create($base + ['payout_id' => $g['payout']->id, 'deduction_id' => $g['deduction']->id]));
        $line = LiquidationItem::create($base + ['payout_id' => $g['payout']->id]);
        $this->assertTrue($line->payout->billingItem->student->is($g['student']));
        $this->assertDatabaseRejects(fn () => LiquidationItem::create($base + ['payout_id' => $g['payout']->id]));
        $g['liquidation']->update(['status' => 'accepted', 'reviewed_by' => $g['actor']->id, 'reviewed_at' => now()]);
        $this->assertDatabaseRejects(fn () => $line->update(['amount' => '2.00']), 'history is immutable');
        $this->assertDatabaseRejects(fn () => $line->delete(), 'SMS history is immutable');
        $this->assertDatabaseRejects(fn () => LiquidationItem::create($base + ['deduction_id' => $g['deduction']->id]), 'SMS parent history is immutable');
    }

    public function test_evidence_requires_exactly_one_existing_owner_and_accepted_evidence_is_retained(): void
    {
        $g = $this->financialContext();
        $file = ['storage_path' => 'private/evidence.pdf', 'original_name' => 'evidence.pdf', 'document_type' => 'test'];
        $this->assertDatabaseRejects(fn () => Document::create($file));
        $this->assertDatabaseRejects(fn () => Document::create($file + ['payout_id' => $g['payout']->id, 'liquidation_id' => $g['liquidation']->id]));
        $this->assertDatabaseRejects(fn () => Document::create($file + ['payout_id' => 9999999]));
        $document = Document::create($file + ['payout_id' => $g['payout']->id, 'review_status' => 'accepted']);
        $this->assertDatabaseRejects(fn () => $document->delete(), 'SMS history is immutable');
        $this->assertDatabaseRejects(fn () => $document->update(['storage_path' => 'another.pdf']), 'SMS history is immutable');
    }

    public function test_parent_deletion_cannot_cascade_away_business_history_and_audit_is_append_only(): void
    {
        $g = $this->financialContext();
        foreach (['students' => 'student', 'scholarship_programs' => 'program', 'academic_periods' => 'period', 'scholarship_assignments' => 'assignment', 'billing_batches' => 'batch'] as $table => $key) {
            $this->assertDatabaseRejects(fn () => DB::table($table)->where('id', $g[$key]->id)->delete());
        }
        $audit = AuditEvent::create(['actor_id' => $g['actor']->id, 'actor_snapshot' => ['name' => 'Test Actor'], 'action' => 'test_record', 'subject_type' => 'payout', 'subject_id' => $g['payout']->id, 'occurred_at' => now()]);
        $this->assertDatabaseRejects(fn () => $audit->delete(), 'SMS history is immutable');
        $this->assertDatabaseRejects(fn () => $audit->update(['reason' => 'edited']), 'SMS history is immutable');
        $this->assertDatabaseRejects(fn () => DB::table('users')->where('id', $g['actor']->id)->delete());
    }

    public function test_finalized_batches_cannot_be_changed_by_adding_or_editing_items(): void
    {
        $g = $this->financialContext();
        $other = $this->applicationContext();
        $g['batch']->update(['status' => 'finalized', 'finalized_at' => now(), 'finalized_by' => $g['actor']->id]);
        $this->assertDatabaseRejects(fn () => $g['item']->update(['gross_amount' => '1.00']), 'history is immutable');
        $this->assertDatabaseRejects(fn () => BillingItem::create(array_replace($this->billingItemData($other), ['billing_batch_id' => $g['batch']->id])), 'SMS parent history is immutable');
    }

    public function test_mysql_uses_fixed_precision_money_and_the_required_composite_unique_index(): void
    {
        $columns = collect(Schema::getColumns('payouts'))->keyBy('name');
        if (DB::getDriverName() === 'mysql') {
            $this->assertSame('decimal(12,2)', $columns['gross_amount']['type']);
        } else {
            // Laravel uses SQLite's NUMERIC affinity; precision/scale are verified on MySQL.
            $this->assertSame('numeric', $columns['gross_amount']['type']);
        }
        $indexes = collect(Schema::getIndexes('billing_items'))->keyBy('name');
        $this->assertTrue($indexes['sms_one_bill_per_assignment_period_uq']['unique']);
        $this->assertSame(['assignment_id', 'academic_period_id'], $indexes['sms_one_bill_per_assignment_period_uq']['columns']);
    }
}
