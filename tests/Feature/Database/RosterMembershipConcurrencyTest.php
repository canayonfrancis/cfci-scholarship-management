<?php

namespace Tests\Feature\Database;

use App\Actions\Records\ManageScholarshipRoster;
use App\Models\AcademicPeriod;
use App\Models\ScholarshipAssignment;
use App\Models\ScholarshipBatchMembership;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RosterMembershipConcurrencyTest extends TestCase
{
    public function test_competing_membership_with_old_snapshot_waits_and_returns_duplicate_validation(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Membership concurrency requires an isolated MySQL/MariaDB _test database.');
        }
        $previous = DB::getDefaultConnection();
        $source = DB::connection();
        $this->assertStringEndsWith('_test', $source->getDatabaseName());
        $database = 'cfci_roster_race_'.bin2hex(random_bytes(6)).'_test';
        $source->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $config = $source->getConfig();
        unset($config['name']);
        $config['database'] = $database;
        $config['url'] = null;
        config(['database.connections.roster_race_test' => $config]);
        DB::setDefaultConnection('roster_race_test');
        Schema::clearResolvedInstance('db.schema');
        $process = null;
        try {
            foreach (glob(database_path('migrations/*.php')) as $file) {
                (require $file)->up();
            }
            $actor = User::factory()->create();
            $actor->roles()->create(['role' => 'coordinator']);
            $program = ScholarshipProgram::create(['code' => 'RACE', 'name' => 'Synthetic membership race']);
            $period = AcademicPeriod::create(['academic_year' => '2026-2027', 'semester_code' => '1']);
            $student = Student::create(['student_number' => 'RACE', 'first_name' => 'Race', 'last_name' => 'Scholar']);
            $assignment = ScholarshipAssignment::create(['student_id' => $student->id, 'program_id' => $program->id, 'start_period_id' => $period->id, 'status' => 'pending']);
            $batch = app(ManageScholarshipRoster::class)->create($actor, ['program_id' => $program->id, 'academic_period_id' => $period->id, 'batch_number' => 'Race roster']);
            $script = <<<'PHP'
                require 'vendor/autoload.php';
                $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                config(['database.connections.roster_child' => $input['config'], 'database.default' => 'roster_child', 'cache.default' => 'array']);
                $actor = App\Models\User::findOrFail($input['actor']);
                $student = App\Models\Student::findOrFail($input['student']);
                $batch = App\Models\ScholarshipBatch::findOrFail($input['batch']);
                Illuminate\Support\Facades\DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                Illuminate\Support\Facades\DB::beginTransaction();
                App\Models\ScholarshipBatchMembership::count();
                echo "READY\n"; flush();
                try {
                    app(App\Actions\Records\ManageScholarshipRoster::class)->join($student, $batch, $actor, $input['assignment']);
                    Illuminate\Support\Facades\DB::commit();
                    echo json_encode(['saved' => true]);
                } catch (Illuminate\Validation\ValidationException $error) {
                    Illuminate\Support\Facades\DB::rollBack();
                    echo json_encode(['saved' => false, 'duplicate' => isset($error->errors()['scholarship_batch_id'])]);
                }
                PHP;
            $process = new Process([PHP_BINARY, '-r', $script], base_path());
            $process->setInput(json_encode(['config' => $config, 'actor' => $actor->id, 'student' => $student->id, 'batch' => $batch->id, 'assignment' => $assignment->id], JSON_THROW_ON_ERROR));
            $process->setTimeout(20);
            DB::beginTransaction();
            app(ManageScholarshipRoster::class)->join($student, $batch, $actor, $assignment->id);
            $output = '';
            $process->start(function ($type, $chunk) use (&$output) {
                if ($type === Process::OUT) {
                    $output .= $chunk;
                }
            });
            $deadline = microtime(true) + 10;
            while (! str_contains($output, 'READY') && $process->isRunning() && microtime(true) < $deadline) {
                usleep(1000);
            }
            $this->assertStringContainsString('READY', $output, $process->getErrorOutput());
            usleep(100000);
            $this->assertTrue($process->isRunning(), 'The competing member assignment must wait for the student lock.');
            DB::commit();
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $result = json_decode(explode("\n", trim($process->getOutput()))[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['saved' => false, 'duplicate' => true], $result);
            $this->assertSame(1, ScholarshipBatchMembership::count());
            $this->assertSame(0, DB::table('billing_items')->count());
        } finally {
            while (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
            $process?->stop();
            DB::purge('roster_race_test');
            DB::setDefaultConnection($previous);
            Schema::clearResolvedInstance('db.schema');
            $source->statement('DROP DATABASE `'.$database.'`');
        }
    }
}
