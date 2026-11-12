<?php

namespace Tests\Feature\Database;

use App\Actions\Billing\ManageBillingBatch;
use App\Models\AuditEvent;
use App\Models\BillingItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsBillableAllocations;
use Tests\Concerns\BuildsScholarshipRecords;
use Tests\TestCase;

class BillingRosterConcurrencyTest extends TestCase
{
    use BuildsBillableAllocations, BuildsScholarshipRecords;

    public function test_two_linked_batches_cannot_concurrently_reserve_the_same_roster_member(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Roster billing races require an isolated MySQL/MariaDB _test database.');
        }
        $previous = DB::getDefaultConnection();
        $source = DB::connection();
        $this->assertStringEndsWith('_test', $source->getDatabaseName());
        $database = 'cfci_billing_roster_'.bin2hex(random_bytes(6)).'_test';
        $source->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $config = $source->getConfig();
        unset($config['name']);
        $config['database'] = $database;
        $config['url'] = null;
        config(['database.connections.billing_roster_race' => $config]);
        DB::setDefaultConnection('billing_roster_race');
        Schema::clearResolvedInstance('db.schema');
        $process = null;
        try {
            foreach (glob(database_path('migrations/*.php')) as $file) {
                (require $file)->up();
            }
            Storage::fake('scholarship');
            $g = $this->ready();
            $actor = auth()->user();
            $scope = ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'scholarship_batch_id' => $g['roster']->id];
            $first = app(ManageBillingBatch::class)->create($actor, $scope);
            $second = app(ManageBillingBatch::class)->create($actor, $scope);
            $script = <<<'PHP'
                require 'vendor/autoload.php';
                $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                config(['database.connections.roster_child' => $input['config'], 'database.default' => 'roster_child', 'cache.default' => 'array', 'filesystems.disks.scholarship.root' => $input['evidence_root']]);
                Illuminate\Support\Facades\DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $actor = App\Models\User::findOrFail($input['actor']);
                $batch = App\Models\BillingBatch::findOrFail($input['batch']);
                Illuminate\Support\Facades\DB::beginTransaction();
                App\Models\BillingItem::count(); // Deliberately retain a snapshot older than the winning reservation.
                echo "READY\n"; flush();
                try {
                    app(App\Actions\Billing\ManageBillingBatch::class)->change($batch, $actor, 0, 'add', ['application_id' => $input['application']]);
                    Illuminate\Support\Facades\DB::commit();
                    echo json_encode(['saved' => true]);
                } catch (Illuminate\Validation\ValidationException $error) {
                    Illuminate\Support\Facades\DB::rollBack();
                    echo json_encode(['saved' => false, 'duplicate_rejected' => isset($error->errors()['billing'])]);
                }
                PHP;
            $process = new Process([PHP_BINARY, '-r', $script], base_path());
            $process->setInput(json_encode(['config' => $config, 'actor' => $actor->id, 'batch' => $second->id, 'application' => $g['application']->id, 'evidence_root' => Storage::disk('scholarship')->path('')], JSON_THROW_ON_ERROR));
            $process->setTimeout(20);
            DB::beginTransaction();
            app(ManageBillingBatch::class)->change($first, $actor, 0, 'add', ['application_id' => $g['application']->id]);
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
            $this->assertTrue($process->isRunning(), 'The competing reservation must wait for the first transaction.');
            DB::commit();
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $result = json_decode(explode("\n", trim($process->getOutput()))[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['saved' => false, 'duplicate_rejected' => true], $result);
            $this->assertSame(1, BillingItem::count());
            $this->assertSame($first->id, BillingItem::sole()->billing_batch_id);
            $this->assertSame(0, $second->fresh()->lock_version);
            $this->assertSame($g['roster']->id, $second->scholarship_batch_id);
            $this->assertSame(1, AuditEvent::where('action', 'billing_item_added')->count());
        } finally {
            while (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
            $process?->stop();
            DB::purge('billing_roster_race');
            DB::setDefaultConnection($previous);
            Schema::clearResolvedInstance('db.schema');
            $source->statement('DROP DATABASE `'.$database.'`');
        }
    }
}
