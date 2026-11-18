<?php

namespace Tests\Feature\Database;

use App\Actions\Accounts\LinkStudentAccount;
use App\Models\AuditEvent;
use App\Models\Student;
use App\Models\User;
use App\Services\Accounts\StudentCredentialDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StudentAccountCreationConcurrencyTest extends TestCase
{
    public static function competingCreations(): array
    {
        return [['same'], ['other_student'], ['other_account']];
    }

    #[DataProvider('competingCreations')]
    public function test_competing_creation_serializes_and_leaves_one_pending_account_without_orphans(string $competition): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Student creation concurrency requires an isolated MySQL/MariaDB _test database.');
        }
        $previous = DB::getDefaultConnection();
        $source = DB::connection();
        $this->assertStringEndsWith('_test', $source->getDatabaseName());
        $database = 'cfci_create_student_race_'.bin2hex(random_bytes(6)).'_test';
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
            $email = 'create-race@example.test';
            $otherEmail = 'other-create-race@example.test';
            $this->partialMock(StudentCredentialDelivery::class)->shouldReceive('available')->andReturn(true);
            $actor = User::factory()->create();
            $actor->roles()->create(['role' => 'registrar']);
            $student = Student::create(['student_number' => 'LINK-RACE', 'first_name' => 'Race', 'last_name' => 'Fixture', 'email' => $email]);
            $other = Student::create(['student_number' => 'OTHER-RACE', 'first_name' => 'Other', 'last_name' => 'Fixture', 'email' => $otherEmail]);
            $script = <<<'PHP'
                require 'vendor/autoload.php';
                $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
                $app = require 'bootstrap/app.php';
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                config(['database.connections.student_link_child' => $input['config'], 'database.default' => 'student_link_child', 'cache.default' => 'array']);
                $app->instance(App\Services\Accounts\StudentCredentialDelivery::class, new class extends App\Services\Accounts\StudentCredentialDelivery { public function available(): bool { return true; } });
                echo "READY\n"; flush();
                $result = app(App\Actions\Accounts\LinkStudentAccount::class)->createForActor(App\Models\User::findOrFail($input['actor']), $input['arguments']['student_number'], $input['arguments']['email'], 'Race identity confirmed', true);
                echo json_encode(['success' => $result->successful, 'code' => $result->code]);
                PHP;
            $arguments = ['email' => $competition === 'other_account' ? $otherEmail : $email,
                'student_number' => $competition === 'other_student' ? $other->student_number : $student->student_number];
            $process = new Process([PHP_BINARY, '-r', $script], base_path());
            $process->setInput(json_encode(['config' => $config, 'arguments' => $arguments, 'actor' => $actor->id], JSON_THROW_ON_ERROR));
            $process->setTimeout(20);
            DB::beginTransaction();
            $result = app(LinkStudentAccount::class)->createForActor($actor, $student->student_number, $email, 'Race identity confirmed', true);
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
            $this->assertSame(['success' => $competition === 'same', 'code' => $competition === 'same' ? 'unchanged' : ($competition === 'other_student' ? 'email_conflict' : 'association_conflict')], $result);
            $user = $student->fresh()->user;
            $this->assertNotNull($user);
            $this->assertTrue($user->student_setup_required);
            $this->assertSame(2, User::count());
            $this->assertNull($other->fresh()->user_id);
            $this->assertSame(['student'], $user->roles()->pluck('role')->all());
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
