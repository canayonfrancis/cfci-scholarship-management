<?php

namespace Tests\Feature\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScholarshipMigrationTest extends TestCase
{
    public function test_additive_migration_and_rollback_preserve_existing_authentication_and_unrelated_data(): void
    {
        $previous = DB::getDefaultConnection();
        $admin = DB::connection($previous);
        $database = null;
        if ($admin->getDriverName() === 'mysql') {
            $this->assertStringEndsWith('_test', $admin->getDatabaseName());
            $database = 'cfci_sms_migration_'.bin2hex(random_bytes(6)).'_test';
            $connection = $admin->getConfig();
            unset($connection['name']);
            $connection['database'] = $database;
            $connection['url'] = null;
            $admin->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } else {
            $connection = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true];
        }
        config(['database.connections.sms_migration_test' => $connection]);
        DB::setDefaultConnection('sms_migration_test');
        Schema::clearResolvedInstance('db.schema');

        try {
            $auth = require database_path('migrations/0001_01_01_000000_create_users_table.php');
            $auth->up();
            DB::table('users')->insert(['id' => 1, 'name' => 'Existing Account', 'email' => 'existing@example.test', 'password' => 'existing-password-hash']);
            DB::table('sessions')->insert(['id' => 'existing-session', 'user_id' => 1, 'payload' => 'existing-payload', 'last_activity' => 1]);
            Schema::create('legacy_school_records', fn (Blueprint $table) => $table->string('reference')->primary());
            DB::table('legacy_school_records')->insert(['reference' => 'preserve-this']);

            $foundation = require database_path('migrations/2026_11_11_000000_create_scholarship_foundation_tables.php');
            $foundation->up();
            $records = require database_path('migrations/2026_11_11_000001_preserve_closed_scholarship_assignments.php');
            $records->up();
            $this->assertTrue(Schema::hasColumn('scholarship_assignments', 'closure_reason'));
            $this->assertTrue(Schema::hasTable('students'));
            $this->assertSame('existing-password-hash', DB::table('users')->where('id', 1)->value('password'));
            $this->assertSame('existing-payload', DB::table('sessions')->value('payload'));
            $this->assertSame('preserve-this', DB::table('legacy_school_records')->value('reference'));
            $records->down();
            $this->assertFalse(Schema::hasColumn('scholarship_assignments', 'closure_reason'));
            $foundation->down();

            $this->assertFalse(Schema::hasTable('students'));
            $this->assertSame(1, DB::table('users')->count());
            $this->assertSame(1, DB::table('sessions')->count());
            $this->assertSame('preserve-this', DB::table('legacy_school_records')->value('reference'));
            $foundation->up();
            $records->up();
            $this->assertTrue(Schema::hasTable('liquidation_items'));
            $this->assertSame(1, DB::table('users')->count());
        } finally {
            DB::purge('sms_migration_test');
            DB::setDefaultConnection($previous);
            Schema::clearResolvedInstance('db.schema');
            if ($database !== null) {
                $admin->statement("DROP DATABASE `{$database}`");
            }
        }
    }
}
