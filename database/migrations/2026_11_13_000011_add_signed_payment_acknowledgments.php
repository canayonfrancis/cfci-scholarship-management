<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_acknowledgments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_id')->constrained('payouts')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('document_id')->unique()->constrained('documents')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('verified_at');
            $table->string('document_reference');
            $table->string('page_line');
            $table->string('institutional_student_id', 50);
            $table->string('beneficiary_name', 1100);
            $table->date('paid_on');
            $table->decimal('amount_received', 12, 2);
            $table->string('payment_reference');
            $table->string('document_checksum', 64);
            $table->string('policy_reference', 100);
            $table->json('evidence_snapshot');
            $table->timestamps();
        });
        Schema::table('liquidations', fn (Blueprint $table) => $table->json('compliance_snapshot')->nullable());
        $this->guard('sms_ack_scope_insert', 'payment_acknowledgments', 'INSERT',
            "TRIM(NEW.document_reference) = '' OR TRIM(NEW.page_line) = '' OR NEW.policy_reference <> 'confirmed-payment-acknowledgment-v1' OR NOT EXISTS (
                SELECT 1 FROM payouts p JOIN billing_items b ON b.id = p.billing_item_id JOIN documents d ON d.id = NEW.document_id
                WHERE p.id = NEW.payout_id AND p.status = 'posted' AND p.entry_type = 'original' AND b.student_id = NEW.student_id
                AND NOT EXISTS (SELECT 1 FROM payouts r WHERE r.reversal_of_id = p.id AND r.status = 'posted')
                AND d.payout_id = p.id AND d.document_type = 'signed_acknowledgment' AND d.review_status = 'accepted'
                AND d.reviewed_by IS NOT NULL AND d.reviewed_at IS NOT NULL AND d.uploaded_by <> NEW.verified_by
                AND (p.recorded_by IS NULL OR p.recorded_by <> NEW.verified_by)
                AND d.checksum = NEW.document_checksum AND NEW.institutional_student_id = p.institutional_student_id
                AND TRIM(NEW.institutional_student_id) <> '' AND NEW.beneficiary_name = p.payee AND TRIM(NEW.beneficiary_name) <> ''
                AND NEW.paid_on = p.paid_on AND NEW.amount_received = p.net_amount AND NEW.payment_reference = p.reference
            )");
        foreach (['UPDATE', 'DELETE'] as $event) {
            $this->guard('sms_ack_frozen_'.strtolower($event), 'payment_acknowledgments', $event, '1 = 1');
        }
        foreach (['INSERT', 'UPDATE'] as $event) {
            $changedStage = $event === 'INSERT' ? '1 = 1' : 'NEW.status <> OLD.status';
            $policy = "COALESCE(json_extract(NEW.compliance_snapshot, '$.policy'), '')";
            if (DB::getDriverName() === 'mysql') {
                $policy = "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(NEW.compliance_snapshot, '$.policy')), '')";
            }
            $this->guard('sms_liq_checklist_'.strtolower($event), 'liquidations', $event,
                "({$changedStage}) AND NEW.status IN ('submitted','accepted') AND (
                    NEW.compliance_snapshot IS NULL OR {$policy} <> 'confirmed-payment-acknowledgment-v1'
                    OR NOT EXISTS (SELECT 1 FROM documents d WHERE d.liquidation_id = NEW.id AND d.document_type = 'liquidation_support' AND d.review_status = 'accepted' AND d.reviewed_by IS NOT NULL AND d.reviewed_at IS NOT NULL)
                    OR EXISTS (SELECT 1 FROM payouts p WHERE p.billing_batch_id = NEW.billing_batch_id AND p.status = 'posted' AND p.entry_type = 'original'
                        AND NOT EXISTS (SELECT 1 FROM payment_acknowledgments a JOIN documents d ON d.id = a.document_id WHERE a.payout_id = p.id AND d.payout_id = p.id AND d.document_type = 'signed_acknowledgment' AND d.review_status = 'accepted' AND d.checksum = a.document_checksum))
                )");
        }
        $changed = DB::getDriverName() === 'mysql' ? 'NOT (NEW.compliance_snapshot <=> OLD.compliance_snapshot)' : 'NEW.compliance_snapshot IS NOT OLD.compliance_snapshot';
        $this->guard('sms_liq_checklist_frozen', 'liquidations', 'UPDATE', "OLD.compliance_snapshot IS NOT NULL AND ({$changed})");
    }

    private function guard(string $name, string $table, string $event, string $condition): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SMS signed acknowledgment or liquidation checklist constraint'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'SMS signed acknowledgment or liquidation checklist constraint'); END");
        }
    }

    public function down(): void
    {
        if (DB::table('payment_acknowledgments')->exists() || DB::table('liquidations')->whereNotNull('compliance_snapshot')->exists()) {
            throw new RuntimeException('Retain signed acknowledgment and liquidation checklist history; populated rollback is prohibited.');
        }
        foreach (['sms_liq_checklist_insert', 'sms_liq_checklist_update', 'sms_liq_checklist_frozen'] as $name) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
        }
        Schema::dropIfExists('payment_acknowledgments');
        Schema::table('liquidations', fn (Blueprint $table) => $table->dropColumn('compliance_snapshot'));
    }
};
