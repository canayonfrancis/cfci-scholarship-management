<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_assignments', function (Blueprint $table) {
            $table->string('closure_reason', 30)->nullable()->index();
        });

        foreach (['UPDATE', 'DELETE'] as $event) {
            $name = 'sms_closed_assignment_'.strtolower($event);
            if (DB::getDriverName() === 'mysql') {
                DB::unprepared("CREATE TRIGGER `{$name}` BEFORE {$event} ON scholarship_assignments FOR EACH ROW BEGIN IF OLD.status = 'ended' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Closed scholarship history is immutable'; END IF; END");
            } else {
                DB::unprepared("CREATE TRIGGER `{$name}` BEFORE {$event} ON scholarship_assignments FOR EACH ROW WHEN OLD.status = 'ended' BEGIN SELECT RAISE(ABORT, 'Closed scholarship history is immutable'); END");
            }
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS sms_closed_assignment_update');
        DB::unprepared('DROP TRIGGER IF EXISTS sms_closed_assignment_delete');
        Schema::table('scholarship_assignments', function (Blueprint $table) {
            $table->dropIndex(['closure_reason']);
            $table->dropColumn('closure_reason');
        });
    }
};
