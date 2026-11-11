<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['funding_receipts', 'payouts'] as $name) {
            if (DB::getDriverName() === 'sqlite') {
                // Add the nullable FK directly; rebuilding tables would discard history triggers.
                DB::statement("ALTER TABLE {$name} ADD COLUMN recorded_by INTEGER NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT");
                DB::statement("ALTER TABLE {$name} ADD COLUMN record_key VARCHAR(64) NULL");
                Schema::table($name, fn (Blueprint $table) => $table->unique('record_key'));
            } else {
                Schema::table($name, function (Blueprint $table) {
                    $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
                    $table->string('record_key', 64)->nullable()->unique();
                });
            }
        }
        Schema::table('payouts', function (Blueprint $table) {
            $table->string('payment_mode', 20)->nullable()->index();
            $table->string('institutional_student_id', 50)->nullable();
            $table->json('basis_snapshot')->nullable();
        });
        Schema::table('deductions', function (Blueprint $table) {
            $table->string('authority_reference')->nullable();
            $table->string('consent_reference')->nullable();
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE deductions ADD COLUMN settled_by INTEGER NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT');
            DB::statement('ALTER TABLE liquidations ADD COLUMN submitted_by INTEGER NULL REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT');
        } else {
            Schema::table('deductions', fn (Blueprint $table) => $table->foreignId('settled_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate());
            Schema::table('liquidations', fn (Blueprint $table) => $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate());
        }
        foreach (['funding_receipts', 'payouts'] as $table) {
            $amount = $table === 'payouts' ? 'NEW.gross_amount' : 'NEW.amount';
            $extra = $table === 'payouts' ? " OR NEW.payment_mode NOT IN ('school','direct') OR NEW.payment_mode IS NULL OR NEW.institutional_student_id IS NULL OR TRIM(NEW.institutional_student_id) = '' OR NEW.method IS NULL OR TRIM(NEW.method) = '' OR NEW.payee IS NULL OR TRIM(NEW.payee) = ''" : " OR NEW.source IS NULL OR TRIM(NEW.source) = '' OR NEW.received_on IS NULL";
            foreach (['INSERT', 'UPDATE'] as $event) {
                $this->guard('sms_cash_'.$table.'_'.strtolower($event), $table, $event,
                    "NEW.record_key IS NOT NULL AND (NEW.recorded_by IS NULL OR {$amount} IS NULL OR {$amount} <= 0 OR NEW.reference IS NULL OR TRIM(NEW.reference) = '' {$extra} OR (NEW.status = 'posted' AND NEW.posted_by = NEW.recorded_by))");
            }
        }
        $changed = fn ($columns) => implode(' OR ', array_map(fn ($c) => DB::getDriverName() === 'mysql' ? "NOT (NEW.{$c} <=> OLD.{$c})" : "NEW.{$c} IS NOT OLD.{$c}", $columns));
        $this->guard('sms_deduction_authority_history', 'deductions', 'UPDATE', 'OLD.authorized_at IS NOT NULL AND ('.$changed(['authority_reference', 'consent_reference']).')');
        $this->guard('sms_cash_liquidation_frozen', 'liquidations', 'UPDATE', "OLD.status = 'returned' OR (OLD.status = 'submitted' AND (NEW.status NOT IN ('accepted','returned') OR NEW.reviewed_by IS NULL OR NEW.reviewed_at IS NULL OR NEW.reviewed_by = OLD.prepared_by OR NEW.reviewed_by = OLD.submitted_by OR ".$changed(['billing_batch_id', 'sequence', 'reference', 'prepared_by', 'submitted_by', 'submitted_at']).'))');
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
            $row = $event === 'DELETE' ? 'OLD' : 'NEW';
            $this->guard('sms_cash_liq_item_'.strtolower($event), 'liquidation_items', $event, "EXISTS (SELECT 1 FROM liquidations l WHERE l.id = {$row}.liquidation_id AND l.status IN ('submitted','returned'))".($event === 'UPDATE' ? " OR EXISTS (SELECT 1 FROM liquidations l WHERE l.id = OLD.liquidation_id AND l.status IN ('submitted','returned'))" : ''));
        }
        $this->guard('sms_cash_liquidation_delete', 'liquidations', 'DELETE', "OLD.status <> 'draft'");
        // An accepted payment/settlement line cannot be claimed by another accepted report.
        $this->guard('sms_cash_liquidation_coverage', 'liquidations', 'UPDATE', "NEW.status = 'accepted' AND EXISTS (SELECT 1 FROM liquidation_items n JOIN liquidation_items p ON (n.payout_id = p.payout_id OR n.deduction_id = p.deduction_id) JOIN liquidations l ON l.id = p.liquidation_id WHERE n.liquidation_id = NEW.id AND l.status = 'accepted' AND l.id <> NEW.id)");
    }

    private function guard(string $name, string $table, string $event, string $condition): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SMS financial recording/history constraint'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'SMS financial recording/history constraint'); END");
        }
    }

    public function down(): void
    {
        foreach (['sms_cash_funding_receipts_insert', 'sms_cash_funding_receipts_update', 'sms_cash_payouts_insert', 'sms_cash_payouts_update', 'sms_deduction_authority_history', 'sms_cash_liquidation_frozen', 'sms_cash_liq_item_insert', 'sms_cash_liq_item_update', 'sms_cash_liq_item_delete', 'sms_cash_liquidation_delete', 'sms_cash_liquidation_coverage'] as $name) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
        }
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE deductions DROP COLUMN settled_by');
            DB::statement('ALTER TABLE liquidations DROP COLUMN submitted_by');
        } else {
            Schema::table('deductions', fn (Blueprint $table) => $table->dropConstrainedForeignId('settled_by'));
            Schema::table('liquidations', fn (Blueprint $table) => $table->dropConstrainedForeignId('submitted_by'));
        }
        Schema::table('deductions', fn (Blueprint $table) => $table->dropColumn(['authority_reference', 'consent_reference']));
        Schema::table('payouts', fn (Blueprint $table) => $table->dropColumn(['payment_mode', 'institutional_student_id', 'basis_snapshot']));
        foreach (['funding_receipts', 'payouts'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropUnique(['record_key']));
            if (DB::getDriverName() === 'sqlite') {
                DB::statement("ALTER TABLE {$name} DROP COLUMN recorded_by");
            } else {
                Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('recorded_by'));
            }
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('record_key'));
        }
    }
};
