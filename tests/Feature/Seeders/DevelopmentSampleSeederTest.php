<?php

namespace Tests\Feature\Seeders;

use App\Actions\Billing\ManageBillingBatch;
use App\Actions\Records\RecordAudit;
use App\Models\AcademicPeriod;
use App\Models\AcademicRecord;
use App\Models\AcademicRecordGrade;
use App\Models\AcademicValidationContext;
use App\Models\BillingBatch;
use App\Models\BillingItem;
use App\Models\EvaluationRun;
use App\Models\ProgramPeriodTerm;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\AllocationSummary;
use App\Services\Billing\BillingEligibility;
use App\Services\Eligibility\SchoolGradingPolicy;
use Database\Seeders\AcademicYearSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevelopmentSampleSeeder;
use Database\Seeders\Support\SampleData;
use Database\Seeders\SyntheticDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/** Each case builds a new in-memory schema additively; no existing database is reset. */
class DevelopmentSampleSeederTest extends TestCase
{
    private string $previousConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousConnection = DB::getDefaultConnection();
        config(['database.connections.sample_seed_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true], 'sample-data.academic_year' => '2026-2027', 'cache.default' => 'array', 'session.driver' => 'array']);
        DB::purge('sample_seed_test');
        DB::setDefaultConnection('sample_seed_test');
        Schema::clearResolvedInstance('db.schema');
        foreach (glob(database_path('migrations/*.php')) as $file) {
            (require $file)->up();
        }
        Storage::fake('academic');
        Storage::fake('scholarship');
    }

    protected function tearDown(): void
    {
        DB::purge('sample_seed_test');
        DB::setDefaultConnection($this->previousConnection);
        Schema::clearResolvedInstance('db.schema');
        parent::tearDown();
    }

    private function fingerprint(): array
    {
        $result = [];
        foreach (array_keys(SampleData::counts()) as $table) {
            if ($table !== 'academic_years') {
                $result[$table] = hash('sha256', DB::table($table)->orderBy('id')->get()->toJson());
            }
        }
        $result['context'] = hash('sha256', DB::table('academic_validation_context')->get()->toJson());

        return $result;
    }

    public function test_academic_years_are_complete_and_preserve_dates_and_active_configuration(): void
    {
        $period = AcademicPeriod::create(['academic_year' => '2007-2008', 'semester_code' => '1', 'starts_on' => '2007-06-11', 'ends_on' => '2007-10-26', 'is_open' => true]);
        $context = AcademicValidationContext::current();
        $context->update(['active_period_id' => $period->id, 'revision' => 3]);
        $before = $context->fresh()->getAttributes();
        config(['sample-data.academic_year' => null]);
        $this->seed(AcademicYearSeeder::class);
        $this->seed(AcademicYearSeeder::class);
        $this->assertDatabaseCount('academic_periods', 40);
        foreach (range(2007, 2026) as $year) {
            $this->assertSame(['1', '2'], AcademicPeriod::where('academic_year', $year.'-'.($year + 1))->orderBy('semester_code')->pluck('semester_code')->all());
        }
        $this->assertSame($before, $context->fresh()->getAttributes());
        $this->assertSame('2007-06-11', $period->fresh()->starts_on->toDateString());
        $this->assertTrue($period->fresh()->is_open);
        $this->assertSame(39, AcademicPeriod::whereNull('starts_on')->whereNull('ends_on')->where('is_open', false)->count());
        config(['sample-data.academic_year' => '2028-2029']);
        $this->seed(AcademicYearSeeder::class);
        $this->assertDatabaseCount('academic_periods', 44);
    }

    public function test_normal_samples_are_related_nonfinancial_and_keep_the_production_policy(): void
    {
        $context = AcademicValidationContext::current()->getAttributes();
        $this->seed(DevelopmentSampleSeeder::class);
        foreach (['students' => 80, 'scholarship_assignments' => 60, 'academic_records' => 100, 'academic_record_grades' => 500, 'scholarship_applications' => 60, 'evaluation_runs' => 60, 'external_approvals' => 60, 'documents' => 100] as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertDatabaseCount('academic_periods', 40);
        $this->assertDatabaseCount('scholarship_programs', 3);
        $this->assertDatabaseCount('users', 2);
        $this->assertSame(['coordinator', 'registrar'], DB::table('user_roles')->distinct()->orderBy('role')->pluck('role')->all());
        $this->assertSame(54, Student::where('record_status', 'active')->count());
        $this->assertSame(26, Student::where('record_status', 'archived')->count());
        $this->assertSame(60, EvaluationRun::where('automatic_outcome', 'needs_review')->count());
        foreach (['billing_batches', 'billing_items', 'billing_batch_approvals', 'scholarship_rates', 'scholarship_budgets', 'funding_receipts', 'payouts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($context, AcademicValidationContext::current()->getAttributes());
        $this->assertSame('review', app(SchoolGradingPolicy::class)->check(null)['status']);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame(80, Student::where('email', 'like', '%@example.test')->count());
        $this->assertSame(45, Student::all()->filter->hasCompleteProfile()->count());
        $this->assertSame(35, Student::all()->reject->hasCompleteProfile()->count());
        $this->assertDatabaseCount('academic_programs', 5);
    }

    public function test_repeating_normal_samples_does_not_overwrite_manual_records_or_sample_edits(): void
    {
        $manual = Student::create(['student_number' => 'MANUAL-CFCI-001', 'first_name' => 'Original', 'last_name' => 'Record']);
        $this->seed(DevelopmentSampleSeeder::class);
        $sample = Student::where('student_number', 'SAMPLE-C-2026-001')->firstOrFail();
        $sample->update(['first_name' => 'Manually edited']);
        $finance = User::where('email', 'sample.coordinator@example.test')->firstOrFail();
        $finance->update(['name' => 'Manually renamed', 'password' => 'manually-selected-secret']);
        $finance->roles()->delete();
        $before = $this->fingerprint();
        $fileCount = count(Storage::disk('academic')->allFiles());
        $this->seed(DevelopmentSampleSeeder::class);
        $this->assertSame($before, $this->fingerprint());
        $this->assertSame($fileCount, count(Storage::disk('academic')->allFiles()));
        $this->assertSame('Original', $manual->fresh()->first_name);
        $this->assertSame('Manually edited', $sample->fresh()->first_name);
        $this->assertTrue(Hash::check('manually-selected-secret', $finance->fresh()->password));
        $this->assertSame(0, $finance->roles()->count());
    }

    public function test_a_reserved_sample_identifier_collision_rolls_back_without_changing_manual_data(): void
    {
        $manual = Student::create(['student_number' => 'SAMPLE-C-2026-001', 'first_name' => 'Manual', 'last_name' => 'Owner']);
        try {
            $this->seed(DevelopmentSampleSeeder::class);
            $this->fail('A manual record using the reserved sample identifier must be preserved.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('existing manual record', $error->getMessage());
        }
        $this->assertSame('Manual', $manual->fresh()->first_name);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('academic_periods', 0);
        $this->assertDatabaseCount('students', 1);
        $this->assertSame([], Storage::disk('academic')->allFiles());
    }

    public function test_default_database_seeding_preserves_existing_credentials_and_roles(): void
    {
        $user = User::factory()->create(['email' => 'test@example.com', 'name' => 'Manually configured user', 'password' => 'original-secret']);
        $user->roles()->create(['role' => 'viewer']);
        $before = $user->fresh()->getAttributes();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame(['viewer'], $user->roles()->pluck('role')->all());
    }

    public function test_synthetic_seed_requires_explicit_isolation_and_production_seed_is_denied(): void
    {
        try {
            $this->seed(SyntheticDemoSeeder::class);
            $this->fail('Synthetic fixtures need the explicit demo flag.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('restricted', $error->getMessage());
        }
        $this->assertDatabaseCount('students', 0);
        app()->instance('env', 'production');
        try {
            app(DevelopmentSampleSeeder::class)->run();
            $this->fail('Production must reject sample data.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('local/testing', $error->getMessage());
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_complete_isolated_demo_has_valid_financial_records_and_nonqualifying_variations(): void
    {
        config(['sample-data.synthetic_demo' => true]);
        $this->seed(SyntheticDemoSeeder::class);
        foreach (['students' => 80, 'scholarship_assignments' => 60, 'academic_records' => 140, 'academic_record_grades' => 700, 'scholarship_applications' => 60, 'evaluation_runs' => 120, 'external_approvals' => 102, 'scholarship_rates' => 18, 'scholarship_budgets' => 18, 'billing_batches' => 36, 'billing_items' => 36, 'billing_batch_approvals' => 18, 'billing_adjustments' => 16, 'funding_receipts' => 18, 'documents' => 310] as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertSame(36, ScholarshipAssignment::where('status', 'active')->count());
        $this->assertSame(36, EvaluationRun::where('run_number', 2)->where('automatic_outcome', 'passed')->count());
        $this->assertSame(6, EvaluationRun::where('run_number', 2)->where('automatic_outcome', 'failed')->count());
        $this->assertSame(18, EvaluationRun::where('run_number', 2)->where('automatic_outcome', 'needs_review')->count());
        $this->assertSame(9, BillingBatch::where('status', 'draft')->count());
        $this->assertSame(9, BillingBatch::where('status', 'submitted')->whereDoesntHave('approval')->count());
        $this->assertSame(1, AcademicPeriod::where('is_open', true)->count());
        $this->assertSame('2026-2027', AcademicValidationContext::current()->activePeriod->academic_year);
        $this->assertSame('1', AcademicValidationContext::current()->activePeriod->semester_code);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertSame(0, DB::table('billing_items')->select('student_id', 'academic_period_id')->groupBy('student_id', 'academic_period_id')->havingRaw('COUNT(*) > 1')->get()->count());
        foreach (BillingItem::with(['application', 'assignment', 'student', 'batch.rate'])->get() as $item) {
            $this->assertSame('active', $item->student->record_status);
            $this->assertSame('active', $item->assignment->status);
            $this->assertSame($item->gross_amount, $item->batch->rate->amount);
            $this->assertSame($item->evaluation_run_id, $item->application->currentSchoolDecision()?->id);
            $this->assertSame('approved', $item->externalApproval->decision);
            $this->assertNotSame('COSCHO', $item->program->code);
        }
        $this->assertDatabaseCount('payouts', 0);
        $this->assertSame(0, DB::table('funding_receipts')->where('status', 'posted')->count());
        $this->assertSame('review', app(SchoolGradingPolicy::class)->check(null)['status']);
        $before = $this->fingerprint();
        $this->seed(SyntheticDemoSeeder::class);
        $this->assertSame($before, $this->fingerprint());
        foreach (['SAMPLE-C-2026-037', 'SAMPLE-C-2026-043', 'SAMPLE-C-2026-049', 'SAMPLE-C-2026-055'] as $number) {
            $assessment = ScholarshipApplication::whereHas('student', fn ($q) => $q->where('student_number', $number))->firstOrFail();
            try {
                app(BillingEligibility::class)->basis($assessment, $assessment->terms);
                $this->fail('Nonqualifying scholar must not be billable: '.$number);
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
        foreach (ProgramPeriodTerm::all() as $terms) {
            $summary = app(AllocationSummary::class)->forTerms($terms);
            $this->assertSame('0.00', $summary['received']);
            $this->assertSame('0.00', $summary['released_net']);
        }
    }

    public function test_new_factories_keep_foreign_keys_valid_and_default_to_pending(): void
    {
        $record = AcademicRecord::factory()->create();
        $grade = AcademicRecordGrade::factory()->for($record, 'academicRecord')->create();
        $assignment = ScholarshipAssignment::factory()->create();
        $this->assertSame($record->id, $grade->academic_record_id);
        $this->assertSame('pending', $record->verification_status);
        $this->assertSame('unverified', $record->academic_standing);
        $this->assertSame('pending', $assignment->status);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_invalid_year_and_unowned_staff_identifiers_do_not_change_existing_records(): void
    {
        config(['sample-data.academic_year' => '2026-2028']);
        try {
            app(AcademicYearSeeder::class)->run();
            $this->fail('Nonconsecutive years must be rejected.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('consecutive', $error->getMessage());
        }
        $this->assertDatabaseCount('academic_periods', 0);
        config(['sample-data.academic_year' => '2026-2027']);
        $manual = User::factory()->create(['email' => 'sample.coordinator@example.test']);
        $before = $manual->fresh()->getAttributes();
        try {
            app(DevelopmentSampleSeeder::class)->run();
            $this->fail('Unowned reserved accounts must be preserved.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('without seed ownership', $error->getMessage());
        }
        $this->assertSame($before, $manual->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('user_roles', 0);
        $this->assertDatabaseCount('students', 0);
        $this->assertDatabaseCount('academic_periods', 0);
    }

    public function test_demo_financial_failure_rolls_back_data_and_only_removes_new_files(): void
    {
        $this->seed(DevelopmentSampleSeeder::class);
        $before = $this->fingerprint();
        $files = Storage::disk('academic')->allFiles();
        $service = \Mockery::mock(ManageBillingBatch::class, [app(BillingEligibility::class), app(RecordAudit::class)])->makePartial();
        $service->shouldReceive('create')->once()->andThrow(new RuntimeException('Simulated demo billing failure'));
        app()->instance(ManageBillingBatch::class, $service);
        config(['sample-data.synthetic_demo' => true]);
        try {
            app(SyntheticDemoSeeder::class)->run();
            $this->fail('The interrupted financial fixture must roll back.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated demo billing failure', $error->getMessage());
        }
        $this->assertSame($before, $this->fingerprint());
        $this->assertSame($files, Storage::disk('academic')->allFiles());
        $this->assertSame([], Storage::disk('scholarship')->allFiles());
        $this->assertSame('review', app(SchoolGradingPolicy::class)->check(null)['status']);
    }

    public function test_demo_command_rejects_implicit_synthetic_policy_and_nonmysql_connections(): void
    {
        $this->artisan('sms:seed-demo')->expectsOutputToContain('Use --synthetic-policy')->assertFailed();
        $this->artisan('sms:seed-demo --synthetic-policy')->expectsOutputToContain('different MySQL database')->assertFailed();
        $this->assertDatabaseCount('students', 0);
        $this->assertSame('sample_seed_test', DB::getDefaultConnection());
        $this->assertFalse(config('sample-data.synthetic_demo'));
    }
}
