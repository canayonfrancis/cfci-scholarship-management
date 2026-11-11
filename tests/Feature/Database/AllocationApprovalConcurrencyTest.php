<?php

namespace Tests\Feature\Database;

use App\Actions\Billing\ManageScholarshipFunds;
use App\Models\BillingBatch;
use App\Services\Billing\AllocationSummary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsBillableAllocations;
use Tests\Concerns\BuildsScholarshipRecords;
use Tests\TestCase;

class AllocationApprovalConcurrencyTest extends TestCase
{
    use BuildsBillableAllocations, BuildsScholarshipRecords;

    public function test_concurrent_administrator_approvals_cannot_spend_the_same_remaining_budget(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Budget locking is exercised on the isolated MySQL/MariaDB run.');
        }
        $previous = DB::getDefaultConnection();
        $adminConnection = DB::connection();
        $this->assertStringEndsWith('_test', $adminConnection->getDatabaseName());
        $database = 'sms_budget_race_'.bin2hex(random_bytes(6)).'_test';
        $config = $adminConnection->getConfig();
        unset($config['name']);
        $config['database'] = $database;
        $config['url'] = null;
        $adminConnection->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        config(['database.connections.allocation_race_test' => $config]);
        DB::setDefaultConnection('allocation_race_test');
        Schema::clearResolvedInstance('db.schema');
        $process = null;
        try {
            (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
            foreach (glob(database_path('migrations/2026_11_11_*.php')) as $file) {
                (require $file)->up();
            }
            Storage::fake('scholarship');
            $first = $this->submitted();
            $second = $this->submitted();
            $approver = $this->staff('administrator');
            $this->actingAs($approver)->post(route('billing-batches.budget'), ['program_id' => $first['program']->id, 'academic_period_id' => $first['period']->id, 'previous_budget' => 0, 'amount' => '7500.00', 'source_reference' => 'Synthetic budget for exactly one scholar'])->assertSessionHasNoErrors();
            $script = <<<'PHP'
                require 'vendor/autoload.php';
                $data = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                config(['database.connections.concurrent_test' => $data['config'], 'database.default' => 'concurrent_test', 'filesystems.disks.scholarship.root' => $data['evidence_root'], 'cache.default' => 'array', 'session.driver' => 'array']);
                echo "READY\n";
                flush();
                try {
                    app(App\Actions\Billing\ManageScholarshipFunds::class)->approve(App\Models\BillingBatch::findOrFail($data['batch_id']), App\Models\User::findOrFail($data['actor_id']), ['reference' => 'Synthetic competing approval']);
                    echo json_encode(['saved' => true]);
                } catch (Illuminate\Validation\ValidationException $exception) {
                    echo json_encode(['saved' => false, 'budget_rejected' => str_contains(json_encode($exception->errors()), 'budget')]);
                }
                PHP;
            $process = new Process([PHP_BINARY, '-r', $script], base_path());
            $process->setInput(json_encode(['config' => $config, 'evidence_root' => Storage::disk('scholarship')->path(''), 'batch_id' => $second['batch']->id, 'actor_id' => $approver->id], JSON_THROW_ON_ERROR));
            $process->setTimeout(20);
            DB::beginTransaction();
            app(ManageScholarshipFunds::class)->approve($first['batch'], $approver, ['reference' => 'Synthetic first approval']);
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
            $this->assertTrue($process->isRunning(), 'Second approval must wait for the first transaction.');
            DB::commit();
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $result = json_decode(explode("\n", trim($process->getOutput()))[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['saved' => false, 'budget_rejected' => true], $result);
            $this->assertSame(1, DB::table('billing_batch_approvals')->count());
            $summary = app(AllocationSummary::class)->forTerms($first['terms']);
            $this->assertSame('7500.00', $summary['allocated']);
            $this->assertSame('0.00', $summary['remaining']);
        } finally {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
            $process?->stop();
            DB::purge('allocation_race_test');
            DB::setDefaultConnection($previous);
            Schema::clearResolvedInstance('db.schema');
            $adminConnection->statement('DROP DATABASE `'.$database.'`');
        }
    }

    private function submitted(): array
    {
        $g = $this->ready('TES', '7500.00');
        $this->post(route('billing-batches.store'), ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id])->assertSessionHasNoErrors();
        $g['batch'] = BillingBatch::latest('id')->firstOrFail();
        $this->add($g)->assertSessionHasNoErrors();
        $this->submit($g['batch'])->assertSessionHasNoErrors();

        return $g;
    }
}
