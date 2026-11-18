<?php

namespace Tests\Feature\Database;

use App\Actions\Accounts\StudentAccountLifecycle;
use App\Actions\Accounts\StudentCredentials;
use App\Models\AuditEvent;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StudentLifecycleConcurrencyTest extends TestCase
{
    public static function races(): array
    {
        return [['consumed'], ['deactivated']];
    }

    #[DataProvider('races')]
    public function test_credential_consumer_waits_for_user_lock_and_revalidates_token_and_state(string $race): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Lifecycle locking requires isolated MySQL/MariaDB.');
        }
        $previous = DB::getDefaultConnection();
        $source = DB::connection();
        $this->assertStringEndsWith('_test', $source->getDatabaseName());
        $database = 'cfci_lifecycle_race_'.bin2hex(random_bytes(6)).'_test';
        $source->statement('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $config = $source->getConfig();
        unset($config['name']);
        $config['database'] = $database;
        $config['url'] = null;
        config(['database.connections.lifecycle_race' => $config]);
        DB::setDefaultConnection('lifecycle_race');
        Schema::clearResolvedInstance('db.schema');
        $process = null;
        try {
            foreach (glob(database_path('migrations/*.php')) as $file) {
                (require $file)->up();
            }
            $user = User::factory()->create();
            $user->roles()->create(['role' => 'student']);
            $user->forceFill(['student_setup_required' => true])->save();
            $student = Student::create(['student_number' => (string) Str::uuid(), 'first_name' => 'Race', 'last_name' => 'Fixture', 'user_id' => $user->id]);
            $actor = User::factory()->create();
            $actor->roles()->create(['role' => 'registrar']);
            $token = Password::createToken($user);
            $credentials = ['email' => $user->email, 'token' => $token, 'password' => 'chosen-race-password', 'password_confirmation' => 'chosen-race-password'];
            $script = <<<'PHP'
                require 'vendor/autoload.php';
                $input=json_decode(stream_get_contents(STDIN),true,flags:JSON_THROW_ON_ERROR);
                $app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                config(['database.connections.lifecycle_child'=>$input['config'],'database.default'=>'lifecycle_child','cache.default'=>'array','session.driver'=>'array']);
                echo "READY\n"; flush();
                try { $status=app(App\Actions\Accounts\StudentCredentials::class)->reset($input['credentials']); }
                catch(Throwable) { $status='unexpected_error'; }
                echo json_encode(['status'=>$status]);
                PHP;
            DB::beginTransaction();
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($race === 'consumed') {
                $this->assertSame(Password::PASSWORD_RESET, app(StudentCredentials::class)->reset($credentials));
            } else {
                $this->assertTrue(app(StudentAccountLifecycle::class)->transition($actor, $student->id, $user->email, 'Reviewed', true, false));
            }
            $process = new Process([PHP_BINARY, '-r', $script], base_path());
            $process->setInput(json_encode(['config' => $config, 'credentials' => $credentials], JSON_THROW_ON_ERROR));
            $process->setTimeout(20);
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
            $this->assertStringContainsString('READY', $output);
            usleep(100000);
            $this->assertTrue($process->isRunning(), 'Consumer must wait for the locked account.');
            DB::commit();
            $process->wait();
            $this->assertSame(0, $process->getExitCode());
            $result = json_decode(explode("\n", trim($process->getOutput()))[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['status' => Password::INVALID_TOKEN], $result);
            $this->assertSame(1, AuditEvent::count());
            $this->assertFalse(Password::tokenExists($user, $token));
            $this->assertSame($race === 'deactivated', $user->fresh()->student_setup_required);
        } finally {
            while (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
            $process?->stop();
            DB::purge('lifecycle_race');
            DB::setDefaultConnection($previous);
            Schema::clearResolvedInstance('db.schema');
            $source->statement('DROP DATABASE `'.$database.'`');
        }
    }
}
