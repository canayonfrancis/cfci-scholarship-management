<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_batch_id')->unique()->constrained('billing_batches')->restrictOnDelete()->restrictOnUpdate();
            $table->string('reference');
            $table->enum('status', ['draft', 'prepared'])->default('draft');
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('prepared_at')->nullable();
            $table->json('funding_snapshot');
            $table->timestamps();
            $table->unique(['id', 'billing_batch_id'], 'sms_payroll_batch_uq');
        });
        Schema::create('payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id');
            $table->foreignId('billing_batch_id');
            $table->foreignId('billing_item_id');
            $table->string('institutional_student_id', 50);
            $table->string('beneficiary_name', 1100);
            $table->decimal('effective_amount_snapshot', 12, 2);
            $table->decimal('posted_gross_snapshot', 12, 2);
            $table->decimal('planned_gross_amount', 12, 2);
            $table->json('basis_snapshot');
            $table->timestamps();
            $table->foreign(['payroll_id', 'billing_batch_id'], 'sms_payroll_item_parent_fk')->references(['id', 'billing_batch_id'])->on('payrolls')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['billing_item_id', 'billing_batch_id'], 'sms_payroll_item_bill_fk')->references(['id', 'billing_batch_id'])->on('billing_items')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['payroll_id', 'billing_item_id'], 'sms_payroll_item_bill_uq');
        });
        foreach (['INSERT', 'UPDATE'] as $event) {
            $suffix = strtolower($event);
            $this->guard('sms_payroll_shape_'.$suffix, 'payrolls', $event,
                "TRIM(NEW.reference) = '' OR (NEW.status = 'draft' AND NEW.prepared_at IS NOT NULL) OR (NEW.status = 'prepared' AND (NEW.prepared_at IS NULL OR NOT EXISTS (SELECT 1 FROM payroll_items i WHERE i.payroll_id = NEW.id) OR NOT EXISTS (SELECT 1 FROM billing_batches b WHERE b.id = NEW.billing_batch_id AND ((b.status = 'submitted' AND b.submitted_at IS NOT NULL AND EXISTS (SELECT 1 FROM billing_batch_approvals a WHERE a.billing_batch_id = b.id)) OR (b.status = 'finalized' AND b.finalized_at IS NOT NULL AND b.finalized_by IS NOT NULL)))))");
            $this->guard('sms_payroll_item_shape_'.$suffix, 'payroll_items', $event,
                "NEW.planned_gross_amount <= 0 OR NEW.posted_gross_snapshot < 0 OR NEW.effective_amount_snapshot < NEW.posted_gross_snapshot OR ROUND(NEW.planned_gross_amount * 100) <> ROUND(NEW.effective_amount_snapshot * 100) - ROUND(NEW.posted_gross_snapshot * 100) OR TRIM(NEW.institutional_student_id) = '' OR TRIM(NEW.beneficiary_name) = '' OR NOT EXISTS (SELECT 1 FROM billing_items b WHERE b.id = NEW.billing_item_id AND b.status = 'finalized')");
            $condition = "EXISTS (SELECT 1 FROM payrolls p WHERE p.id = NEW.payroll_id AND p.status = 'prepared')";
            if ($event === 'UPDATE') {
                $condition .= " OR EXISTS (SELECT 1 FROM payrolls p WHERE p.id = OLD.payroll_id AND p.status = 'prepared')";
            }
            $this->guard('sms_payroll_item_parent_'.$suffix, 'payroll_items', $event, $condition);
        }
        foreach (['UPDATE', 'DELETE'] as $event) {
            $this->guard('sms_payroll_frozen_'.strtolower($event), 'payrolls', $event, "OLD.status = 'prepared'");
        }
        $this->guard('sms_payroll_item_frozen_delete', 'payroll_items', 'DELETE', "EXISTS (SELECT 1 FROM payrolls p WHERE p.id = OLD.payroll_id AND p.status = 'prepared')");
    }

    private function guard(string $name, string $table, string $event, string $condition): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SMS payroll preparation or history constraint'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'SMS payroll preparation or history constraint'); END");
        }
    }

    public function down(): void
    {
        if (DB::table('payrolls')->exists()) {
            throw new RuntimeException('Retain prepared payroll history; do not drop populated payroll tables.');
        }
        Schema::dropIfExists('payroll_items');
        Schema::dropIfExists('payrolls');
    }
};
