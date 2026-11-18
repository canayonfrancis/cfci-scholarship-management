<?php

namespace Tests\Feature\Auth;

use App\Models\AuditEvent;
use App\Models\Student;
use App\Models\User;
use App\Services\Accounts\StudentCredentialDelivery;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentAccountCreationCommitTest extends TestCase
{
    use DatabaseMigrations;

    private function setupFixture(): array
    {
        Notification::fake();
        Mail::fake();
        config(['app.url' => 'https://sms.example.test', 'student_accounts.mailer' => 'student_accounts',
            'mail.mailers.student_accounts' => ['transport' => 'smtp', 'scheme' => 'smtp', 'require_tls' => true, 'host' => 'smtp.example.test', 'port' => 587,
                'username' => 'test-user', 'password' => 'test-secret', 'from' => ['address' => 'qa@example.test']]]);
        $this->partialMock(StudentCredentialDelivery::class)->shouldReceive('available')->andReturn(true);
        $actor = User::factory()->create();
        $actor->roles()->create(['role' => 'registrar']);
        $student = Student::create(['student_number' => (string) Str::uuid(), 'first_name' => 'Commit', 'last_name' => 'Fictional', 'email' => 'commit-'.Str::uuid().'@example.test']);
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => now()->timestamp]);

        return [$actor, $student, ['login_email' => $student->email, 'reason' => 'Verified fictional identity', 'identity_confirmed' => '1']];
    }

    public function test_committed_creation_and_repeat_never_send_mail_even_with_verified_delivery(): void
    {
        [$actor, $student, $payload] = $this->setupFixture();
        $this->post(route('student-accounts.create', $student), $payload)->assertStatus(303)->assertSessionHasNoErrors();
        $user = $student->fresh()->user;
        $this->assertTrue($user->student_setup_required);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertSame(1, AuditEvent::where('action', 'student_account_created')->count());
        $this->assertSame(1, AuditEvent::count());
        $this->post(route('student-accounts.create', $student), $payload)->assertSessionHasNoErrors();
        $this->assertSame(2, User::count());
        $this->assertSame(1, AuditEvent::count());
        Notification::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_unavailable_delivery_does_not_prevent_creation_or_generate_broker_tokens(): void
    {
        [$actor, $student, $payload] = $this->setupFixture();
        $delivery = $this->mock(StudentCredentialDelivery::class);
        $delivery->shouldNotReceive('available');
        $delivery->shouldNotReceive('send');
        $this->post(route('student-accounts.create', $student), $payload)->assertStatus(303)->assertSessionHas('status', 'Student login account created.');
        $this->assertTrue($student->fresh()->user->student_setup_required);
        $this->assertSame(2, User::count());
        $this->assertSame(1, AuditEvent::count());
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_unverified_staff_recovery_does_not_generate_tokens_or_log_mail(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $user->roles()->create(['role' => 'registrar']);
        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        Notification::assertNothingSent();
    }
}
