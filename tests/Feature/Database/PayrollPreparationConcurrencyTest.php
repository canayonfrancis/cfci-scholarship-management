<?php

namespace Tests\Feature\Database;

use App\Actions\Billing\PreparePayroll;
use App\Actions\Billing\RecordFinancialActivity;
use App\Models\Payroll;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsPayrollRecords;
use Tests\TestCase;

class PayrollPreparationConcurrencyTest extends TestCase
{
    use BuildsPayrollRecords;

    public static function races(): array
    {
        return [['same_reference'], ['different_reference'], ['posted_payment']];
    }

    #[DataProvider('races')]
    public function test_old_snapshot_cannot_duplicate_payroll_or_reuse_a_paid_benefit(string $operation): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Payroll races require an isolated MySQL/MariaDB _test database.');
        }
        $previous = DB::getDefaultConnection();
        $source = DB::connection();
        $this->assertStringEndsWith('_test', $source->getDatabaseName());
        $database = 'cfci_payroll_race_'.bin2hex(random_bytes(6)).'_test';
        $source->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $config = $source->getConfig();
        unset($config['name']);
        $config['database'] = $database;
        $config['url'] = null;
        config(['database.connections.payroll_race_test' => $config]);
        DB::setDefaultConnection('payroll_race_test');
        Schema::clearResolvedInstance('db.schema');
        $process = null;
        try {
            foreach (glob(database_path('migrations/*.php')) as $migration) {
                (require $migration)->up();
            }
            Storage::fake('scholarship');
            $g = $this->payrollBatch();
            $this->payrollFunds($g);
            $payment = null;
            if ($operation === 'posted_payment') {
                $payment = app(RecordFinancialActivity::class)->payout($g['recorder'], ['billing_item_id' => $g['item']->id, 'payment_mode' => 'school', 'gross_amount' => '7500.25', 'net_amount' => '7500.25', 'paid_on' => now('Asia/Manila')->toDateString(), 'method' => 'cash', 'reference' => 'RACE-PAYMENT']);
                $this->payrollProof($g, $payment, 'payment_evidence');
            }
            $script = <<<'PHP'
                require 'vendor/autoload.php';
                $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                config(['database.connections.payroll_child' => $input['config'], 'database.default' => 'payroll_child', 'filesystems.disks.scholarship.root' => $input['evidence_root'], 'cache.default' => 'array']);
                $actor = App\Models\User::with('roles')->findOrFail($input['actor']);
                $batch = App\Models\BillingBatch::findOrFail($input['batch']);
                Illuminate\Support\Facades\DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                Illuminate\Support\Facades\DB::beginTransaction();
                App\Models\Payroll::count();
                echo "READY\n"; flush();
                try {
                    $payroll = app(App\Actions\Billing\PreparePayroll::class)->handle($batch, $actor, $input['reference']);
                    Illuminate\Support\Facades\DB::commit();
                    echo json_encode(['saved' => true, 'payroll' => $payroll->id]);
                } catch (Illuminate\Validation\ValidationException $error) {
                    Illuminate\Support\Facades\DB::rollBack();
                    echo json_encode(['saved' => false, 'payroll_rejected' => isset($error->errors()['payroll'])]);
                }
                PHP;
            $process = new Process([PHP_BINARY, '-r', $script], base_path());
            $process->setInput(json_encode(['config' => $config, 'actor' => $g['recorder']->id, 'batch' => $g['batch']->id, 'reference' => $operation === 'different_reference' ? 'SECOND' : 'RACE', 'evidence_root' => Storage::disk('scholarship')->path('')], JSON_THROW_ON_ERROR));
            $process->setTimeout(25);
            DB::beginTransaction();
            $first = null;
            if ($payment) {
                app(RecordFinancialActivity::class)->postPayout($payment, $g['reviewer']);
            } else {
                $first = app(PreparePayroll::class)->handle($g['batch'], $g['recorder'], 'RACE');
            }
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
            $this->assertTrue($process->isRunning(), 'Payroll preparation must wait for the current financial scope lock.');
            DB::commit();
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $result = json_decode(explode("\n", trim($process->getOutput()))[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($operation === 'same_reference' ? ['saved' => true, 'payroll' => $first->id] : ['saved' => false, 'payroll_rejected' => true], $result);
            $this->assertSame($payment ? 0 : 1, Payroll::count());
            $this->assertSame($payment ? 1 : 0, DB::table('payouts')->where('status', 'posted')->count());
            $this->assertSame($payment ? 0 : 1, DB::table('payroll_items')->count());
        } finally {
            while (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
            $process?->stop();
            DB::purge('payroll_race_test');
            DB::setDefaultConnection($previous);
            Schema::clearResolvedInstance('db.schema');
            $source->statement('DROP DATABASE `'.$database.'`');
        }
    }
}
