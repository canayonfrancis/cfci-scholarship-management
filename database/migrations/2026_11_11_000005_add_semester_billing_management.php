<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // Native additive columns preserve existing triggers and composite foreign keys.
            foreach ([
                'program_period_terms' => [
                    'billing_policy VARCHAR(80) NULL', 'billing_source_reference TEXT NULL',
                    'billing_confirmed_by INTEGER NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'billing_confirmed_at DATETIME NULL',
                ],
                'billing_batches' => [
                    'submitted_by INTEGER NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT',
                    'lock_version INTEGER NOT NULL DEFAULT 0',
                ],
            ] as $table => $columns) {
                foreach ($columns as $column) {
                    DB::statement("ALTER TABLE {$table} ADD COLUMN {$column}");
                }
            }
        } else {
            Schema::table('program_period_terms', function (Blueprint $table) {
                $table->string('billing_policy', 80)->nullable();
                $table->text('billing_source_reference')->nullable();
                $table->foreignId('billing_confirmed_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
                $table->timestamp('billing_confirmed_at')->nullable();
            });
            Schema::table('billing_batches', function (Blueprint $table) {
                $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
                $table->unsignedInteger('lock_version')->default(0);
            });
        }
        // Academic confirmation freezes academic policy; financial confirmation is independent.
        DB::unprepared('DROP TRIGGER IF EXISTS sms_program_period_terms_history_update');
        $academic = ['id', 'program_id', 'academic_period_id', 'criteria', 'rule_version', 'source_reference', 'confirmed_by', 'confirmed_at', 'created_at'];
        $this->guard('sms_terms_academic_update', 'program_period_terms', 'UPDATE', 'OLD.confirmed_at IS NOT NULL AND ('.$this->changes($academic).')');
        foreach (['UPDATE', 'DELETE'] as $event) {
            $this->guard('sms_terms_billing_'.strtolower($event), 'program_period_terms', $event,
                'OLD.billing_confirmed_at IS NOT NULL'.($event === 'UPDATE' ? ' AND ('.$this->changes(['id', 'program_id', 'academic_period_id', 'semester_gross_amount', 'billing_policy', 'billing_source_reference', 'billing_confirmed_by', 'billing_confirmed_at']).')' : ''));
            $this->guard('sms_submitted_batch_'.strtolower($event), 'billing_batches', $event, "OLD.status = 'submitted' OR OLD.submitted_at IS NOT NULL");
            $this->guard('sms_submitted_item_'.strtolower($event), 'billing_items', $event, "EXISTS (SELECT 1 FROM billing_batches b WHERE b.id = OLD.billing_batch_id AND (b.status = 'submitted' OR b.submitted_at IS NOT NULL))");
        }
        foreach (['INSERT', 'UPDATE'] as $event) {
            $this->guard('sms_billing_terms_valid_'.strtolower($event), 'program_period_terms', $event,
                "NEW.billing_confirmed_at IS NOT NULL AND (NEW.semester_gross_amount IS NULL OR NEW.semester_gross_amount <= 0 OR NEW.billing_confirmed_by IS NULL OR NEW.billing_policy IS NULL OR NEW.billing_policy <> 'current_eligible_active_exact_semester_v1' OR NEW.billing_source_reference IS NULL OR TRIM(NEW.billing_source_reference) = '')");
            $this->guard('sms_submitted_item_parent_'.strtolower($event), 'billing_items', $event, "EXISTS (SELECT 1 FROM billing_batches b WHERE b.id = NEW.billing_batch_id AND (b.status = 'submitted' OR b.submitted_at IS NOT NULL))");
            $this->guard('sms_batch_submission_valid_'.strtolower($event), 'billing_batches', $event,
                "NEW.status = 'submitted' AND (NEW.submitted_by IS NULL OR NEW.submitted_at IS NULL OR NEW.prepared_by IS NULL OR NEW.reference IS NULL OR TRIM(NEW.reference) = '' OR NOT EXISTS (SELECT 1 FROM billing_items i WHERE i.billing_batch_id = NEW.id) OR EXISTS (SELECT 1 FROM billing_items i WHERE i.billing_batch_id = NEW.id AND (i.status <> 'finalized' OR i.gross_amount IS NULL OR i.gross_amount <= 0 OR i.entitlement_snapshot IS NULL OR i.gross_amount <> i.entitlement_snapshot OR i.evaluation_run_id IS NULL OR i.external_approval_id IS NULL OR i.basis_snapshot IS NULL)))");
        }
    }

    private function changes(array $columns): string
    {
        return implode(' OR ', array_map(fn ($column) => DB::getDriverName() === 'mysql'
            ? "NOT (NEW.`{$column}` <=> OLD.`{$column}`)" : "NEW.`{$column}` IS NOT OLD.`{$column}`", $columns));
    }

    private function guard(string $name, string $table, string $event, string $condition): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SMS billing history or submission constraint'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'SMS billing history or submission constraint'); END");
        }
    }

    public function down(): void
    {
        foreach (['sms_terms_academic_update', 'sms_terms_billing_update', 'sms_terms_billing_delete', 'sms_submitted_batch_update', 'sms_submitted_batch_delete', 'sms_submitted_item_update', 'sms_submitted_item_delete', 'sms_billing_terms_valid_insert', 'sms_billing_terms_valid_update', 'sms_submitted_item_parent_insert', 'sms_submitted_item_parent_update', 'sms_batch_submission_valid_insert', 'sms_batch_submission_valid_update'] as $name) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
        }
        $this->guard('sms_program_period_terms_history_update', 'program_period_terms', 'UPDATE', 'OLD.confirmed_at IS NOT NULL');
        if (DB::getDriverName() === 'sqlite') {
            foreach (['billing_batches' => ['submitted_by', 'lock_version'], 'program_period_terms' => ['billing_confirmed_by', 'billing_policy', 'billing_source_reference', 'billing_confirmed_at']] as $table => $columns) {
                foreach ($columns as $column) {
                    DB::statement("ALTER TABLE {$table} DROP COLUMN {$column}");
                }
            }
        } else {
            Schema::table('billing_batches', function (Blueprint $table) {
                $table->dropConstrainedForeignId('submitted_by');
                $table->dropColumn('lock_version');
            });
            Schema::table('program_period_terms', function (Blueprint $table) {
                $table->dropConstrainedForeignId('billing_confirmed_by');
                $table->dropColumn(['billing_policy', 'billing_source_reference', 'billing_confirmed_at']);
            });
        }
    }
};
