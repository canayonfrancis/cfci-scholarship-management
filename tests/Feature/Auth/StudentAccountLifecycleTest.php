<?php

namespace Tests\Feature\Auth;

use App\Actions\Accounts\LinkStudentAccount;
use App\Actions\Accounts\StudentAccountLifecycle;
use App\Actions\Accounts\StudentCredentials;
use App\Models\AuditEvent;
use App\Models\Student;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsScholarshipRecords;
use Tests\TestCase;

class StudentAccountLifecycleTest extends TestCase
{
    use BuildsScholarshipRecords, RefreshDatabase;

    private function user(string $role = 'student', array $flags = []): User
    {
        $user = User::factory()->create();
        $user->roles()->create(['role' => $role]);
        $user->forceFill($flags)->save();

        return $user;
    }

    private function student(User $user): Student
    {
        return Student::create(['student_number' => (string) Str::uuid(), 'first_name' => 'Lifecycle', 'last_name' => 'Fixture', 'user_id' => $user->id, 'record_status' => 'archived']);
    }

    private function payload(User $target): array
    {
        return ['login_email' => $target->email, 'reason' => 'Verified Student identity and requested login change', 'identity_confirmed' => '1'];
    }

    private function snapshot(): array
    {
        $state = [];
        foreach (Schema::getTableListing(DB::getDriverName() === 'sqlite' ? 'main' : DB::getDatabaseName(), schemaQualified: false) as $table) {
            if (in_array($table, ['users', 'audit_events', 'sessions', 'cache', 'cache_locks', 'password_reset_tokens'], true)) {
                continue;
            }
            $rows = DB::table($table)->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->sort()->values()->all();
            $state[$table] = hash('sha256', implode("\n", $rows));
        }

        return $state;
    }

    public static function authorities(): array
    {
        return [['registrar'], ['administrator']];
    }

