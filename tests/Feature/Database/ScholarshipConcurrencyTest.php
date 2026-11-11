<?php

namespace Tests\Feature\Database;

use App\Models\AcademicPeriod;
use App\Models\BillingBatch;
use App\Models\ProgramPeriodTerm;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipAssignment;
use App\Models\ScholarshipProgram;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsScholarshipRecords;
use Tests\TestCase;

class ScholarshipConcurrencyTest extends TestCase
{
    use BuildsScholarshipRecords;

    public function test_mysql_serializes_competing_active_assignments_and_semester_bills(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concurrent unique-index enforcement is verified by the MySQL test run.');
        }

        $previous = DB::getDefaultConnection();
        $admin = DB::connection($previous);
        $this->assertStringEndsWith('_test', $admin->getDatabaseName(), 'Use an isolated MySQL database ending in _test.');
        $database = 'cfci_sms_race_'.strtolower(Str::random(12)).'_test';
        $connection = $admin->getConfig();
        unset($connection['name']);
        $connection['database'] = $database;
        $connection['url'] = null;
        $admin->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        config(['database.connections.sms_concurrency_test' => $connection]);
        DB::setDefaultConnection('sms_concurrency_test');
        Schema::clearResolvedInstance('db.schema');

        try {
            (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
            foreach (glob(database_path('migrations/2026_11_11_*.php')) as $migration) {
                (require $migration)->up();
            }
            $g = $this->applicationContext();
            $g['assignment']->update(['status' => 'ended']);
            $assignment = ['student_id' => $g['student']->id, 'program_id' => $g['program']->id, 'status' => 'active'];
            $this->assertCompetingInsertIsRejected('scholarship_assignments', $assignment, $assignment, $connection);

            $secondBatch = BillingBatch::create([
                'program_period_term_id' => $g['terms']->id, 'program_id' => $g['program']->id,
                'academic_period_id' => $g['period']->id, 'batch_number' => 'RACE-SECOND',
            ]);
            $item = $this->billingItemData($g);
            $this->assertCompetingInsertIsRejected('billing_items', $item, array_replace($item, ['billing_batch_id' => $secondBatch->id]), $connection);
            // Different assignments and programs must also compete for the same scholar/semester slot.
            $period = AcademicPeriod::create(['academic_year' => '2026-2027', 'semester_code' => '2']);
            $rows = [];
            foreach (['TDP', 'TES'] as $code) {
                $program = ScholarshipProgram::firstOrCreate(['code' => $code], ['name' => $code]);
                $terms = ProgramPeriodTerm::create(['program_id' => $program->id, 'academic_period_id' => $period->id]);
                $assignment = ScholarshipAssignment::create(['student_id' => $g['student']->id, 'program_id' => $program->id, 'start_period_id' => $period->id]);
                $application = ScholarshipApplication::create(['student_id' => $g['student']->id, 'program_id' => $program->id, 'academic_period_id' => $period->id, 'program_period_term_id' => $terms->id, 'assignment_id' => $assignment->id]);
                $batch = BillingBatch::create(['program_period_term_id' => $terms->id, 'program_id' => $program->id, 'academic_period_id' => $period->id, 'batch_number' => 'Cross-program race']);
                $rows[] = ['billing_batch_id' => $batch->id, 'application_id' => $application->id, 'assignment_id' => $assignment->id, 'student_id' => $g['student']->id, 'program_id' => $program->id, 'academic_period_id' => $period->id];
            }
            $this->assertCompetingInsertIsRejected('billing_items', $rows[0], $rows[1], $connection);
            $revision = ['student_id' => $g['student']->id, 'academic_period_id' => $g['period']->id, 'revision' => 2, 'supersedes_id' => $g['record']->id];
            $this->assertCompetingInsertIsRejected('academic_records', $revision, $revision, $connection);
            $evaluation = ['application_id' => $g['application']->id, 'student_id' => $g['student']->id, 'program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'run_number' => 2, 'application_revision' => 1];
            $this->assertCompetingInsertIsRejected('evaluation_runs', $evaluation, $evaluation, $connection);
        } finally {
            DB::purge('sms_concurrency_test');
            DB::setDefaultConnection($previous);
            Schema::clearResolvedInstance('db.schema');
            // Only the random database created by this test is removed.
            $admin->statement("DROP DATABASE `{$database}`");
        }
    }

    private function assertCompetingInsertIsRejected(string $table, array $first, array $second, array $config): void
    {
        $script = <<<'PHP'
            $data = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
            $config = $data['config'];
            $dsn = !empty($config['unix_socket'])
                ? 'mysql:unix_socket='.$config['unix_socket'].';dbname='.$config['database']
                : 'mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'];
            $pdo = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('SET innodb_lock_wait_timeout = 5');
            echo "READY\n";
            flush();
            try {
                $columns = '`'.implode('`,`', array_keys($data['values'])).'`';
                $marks = implode(',', array_fill(0, count($data['values']), '?'));
                $pdo->prepare('INSERT INTO `'.$data['table'].'` ('.$columns.') VALUES ('.$marks.')')->execute(array_values($data['values']));
                echo json_encode(['saved' => true]);
            } catch (PDOException $exception) {
                echo json_encode(['saved' => false, 'state' => $exception->errorInfo[0], 'code' => $exception->errorInfo[1]]);
            }
            PHP;

        $process = new Process([PHP_BINARY, '-r', $script]);
        $process->setInput(json_encode(['config' => $config, 'table' => $table, 'values' => $second], JSON_THROW_ON_ERROR));
        $process->setTimeout(15);
        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            $connection->table($table)->insert($first);
            // Accumulate from start: waitUntil can consume READY during its initial
            // status refresh before installing its readiness callback on Windows.
            $readiness = '';
            $process->start(function ($type, $output) use (&$readiness) {
                if ($type === Process::OUT) {
                    $readiness .= $output;
                }
            });
            $deadline = microtime(true) + 10;
            while (! str_contains($readiness, 'READY') && $process->isRunning() && microtime(true) < $deadline) {
                usleep(1000);
            }
            $this->assertStringContainsString('READY', $readiness, $process->getErrorOutput());
            usleep(100000);
            $this->assertTrue($process->isRunning(), 'The competing writer should wait for the first writer to commit.');
            $connection->commit();
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $result = json_decode(explode("\n", trim($process->getOutput()))[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['saved' => false, 'state' => '23000', 'code' => 1062], $result);
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $process->stop();
        }
    }
}
