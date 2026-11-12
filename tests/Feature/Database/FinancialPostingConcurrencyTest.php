<?php

namespace Tests\Feature\Database;

use App\Actions\Billing\RecordFinancialActivity;
use App\Models\BillingBatch;
use App\Models\Payout;
use App\Services\Billing\FinancialEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsBillableAllocations;
use Tests\Concerns\BuildsScholarshipRecords;
use Tests\TestCase;

class FinancialPostingConcurrencyTest extends TestCase
{
    use BuildsBillableAllocations, BuildsScholarshipRecords;

    public function test_repeatable_read_contender_cannot_reuse_the_original_payment_balance(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Run on an isolated MySQL/MariaDB _test connection for real locking reads.');
        }
        $previous = DB::getDefaultConnection();
        $source = DB::connection();
        $this->assertStringEndsWith('_test', $source->getDatabaseName());
        $name = 'cfci_cash_race_'.bin2hex(random_bytes(6)).'_test';
        $source->statement('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $config = $source->getConfig();
        unset($config['name']);
        $config['database'] = $name;
        $config['url'] = null;
        config(['database.connections.cash_race_test' => $config]);
        DB::setDefaultConnection('cash_race_test');
        Schema::clearResolvedInstance('db.schema');
        $process = null;
        try {
            // Build a new schema additively; never reset or delete an existing database.
            foreach (glob(database_path('migrations/*.php')) as $migration) {
                (require $migration)->up();
            }
            Storage::fake('scholarship');
            $g = $this->ready('TES', '7500.00');
            $finance = auth()->user();
            $this->post(route('billing-batches.store'), ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'scholarship_batch_id' => $g['roster']->id])->assertSessionHasNoErrors();
            $g['batch'] = BillingBatch::latest('id')->firstOrFail();
            $this->add($g)->assertSessionHasNoErrors();
            $this->submit($g['batch'])->assertSessionHasNoErrors();
            $admin = $this->staff('administrator');
            $this->actingAs($admin)->post(route('billing-batches.budget'), ['program_id' => $g['program']->id, 'academic_period_id' => $g['period']->id, 'previous_budget' => 0, 'amount' => '7500.00', 'source_reference' => 'SAMPLE concurrency budget'])->assertSessionHasNoErrors();
            $this->post(route('billing-batches.approve', $g['batch']), ['reference' => 'SAMPLE concurrency approval'])->assertSessionHasNoErrors();
            $item = $g['batch']->items()->firstOrFail();
            $payments = [];
            foreach ([1, 2] as $number) {
                $payment = app(RecordFinancialActivity::class)->payout($finance, ['billing_item_id' => $item->id, 'payment_mode' => 'direct', 'gross_amount' => '4000.00', 'net_amount' => '4000.00', 'paid_on' => now('Asia/Manila')->toDateString(), 'method' => 'bank_transfer', 'reference' => 'SAMPLE-RACE-'.$number]);
                $doc = app(FinancialEvidence::class)->upload($payment, $finance, UploadedFile::fake()->createWithContent('sample.pdf', "%PDF-1.4\nSynthetic test proof\n%%EOF"), 'payment_evidence');
                app(FinancialEvidence::class)->review($doc, $admin, 'accepted', 'SAMPLE independently checked');
                $payments[] = $payment;
            }
            $script = <<<'PHP'
                require 'vendor/autoload.php';
                $data = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                config(['database.connections.contender' => $data['config'], 'database.default' => 'contender', 'filesystems.disks.scholarship.root' => $data['evidence_root'], 'cache.default' => 'array']);
                echo "READY\n"; flush();
                try {
                    app(App\Actions\Billing\RecordFinancialActivity::class)->postPayout(App\Models\Payout::findOrFail($data['payment']), App\Models\User::findOrFail($data['actor']));
                    echo json_encode(['saved' => true]);
                } catch (Illuminate\Validation\ValidationException $error) {
                    echo json_encode(['saved' => false, 'balance_rejected' => str_contains(json_encode($error->errors()), 'allocation')]);
                }
                PHP;
            $process = new Process([PHP_BINARY, '-r', $script], base_path());
            $process->setInput(json_encode(['config' => $config, 'payment' => $payments[1]->id, 'actor' => $admin->id, 'evidence_root' => Storage::disk('scholarship')->path('')], JSON_THROW_ON_ERROR));
            $process->setTimeout(20);
            DB::beginTransaction();
            app(RecordFinancialActivity::class)->postPayout($payments[0], $admin);
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
            $this->assertTrue($process->isRunning(), 'The competing verifier must wait for the first posting transaction.');
            DB::commit();
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $result = json_decode(explode("\n", trim($process->getOutput()))[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['saved' => false, 'balance_rejected' => true], $result);
            $this->assertSame(1, Payout::where('status', 'posted')->count());
            $this->assertSame('4000.00', Payout::where('status', 'posted')->sole()->gross_amount);
        } finally {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
            $process?->stop();
            DB::purge('cash_race_test');
            DB::setDefaultConnection($previous);
            Schema::clearResolvedInstance('db.schema');
            // Keep the uniquely named test database as evidence; no destructive cleanup.
        }
    }
}
