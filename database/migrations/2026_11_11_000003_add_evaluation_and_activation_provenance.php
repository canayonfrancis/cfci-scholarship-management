<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $triggers = $this->existingTriggers();
        Schema::table('evaluation_runs', function (Blueprint $table) {
            $table->foreignId('evaluated_by')->nullable()->index()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('evaluated_at')->nullable();
        });
        Schema::table('scholarship_assignments', function (Blueprint $table) {
            $table->foreignId('activation_evaluation_id')->nullable()->index()->constrained('evaluation_runs')->restrictOnDelete()->restrictOnUpdate();
            $table->foreignId('activation_approval_id')->nullable()->index()->constrained('external_approvals')->restrictOnDelete()->restrictOnUpdate();
            $table->timestamp('activated_at')->nullable();
        });
        $this->restoreTriggers($triggers);
    }

    public function down(): void
    {
        $triggers = $this->existingTriggers();
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('scholarship_assignments', function (Blueprint $table) {
                $table->dropIndex(['activation_evaluation_id']);
                $table->dropIndex(['activation_approval_id']);
            });
            Schema::table('evaluation_runs', fn (Blueprint $table) => $table->dropIndex(['evaluated_by']));
        }
        Schema::table('scholarship_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('activation_evaluation_id');
            $table->dropConstrainedForeignId('activation_approval_id');
            $table->dropColumn('activated_at');
        });
        Schema::table('evaluation_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('evaluated_by');
            $table->dropColumn('evaluated_at');
        });
        $this->restoreTriggers($triggers);
    }

    private function existingTriggers(): array
    {
        // SQLite rebuilds tables when adding/removing FKs, dropping their history triggers.
        return DB::getDriverName() === 'sqlite'
            ? DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' AND tbl_name IN ('evaluation_runs', 'scholarship_assignments')") : [];
    }

    private function restoreTriggers(array $triggers): void
    {
        foreach ($triggers as $trigger) {
            if (! DB::selectOne("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$trigger->name])) {
                DB::unprepared($trigger->sql);
            }
        }
    }
};
