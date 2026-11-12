<?php

namespace Tests\Feature\Database;

use App\Actions\Billing\ManageBillingBatch;
use App\Models\AcademicPeriod;
use App\Models\BillingBatch;
use App\Models\ScholarshipProgram;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BatchNumberConcurrencyTest extends TestCase
{
    public function test_competing_manual_numbers_return_a_validation_error_even_with_an_older_snapshot(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Manual batch-number locking requires an isolated MySQL/MariaDB _test database.');
        }
        $previous = DB::getDefaultConnection();
        $source = DB::connection();
        $this->assertStringEndsWith('_test', $source->getDatabaseName());
        $database = 'cfci_batch_number_'.bin2hex(random_bytes(6)).'_test';
        $source->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $config = $source->getConfig();
        unset($config['name']);
        $config['database'] = $database;
        $config['url'] = null;
        config(['database.connections.batch_number_test' => $config]);
        DB::setDefaultConnection('batch_number_test');
        Schema::clearResolvedInstance('db.schema');
        $process = null;
        try {
            foreach (glob(database_path('migrations/*.php')) as $file) {
                (require $file)->up();
            }
            $actor = User::factory()->create();
            $actor->roles()->create(['role' => 'coordinator']);
            $program = ScholarshipProgram::create(['code' => 'SAMPLEBN', 'name' => 'Synthetic concurrency fixture']);
            $period = AcademicPeriod::create(['academic_year' => '2026-2027', 'semester_code' => '1']);
            $data = ['program_id' => $program->id, 'academic_period_id' => $period->id];
            app(ManageBillingBatch::class)->configure($actor, $data + ['semester_gross_amount' => '7500.00', 'billing_source_reference' => 'SYNTHETIC batch-number test rate']);
            $script = <<<'PHP'
                require 'vendor/autoload.php';
                $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                config(['database.connections.batch_number_child' => $input['config'], 'database.default' => 'batch_number_child', 'cache.default' => 'array']);
                Illuminate\Support\Facades\DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $actor = App\Models\User::findOrFail($input['actor']);
                Illuminate\Support\Facades\DB::beginTransaction();
                App\Models\BillingBatch::count(); // Establish an old read snapshot before the first batch commits.
                echo "READY\n"; flush();
                try {
                    app(App\Actions\Billing\ManageBillingBatch::class)->create($actor, $input['data']);
                    Illuminate\Support\Facades\DB::commit();
                    echo json_encode(['saved' => true]);
                } catch (Illuminate\Validation\ValidationException $exception) {
                    Illuminate\Support\Facades\DB::rollBack();
                    echo json_encode(['saved' => false, 'number_rejected' => isset($exception->errors()['batch_number'])]);
                }
                PHP;
            $process = new Process([PHP_BINARY, '-r', $script], base_path());
            $process->setInput(json_encode(['config' => $config, 'actor' => $actor->id, 'data' => $data + ['batch_number' => 3]], JSON_THROW_ON_ERROR));
            $process->setTimeout(20);
            DB::beginTransaction();
            app(ManageBillingBatch::class)->create($actor, $data + ['batch_number' => 3]);
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
            $this->assertTrue($process->isRunning(), 'The competing creator must wait for the first transaction.');
            DB::commit();
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $result = json_decode(explode("\n", trim($process->getOutput()))[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['saved' => false, 'number_rejected' => true], $result);
            $this->assertSame(1, BillingBatch::count());
            $this->assertSame('Batch 3', BillingBatch::sole()->batch_number);
        } finally {
            while (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
            $process?->stop();
            DB::purge('batch_number_test');
            DB::setDefaultConnection($previous);
            Schema::clearResolvedInstance('db.schema');
            $source->statement('DROP DATABASE `'.$database.'`');
        }
    }
}
