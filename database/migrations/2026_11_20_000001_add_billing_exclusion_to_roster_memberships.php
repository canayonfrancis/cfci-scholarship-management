<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scholarship_batch_memberships', fn (Blueprint $table) => $table->json('billing_exclusion')->nullable());
        $this->guardIdentity();
    }

    // Permit exclusion metadata/updated_at only; retain the original immutable membership identity.
    private function guardIdentity(bool $allUpdates = false): void
    {
        $columns = ['id', 'scholarship_batch_id', 'assignment_id', 'student_id', 'program_id', 'academic_period_id', 'assigned_by', 'assigned_at', 'created_at'];
        $mysql = DB::getDriverName() === 'mysql';
        $condition = $allUpdates ? '1 = 1' : implode(' OR ', array_map(
            fn ($column) => $mysql ? "NOT (NEW.{$column} <=> OLD.{$column})" : "NEW.{$column} IS NOT OLD.{$column}", $columns
        ));
        DB::unprepared('DROP TRIGGER IF EXISTS sms_scholarship_batch_memberships_update');
        if ($mysql) {
            DB::unprepared("CREATE TRIGGER sms_scholarship_batch_memberships_update BEFORE UPDATE ON scholarship_batch_memberships FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'SMS roster membership identity is immutable'; END IF; END");
        } else {
            DB::unprepared("CREATE TRIGGER sms_scholarship_batch_memberships_update BEFORE UPDATE ON scholarship_batch_memberships FOR EACH ROW WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'SMS roster membership identity is immutable'); END");
        }
    }

    public function down(): void
    {
        if (DB::table('scholarship_batch_memberships')->whereNotNull('billing_exclusion')->exists()) {
            throw new RuntimeException('Retain recorded exclusion decisions, including released exclusions. Use a reviewed forward correction.');
        }
        // Native SQLite removal avoids rebuilding the table and losing its retained FK/history triggers.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE scholarship_batch_memberships DROP COLUMN billing_exclusion');
        } else {
            Schema::table('scholarship_batch_memberships', fn (Blueprint $table) => $table->dropColumn('billing_exclusion'));
        }
        $this->guardIdentity(true);
    }
};
