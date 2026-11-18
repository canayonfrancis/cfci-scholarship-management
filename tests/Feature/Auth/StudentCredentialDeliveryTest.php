<?php

namespace Tests\Feature\Auth;

use App\Actions\Accounts\StudentCredentials;
use App\Models\AuditEvent;
use App\Models\Student;
use App\Models\User;
use App\Services\Accounts\StudentCredentialDelivery;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentCredentialDeliveryTest extends TestCase
{
    use DatabaseMigrations;

    private function fixture(): array
    {
        $user = User::factory()->create();
        $user->roles()->create(['role' => 'student']);
        $user->forceFill(['student_setup_required' => true])->save();
        $student = Student::create(['student_number' => (string) Str::uuid(), 'first_name' => 'Credential', 'last_name' => 'Fixture', 'user_id' => $user->id]);
        $actor = User::factory()->create();
        $actor->roles()->create(['role' => 'registrar']);

        return [$user, $student, $actor];
    }

    public function test_approved_transport_is_only_simulated_and_broker_throttle_resend_events_and_consumption_work(): void
    {
        Notification::fake();
        Event::fake([PasswordResetLinkSent::class, PasswordReset::class]);
        config(['student_accounts.mailer' => 'student_accounts', 'app.url' => 'https://sms.example.test',
            'mail.mailers.student_accounts' => ['transport' => 'smtp', 'scheme' => 'smtp', 'require_tls' => true, 'host' => 'smtp.example.test', 'port' => 587,
                'username' => 'test-only-user', 'password' => 'test-only-secret', 'from' => ['address' => 'sender@example.test']]]);
        $this->partialMock(StudentCredentialDelivery::class)->shouldReceive('available')->andReturn(true);
        [$user, $student, $actor] = $this->fixture();
        $credentials = app(StudentCredentials::class);
        $this->assertSame(Password::RESET_LINK_SENT, $credentials->issue($user, $actor, 'Identity reviewed', true, $student->id));
        Notification::assertSentTo($user, ResetPassword::class);
        Event::assertDispatchedTimes(PasswordResetLinkSent::class, 1);
        $token = Notification::sent($user, ResetPassword::class)->first()->token;
        $this->assertTrue(Password::tokenExists($user, $token));
        $this->assertSame(Password::RESET_THROTTLED, $credentials->issue($user, $actor, 'Identity reviewed', true, $student->id));
        $this->travel(61)->seconds();
        $this->assertSame(Password::RESET_LINK_SENT, $credentials->issue($user, $actor, 'Identity reviewed', true, $student->id));
        $next = Notification::sent($user, ResetPassword::class)->last()->token;
        $this->assertNotSame($token, $next);
        $this->assertFalse(Password::tokenExists($user, $token));
        $this->assertTrue(Password::tokenExists($user, $next));
        $this->assertStringNotContainsString($next, AuditEvent::all()->toJson());
        $status = $credentials->reset(['email' => $user->email, 'token' => $next, 'password' => 'user-chosen-password', 'password_confirmation' => 'user-chosen-password']);
        $this->assertSame(Password::PASSWORD_RESET, $status);
        $this->assertFalse($user->fresh()->student_setup_required);
        Event::assertDispatchedTimes(PasswordReset::class, 1);
        $this->assertSame(2, AuditEvent::where('action', 'student_setup_requested')->count());
        $this->assertSame(2, AuditEvent::where('action', 'student_credential_transport_accepted')->count());
        $this->assertSame('transport_accepted', AuditEvent::where('action', 'student_credential_transport_accepted')->first()->after_state['result']);
    }

    public function test_log_transport_cannot_emit_student_tokens_even_if_readiness_is_mocked(): void
    {
        Notification::fake();
        config(['student_accounts.mailer' => 'log']);
        $delivery = new class extends StudentCredentialDelivery
        {
            public function available(): bool
            {
                return true;
            }
        };
        [$user] = $this->fixture();
        try {
            $delivery->send($user, Str::random(64));
            $this->fail('Log transport must not receive credential tokens.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Student credential delivery is not approved and verified.', $error->getMessage());
        }
        Notification::assertNothingSent();
    }

    public function test_additive_migration_preserves_existing_accounts_and_defaults_on_round_trip(): void
    {
        [$user, $student] = $this->fixture();
        $before = $user->fresh()->getRawOriginal();
        unset($before['student_setup_required'], $before['student_access_disabled_at']);
        $migration = require database_path('migrations/2026_11_18_000001_add_student_lifecycle_to_users.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'student_setup_required'));
        $migration->up();
        $this->assertSame($before, array_intersect_key($user->fresh()->getRawOriginal(), $before));
        $this->assertFalse($user->fresh()->student_setup_required);
        $this->assertNull($user->fresh()->student_access_disabled_at);
        $this->assertSame($user->id, $student->fresh()->user_id);
        $this->assertSame(2, DB::table('user_roles')->count());
    }

    public function test_transport_failure_is_sanitized_audited_and_does_not_activate_the_pending_account(): void
    {
        Exceptions::fake();
        $delivery = $this->mock(StudentCredentialDelivery::class);
        $delivery->shouldReceive('available')->andReturn(true);
        $delivery->shouldReceive('send')->once()->andThrow(new \RuntimeException('SIMULATED-SENSITIVE-CONTENT'));
        [$user, $student, $actor] = $this->fixture();
        $this->assertSame('credential_delivery_failed', app(StudentCredentials::class)->issue($user, $actor, 'Reviewed', true, $student->id));
        $this->assertTrue($user->fresh()->student_setup_required);
        $this->assertSame('delivery_unconfirmed', AuditEvent::where('action', 'student_credential_delivery_failed')->sole()->after_state['result']);
        Exceptions::assertReported(fn (\RuntimeException $error) => $error->getMessage() === 'Student credential transport failed; delivery is unconfirmed.' && $error->getPrevious() === null);
        $this->assertStringNotContainsString('SIMULATED-SENSITIVE-CONTENT', AuditEvent::all()->toJson());
    }

    public function test_issuance_audit_failure_rolls_back_broker_token_before_any_notification(): void
    {
        Notification::fake();
        $this->partialMock(StudentCredentialDelivery::class)->shouldReceive('available')->andReturn(true);
        [$user, $student, $actor] = $this->fixture();
        AuditEvent::creating(fn () => false);
        try {
            try {
                app(StudentCredentials::class)->issue($user, $actor, 'Reviewed', true, $student->id);
                $this->fail('An unsaved audit must fail credential issuance.');
            } catch (\RuntimeException $error) {
                $this->assertSame('Student account audit could not be saved.', $error->getMessage());
            }
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertTrue($user->fresh()->student_setup_required);
        Notification::assertNothingSent();
    }
}
