<?php

namespace Tests\Feature\Auth;

use App\Actions\Accounts\StudentCredentials;
use App\Models\AuditEvent;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StudentInitialPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $role = 'registrar'): array
    {
        Notification::fake();
        Mail::fake();
        $actor = User::factory()->create();
        $actor->roles()->create(['role' => $role]);
        $target = User::factory()->create();
        $target->roles()->create(['role' => 'student']);
        $target->forceFill(['student_setup_required' => true])->save();
        $student = Student::create(['student_number' => (string) Str::uuid(), 'first_name' => 'Fictional', 'last_name' => 'Password setup',
            'email' => $target->email, 'user_id' => $target->id, 'record_status' => 'archived']);
        $password = 'Chosen-Student-'.Str::random(16);
        $payload = ['login_email' => $target->email, 'reason' => 'Reviewed fictional identity for initial setup', 'identity_confirmed' => '1',
            'password' => $password, 'password_confirmation' => $password];

        return [$actor, $target->fresh(), $student->fresh(), $payload];
    }

    public static function managers(): array
    {
        return [['registrar'], ['administrator']];
    }

    #[DataProvider('managers')]
    public function test_approved_operator_saves_password_and_student_can_use_only_own_profile(string $role): void
    {
        [$actor, $target, $student, $payload] = $this->fixture($role);
        if ($role === 'registrar') {
            $payload['password'] = $payload['password_confirmation'] = 'CRONASIA';
        }
        $originalStudent = $student->getAttributes();
        $oldToken = Password::createToken($target);
        $this->assertFalse(Gate::forUser($target)->allows('viewOwnProfile', $student));
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $this->get(route('student-accounts.show', $student))->assertOk()->assertSeeText('Set Student password')
            ->assertSee('name="password_confirmation"', false)->assertDontSeeText('Send password setup link')->assertDontSeeText('Send password reset link');
        $this->post(route('student-accounts.initial-password', $student), $payload)->assertStatus(303)
            ->assertRedirect(route('student-accounts.show', $student))->assertSessionHasNoErrors();
        $fresh = $target->fresh(['roles']);
        $this->assertFalse($fresh->student_setup_required);
        $this->assertTrue(Hash::check($payload['password'], $fresh->password));
        $this->assertNull($fresh->student_access_disabled_at);
        $this->assertFalse(Password::tokenExists($fresh, $oldToken));
        $this->assertSame(['student'], $fresh->roles->pluck('role')->all());
        $this->assertSame($originalStudent, $student->fresh()->getAttributes());
        $audit = AuditEvent::sole();
        $this->assertSame('student_account_activated', $audit->action);
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame('operator_password_form', $audit->after_state['source']);
        $this->assertStringNotContainsString($payload['password'], $audit->toJson());
        $this->assertStringNotContainsString($fresh->password, $audit->toJson());
        $this->get(route('student-accounts.show', $student))->assertOk()->assertSeeText('Linked and configured')
            ->assertDontSeeText('Set Student password')->assertDontSee($payload['password'], false);
        $this->post('/logout');
        $this->post('/login', ['email' => $fresh->email, 'password' => $payload['password']])->assertRedirect('/student/profile');
        $this->get('/student/profile')->assertOk()->assertSeeText($student->student_number);
        $other = Student::create(['student_number' => (string) Str::uuid(), 'first_name' => 'Another', 'last_name' => 'Fictional']);
        $this->get(route('scholars.show', $other))->assertForbidden();
        $this->get('/student-accounts')->assertForbidden();
        Notification::assertNothingSent();
        Mail::assertNothingSent();
    }

    public static function unauthorized(): array
    {
        return [['student'], ['coordinator'], ['finance'], ['viewer'], ['REGISTRAR']];
    }

    #[DataProvider('unauthorized')]
    public function test_unauthorized_direct_requests_never_change_password_or_flags(string $role): void
    {
        [$actor, $target, $student, $payload] = $this->fixture($role);
        $before = $target->getRawOriginal();
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->postJson(route('student-accounts.initial-password', $student), $payload)->assertForbidden();
        $this->assertSame($before, $target->fresh()->getRawOriginal());
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_guest_and_stale_confirmation_are_rejected_without_flashing_secrets(): void
    {
        [$actor, $target, $student, $payload] = $this->fixture();
        $before = $target->getRawOriginal();
        $this->post(route('student-accounts.initial-password', $student), $payload)->assertRedirect('/login');
        $this->actingAs($actor)->post(route('student-accounts.initial-password', $student), $payload)->assertStatus(303)->assertRedirect('/confirm-password');
        $this->assertArrayNotHasKey('password', session('_old_input', []));
        $this->assertArrayNotHasKey('password_confirmation', session('_old_input', []));
        $this->postJson(route('student-accounts.initial-password', $student), $payload)->assertStatus(423);
        $this->assertSame($before, $target->fresh()->getRawOriginal());
    }

    public static function invalidInput(): array
    {
        return [
            ['short', 'password'], ['mismatch', 'password'], ['long', 'password'], ['multibyte', 'password'],
            ['null', 'password'], ['email', 'account'], ['reason', 'reason'], ['identity', 'identity_confirmed'], ['role', 'roles'],
        ];
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_input_preserves_pending_account_and_does_not_flash_password(string $case, string $field): void
    {
        [$actor, $target, $student, $payload] = $this->fixture();
        $before = $target->getRawOriginal();
        match ($case) {
            'short' => $payload['password'] = $payload['password_confirmation'] = 'short',
            'mismatch' => $payload['password_confirmation'] = 'Does-not-match',
            'long' => $payload['password'] = $payload['password_confirmation'] = str_repeat('a', 73),
            'multibyte' => $payload['password'] = $payload['password_confirmation'] = str_repeat("\u{00e9}", 40),
            'null' => $payload['password'] = $payload['password_confirmation'] = "Invalid\0Password",
            'email' => $payload['login_email'] = 'different@example.test',
            'reason' => $payload['reason'] = ' ',
            'identity' => $payload['identity_confirmed'] = '0',
            'role' => $payload['roles'] = ['administrator'],
        };
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->post(route('student-accounts.initial-password', $student), $payload)->assertStatus(303)->assertSessionHasErrors($field);
        $this->assertSame($before, $target->fresh()->getRawOriginal());
        $this->assertArrayNotHasKey('password', session('_old_input', []));
        $this->assertArrayNotHasKey('password_confirmation', session('_old_input', []));
        $this->assertDatabaseCount('audit_events', 0);
    }

    public static function ineligible(): array
    {
        return [['active'], ['inactive'], ['missing_role'], ['capitalized'], ['mixed'], ['unlinked'], ['invalid_email']];
    }

    #[DataProvider('ineligible')]
    public function test_ineligible_and_privileged_targets_cannot_be_changed(string $case): void
    {
        [$actor, $target, $student, $payload] = $this->fixture();
        match ($case) {
            'active' => $target->forceFill(['student_setup_required' => false])->save(),
            'inactive' => $target->forceFill(['student_access_disabled_at' => now()])->save(),
            'missing_role' => $target->roles()->delete(),
            'capitalized' => $target->roles()->update(['role' => 'Student']),
            'mixed' => $target->roles()->create(['role' => 'registrar']),
            'unlinked' => $student->update(['user_id' => null]),
            'invalid_email' => $target->forceFill(['email' => ' '.$target->email])->save(),
        };
        $before = $target->fresh()->getRawOriginal();
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $page = $this->get(route('student-accounts.show', $student))->assertOk();
        if ($case !== 'invalid_email') {
            $page->assertDontSeeText('Set Student password');
        }
        $this->post(route('student-accounts.initial-password', $student), $payload)->assertStatus(303)->assertSessionHasErrors('account');
        $this->assertSame($before, $target->fresh()->getRawOriginal());
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_repeated_setup_does_not_replace_password_or_duplicate_audit(): void
    {
        [$actor, $target, $student, $payload] = $this->fixture();
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $this->post(route('student-accounts.initial-password', $student), $payload)->assertSessionHasNoErrors();
        $before = $target->fresh()->getRawOriginal();
        $payload['password'] = $payload['password_confirmation'] = 'Another-chosen-password';
        $this->post(route('student-accounts.initial-password', $student), $payload)->assertSessionHasErrors('account');
        $this->assertSame($before, $target->fresh()->getRawOriginal());
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_audit_or_postcondition_failure_rolls_back_hash_flag_and_token_revocation(): void
    {
        Exceptions::fake();
        [$actor, $target, $student, $payload] = $this->fixture();
        $token = Password::createToken($target);
        $before = $target->fresh()->getRawOriginal();
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        AuditEvent::creating(fn () => false);
        try {
            $this->post(route('student-accounts.initial-password', $student), $payload)->assertSessionHasErrors('account');
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertSame($before, $target->fresh()->getRawOriginal());
        $this->assertTrue(Password::tokenExists($target, $token));
        Gate::before(fn ($user, $ability) => $ability === 'viewOwnProfile' ? false : null);
        $this->post(route('student-accounts.initial-password', $student), $payload)->assertSessionHasErrors('account');
        $this->assertSame($before, $target->fresh()->getRawOriginal());
        $this->assertTrue(Password::tokenExists($target, $token));
        $this->assertDatabaseCount('audit_events', 0);
        Exceptions::assertReported(fn (\RuntimeException $error) => $error->getMessage() === 'Student initial password setup failed; no credential details were recorded.');
    }

    public function test_setup_revokes_only_target_database_sessions_and_rechecks_actor_permissions(): void
    {
        [$actor, $target, $student, $payload] = $this->fixture();
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert([
            ['id' => 'synthetic-target-session', 'user_id' => $target->id, 'payload' => 'synthetic', 'last_activity' => time()],
            ['id' => 'synthetic-actor-session', 'user_id' => $actor->id, 'payload' => 'synthetic', 'last_activity' => time()],
        ]);
        app(StudentCredentials::class)->setInitialPassword($actor, $student->id, $payload);
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertDatabaseHas('sessions', ['user_id' => $actor->id]);
        $actor->load('roles');
        $actor->roles()->delete();
        $this->expectException(HttpException::class);
        app(StudentCredentials::class)->setInitialPassword($actor, $student->id, $payload);
    }

    public function test_laravel_reset_form_still_rejects_invalid_tokens_without_activating_student(): void
    {
        [$actor, $target, $student, $payload] = $this->fixture();
        $before = $target->fresh()->getRawOriginal();
        $this->post('/reset-password', ['email' => $target->email, 'password' => $payload['password'], 'password_confirmation' => $payload['password'], 'token' => 'NOT-A-VALID-BROKER-TOKEN'])
            ->assertSessionHasErrors('email');
        $this->assertSame($before, $target->fresh()->getRawOriginal());
    }
}
