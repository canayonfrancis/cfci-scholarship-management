<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_validation_context', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->foreignId('active_period_id')->nullable()->index()->constrained('academic_periods')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('source_period_id')->nullable()->index()->constrained('academic_periods')->restrictOnDelete()->restrictOnUpdate();
            $table->text('source_policy_reference')->nullable();
            $table->foreignId('confirmed_by')->nullable()->index()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
        });
        DB::table('academic_validation_context')->insert(['id' => 1, 'revision' => 0, 'created_at' => now(), 'updated_at' => now()]);
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE academic_validation_context ADD CONSTRAINT sms_single_academic_context CHECK (id = 1)');
        } else {
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::unprepared('CREATE TRIGGER sms_single_academic_context_'.strtolower($event)." BEFORE {$event} ON academic_validation_context FOR EACH ROW WHEN NEW.id <> 1 BEGIN SELECT RAISE(ABORT, 'SMS single academic context constraint'); END");
            }
        }

        Schema::create('academic_record_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_record_id')->index()->constrained('academic_records')->restrictOnDelete()->restrictOnUpdate();
            $table->string('subject_code', 60)->nullable();
            $table->string('subject_name');
            // Verbatim reported numbers, not calculated or rounded on an unconfirmed scale.
            foreach (['units', 'prelim', 'midterm', 'semi_final', 'final', 'average', 'equivalent'] as $column) {
                $table->string($column, 30)->nullable();
            }
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->unique(['academic_record_id', 'subject_code'], 'sms_record_subject_code_uq');
        });
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
            $newLocked = "EXISTS (SELECT 1 FROM academic_records r WHERE r.id = NEW.academic_record_id AND (r.verification_status <> 'pending' OR r.verified_at IS NOT NULL))";
            $oldLocked = str_replace('NEW.', 'OLD.', $newLocked);
            $condition = match ($event) {
                'INSERT' => $newLocked, 'DELETE' => $oldLocked, default => "({$oldLocked}) OR ({$newLocked})",
            };
            $name = 'sms_verified_grade_'.strtolower($event);
            if (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON academic_record_grades FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SMS verified academic grades are immutable'; END IF; END");
            } else {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON academic_record_grades FOR EACH ROW WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'SMS verified academic grades are immutable'); END");
            }
        }
    }

    public function down(): void
    {
        foreach (['insert', 'update', 'delete'] as $event) {
            DB::unprepared('DROP TRIGGER IF EXISTS sms_verified_grade_'.$event);
        }
        foreach (['insert', 'update'] as $event) {
            DB::unprepared('DROP TRIGGER IF EXISTS sms_single_academic_context_'.$event);
        }
        Schema::dropIfExists('academic_record_grades');
        Schema::dropIfExists('academic_validation_context');
    }
};
