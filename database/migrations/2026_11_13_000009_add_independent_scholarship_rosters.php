<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scholarship_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('scholarship_programs')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('academic_period_id')->constrained('academic_periods')->restrictOnDelete()->restrictOnUpdate();
            $table->string('batch_number', 60);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamps();
            $table->unique(['program_id', 'academic_period_id', 'batch_number'], 'sms_roster_name_uq');
            $table->unique(['id', 'program_id', 'academic_period_id'], 'sms_roster_context_uq');
        });
        Schema::create('scholarship_batch_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scholarship_batch_id');
            $table->foreignId('assignment_id');
            $table->foreignId('student_id');
            $table->foreignId('program_id');
            $table->foreignId('academic_period_id');
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('assigned_at');
            $table->timestamps();
            $table->foreign(['scholarship_batch_id', 'program_id', 'academic_period_id'], 'sms_member_roster_fk')
                ->references(['id', 'program_id', 'academic_period_id'])->on('scholarship_batches')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['assignment_id', 'student_id', 'program_id'], 'sms_member_assignment_fk')
                ->references(['id', 'student_id', 'program_id'])->on('scholarship_assignments')->restrictOnDelete()->restrictOnUpdate();
            $table->unique(['scholarship_batch_id', 'student_id'], 'sms_roster_student_uq');
            $table->index(['student_id', 'academic_period_id'], 'sms_member_student_period_idx');
        });
        // Native nullable addition retains existing SQLite history triggers and composite FKs.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE billing_batches ADD COLUMN scholarship_batch_id INTEGER NULL REFERENCES scholarship_batches(id) ON DELETE RESTRICT ON UPDATE RESTRICT');
            Schema::table('billing_batches', fn (Blueprint $table) => $table->index('scholarship_batch_id'));
        } else {
            Schema::table('billing_batches', fn (Blueprint $table) => $table->foreignId('scholarship_batch_id')->nullable()->constrained('scholarship_batches')->restrictOnDelete()->restrictOnUpdate());
        }
        foreach (['INSERT', 'UPDATE'] as $event) {
            $this->guard('sms_roster_billing_scope_'.strtolower($event), 'billing_batches', $event,
                'NEW.scholarship_batch_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM scholarship_batches r WHERE r.id = NEW.scholarship_batch_id AND r.program_id = NEW.program_id AND r.academic_period_id = NEW.academic_period_id)');
        }
        foreach (['scholarship_batches', 'scholarship_batch_memberships'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $event) {
                $this->guard('sms_'.$table.'_'.strtolower($event), $table, $event, '1 = 1');
            }
        }
    }

    private function guard(string $name, string $table, string $event, string $condition): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SMS roster history or scope constraint'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'SMS roster history or scope constraint'); END");
        }
    }

    public function down(): void
    {
        if (DB::table('scholarship_batches')->exists() || DB::table('scholarship_batch_memberships')->exists()) {
            throw new RuntimeException('Retain recorded roster history. Review a forward correction instead of dropping populated roster tables.');
        }
        foreach (['sms_roster_billing_scope_insert', 'sms_roster_billing_scope_update', 'sms_scholarship_batches_update', 'sms_scholarship_batches_delete', 'sms_scholarship_batch_memberships_update', 'sms_scholarship_batch_memberships_delete'] as $name) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
        }
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('billing_batches', fn (Blueprint $table) => $table->dropIndex(['scholarship_batch_id']));
            DB::statement('ALTER TABLE billing_batches DROP COLUMN scholarship_batch_id');
        } else {
            Schema::table('billing_batches', fn (Blueprint $table) => $table->dropConstrainedForeignId('scholarship_batch_id'));
        }
        Schema::dropIfExists('scholarship_batch_memberships');
        Schema::dropIfExists('scholarship_batches');
    }
};
