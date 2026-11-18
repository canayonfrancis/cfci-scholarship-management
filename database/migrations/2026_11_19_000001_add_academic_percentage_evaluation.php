<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_record_grades', fn (Blueprint $table) => $table->string('reported_status', 20)->nullable());
        Schema::table('academic_records', function (Blueprint $table) {
            $table->string('certified_percentage_gwa', 30)->nullable();
            $table->text('certified_gwa_reference')->nullable();
        });
        Schema::create('program_academic_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_period_term_id')->constrained('program_period_terms')->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedInteger('revision');
            $table->json('criteria');
            $table->string('version_reference', 100)->nullable();
            $table->text('source_reference')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['program_period_term_id', 'revision'], 'sms_academic_policy_version_uq');
        });
        foreach (['UPDATE', 'DELETE'] as $event) {
            $name = 'sms_academic_policy_'.strtolower($event);
            if (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON program_academic_requirements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Academic requirement versions are immutable'");
            } else {
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON program_academic_requirements BEGIN SELECT RAISE(ABORT, 'Academic requirement versions are immutable'); END");
            }
        }
    }

    public function down(): void
    {
        foreach (['update', 'delete'] as $event) {
            DB::unprepared('DROP TRIGGER IF EXISTS sms_academic_policy_'.$event);
        }
        Schema::dropIfExists('program_academic_requirements');
        Schema::table('academic_records', fn (Blueprint $table) => $table->dropColumn(['certified_percentage_gwa', 'certified_gwa_reference']));
        Schema::table('academic_record_grades', fn (Blueprint $table) => $table->dropColumn('reported_status'));
    }
};
