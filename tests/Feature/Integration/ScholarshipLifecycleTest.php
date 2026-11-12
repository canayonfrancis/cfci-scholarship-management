<?php

namespace Tests\Feature\Integration;

use App\Models\AcademicPeriod;
use App\Models\AcademicRecord;
use App\Models\AcademicValidationContext;
use App\Models\BillingBatch;
use App\Models\Deduction;
use App\Models\Document;
use App\Models\FundingReceipt;
use App\Models\Liquidation;
use App\Models\PaymentAcknowledgment;
use App\Models\Payout;
use App\Models\Payroll;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipBatch;
use App\Models\ScholarshipBatchMembership;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\LiquidationSummary;
use App\Services\DashboardAnalytics;
use App\Services\Eligibility\OfficialRuleCatalog;
use App\Services\Eligibility\SchoolGradingPolicy;
use App\Services\Reporting\ScholarshipReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsAcknowledgmentRecords;
use Tests\TestCase;

class ScholarshipLifecycleTest extends TestCase
{
    use BuildsAcknowledgmentRecords, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('academic');
        Storage::fake('scholarship');
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['password' => Hash::make('Synthetic-Integration-2026!')]);
        $user->roles()->create(['role' => $role]);

        return $user;
    }

    private function login(User $user): void
    {
        if (auth()->check()) {
            $this->post(route('logout'));
        }
        $this->app['auth']->forgetGuards();
        session()->forget('url.intended');
        $this->post(route('login'), ['email' => $user->email, 'password' => 'Synthetic-Integration-2026!'])->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('integration-evidence.pdf', "%PDF-1.4\nSYNTHETIC INTEGRATION EVIDENCE ONLY\n%%EOF");
    }

    /** All records originate through their normal HTTP workflow; reference/role setup is test-only. */
    private function evaluatedScholar(string $recordingRole = 'registrar', string $reviewingRole = 'coordinator', string $enrollment = 'enrolled'): array
    {
        // One Registrar records; one Coordinator reviews. The approved test
        // Administrator can exercise either responsibility without bypassing record rules.
        $registrar = $this->staff($recordingRole);
        $coordinator = $this->staff($reviewingRole);
        $admin = $coordinator;
        $finance = $registrar;
        $this->login($admin);
        $this->post(route('scholarship-programs.store'), ['code' => 'TES', 'name' => 'Synthetic TES reference', 'is_active' => 1])->assertSessionHasNoErrors();
        $program = ScholarshipProgram::sole();
        $this->post(route('academic-periods.store'), ['academic_year' => '2026-2027', 'semester_code' => '1', 'is_open' => 1])->assertSessionHasNoErrors();
        $period = AcademicPeriod::sole();
        $this->post(route('scholarship-batches.store'), ['program_id' => $program->id, 'academic_period_id' => $period->id, 'batch_number' => 'SYNTHETIC-SEMESTER-ROSTER'])->assertSessionHasNoErrors();
        $roster = ScholarshipBatch::sole();
        $this->assertDatabaseCount('scholarship_rates', 0);
        $this->assertDatabaseCount('billing_batches', 0);
        $this->post(route('academic-periods.context'), ['revision' => AcademicValidationContext::current()->revision, 'active_period_id' => $period->id, 'source_period_id' => $period->id, 'source_policy_reference' => 'SYNTHETIC source mapping for integration testing only', 'confirm_source' => 1])->assertSessionHasNoErrors();
        $scholarData = ['student_number' => 'SYNTHETIC-INTEGRATION-001', 'first_name' => 'Maria Alexandra', 'last_name' => 'Synthetic Scholar', 'record_status' => 'active'];
        $this->post(route('scholars.store'), $scholarData)->assertSessionHasNoErrors();
        $student = Student::sole();
        $this->post(route('scholars.store'), $scholarData)->assertSessionHasErrors('student_number');
        $this->post(route('scholars.assignments.store', $student), ['program_id' => $program->id, 'start_period_id' => $period->id, 'status' => 'pending'])->assertSessionHasNoErrors();
        $assignment = $student->assignments()->sole();
        $this->post(route('scholars.batch-memberships.store', $student), ['assignment_id' => $assignment->id, 'scholarship_batch_id' => $roster->id])->assertSessionHasNoErrors();
        $member = ScholarshipBatchMembership::sole();
        $this->assertSame($program->id, $member->program_id);
        $this->assertSame($period->id, $member->academic_period_id);
        $this->assertSame('pending', $assignment->fresh()->status);
        $this->assertDatabaseCount('academic_records', 0);
        $this->assertDatabaseCount('billing_items', 0);
        if ($reviewingRole === 'coordinator') {
            $this->post(route('academic-verifications.store'), [])->assertForbidden();
        }
        $this->login($registrar);
        $this->get(route('scholarship-batches.show', [$roster, 'view' => 'awaiting_verification']))->assertOk()->assertViewHas('members', fn ($m) => $m->total() === 1);
        if ($recordingRole === 'registrar') {
            $this->post(route('scholars.batch-memberships.store', $student), ['assignment_id' => $assignment->id, 'scholarship_batch_id' => $roster->id])->assertForbidden();
        }
        $this->post(route('academic-verifications.store'), ['student_id' => $student->id, 'academic_period_id' => $period->id, 'course' => 'Synthetic course', 'year_level' => '2', 'enrollment_status' => $enrollment, 'academic_standing' => 'passing', 'action' => 'verify', 'source_reference' => 'SYNTHETIC Registrar-certified fixture', 'certificates' => [$this->pdf()], 'grades' => [['subject_code' => 'SYN-101', 'subject_name' => 'Synthetic Subject', 'units' => '3.00', 'average' => '93.00', 'equivalent' => '1.10']]])->assertSessionHasNoErrors();
        $record = AcademicRecord::sole();
        $this->assertSame($registrar->id, $record->verified_by);
        $this->assertSame('93.00', $record->grades()->sole()->average);
        $this->get(route('scholarship-batches.show', [$roster, 'view' => 'awaiting_verification']))->assertOk()->assertViewHas('members', fn ($m) => $m->total() === ($enrollment === 'unverified' ? 1 : 0));
        $this->get(route('scholarship-batches.show', $roster))->assertOk()->assertSee('Form classification pending approved template');
        $this->get(route('academic-verifications.certificate', [$record, $record->documents()->sole()]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->login($coordinator);
        $this->post(route('evaluations.store'), ['program_id' => $program->id, 'academic_period_id' => $period->id, 'assignment_id' => $assignment->id])->assertSessionHasNoErrors();
        $application = ScholarshipApplication::sole();
        $this->post(route('evaluations.policy', $application), ['confirm_policy' => 1])->assertSessionHasNoErrors();
        $facts = ['applicant_type' => 'new', 'facts' => array_fill_keys(array_keys(OfficialRuleCatalog::FACTS), 'yes'), 'fact_sources' => array_fill_keys(array_keys(OfficialRuleCatalog::FACTS), 'SYNTHETIC verified source bundle'), 'returning' => 'no', 'transferee' => 'no', 'legacy_pnsl_transfer' => 'no', 'claiming_pwd' => 'no', 'smart_same_semester' => 'no', 'special_case' => 'none', 'duration_review' => 'within_limit', 'duration_basis' => 'SYNTHETIC documented curriculum/award review', 'exceptions_reviewed' => 1];
        foreach (app(OfficialRuleCatalog::class)->requiredDocuments('TES', $facts) as $type) {
            $this->post(route('evaluations.evidence', $application), ['submission_revision' => $application->fresh()->submission_revision, 'document_type' => $type, 'certificate' => $this->pdf(), 'verified' => 1])->assertSessionHasNoErrors();
        }
        $this->post(route('evaluations.run', $application), $facts + ['submission_revision' => $application->fresh()->submission_revision, 'previous_run' => 0])->assertSessionHasNoErrors();
        $run = $application->evaluationRuns()->sole();
        $this->post(route('evaluations.external', $application), ['previous_approval' => 0, 'decision' => 'approved', 'authority' => 'CHED/UniFAST', 'reference' => 'SYNTHETIC-EXTERNAL-001', 'decided_on' => now('Asia/Manila')->toDateString(), 'verified' => 1, 'verification_notes' => 'SYNTHETIC official-decision fixture', 'certificate' => $this->pdf()])->assertSessionHasNoErrors();
        $approval = $application->externalApprovals()->sole();

        return compact('admin', 'registrar', 'coordinator', 'finance', 'program', 'period', 'roster', 'member', 'student', 'assignment', 'record', 'application', 'run', 'approval');
    }

    public function test_unconfirmed_production_grading_keeps_verified_scholar_pending_and_blocks_activation(): void
    {
        $g = $this->evaluatedScholar();
        $this->assertSame('needs_review', $g['run']->automatic_outcome);
        $this->assertFalse($g['run']->isCurrent());
        $this->post(route('evaluations.activate', $g['application']), ['evaluation_id' => $g['run']->id, 'approval_id' => $g['approval']->id])->assertSessionHasErrors('award');
        $this->assertSame('pending', $g['assignment']->fresh()->status);
        $this->assertDatabaseCount('billing_items', 0);
        $this->assertUnqualifiedBillingBlocked($g);
        $this->get(route('dashboard'))->assertOk()->assertViewHas('metrics', fn ($m) => $m['total_scholars'] === 1 && $m['school_eligible'] === 0 && $m['pending_evaluations'] === 1 && $m['active_awards'] === 0);
    }

    private function assertUnqualifiedBillingBlocked(array $g): void
    {
        $this->login($g['coordinator']);
        $this->post(route('billing-batches.terms'), ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'semester_gross_amount' => '7500.25', 'billing_source_reference' => 'SYNTHETIC rate does not waive eligibility', 'confirm_billing_policy' => 1])->assertSessionHasNoErrors();
        $this->login($g['registrar']);
        $this->post(route('billing-batches.store'), ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id])->assertSessionHasNoErrors();
        $batch = BillingBatch::sole();
        $this->post(route('billing-batches.items.store', $batch), ['application_id' => $g['application']->id, 'lock_version' => $batch->lock_version])->assertSessionHasErrors('billing');
        $this->assertDatabaseCount('billing_items', 0);
        $this->assertDatabaseCount('payouts', 0);
        $this->assertDatabaseCount('scholarship_batch_memberships', 1);
    }

    public static function unresolvedEnrollment(): array
    {
        return [['not_enrolled'], ['unverified']];
    }

    #[DataProvider('unresolvedEnrollment')]
    public function test_verified_non_enrollment_or_unknown_enrollment_retains_membership_without_payable_billing(string $enrollment): void
    {
        $g = $this->evaluatedScholar(enrollment: $enrollment);
        $this->assertNotSame('passed', $g['run']->automatic_outcome);
        $this->assertSame('pending', $g['assignment']->fresh()->status);
        $this->assertUnqualifiedBillingBlocked($g);
        $result = app(ScholarshipReports::class)->build('not-billed');
        $this->assertSame(1, $result['counts']['rows']);
        $this->assertSame('Pending approved Form 2/Form 3 template', $result['rows']->sole()['classification']);
        $this->assertSame($enrollment === 'not_enrolled' ? 'Verified not enrolled' : 'Awaiting Registrar verification', $result['rows']->sole()['enrollment']);
    }

    public static function recordingAndReviewRoles(): array
    {
        return [['registrar', 'coordinator'], ['administrator', 'coordinator'], ['registrar', 'administrator']];
    }

    #[DataProvider('recordingAndReviewRoles')]
    public function test_http_lifecycle_reconciles_all_modules_with_a_test_only_resolved_grading_prerequisite(string $recordingRole, string $reviewingRole): void
    {
        Http::preventStrayRequests();
        // Never enabled in production; this isolates downstream integration from an unconfirmed school rule.
        $this->mock(SchoolGradingPolicy::class)->shouldReceive('check')->andReturn(['status' => 'pass', 'reason' => 'SYNTHETIC resolved prerequisite for integration test only']);
        $g = $this->evaluatedScholar($recordingRole, $reviewingRole);
        $this->assertSame('passed', $g['run']->automatic_outcome);
        $this->assertSame(1, app(ScholarshipReports::class)->build('not-billed')['counts']['rows']);
        $this->post(route('evaluations.activate', $g['application']), ['evaluation_id' => $g['run']->id, 'approval_id' => $g['approval']->id])->assertSessionHasNoErrors();
        $this->assertSame('active', $g['assignment']->fresh()->status);
        $this->post(route('evaluations.activate', $g['application']), ['evaluation_id' => $g['run']->id, 'approval_id' => $g['approval']->id])->assertSessionHasErrors('award');
        $terms = $g['application']->terms;
        $this->login($g['admin']);
        $scope = ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id];
        $this->post(route('billing-batches.terms'), $scope + ['semester_gross_amount' => '7500.25', 'billing_source_reference' => 'SYNTHETIC approved semester amount and exact policy', 'confirm_billing_policy' => 1])->assertSessionHasNoErrors();
        $this->post(route('billing-batches.budget'), $scope + ['previous_budget' => 0, 'amount' => '7500.25', 'source_reference' => 'SYNTHETIC approved budget'])->assertSessionHasNoErrors();
        $this->login($g['finance']);
        $this->post(route('billing-batches.store'), $scope)->assertSessionHasNoErrors();
        $batch = BillingBatch::sole();
        $this->post(route('billing-batches.items.store', $batch), ['application_id' => $g['application']->id, 'lock_version' => $batch->fresh()->lock_version])->assertSessionHasNoErrors();
        $this->assertSame(0, app(ScholarshipReports::class)->build('billed')['counts']['rows']);
        $this->assertSame(1, app(ScholarshipReports::class)->build('not-billed')['counts']['rows']);
        $this->post(route('billing-batches.store'), $scope)->assertSessionHasNoErrors();
        $otherBatch = BillingBatch::latest('id')->firstOrFail();
        $this->post(route('billing-batches.items.store', $otherBatch), ['application_id' => $g['application']->id, 'lock_version' => $otherBatch->lock_version])->assertSessionHasErrors();
        $this->post(route('billing-batches.submit', $batch), ['lock_version' => $batch->fresh()->lock_version, 'reference' => 'SYNTHETIC submission'])->assertSessionHasNoErrors();
        $this->assertSame('7500.25', app(ScholarshipReports::class)->build('billed')['totals']['Total billing amount (original PHP)']);
        $this->assertSame(0, app(ScholarshipReports::class)->build('not-billed')['counts']['rows']);
        $this->post(route('billing-batches.approve', $batch), ['reference' => 'Not permitted'])->assertForbidden();
        $this->login($g['admin']);
        $this->post(route('billing-batches.approve', $batch), ['reference' => 'SYNTHETIC independent approval'])->assertSessionHasNoErrors();
        $item = $batch->items()->sole();
        $this->assertSame(1, app(ScholarshipReports::class)->build('awaiting-funds')['counts']['rows']);
        $this->assertSame('7500.25', $item->gross_amount);
        $this->assertDatabaseCount('funding_receipts', 0);
        $this->assertDatabaseCount('payouts', 0);
        $this->login($g['finance']);
        $this->post(route('financial.payrolls.store', $batch), ['reference' => 'SYNTHETIC-PAYROLL'])->assertSessionHasErrors('payroll');
        $this->assertDatabaseCount('payrolls', 0);
        $this->post(route('financial.receipts.store'), ['program_period_term_id' => $terms->id, 'amount' => '7500.25', 'received_on' => now('Asia/Manila')->toDateString(), 'source' => 'SYNTHETIC provider receipt', 'reference' => 'SYNTHETIC-RECEIPT'])->assertSessionHasNoErrors();
        $receipt = FundingReceipt::sole();
        $this->financialProof($g, 'receipt', $receipt, 'receipt_evidence');
        $this->post(route('financial.receipts.post', $receipt))->assertSessionHasNoErrors();
        $this->assertSame(1, app(ScholarshipReports::class)->build('awaiting-funds')['counts']['rows']);
        $this->login($g['finance']);
        $this->post(route('financial.allocate', $batch), ['funding_receipt_id' => $receipt->id, 'amount' => '7500.25', 'operation_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $this->assertSame(0, app(ScholarshipReports::class)->build('awaiting-funds')['counts']['rows']);
        $this->post(route('financial.payrolls.store', $batch), ['reference' => 'SYNTHETIC-PAYROLL'])->assertSessionHasNoErrors();
        $payroll = Payroll::sole();
        $this->assertSame('7500.25', $payroll->totalPlanned());
        $this->assertSame($item->id, $payroll->items()->sole()->billing_item_id);
        $this->assertDatabaseCount('payouts', 0);
        $this->assertDatabaseCount('payment_acknowledgments', 0);
        $this->get(route('financial.payrolls.print', $payroll))->assertOk()->assertSee('PREPARED ONLY');
        $this->post(route('financial.payouts.store'), ['billing_item_id' => $item->id, 'payment_mode' => 'school', 'gross_amount' => '7500.25', 'net_amount' => '7000.00', 'paid_on' => now('Asia/Manila')->toDateString(), 'method' => 'bank_transfer', 'reference' => 'SYNTHETIC-PAYMENT'])->assertSessionHasNoErrors();
        $payment = Payout::sole();
        $this->login($g['admin']);
        $this->post(route('financial.deduct', $payment), ['type' => 'SYNTHETIC authorized case', 'amount' => '500.25', 'basis' => 'SYNTHETIC exact-case authority; never an assumed deduction rule', 'authority_reference' => 'SYNTHETIC provider permission', 'consent_reference' => 'SYNTHETIC beneficiary instruction', 'destination' => 'SYNTHETIC permitted destination', 'permitted_by_program' => 1, 'authority_evidence' => $this->pdf()])->assertSessionHasNoErrors();
        $deduction = Deduction::sole();
        $proof = $this->financialProof($g, 'payout', $payment, 'payment_evidence');
        $this->post(route('financial.payouts.post', $payment))->assertSessionHasNoErrors();
        $this->assertSame(0, app(ScholarshipReports::class)->build('paid-signed')['counts']['rows']);
        $this->assertSame($g['student']->student_number, $payment->fresh()->institutional_student_id);
        $this->login($g['finance']);
        $this->post(route('financial.liquidations.store', $batch), ['reference' => 'SYNTHETIC-LIQUIDATION'])->assertSessionHasNoErrors();
        $report = Liquidation::sole();
        $this->post(route('financial.liquidations.submit', $report))->assertSessionHasErrors('liquidation');
        $signed = $this->financialProof($g, 'payout', $payment, 'signed_acknowledgment');
        $this->assertSame(0, app(ScholarshipReports::class)->build('paid-signed')['counts']['rows']);
        $this->post(route('financial.acknowledgments.store', $payment), $this->acknowledgmentData($payment->fresh(), $signed->id))->assertSessionHasNoErrors();
        $this->assertSame($g['coordinator']->id, PaymentAcknowledgment::sole()->verified_by);
        $this->assertSame('7000.00', app(ScholarshipReports::class)->build('paid-signed')['totals']['Signed student net (PHP)']);
        $this->financialProof($g, 'liquidation', $report, 'liquidation_support');
        $this->financialProof($g, 'deduction', $deduction, 'deduction_settlement');
        $this->post(route('financial.settle', $deduction), ['settled_on' => now('Asia/Manila')->toDateString(), 'settlement_reference' => 'SYNTHETIC-SETTLED'])->assertSessionHasNoErrors();
        $this->login($g['finance']);
        $this->post(route('financial.liquidations.refresh', $report))->assertSessionHasNoErrors();
        $this->post(route('financial.liquidations.submit', $report))->assertSessionHasNoErrors();
        $this->assertSame(0, app(ScholarshipReports::class)->build('liquidated')['counts']['rows']);
        $this->login($g['admin']);
        $this->post(route('financial.liquidations.review', $report), ['decision' => 'accepted', 'review_notes' => 'SYNTHETIC independently reconciled'])->assertSessionHasNoErrors();
        $summary = app(LiquidationSummary::class)->forBatch($batch, $report->fresh());
        $this->assertTrue($summary['complete']);
        $this->assertSame('0.00', $summary['school_cash_remaining']);
        $this->assertSame(2, $report->items()->count());
        $this->assertSame('prepared', $payroll->fresh()->status);
        $this->assertSame('7500.25', $payroll->totalPlanned());
        $expectedCounts = ['grants' => 1, 'billed' => 1, 'not-billed' => 0, 'awaiting-funds' => 0, 'paid-signed' => 1, 'liquidated' => 1];
        foreach ($expectedCounts as $kind => $expected) {
            $this->get(route('scholarship-reports.index', ['report' => $kind]))->assertOk()->assertViewHas('result', fn ($r) => $r['counts']['rows'] === $expected);
        }
        $this->assertSame('7500.25', app(ScholarshipReports::class)->build('liquidated')['totals']['Verified liquidated coverage (PHP)']);
        // Actual HTTP billing remains separate from its roster; approved form integration is unresolved.
        $this->assertNull($batch->fresh()->scholarship_batch_id);
        $this->assertSame('Unlinked historical submission', app(ScholarshipReports::class)->build('billed')['rows']->sole()['roster']);
        $this->get(route('financial.liquidations.print', $report))->assertOk()->assertSee('7500.25');
        $this->get(route('billing-batches.report', $scope))->assertOk();
        $this->get(route('billing-batches.report', $scope + ['export' => 'csv']))->assertOk()->assertDownload('scholarship-allocations.csv');
        $metrics = app(DashboardAnalytics::class)->build([])['metrics'];
        foreach (['total_scholars' => 1, 'active_awards' => 1, 'school_eligible' => 1, 'billing_total' => '7500.25', 'school_funding' => '7500.25', 'student_payouts' => '7000.00', 'incomplete_liquidations' => 0] as $key => $expected) {
            $this->assertSame($expected, $metrics[$key], $key);
        }
        $this->get(route('dashboard'))->assertOk()->assertViewHas('metrics', $metrics);
        $this->login($g['registrar']);
        $this->get(route('financial.evidence.download', ['payout', $payment, $proof]))->assertOk();
        if ($recordingRole === 'registrar') {
            $this->post(route('financial.evidence.review', ['payout', $payment, $proof]), ['decision' => 'accepted', 'review_notes' => 'Unauthorized Registrar review'])->assertForbidden();
        }
        $this->assertDatabaseCount('billing_items', 1);
        $this->assertDatabaseCount('payouts', 1);
        $this->assertSame($g['run']->id, $g['assignment']->fresh()->activation_evaluation_id);
        $this->assertDatabaseHas('audit_events', ['subject_id' => $g['assignment']->id, 'action' => 'scholarship_award_activated']);
        Http::assertNothingSent();
    }

    private function financialProof(array $g, string $type, $owner, string $documentType): Document
    {
        $this->login($g['finance']);
        $this->post(route('financial.evidence.store', [$type, $owner]), ['document_type' => $documentType, 'evidence' => $this->pdf()])->assertSessionHasNoErrors();
        $document = $owner->documents()->latest('id')->firstOrFail();
        $this->login($g['admin']);
        $this->post(route('financial.evidence.review', [$type, $owner, $document]), ['decision' => 'accepted', 'review_notes' => 'SYNTHETIC evidence verification'])->assertSessionHasNoErrors();
        $this->get(route('financial.evidence.download', [$type, $owner, $document]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        return $document;
    }
}
