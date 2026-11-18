<?php

namespace Tests\Feature\Database;

use App\Actions\Accounts\LinkStudentAccount;
use App\Models\AuditEvent;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StudentAccountProvisioningConcurrencyTest extends TestCase
{
    public static function competingLinks(): array
    {
        return [['same'], ['other_student'], ['other_account']];
    }

    #[DataProvider('competingLinks')]
    public function test_competing_link_waits_then_rechecks_ownership_without_duplicate_audits(string $competition): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Student link concurrency requires an isolated MySQL/MariaDB _test database.');
        }
        $previous = DB::getDefaultConnection();
        $source = DB::connection();
        $this->assertStringEndsWith('_test', $source->getDatabaseName());
        $database = 'cfci_student_service_race_'.bin2hex(random_bytes(6)).'_test';
        $source->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $config = $source->getConfig();
        unset($config['name']);
        $config['database'] = $database;
        $config['url'] = null;
        config(['database.connections.student_service_race_test' => $config]);
        DB::setDefaultConnection('student_service_race_test');
        Schema::clearResolvedInstance('db.schema');
        $process = null;
        try {
            foreach (glob(database_path('migrations/*.php')) as $file) {
                (require $file)->up();
            }
            $user = User::factory()->create();
            $user->roles()->create(['role' => 'Student']);
            $actor = User::factory()->create();
            $actor->roles()->create(['role' => 'registrar']);
            $student = Student::create(['student_number' => 'LINK-RACE', 'first_name' => 'Race', 'last_name' => 'Fixture']);
            $other = Student::create(['student_number' => 'OTHER-RACE', 'first_name' => 'Other', 'last_name' => 'Fixture']);
            $otherUser = User::factory()->create();
            $script = <<<'PHP'
                require 'vendor/autoload.php';
                $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                config(['database.connections.student_link_child' => $input['config'], 'database.default' => 'student_link_child', 'cache.default' => 'array']);
                echo "READY\n"; flush();
                $result = app(App\Actions\Accounts\LinkStudentAccount::class)->forActor(App\Models\User::findOrFail($input['actor']), $input['arguments']['email'], $input['arguments']['student_number'], 'Race identity confirmed', true);
                echo json_encode(['success' => $result->successful, 'code' => $result->code]);
                PHP;
            $arguments = ['email' => $competition === 'other_account' ? $otherUser->email : $user->email,
                'student_number' => $competition === 'other_student' ? $other->student_number : $student->student_number];
            $process = new Process([PHP_BINARY, '-r', $script], base_path());
            $process->setInput(json_encode(['config' => $config, 'arguments' => $arguments, 'actor' => $actor->id], JSON_THROW_ON_ERROR));
            $process->setTimeout(20);
            DB::beginTransaction();
            $result = app(LinkStudentAccount::class)->forActor($actor, $user->email, $student->student_number, 'Race identity confirmed', true);
            $this->assertTrue($result->successful);
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
            $this->assertStringContainsString('READY', $output, 'The competing process did not initialize.');
            usleep(100000);
            $this->assertTrue($process->isRunning(), 'The competing command must wait for the account/student locks.');
            DB::commit();
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), 'The competing process must complete normally.');
            $result = json_decode(explode("\n", trim($process->getOutput()))[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['success' => $competition === 'same', 'code' => $competition === 'same' ? 'unchanged' : 'association_conflict'], $result);
            $this->assertSame($user->id, $student->fresh()->user_id);
            $this->assertNull($other->fresh()->user_id);
            $this->assertSame(['student'], $user->roles()->pluck('role')->all());
            $this->assertSame(0, $otherUser->roles()->count());
            $this->assertSame(1, AuditEvent::count());
            $this->assertSame(2, DB::table('user_roles')->count());
            $this->assertSame($actor->id, AuditEvent::sole()->actor_id);
        } finally {
            while (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
            $process?->stop();
            DB::purge('student_service_race_test');
            DB::setDefaultConnection($previous);
            Schema::clearResolvedInstance('db.schema');
            $source->statement('DROP DATABASE `'.$database.'`');
        }
    }
}
