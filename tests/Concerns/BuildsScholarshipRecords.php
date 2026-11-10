<?php

namespace Tests\Concerns;

use App\Models\AcademicPeriod;
use App\Models\AcademicRecord;
use App\Models\BillingBatch;
use App\Models\BillingItem;
use App\Models\Deduction;
use App\Models\EvaluationRun;
use App\Models\ExternalApproval;
use App\Models\FundingAllocation;
use App\Models\FundingReceipt;
use App\Models\Liquidation;
use App\Models\Payout;
use App\Models\ProgramPeriodTerm;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipAssignment;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

trait BuildsScholarshipRecords
{
    /** Synthetic fixtures establish relationships, not school eligibility or financial policy. */
    private function applicationContext(string $programCode = 'TDP', string $semester = '1'): array
    {
        $actor = User::factory()->create();
        $program = ScholarshipProgram::firstOrCreate(['code' => $programCode], ['name' => $programCode]);
        $period = AcademicPeriod::firstOrCreate(['academic_year' => '2026-2027', 'semester_code' => $semester]);
        $terms = ProgramPeriodTerm::firstOrCreate(['program_id' => $program->id, 'academic_period_id' => $period->id]);
        $student = Student::create(['student_number' => (string) Str::uuid(), 'first_name' => 'Test', 'last_name' => 'Scholar']);
        $assignment = ScholarshipAssignment::create(['student_id' => $student->id, 'program_id' => $program->id, 'status' => 'active', 'start_period_id' => $period->id]);
        $application = ScholarshipApplication::create([
            'student_id' => $student->id, 'program_id' => $program->id, 'academic_period_id' => $period->id,
            'program_period_term_id' => $terms->id, 'assignment_id' => $assignment->id,
        ]);
        $record = AcademicRecord::create(['student_id' => $student->id, 'academic_period_id' => $period->id, 'revision' => 1]);
        $evaluation = EvaluationRun::create([
            'application_id' => $application->id, 'student_id' => $student->id, 'program_id' => $program->id,
            'academic_period_id' => $period->id, 'run_number' => 1, 'application_revision' => 1,
            'grade_record_id' => $record->id, 'enrollment_record_id' => $record->id,
            'input_snapshot' => ['source_revision' => 1], 'automatic_outcome' => 'needs_review',
        ]);
        $approval = ExternalApproval::create([
            'student_id' => $student->id, 'program_id' => $program->id, 'scope' => 'semester',
            'academic_period_id' => $period->id, 'application_id' => $application->id,
        ]);
        $batch = BillingBatch::create([
            'program_period_term_id' => $terms->id, 'program_id' => $program->id,
            'academic_period_id' => $period->id, 'batch_number' => (string) Str::uuid(),
        ]);

        return compact('actor', 'program', 'period', 'terms', 'student', 'assignment', 'application', 'record', 'evaluation', 'approval', 'batch');
    }

    private function billingItemData(array $context): array
    {
        return [
            'billing_batch_id' => $context['batch']->id, 'application_id' => $context['application']->id,
            'assignment_id' => $context['assignment']->id, 'student_id' => $context['student']->id,
            'program_id' => $context['program']->id, 'academic_period_id' => $context['period']->id,
            'evaluation_run_id' => $context['evaluation']->id, 'external_approval_id' => $context['approval']->id,
        ];
    }

    private function financialContext(): array
    {
        $context = $this->applicationContext();
        $item = BillingItem::create($this->billingItemData($context) + ['gross_amount' => '7500.00', 'entitlement_snapshot' => '7500.00']);
        $receipt = FundingReceipt::create([
            'program_period_term_id' => $context['terms']->id, 'program_id' => $context['program']->id,
            'academic_period_id' => $context['period']->id, 'amount' => '7500.00',
        ]);
        $allocation = FundingAllocation::create([
            'funding_receipt_id' => $receipt->id, 'billing_batch_id' => $context['batch']->id,
            'program_id' => $context['program']->id, 'academic_period_id' => $context['period']->id,
            'amount' => '7500.00',
        ]);
        $payout = Payout::create(['billing_item_id' => $item->id, 'billing_batch_id' => $context['batch']->id, 'gross_amount' => '7500.00', 'net_amount' => '7000.00']);
        $deduction = Deduction::create(['payout_id' => $payout->id, 'billing_batch_id' => $context['batch']->id, 'type' => 'test_authorized_item', 'amount' => '500.00']);
        $liquidation = Liquidation::create(['billing_batch_id' => $context['batch']->id, 'sequence' => 1]);

        return $context + compact('item', 'receipt', 'allocation', 'payout', 'deduction', 'liquidation');
    }

    private function assertDatabaseRejects(callable $operation, ?string $message = null): void
    {
        try {
            $operation();
        } catch (QueryException $exception) {
            // Reject unrelated SQL errors, such as missing tables/columns, as test failures.
            $this->assertMatchesRegularExpression('/constraint|foreign key|unique|duplicate|sms_|SMS .*(immutable|mismatch)/i', $exception->getMessage());
            if ($message !== null) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }

            return;
        }

        $this->fail('The database accepted an operation that must violate an integrity constraint.');
    }
}