    #[DataProvider('authorities')]
    public function test_authorized_transition_revokes_only_target_sessions_and_preserves_school_records(string $role): void
    {
        $actor = $this->user($role);
        $target = $this->user();
        $student = $this->student($target);
        $other = $this->user();
        $token = Password::createToken($target);
        $oldRemember = $target->remember_token;
        foreach ([$target, $target, $other] as $user) {
            DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        }
        $state = $this->snapshot();
        config(['session.driver' => 'database']);
        $service = app(StudentAccountLifecycle::class);
        $this->assertTrue($service->transition($actor, $student->id, $target->email, 'Identity reviewed', true, false));
        $this->assertSame($state, $this->snapshot());
        $this->assertNotNull($target->fresh()->student_access_disabled_at);
        $this->assertNotSame($oldRemember, $target->fresh()->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
        $this->assertFalse(Password::tokenExists($target, $token));
        $this->assertFalse(Gate::forUser($target->fresh(['roles']))->allows('viewOwnProfile', $student));
        $this->assertFalse($service->transition($actor, $student->id, $target->email, 'Repeated request', true, false));
        $this->assertSame(1, AuditEvent::where('action', 'student_access_deactivated')->count());
        $this->assertTrue($service->transition($actor, $student->id, $target->email, 'Identity reviewed', true, true));
        $this->assertTrue(Gate::forUser($target->fresh(['roles']))->allows('viewOwnProfile', $student));
        $this->assertSame($state, $this->snapshot());
        $this->assertSame($actor->id, AuditEvent::latest('id')->first()->actor_id);
        $this->assertFalse($service->transition($actor, $student->id, $target->email, 'Repeat', true, true));
        $this->assertSame(2, AuditEvent::count());
    }

    public static function deniedStates(): array
    {
        return [[['student_setup_required' => true]], [['student_access_disabled_at' => '2026-11-01 00:00:00']]];
    }

    #[DataProvider('deniedStates')]
    public function test_inactive_or_pending_login_and_existing_protected_sessions_are_denied(array $flags): void
    {
        $user = $this->user(flags: $flags);
        $student = $this->student($user);
        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1'])->assertSessionHasErrors('email');
        $this->assertGuest();
        foreach (['/student/profile', '/profile', '/dashboard', '/scholars', '/student-accounts'] as $url) {
            $this->actingAs($user)->getJson($url)->assertForbidden();
            $this->assertGuest();
        }
        $this->actingAs($user)->putJson('/password', ['current_password' => 'password', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertForbidden();
        $this->assertSame($user->id, $student->fresh()->user_id);
    }

    public function test_pending_or_disabled_flags_still_deny_when_role_or_link_is_missing(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['student_access_disabled_at' => now()])->save();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->actingAs($user)->getJson('/profile')->assertForbidden();
    }

    public function test_reactivation_retains_pending_setup_and_does_not_restore_old_tokens_or_sessions(): void
    {
        $actor = $this->user('registrar');
        $user = $this->user(flags: ['student_setup_required' => true, 'student_access_disabled_at' => now()]);
        $student = $this->student($user);
        $this->assertTrue(app(StudentAccountLifecycle::class)->transition($actor, $student->id, $user->email, 'Reviewed', true, true));
        $this->assertTrue($user->fresh()->student_setup_required);
        $this->assertFalse(Gate::forUser($user->fresh(['roles']))->allows('viewOwnProfile', $student));
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
    }

    public static function invalidTokens(): array
    {
        return [['expired'], ['invalid'], ['inactive'], ['missing_role'], ['missing_link'], ['capitalized']];
    }

    #[DataProvider('invalidTokens')]
    public function test_invalid_ineligible_and_expired_credentials_never_change_password_or_activate(string $case): void
    {
        $user = $this->user(flags: ['student_setup_required' => true]);
        $student = $this->student($user);
        $token = Password::createToken($user);
        if ($case === 'expired') {
            DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()->subMinutes(61)]);
        } elseif ($case === 'invalid') {
            $token = Str::random(64);
        } elseif ($case === 'inactive') {
            $user->forceFill(['student_access_disabled_at' => now()])->save();
        } elseif ($case === 'missing_role') {
            $user->roles()->update(['role' => 'unknown']);
        } elseif ($case === 'missing_link') {
            $student->update(['user_id' => null]);
        } elseif ($case === 'capitalized') {
            $user->roles()->update(['role' => 'Student']);
        }
        $hash = $user->fresh()->password;
        $this->post('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'valid-password', 'password_confirmation' => 'valid-password'])->assertSessionHasErrors('email');
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertTrue($user->fresh()->student_setup_required);
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_valid_setup_is_hashed_single_use_and_enables_only_own_profile(): void
    {
        $user = $this->user(flags: ['student_setup_required' => true]);
        $student = $this->student($user);
        $foreign = $this->student($this->user());
        $token = Password::createToken($user);
        $verifiedAt = $user->fresh()->email_verified_at;
        $this->assertNotSame($token, DB::table('password_reset_tokens')->where('email', $user->email)->value('token'));
        $response = $this->get('/reset-password/'.$token.'?email='.urlencode($user->email))->assertOk();
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'student-chosen-password', 'password_confirmation' => 'student-chosen-password'];
        $this->post('/reset-password', $payload)->assertSessionHasNoErrors()->assertRedirect('/login');
        $this->assertFalse($user->fresh()->student_setup_required);
        $this->assertTrue(Hash::check($payload['password'], $user->fresh()->password));
        $this->assertFalse(Password::tokenExists($user, $token));
        $this->post('/reset-password', $payload)->assertSessionHasErrors('email');
        $this->assertSame(1, AuditEvent::where('action', 'student_account_activated')->count());
        $audit = AuditEvent::sole()->toJson();
        $this->assertStringNotContainsString($token, $audit);
        $this->assertStringNotContainsString($payload['password'], $audit);
        $this->assertEquals($verifiedAt, $user->fresh()->email_verified_at);
        $this->post('/login', ['email' => $user->email, 'password' => $payload['password']])->assertRedirect('/student/profile');
        $this->get('/student/profile')->assertOk()->assertSeeText($student->student_number);
        $this->get('/scholars/'.$foreign->id)->assertForbidden();
        $this->get('/student-accounts')->assertForbidden();
    }

    public static function deniedActors(): array
    {
        return [['student'], ['coordinator'], ['finance'], ['viewer'], ['unknown']];
    }

    #[DataProvider('deniedActors')]
    public function test_lifecycle_and_credential_routes_reject_unauthorized_actors(string $role): void
    {
        $target = $this->user();
        $student = $this->student($target);
        $actor = $this->user($role);
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        foreach (['deactivate', 'reactivate'] as $action) {
            $this->patchJson(route('student-accounts.'.$action, $student), $this->payload($target))->assertForbidden();
        }
        foreach (['setup-link', 'password-reset'] as $action) {
            $this->postJson(route('student-accounts.'.$action, $student), $this->payload($target))->assertForbidden();
        }
        $this->assertNull($target->fresh()->student_access_disabled_at);
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_protected_and_ambiguous_targets_cannot_be_deactivated(): void
    {
        $actor = $this->user('registrar');
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        foreach (['registrar', 'Student', 'student'] as $role) {
            $target = $this->user($role);
            if ($role === 'student') {
                $target->roles()->create(['role' => ' student']);
            }
            $student = $this->student($target);
            $this->patch(route('student-accounts.deactivate', $student), $this->payload($target))->assertSessionHasErrors('account');
            $this->assertNull($target->fresh()->student_access_disabled_at);
        }
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_existing_staff_access_is_preserved_despite_student_flags(): void
    {
        foreach (['registrar', 'administrator', 'coordinator', 'finance', 'viewer'] as $role) {
            $user = $this->user($role, ['student_setup_required' => true, 'student_access_disabled_at' => now()]);
            $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasNoErrors()->assertRedirect('/dashboard');
            $this->get('/dashboard')->assertOk();
            $this->post('/logout');
        }
    }

    public function test_public_recovery_is_generic_and_student_log_delivery_is_blocked_without_tokens(): void
    {
        Notification::fake();
        $student = $this->user();
        $this->student($student);
        $unknown = Str::uuid().'@example.test';
        $this->post('/forgot-password', ['email' => $unknown])->assertRedirect();
        $message = session('status');
        $this->post('/forgot-password', ['email' => $student->email])->assertSessionHas('status', $message);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $student->email]);
        $this->assertNull(AuditEvent::sole()->actor_id);
        $this->assertSame('public credential request', AuditEvent::sole()->actor_snapshot['source']);
        Notification::assertNothingSent();
    }

    public function test_http_lifecycle_confirmation_validation_status_filters_and_blocked_delivery(): void
    {
        Notification::fake();
        $actor = $this->user('registrar');
        $user = $this->user();
        $student = $this->student($user);
        $this->actingAs($actor)->patch(route('student-accounts.deactivate', $student), $this->payload($user))->assertStatus(303)->assertRedirect('/confirm-password');
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp])->patch(route('student-accounts.deactivate', $student), ['login_email' => $user->email])->assertStatus(303)->assertSessionHasErrors(['reason', 'identity_confirmed']);
        $this->patch(route('student-accounts.deactivate', $student), $this->payload($user))->assertStatus(303)->assertRedirect(route('student-accounts.show', $student))->assertSessionHasNoErrors();
        $this->get(route('student-accounts.index', ['account_status' => 'inactive']))->assertOk()->assertViewHas('students', fn ($rows) => $rows->total() === 1);
        $this->get(route('student-accounts.show', $student))->assertOk()->assertSeeText('Reactivate Student access');
        // Use another authorized actor session so the target quota remains meaningful.
        app(RateLimiter::class)->clear(md5('student-account-links'.'student:'.$student->id));
        $this->patch(route('student-accounts.reactivate', $student), $this->payload($user))->assertStatus(303)->assertSessionHasNoErrors();
        app(RateLimiter::class)->clear(md5('student-account-links'.'student:'.$student->id));
        $this->post(route('student-accounts.password-reset', $student), $this->payload($user))->assertSessionHasErrors('account');
        Notification::assertNothingSent();
    }

    public function test_credential_service_revokes_existing_tokens_atomically_on_student_email_change(): void
    {
        $user = $this->user();
        $this->student($user);
        $token = Password::createToken($user);
        $oldEmail = $user->email;
        $former = $this->user('registrar');
        $adoptedEmail = $former->email;
        $obsolete = Password::createToken($former);
        $former->update(['email' => 'former-'.Str::uuid().'@example.test']);
        app(StudentCredentials::class)->updateProfile($user, ['name' => $user->name, 'email' => $adoptedEmail]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $oldEmail]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $adoptedEmail]);
        $this->assertFalse(Password::tokenExists($user->fresh(), $obsolete));
        $this->assertFalse(Password::tokenExists($user, $token));
    }

    public function test_audit_failures_roll_back_lifecycle_password_tokens_and_sessions(): void
    {
        $actor = $this->user('registrar');
        $user = $this->user(flags: ['student_setup_required' => true]);
        $student = $this->student($user);
        $token = Password::createToken($user);
        $before = $user->fresh()->getAttributes();
        AuditEvent::creating(fn () => false);
        try {
            $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => now()->timestamp])
                ->patch(route('student-accounts.deactivate', $student), $this->payload($user))->assertSessionHasErrors('account');
            $this->post('/logout');
            $this->post('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'valid-password', 'password_confirmation' => 'valid-password'])->assertSessionHasErrors('email');
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertTrue(Password::tokenExists($user, $token));
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_console_link_never_silently_reactivates_pending_or_disabled_account(): void
    {
        foreach ([['student_setup_required' => true], ['student_access_disabled_at' => now()]] as $flags) {
            $user = $this->user(flags: $flags);
            $student = $this->student($user);
            $before = $user->fresh()->getAttributes();
            $this->assertFalse(app(LinkStudentAccount::class)->fromTrustedConsole($user->email, $student->student_number)->successful);
            $this->assertSame($before, $user->fresh()->getAttributes());
        }
    }

    public function test_school_financial_and_audit_history_survive_both_transitions(): void
    {
        $context = $this->financialContext();
        $actor = $this->user('registrar');
        $user = $this->user();
        $student = $context['student'];
        $student->update(['user_id' => $user->id]);
        $event = AuditEvent::create(['action' => 'original_school_history', 'subject_type' => Student::class,
            'subject_id' => $student->id, 'occurred_at' => now(), 'after_state' => ['retained' => true]]);
        $history = $event->fresh()->getAttributes();
        $before = $this->snapshot();
        foreach ([false, true] as $reactivate) {
            app(StudentAccountLifecycle::class)->transition($actor, $student->id, $user->email, 'Reviewed account access only', true, $reactivate);
            $this->assertSame($before, $this->snapshot());
            $this->assertSame($history, $event->fresh()->getAttributes());
        }
        $this->assertSame(1, DB::table('payouts')->count());
        $this->assertSame(1, DB::table('billing_items')->count());
    }

    public function test_password_policy_rejection_does_not_flash_credentials_or_consume_token(): void
    {
        $user = $this->user(flags: ['student_setup_required' => true]);
        $this->student($user);
        $token = Password::createToken($user);
        $this->post('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->assertArrayNotHasKey('token', session('_old_input', []));
        $this->assertArrayNotHasKey('password', session('_old_input', []));
        $this->assertTrue(Password::tokenExists($user, $token));
        $this->assertTrue($user->fresh()->student_setup_required);
    }

    public function test_canceled_password_writes_cannot_report_success(): void
    {
        $user = $this->user(flags: ['student_setup_required' => true]);
        $this->student($user);
        $token = Password::createToken($user);
        $hash = $user->fresh()->password;
        User::saving(fn ($model) => ! $model->isDirty('password'));
        try {
            $this->post('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'valid-password', 'password_confirmation' => 'valid-password'])->assertSessionHasErrors('email');
        } finally {
            User::flushEventListeners();
        }
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertTrue(Password::tokenExists($user, $token));
        $this->assertTrue($user->fresh()->student_setup_required);
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_active_student_recovery_revokes_target_sessions_without_changing_other_accounts(): void
    {
        $user = $this->user();
        $student = $this->student($user);
        $other = $this->user();
        $oldRemember = $user->remember_token;
        foreach ([$user, $other] as $sessionUser) {
            DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $sessionUser->id, 'payload' => '', 'last_activity' => time()]);
        }
        config(['session.driver' => 'database']);
        $token = Password::createToken($user);
        $this->post('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'recovered-password', 'password_confirmation' => 'recovered-password'])
            ->assertSessionHasNoErrors()->assertRedirect('/login');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
        $this->assertNotSame($oldRemember, $user->fresh()->remember_token);
        $this->assertFalse($user->fresh()->student_setup_required);
        $this->assertNull($user->fresh()->student_access_disabled_at);
        $this->assertSame($user->id, $student->fresh()->user_id);
        $this->assertSame(1, AuditEvent::where('action', 'student_password_reset_completed')->count());
        $this->assertTrue(Hash::check('recovered-password', $user->fresh()->password));
    }
}
