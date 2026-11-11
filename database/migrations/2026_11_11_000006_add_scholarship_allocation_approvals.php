<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('billing_items')->select('student_id', 'academic_period_id')->groupBy('student_id', 'academic_period_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Duplicate scholar/semester billing records require an authorized reconciliation before this migration. No records were removed.');
        }
        foreach (['scholarship_rates', 'scholarship_budgets'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->foreignId('program_period_term_id')->constrained('program_period_terms')->restrictOnDelete()->restrictOnUpdate();
                $table->unsignedInteger('revision');
                $table->decimal('amount', 12, 2);
                $table->text('source_reference');
                $table->foreignId('approved_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
                $table->timestamp('approved_at');
                $table->timestamps();
                $table->unique(['program_period_term_id', 'revision']);
            });
        }
        foreach (DB::table('program_period_terms')->whereNotNull('billing_confirmed_at')->whereNotNull('billing_confirmed_by')->where('semester_gross_amount', '>', 0)->orderBy('id')->get() as $terms) {
            DB::table('scholarship_rates')->insert([
                'program_period_term_id' => $terms->id, 'revision' => 1, 'amount' => $terms->semester_gross_amount,
                'source_reference' => $terms->billing_source_reference ?: 'Retained legacy semester billing approval',
                'approved_by' => $terms->billing_confirmed_by, 'approved_at' => $terms->billing_confirmed_at,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        Schema::create('billing_batch_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_batch_id')->unique()->constrained('billing_batches')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('approved_at');
            $table->string('reference');
            $table->decimal('amount_snapshot', 14, 2);
            $table->foreignId('scholarship_budget_id')->constrained('scholarship_budgets')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamps();
        });
        Schema::create('billing_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_item_id')->constrained('billing_items')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedInteger('sequence');
            $table->enum('direction', ['increase', 'decrease']);
            $table->decimal('amount', 12, 2);
            $table->decimal('amount_before', 12, 2);
            $table->decimal('amount_after', 12, 2);
            $table->text('reason');
            $table->json('basis_snapshot');
            $table->string('reference');
            $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('authorized_at');
            $table->foreignId('scholarship_rate_id')->nullable()->constrained('scholarship_rates')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamps();
            $table->unique(['billing_item_id', 'sequence']);
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE billing_batches ADD COLUMN scholarship_rate_id INTEGER NULL REFERENCES scholarship_rates(id) ON DELETE RESTRICT ON UPDATE RESTRICT');
        } else {
            Schema::table('billing_batches', fn (Blueprint $table) => $table->foreignId('scholarship_rate_id')->nullable()->constrained('scholarship_rates')->restrictOnDelete()->restrictOnUpdate());
        }
        Schema::table('billing_items', fn (Blueprint $table) => $table->unique(['student_id', 'academic_period_id'], 'sms_one_allocation_per_scholar_semester'));
        foreach (['scholarship_rates', 'scholarship_budgets', 'billing_batch_approvals', 'billing_adjustments'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $this->guard('sms_'.$table.'_'.strtolower($event), $table, $event, '1 = 1');
            }
        }
        foreach (['INSERT', 'UPDATE'] as $event) {
            $this->guard('sms_batch_rate_scope_'.strtolower($event), 'billing_batches', $event,
                'NEW.scholarship_rate_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM scholarship_rates r WHERE r.id = NEW.scholarship_rate_id AND r.program_period_term_id = NEW.program_period_term_id)');
        }
        foreach (['scholarship_rates' => 'NEW.amount <= 0', 'scholarship_budgets' => 'NEW.amount < 0'] as $table => $amountCondition) {
            $this->guard('sms_'.$table.'_valid', $table, 'INSERT', "{$amountCondition} OR NEW.revision < 1 OR TRIM(NEW.source_reference) = '' OR NEW.approved_at IS NULL");
        }
        $this->guard('sms_allocation_approval_valid', 'billing_batch_approvals', 'INSERT',
            "NEW.amount_snapshot <= 0 OR TRIM(NEW.reference) = '' OR NOT EXISTS (SELECT 1 FROM billing_batches b JOIN scholarship_budgets s ON s.id = NEW.scholarship_budget_id WHERE b.id = NEW.billing_batch_id AND b.status = 'submitted' AND b.submitted_at IS NOT NULL AND b.submitted_by IS NOT NULL AND b.prepared_by IS NOT NULL AND NEW.approved_by <> b.submitted_by AND NEW.approved_by <> b.prepared_by AND s.program_period_term_id = b.program_period_term_id) OR NOT EXISTS (SELECT 1 FROM billing_items i WHERE i.billing_batch_id = NEW.billing_batch_id) OR EXISTS (SELECT 1 FROM billing_items i WHERE i.billing_batch_id = NEW.billing_batch_id AND (i.status <> 'finalized' OR i.gross_amount IS NULL)) OR ROUND(NEW.amount_snapshot * 100) <> (SELECT SUM(ROUND(i.gross_amount * 100)) FROM billing_items i WHERE i.billing_batch_id = NEW.billing_batch_id)");
        $this->guard('sms_adjustment_valid', 'billing_adjustments', 'INSERT',
            "NEW.amount <= 0 OR NEW.amount_before < 0 OR NEW.amount_after < 0 OR NEW.sequence < 1 OR TRIM(NEW.reason) = '' OR TRIM(NEW.reference) = '' OR (NEW.direction = 'increase' AND ROUND(NEW.amount_after * 100) <> ROUND(NEW.amount_before * 100) + ROUND(NEW.amount * 100)) OR (NEW.direction = 'decrease' AND ROUND(NEW.amount_after * 100) <> ROUND(NEW.amount_before * 100) - ROUND(NEW.amount * 100)) OR NOT EXISTS (SELECT 1 FROM billing_items i JOIN billing_batches b ON b.id = i.billing_batch_id WHERE i.id = NEW.billing_item_id AND (EXISTS (SELECT 1 FROM billing_batch_approvals a WHERE a.billing_batch_id = b.id) OR (b.status = 'finalized' AND b.finalized_by IS NOT NULL AND b.finalized_at IS NOT NULL))) OR NEW.sequence <> COALESCE((SELECT MAX(a.sequence) FROM billing_adjustments a WHERE a.billing_item_id = NEW.billing_item_id), 0) + 1 OR ROUND(NEW.amount_before * 100) <> ROUND(COALESCE((SELECT a.amount_after FROM billing_adjustments a WHERE a.billing_item_id = NEW.billing_item_id ORDER BY a.sequence DESC LIMIT 1), (SELECT i.gross_amount FROM billing_items i WHERE i.id = NEW.billing_item_id)) * 100) OR (NEW.scholarship_rate_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM scholarship_rates r JOIN billing_items i ON i.id = NEW.billing_item_id JOIN billing_batches b ON b.id = i.billing_batch_id WHERE r.id = NEW.scholarship_rate_id AND r.program_period_term_id = b.program_period_term_id))");
    }

    private function guard(string $name, string $table, string $event, string $condition): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SMS scholarship allocation history or scope constraint'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'SMS scholarship allocation history or scope constraint'); END");
        }
    }

    public function down(): void
    {
        foreach (['scholarship_rates', 'scholarship_budgets', 'billing_batch_approvals', 'billing_adjustments'] as $table) {
            foreach (['update', 'delete'] as $event) {
                DB::unprepared('DROP TRIGGER IF EXISTS sms_'.$table.'_'.$event);
            }
        }
        foreach (['sms_batch_rate_scope_insert', 'sms_batch_rate_scope_update', 'sms_scholarship_rates_valid', 'sms_scholarship_budgets_valid', 'sms_allocation_approval_valid', 'sms_adjustment_valid'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
        Schema::table('billing_items', fn (Blueprint $table) => $table->dropUnique('sms_one_allocation_per_scholar_semester'));
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE billing_batches DROP COLUMN scholarship_rate_id');
        } else {
            Schema::table('billing_batches', fn (Blueprint $table) => $table->dropConstrainedForeignId('scholarship_rate_id'));
        }
        foreach (['billing_adjustments', 'billing_batch_approvals', 'scholarship_budgets', 'scholarship_rates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
