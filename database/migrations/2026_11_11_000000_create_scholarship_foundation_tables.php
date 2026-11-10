<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('The SMS schema supports MySQL and SQLite. Other drivers require constraint verification first.');
        }

        if ($driver === 'mysql' && version_compare(DB::getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION), '8.0.16', '<')) {
            throw new RuntimeException('The SMS schema requires MySQL 8.0.16 or newer for enforced CHECK constraints.');
        }

        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->restrictOnUpdate();
            $table->string('role', 40);
            $table->timestamps();
            $table->unique(['user_id', 'role']);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('student_number', 50)->unique();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->string('record_status', 30)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('scholarship_programs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->decimal('school_stated_semester_amount', 12, 2)->nullable();
            $table->unsignedSmallInteger('planning_duration_years')->nullable();
            $table->text('source_notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('academic_periods', function (Blueprint $table) {
            $table->id();
            $table->string('academic_year', 30);
            $table->string('semester_code', 30);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_open')->default(false);
            $table->timestamps();
            $table->unique(['academic_year', 'semester_code']);
        });

        Schema::create('program_period_terms', function (Blueprint $table) {
            $table->id();
            $this->reference($table, 'program_id', 'scholarship_programs');
            $this->reference($table, 'academic_period_id', 'academic_periods');
            $table->decimal('semester_gross_amount', 12, 2)->nullable();
            $table->json('criteria')->nullable();
            $table->string('rule_version', 50)->nullable();
            $table->text('source_reference')->nullable();
            $this->reference($table, 'confirmed_by', 'users', true);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['program_id', 'academic_period_id'], 'sms_terms_period_uq');
            $table->unique(['id', 'program_id', 'academic_period_id'], 'sms_terms_context_uq');
        });

        Schema::create('scholarship_assignments', function (Blueprint $table) {
            $table->id();
            $this->reference($table, 'student_id', 'students');
            $this->reference($table, 'program_id', 'scholarship_programs');
            $this->reference($table, 'start_period_id', 'academic_periods', true);
            $this->reference($table, 'end_period_id', 'academic_periods', true);
            $table->enum('status', ['pending', 'active', 'suspended', 'ended'])->default('pending');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $this->reference($table, 'activated_by', 'users', true);
            $this->reference($table, 'closed_by', 'users', true);
            $table->text('status_reason')->nullable();
            $table->unsignedBigInteger('active_student_id')->nullable()
                ->storedAs("CASE WHEN status = 'active' THEN student_id ELSE NULL END");
            $table->timestamps();
            $table->unique('active_student_id', 'sms_one_active_assignment_uq');
            $table->unique(['id', 'student_id', 'program_id'], 'sms_assignment_context_uq');
            $table->index(['program_id', 'status']);
        });

        Schema::create('academic_records', function (Blueprint $table) {
            $table->id();
            $this->reference($table, 'student_id', 'students');
            $this->reference($table, 'academic_period_id', 'academic_periods');
            $table->unsignedInteger('revision')->default(1);
            $table->string('course')->nullable();
            $table->string('year_level', 30)->nullable();
            $table->string('enrollment_status', 50)->nullable();
            $table->decimal('grade_average', 7, 3)->nullable();
            $table->decimal('units_enrolled', 7, 2)->nullable();
            $table->decimal('units_completed', 7, 2)->nullable();
            $table->json('academic_summary')->nullable();
            $table->text('source_reference')->nullable();
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $this->reference($table, 'verified_by', 'users', true);
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            $table->foreignId('supersedes_id')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'academic_period_id', 'revision'], 'sms_academic_revision_uq');
            $table->unique(['id', 'student_id'], 'sms_academic_student_uq');
            $table->unique(['id', 'student_id', 'academic_period_id'], 'sms_academic_context_uq');
            $table->index(['academic_period_id', 'verification_status']);
        });

        Schema::create('scholarship_applications', function (Blueprint $table) {
            $table->id();
            $this->reference($table, 'student_id', 'students');
            $this->reference($table, 'program_id', 'scholarship_programs');
            $this->reference($table, 'academic_period_id', 'academic_periods');
            $table->foreignId('program_period_term_id');
            $table->foreignId('assignment_id')->nullable();
            $table->unsignedInteger('submission_revision')->default(1);
            $table->enum('status', ['draft', 'submitted', 'withdrawn'])->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->decimal('approved_gross_amount', 12, 2)->nullable();
            $this->reference($table, 'amount_authorized_by', 'users', true);
            $table->timestamp('amount_authorized_at')->nullable();
            $table->text('amount_basis')->nullable();
            $table->timestamps();
            $this->context($table, ['program_period_term_id', 'program_id', 'academic_period_id'], 'program_period_terms', ['id', 'program_id', 'academic_period_id'], 'sms_app_terms_fk');
            $this->context($table, ['assignment_id', 'student_id', 'program_id'], 'scholarship_assignments', ['id', 'student_id', 'program_id'], 'sms_app_assignment_fk');
            $table->unique(['student_id', 'program_period_term_id'], 'sms_application_period_uq');
            $table->unique(['id', 'student_id', 'program_id'], 'sms_application_student_uq');
            $table->unique(['id', 'student_id', 'program_id', 'academic_period_id'], 'sms_application_context_uq');
            $table->unique(['id', 'assignment_id', 'student_id', 'program_id', 'academic_period_id'], 'sms_application_billing_uq');
            $table->index(['academic_period_id', 'status']);
        });

        Schema::create('evaluation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id');
            $this->reference($table, 'student_id', 'students');
            $this->reference($table, 'program_id', 'scholarship_programs');
            $this->reference($table, 'academic_period_id', 'academic_periods');
            $table->unsignedInteger('run_number');
            $table->unsignedInteger('application_revision');
            $table->foreignId('grade_record_id')->nullable();
            $table->foreignId('enrollment_record_id')->nullable();
            $table->string('rule_version', 50)->nullable();
            $table->json('input_snapshot')->nullable();
            $table->json('rule_snapshot')->nullable();
            $table->json('check_results')->nullable();
            $table->enum('automatic_outcome', ['pending', 'passed', 'failed', 'needs_review'])->default('pending');
            $table->enum('school_decision', ['pending', 'qualified', 'not_qualified', 'needs_information'])->default('pending');
            $this->reference($table, 'coordinator_id', 'users', true);
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->text('override_reason')->nullable();
            $table->timestamps();
            $this->context($table, ['application_id', 'student_id', 'program_id', 'academic_period_id'], 'scholarship_applications', ['id', 'student_id', 'program_id', 'academic_period_id'], 'sms_eval_application_fk');
            $this->context($table, ['grade_record_id', 'student_id'], 'academic_records', ['id', 'student_id'], 'sms_eval_grades_fk');
            $this->context($table, ['enrollment_record_id', 'student_id', 'academic_period_id'], 'academic_records', ['id', 'student_id', 'academic_period_id'], 'sms_eval_enrollment_fk');
            $table->unique(['application_id', 'run_number'], 'sms_eval_run_uq');
            $table->unique(['id', 'application_id'], 'sms_eval_application_uq');
        });

        Schema::create('external_approvals', function (Blueprint $table) {
            $table->id();
            $this->reference($table, 'student_id', 'students');
            $this->reference($table, 'program_id', 'scholarship_programs');
            $table->enum('scope', ['assignment', 'semester']);
            $table->foreignId('assignment_id')->nullable();
            $this->reference($table, 'academic_period_id', 'academic_periods', true);
            $table->foreignId('application_id')->nullable();
            $table->enum('decision', ['pending', 'approved', 'rejected', 'revoked'])->default('pending');
            $table->string('authority')->nullable();
            $table->string('reference')->nullable()->index();
            $table->date('decided_on')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $this->reference($table, 'recorded_by', 'users', true);
            $table->foreignId('supersedes_id')->nullable();
            $table->timestamps();
            $this->context($table, ['assignment_id', 'student_id', 'program_id'], 'scholarship_assignments', ['id', 'student_id', 'program_id'], 'sms_approval_assignment_fk');
            $this->context($table, ['application_id', 'student_id', 'program_id'], 'scholarship_applications', ['id', 'student_id', 'program_id'], 'sms_approval_application_fk');
            $table->unique(['id', 'student_id', 'program_id'], 'sms_approval_context_uq');
            $table->index(['program_id', 'decision']);
        });

        Schema::create('billing_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_period_term_id');
            $this->reference($table, 'program_id', 'scholarship_programs');
            $this->reference($table, 'academic_period_id', 'academic_periods');
            $table->string('batch_number', 60);
            $table->string('reference')->nullable();
            $table->enum('status', ['draft', 'submitted', 'finalized', 'void'])->default('draft');
            $this->reference($table, 'prepared_by', 'users', true);
            $table->timestamp('submitted_at')->nullable();
            $this->reference($table, 'finalized_by', 'users', true);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $this->context($table, ['program_period_term_id', 'program_id', 'academic_period_id'], 'program_period_terms', ['id', 'program_id', 'academic_period_id'], 'sms_batch_terms_fk');
            $table->unique(['program_period_term_id', 'batch_number'], 'sms_batch_number_uq');
            $table->unique(['id', 'program_id', 'academic_period_id'], 'sms_batch_context_uq');
            $table->index(['academic_period_id', 'status']);
        });

        Schema::create('billing_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_batch_id');
            $table->foreignId('application_id');
            $table->foreignId('assignment_id');
            $this->reference($table, 'student_id', 'students');
            $this->reference($table, 'program_id', 'scholarship_programs');
            $this->reference($table, 'academic_period_id', 'academic_periods');
            $table->foreignId('evaluation_run_id')->nullable();
            $table->foreignId('external_approval_id')->nullable();
            $table->decimal('gross_amount', 12, 2)->nullable();
            $table->decimal('entitlement_snapshot', 12, 2)->nullable();
            $table->json('basis_snapshot')->nullable();
            $table->enum('status', ['draft', 'finalized', 'void'])->default('draft');
            $this->reference($table, 'finalized_by', 'users', true);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $this->context($table, ['billing_batch_id', 'program_id', 'academic_period_id'], 'billing_batches', ['id', 'program_id', 'academic_period_id'], 'sms_item_batch_fk');
            $this->context($table, ['application_id', 'assignment_id', 'student_id', 'program_id', 'academic_period_id'], 'scholarship_applications', ['id', 'assignment_id', 'student_id', 'program_id', 'academic_period_id'], 'sms_item_application_fk');
            $this->context($table, ['evaluation_run_id', 'application_id'], 'evaluation_runs', ['id', 'application_id'], 'sms_item_evaluation_fk');
            $this->context($table, ['external_approval_id', 'student_id', 'program_id'], 'external_approvals', ['id', 'student_id', 'program_id'], 'sms_item_approval_fk');
            $table->unique(['assignment_id', 'academic_period_id'], 'sms_one_bill_per_assignment_period_uq');
            $table->unique('application_id', 'sms_one_bill_per_application_uq');
            $table->unique(['id', 'billing_batch_id'], 'sms_item_batch_uq');
        });

        Schema::create('funding_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_period_term_id');
            $this->reference($table, 'program_id', 'scholarship_programs');
            $this->reference($table, 'academic_period_id', 'academic_periods');
            $table->decimal('amount', 12, 2);
            $table->date('received_on')->nullable();
            $table->string('source')->nullable();
            $table->string('reference')->nullable()->index();
            $this->posting($table);
            $table->timestamps();
            $this->context($table, ['program_period_term_id', 'program_id', 'academic_period_id'], 'program_period_terms', ['id', 'program_id', 'academic_period_id'], 'sms_receipt_terms_fk');
            $table->unique(['id', 'program_id', 'academic_period_id'], 'sms_receipt_context_uq');
        });

        Schema::create('funding_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funding_receipt_id');
            $table->foreignId('billing_batch_id');
            $this->reference($table, 'program_id', 'scholarship_programs');
            $this->reference($table, 'academic_period_id', 'academic_periods');
            $table->decimal('amount', 12, 2);
            $this->posting($table);
            $table->timestamps();
            $this->context($table, ['funding_receipt_id', 'program_id', 'academic_period_id'], 'funding_receipts', ['id', 'program_id', 'academic_period_id'], 'sms_allocation_receipt_fk');
            $this->context($table, ['billing_batch_id', 'program_id', 'academic_period_id'], 'billing_batches', ['id', 'program_id', 'academic_period_id'], 'sms_allocation_batch_fk');
            $table->unique(['id', 'funding_receipt_id', 'billing_batch_id'], 'sms_allocation_source_uq');
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_item_id');
            $table->foreignId('billing_batch_id');
            $table->decimal('gross_amount', 12, 2)->nullable();
            $table->decimal('net_amount', 12, 2)->nullable();
            $table->date('paid_on')->nullable();
            $table->string('payee')->nullable();
            $table->string('method', 60)->nullable();
            $table->string('reference')->nullable()->index();
            $this->posting($table);
            $table->timestamps();
            $this->context($table, ['billing_item_id', 'billing_batch_id'], 'billing_items', ['id', 'billing_batch_id'], 'sms_payout_item_fk');
            $table->unique(['id', 'billing_batch_id'], 'sms_payout_batch_uq');
            $table->unique(['id', 'billing_item_id', 'billing_batch_id'], 'sms_payout_source_uq');
        });

        Schema::create('deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_id');
            $table->foreignId('billing_batch_id');
            $table->string('type', 60);
            $table->decimal('amount', 12, 2);
            $table->text('basis')->nullable();
            $this->reference($table, 'authorized_by', 'users', true);
            $table->timestamp('authorized_at')->nullable();
            $table->string('destination')->nullable();
            $table->enum('settlement_status', ['pending', 'settled'])->default('pending');
            $table->date('settled_on')->nullable();
            $table->string('settlement_reference')->nullable();
            $table->timestamps();
            $this->context($table, ['payout_id', 'billing_batch_id'], 'payouts', ['id', 'billing_batch_id'], 'sms_deduction_payout_fk');
            $table->unique(['id', 'billing_batch_id'], 'sms_deduction_batch_uq');
        });

        Schema::create('liquidations', function (Blueprint $table) {
            $table->id();
            $this->reference($table, 'billing_batch_id', 'billing_batches');
            $table->unsignedInteger('sequence');
            $table->string('reference')->nullable();
            $table->enum('status', ['draft', 'submitted', 'accepted', 'returned'])->default('draft');
            $this->reference($table, 'prepared_by', 'users', true);
            $table->timestamp('submitted_at')->nullable();
            $this->reference($table, 'reviewed_by', 'users', true);
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();
            $table->unique(['billing_batch_id', 'sequence'], 'sms_liquidation_sequence_uq');
            $table->unique(['id', 'billing_batch_id'], 'sms_liquidation_batch_uq');
        });

        Schema::create('liquidation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('liquidation_id');
            $table->foreignId('billing_batch_id');
            $table->foreignId('payout_id')->nullable();
            $table->foreignId('deduction_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->text('verification_notes')->nullable();
            $table->timestamps();
            $this->context($table, ['liquidation_id', 'billing_batch_id'], 'liquidations', ['id', 'billing_batch_id'], 'sms_liq_item_report_fk');
            $this->context($table, ['payout_id', 'billing_batch_id'], 'payouts', ['id', 'billing_batch_id'], 'sms_liq_item_payout_fk');
            $this->context($table, ['deduction_id', 'billing_batch_id'], 'deductions', ['id', 'billing_batch_id'], 'sms_liq_item_deduction_fk');
            $table->unique(['liquidation_id', 'payout_id'], 'sms_liq_payout_uq');
            $table->unique(['liquidation_id', 'deduction_id'], 'sms_liq_deduction_uq');
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 60)->default('local');
            $table->string('storage_path')->unique();
            $table->string('original_name');
            $table->string('document_type', 60);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum', 64)->nullable()->index();
            $this->reference($table, 'uploaded_by', 'users', true);
            $table->enum('review_status', ['pending', 'accepted', 'rejected'])->default('pending');
            $this->reference($table, 'reviewed_by', 'users', true);
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            foreach ($this->documentOwners() as $column => $owner) {
                $this->reference($table, $column, $owner, true);
            }
            $this->reference($table, 'supersedes_id', 'documents', true);
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $this->reference($table, 'actor_id', 'users', true);
            $table->json('actor_snapshot')->nullable();
            $table->string('action', 100);
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');
            $table->timestamp('occurred_at');
            $table->timestamp('effective_at')->nullable();
            $table->text('reason')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('correlation_id', 100)->nullable()->index();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id', 'occurred_at'], 'sms_audit_subject_idx');
        });

        // Self-references are added after their contextual unique indexes exist on MySQL.
        foreach ([
            ['academic_records', 'supersedes_id', ['student_id', 'academic_period_id'], 'sms_academic_supersedes_fk'],
            ['external_approvals', 'supersedes_id', ['student_id', 'program_id'], 'sms_approval_supersedes_fk'],
            ['funding_receipts', 'reversal_of_id', ['program_id', 'academic_period_id'], 'sms_receipt_reversal_fk'],
            ['funding_allocations', 'reversal_of_id', ['funding_receipt_id', 'billing_batch_id'], 'sms_allocation_reversal_fk'],
            ['payouts', 'reversal_of_id', ['billing_item_id', 'billing_batch_id'], 'sms_payout_reversal_fk'],
        ] as [$tableName, $column, $scope, $name]) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $column, $scope, $name) {
                $this->context($table, [$column, ...$scope], $tableName, ['id', ...$scope], $name);
            });
        }

        $this->checksAndHistory();
    }

    public function down(): void
    {
        foreach (['audit_events', 'documents', 'liquidation_items', 'liquidations', 'deductions', 'payouts', 'funding_allocations', 'funding_receipts', 'billing_items', 'billing_batches', 'external_approvals', 'evaluation_runs', 'scholarship_applications', 'academic_records', 'scholarship_assignments', 'program_period_terms', 'academic_periods', 'scholarship_programs', 'students', 'user_roles'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function reference(Blueprint $table, string $column, string $parent, bool $nullable = false): void
    {
        $table->foreignId($column)->nullable($nullable)->index()->constrained($parent)->restrictOnDelete()->restrictOnUpdate();
    }

    private function context(Blueprint $table, array $columns, string $parent, array $references, string $name): void
    {
        $table->index($columns, str_replace('_fk', '_idx', $name));
        $table->foreign($columns, $name)->references($references)->on($parent)->restrictOnDelete()->restrictOnUpdate();
    }

    private function posting(Blueprint $table): void
    {
        $table->enum('status', ['draft', 'posted'])->default('draft');
        $table->enum('entry_type', ['original', 'reversal'])->default('original');
        $table->string('posting_key', 100)->nullable()->unique();
        $this->reference($table, 'posted_by', 'users', true);
        $table->timestamp('posted_at')->nullable();
        $table->foreignId('reversal_of_id')->nullable()->unique();
        $table->text('correction_reason')->nullable();
    }

    private function documentOwners(): array
    {
        return ['application_id' => 'scholarship_applications', 'academic_record_id' => 'academic_records', 'external_approval_id' => 'external_approvals', 'funding_receipt_id' => 'funding_receipts', 'payout_id' => 'payouts', 'deduction_id' => 'deductions', 'liquidation_id' => 'liquidations'];
    }

    private function checksAndHistory(): void
    {
        foreach (['scholarship_programs' => ['school_stated_semester_amount'], 'program_period_terms' => ['semester_gross_amount'], 'scholarship_applications' => ['approved_gross_amount'], 'billing_items' => ['gross_amount', 'entitlement_snapshot'], 'funding_receipts' => ['amount'], 'funding_allocations' => ['amount'], 'payouts' => ['gross_amount', 'net_amount'], 'deductions' => ['amount'], 'liquidation_items' => ['amount']] as $table => $columns) {
            foreach ($columns as $column) {
                $this->check($table, $column.'_nonnegative', "`{$column}` >= 0");
            }
        }

        $this->check('academic_periods', 'dates', '`ends_on` IS NULL OR `starts_on` IS NULL OR `ends_on` >= `starts_on`');
        $this->check('scholarship_assignments', 'dates', '`ends_on` IS NULL OR `starts_on` IS NULL OR `ends_on` >= `starts_on`');
        $this->check('academic_records', 'revision', '`revision` > 0');
        $this->check('scholarship_applications', 'revision', '`submission_revision` > 0');
        $this->check('evaluation_runs', 'revision', '`run_number` > 0 AND `application_revision` > 0');
        $this->check('external_approvals', 'scope', "(`scope` = 'assignment' AND `assignment_id` IS NOT NULL) OR (`scope` = 'semester' AND `academic_period_id` IS NOT NULL)");
        $this->check('external_approvals', 'dates', '`valid_until` IS NULL OR `valid_from` IS NULL OR `valid_until` >= `valid_from`');
        $this->check('payouts', 'net_gross', '`net_amount` IS NULL OR (`gross_amount` IS NOT NULL AND `net_amount` <= `gross_amount`)');
        $this->check('liquidations', 'sequence', '`sequence` > 0');
        $this->check('liquidation_items', 'one_target', 'CASE WHEN `payout_id` IS NULL THEN 0 ELSE 1 END + CASE WHEN `deduction_id` IS NULL THEN 0 ELSE 1 END = 1');
        $owners = array_map(fn ($column) => "CASE WHEN `{$column}` IS NULL THEN 0 ELSE 1 END", array_keys($this->documentOwners()));
        $this->check('documents', 'one_owner', implode(' + ', $owners).' = 1');

        foreach (['funding_receipts', 'funding_allocations', 'payouts'] as $table) {
            $this->check($table, 'posting', "`status` <> 'posted' OR (`posting_key` IS NOT NULL AND `posted_by` IS NOT NULL AND `posted_at` IS NOT NULL)");
            $this->check($table, 'reversal', "(`entry_type` = 'original' AND `reversal_of_id` IS NULL) OR (`entry_type` = 'reversal' AND `reversal_of_id` IS NOT NULL AND `correction_reason` IS NOT NULL)");
            $this->protectHistory($table, "OLD.`status` = 'posted' OR OLD.`posted_at` IS NOT NULL");
        }

        $this->check('payouts', 'posted_amounts', "`status` <> 'posted' OR (`gross_amount` IS NOT NULL AND `net_amount` IS NOT NULL AND `paid_on` IS NOT NULL)");
        $this->check('billing_items', 'finalized_amounts', "`status` <> 'finalized' OR (`gross_amount` IS NOT NULL AND `entitlement_snapshot` IS NOT NULL AND `finalized_by` IS NOT NULL AND `finalized_at` IS NOT NULL)");
        $this->check('deductions', 'settlement', "`settlement_status` <> 'settled' OR (`settled_on` IS NOT NULL AND `settlement_reference` IS NOT NULL)");
        $this->protectHistory('program_period_terms', 'OLD.`confirmed_at` IS NOT NULL');
        $this->protectHistory('academic_records', "OLD.`verification_status` <> 'pending' OR OLD.`verified_at` IS NOT NULL");
        $this->protectHistory('evaluation_runs', "OLD.`completed_at` IS NOT NULL OR OLD.`decided_at` IS NOT NULL OR OLD.`school_decision` <> 'pending'");
        $this->protectHistory('external_approvals', "OLD.`decision` <> 'pending' OR OLD.`decided_on` IS NOT NULL");
        $this->protectHistory('billing_batches', "OLD.`status` IN ('finalized', 'void') OR OLD.`finalized_at` IS NOT NULL");
        $this->protectHistory('billing_items', "OLD.`status` IN ('finalized', 'void') OR OLD.`finalized_at` IS NOT NULL OR EXISTS (SELECT 1 FROM billing_batches WHERE billing_batches.id = OLD.`billing_batch_id` AND billing_batches.status IN ('finalized', 'void'))");
        $this->protectHistory('deductions', "OLD.`authorized_at` IS NOT NULL OR EXISTS (SELECT 1 FROM payouts WHERE payouts.id = OLD.`payout_id` AND payouts.status = 'posted')", ['payout_id', 'billing_batch_id', 'type', 'amount', 'basis', 'authorized_by', 'authorized_at', 'destination']);
        $this->protectHistory('deductions', "OLD.`settlement_status` = 'settled'", null, 'settled');
        $this->protectHistory('liquidations', "OLD.`status` = 'accepted'");
        $this->protectHistory('liquidation_items', "EXISTS (SELECT 1 FROM liquidations WHERE liquidations.id = OLD.`liquidation_id` AND liquidations.status = 'accepted')");
        $this->protectHistory('documents', "OLD.`review_status` = 'accepted'");
        $this->protectHistory('audit_events', '1 = 1');
        $this->guardRelation('billing_items', "EXISTS (SELECT 1 FROM billing_batches WHERE billing_batches.id = NEW.`billing_batch_id` AND billing_batches.status IN ('finalized', 'void'))", ['INSERT', 'UPDATE']);
        $this->guardRelation('liquidation_items', "EXISTS (SELECT 1 FROM liquidations WHERE liquidations.id = NEW.`liquidation_id` AND liquidations.status = 'accepted')", ['INSERT', 'UPDATE']);
        $this->guardRelation('deductions', "EXISTS (SELECT 1 FROM payouts WHERE payouts.id = NEW.`payout_id` AND payouts.status = 'posted')", ['INSERT']);
        $this->guardRelation('deductions', "NEW.`payout_id` <> OLD.`payout_id` AND EXISTS (SELECT 1 FROM payouts WHERE payouts.id = NEW.`payout_id` AND payouts.status = 'posted')", ['UPDATE']);
        $this->guardRelation('billing_items', "EXISTS (SELECT 1 FROM external_approvals a WHERE a.id = NEW.`external_approval_id` AND ((a.scope = 'semester' AND a.academic_period_id <> NEW.`academic_period_id`) OR (a.scope = 'assignment' AND a.assignment_id <> NEW.`assignment_id`)))", ['INSERT', 'UPDATE'], 'approval_scope', 'SMS approval scope mismatch: billing_items');
        $this->guardRelation('external_approvals', "EXISTS (SELECT 1 FROM billing_items b WHERE b.external_approval_id = OLD.`id` AND ((NEW.`scope` = 'semester' AND NEW.`academic_period_id` <> b.academic_period_id) OR (NEW.`scope` = 'assignment' AND NEW.`assignment_id` <> b.assignment_id)))", ['UPDATE'], 'billing_scope', 'SMS approval scope mismatch: external_approvals');
    }

    // SQLite cannot ALTER TABLE ADD CHECK; equivalent triggers enforce insert/update parity.
    private function check(string $table, string $name, string $expression): void
    {
        $name = 'sms_'.substr($table, 0, 14).'_'.$name;

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$expression})");

            return;
        }

        $condition = preg_replace('/`([a-z_]+)`/', 'NEW.`$1`', $expression);
        foreach (['INSERT', 'UPDATE'] as $event) {
            $trigger = $name.'_'.strtolower($event);
            DB::unprepared("CREATE TRIGGER `{$trigger}` BEFORE {$event} ON `{$table}` FOR EACH ROW WHEN NOT ({$condition}) BEGIN SELECT RAISE(ABORT, '{$name}'); END");
        }
    }

    private function protectHistory(string $table, string $condition, ?array $columns = null, string $suffix = 'history'): void
    {
        foreach (['UPDATE', 'DELETE'] as $event) {
            $predicate = $condition;
            if ($event === 'UPDATE' && $columns !== null) {
                $changes = array_map(fn ($column) => DB::getDriverName() === 'mysql' ? "NOT (NEW.`{$column}` <=> OLD.`{$column}`)" : "NEW.`{$column}` IS NOT OLD.`{$column}`", $columns);
                $predicate = '('.$predicate.') AND ('.implode(' OR ', $changes).')';
            }
            $trigger = 'sms_'.$table.'_'.$suffix.'_'.strtolower($event);
            $message = 'SMS history is immutable: '.$table;

            if (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER `{$trigger}` BEFORE {$event} ON `{$table}` FOR EACH ROW BEGIN IF {$predicate} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END");
            } else {
                DB::unprepared("CREATE TRIGGER `{$trigger}` BEFORE {$event} ON `{$table}` FOR EACH ROW WHEN {$predicate} BEGIN SELECT RAISE(ABORT, '{$message}'); END");
            }
        }
    }

    private function guardRelation(string $table, string $condition, array $events, string $suffix = 'locked_parent', ?string $message = null): void
    {
        foreach ($events as $event) {
            $name = 'sms_'.$table.'_'.$suffix.'_'.strtolower($event);
            $message ??= 'SMS parent history is immutable: '.$table;
            if (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER `{$name}` BEFORE {$event} ON `{$table}` FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END");
            } else {
                DB::unprepared("CREATE TRIGGER `{$name}` BEFORE {$event} ON `{$table}` FOR EACH ROW WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END");
            }
        }
    }
};
