<?php

namespace Tests\Feature\Database;

use App\Actions\Billing\VerifyPaymentAcknowledgment;
use App\Models\PaymentAcknowledgment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsAcknowledgmentRecords;
use Tests\Concerns\BuildsPayrollRecords;
use Tests\TestCase;

class SignedAcknowledgmentConcurrencyTest extends TestCase
{
    use BuildsAcknowledgmentRecords, BuildsPayrollRecords;

    public static function requests(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('requests')]
    public function test_old_snapshot_cannot_duplicate_or_rewrite_signature_verification(bool $changeCoverage): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Signature races require an isolated MySQL/MariaDB _test database.');
        }
        $previous = DB::getDefaultConnection();
        $source = DB::connection();
        $this->assertStringEndsWith('_test', $source->getDatabaseName());
        $database = 'cfci_ack_race_'.bin2hex(random_bytes(6)).'_test';
        $source->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $config = $source->getConfig();
        unset($config['name']);
        $config['database'] = $database;
        $config['url'] = null;
        config(['database.connections.ack_race_test' => $config]);
        DB::setDefaultConnection('ack_race_test');
        Schema::clearResolvedInstance('db.schema');
        $process = null;
        try {
            foreach (glob(database_path('migrations/*.php')) as $migration) {
                (require $migration)->up();
            }
            Storage::fake('scholarship');
            $g = $this->payrollBatch();
            $payment = $this->payrollPayment($g, '7500.25', 'direct')->fresh();
            $document = $this->signedDocument($payment, $g['recorder']);
            $data = $this->acknowledgmentData($payment, $document->id);
            $childData = array_replace($data, $changeCoverage ? ['page_line' => 'Conflicting page'] : []);
            $script = <<<'PHP'
                require 'vendor/autoload.php';
                $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                config(['database.connections.ack_child' => $input['config'], 'database.default' => 'ack_child', 'filesystems.disks.scholarship.root' => $input['evidence_root'], 'cache.default' => 'array']);
                $actor = App\Models\User::with('roles')->findOrFail($input['actor']);
                $payment = App\Models\Payout::with('batch')->findOrFail($input['payment']);
                Illuminate\Support\Facades\DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                Illuminate\Support\Facades\DB::beginTransaction();
                App\Models\PaymentAcknowledgment::count();
                echo "READY\n"; flush();
                try {
                    $ack = app(App\Actions\Billing\VerifyPaymentAcknowledgment::class)->handle($payment, $actor, $input['data']);
                    Illuminate\Support\Facades\DB::commit();
                    echo json_encode(['saved' => true, 'id' => $ack->id]);
                } catch (Illuminate\Validation\ValidationException $error) {
                    Illuminate\Support\Facades\DB::rollBack();
                    echo json_encode(['saved' => false, 'rejected' => isset($error->errors()['acknowledgment'])]);
                }
                PHP;
            DB::beginTransaction();
            $first = app(VerifyPaymentAcknowledgment::class)->handle($payment, $g['reviewer'], $data);
            $process = new Process([PHP_BINARY, '-r', $script], base_path());
            $process->setInput(json_encode(['config' => $config, 'actor' => $g['reviewer']->id, 'payment' => $payment->id, 'data' => $childData, 'evidence_root' => Storage::disk('scholarship')->path('')], JSON_THROW_ON_ERROR));
            $process->setTimeout(25);
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
            $this->assertTrue($process->isRunning(), 'Signature verification must wait for the current financial scope lock.');
            DB::commit();
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $result = json_decode(explode("\n", trim($process->getOutput()))[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($changeCoverage ? ['saved' => false, 'rejected' => true] : ['saved' => true, 'id' => $first->id], $result);
            $this->assertSame(1, PaymentAcknowledgment::count());
            $this->assertSame($data['page_line'], PaymentAcknowledgment::sole()->page_line);
            $this->assertSame('posted', $payment->fresh()->status);
        } finally {
            while (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
            $process?->stop();
            DB::purge('ack_race_test');
            DB::setDefaultConnection($previous);
            Schema::clearResolvedInstance('db.schema');
            $source->statement('DROP DATABASE `'.$database.'`');
        }
    }
}
