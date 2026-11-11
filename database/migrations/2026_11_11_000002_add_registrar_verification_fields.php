<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_records', function (Blueprint $table) {
            $table->enum('academic_standing', ['unverified', 'passing', 'failing'])->default('unverified');
            $table->text('correction_reason')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
        });

        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::unprepared('CREATE TRIGGER sms_academic_standing_'.strtolower($event)." BEFORE {$event} ON academic_records FOR EACH ROW WHEN NEW.academic_standing NOT IN ('unverified', 'passing', 'failing') BEGIN SELECT RAISE(ABORT, 'SMS academic standing constraint'); END");
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS sms_academic_standing_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS sms_academic_standing_update');
        Schema::table('academic_records', function (Blueprint $table) {
            $table->dropColumn(['academic_standing', 'correction_reason', 'lock_version']);
        });
    }
};
