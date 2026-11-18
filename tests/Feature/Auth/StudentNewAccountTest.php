<?php

namespace Tests\Feature\Auth;

use App\Actions\Accounts\LinkStudentAccount;
use App\Actions\Accounts\StudentCredentials;
use App\Models\AuditEvent;
use App\Models\Student;
use App\Models\User;
use App\Services\Accounts\StudentAccess;
use App\Services\Accounts\StudentCredentialDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudentNewAccountTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role = 'registrar'): User
    {
        $user = User::factory()->create();
        $user->roles()->create(['role' => $role]);

        return $user;
    }

    private function student(): Student
    {
        return Student::create(['student_number' => (string) Str::uuid(), 'first_name' => 'Fictional', 'last_name' => 'New account',
            'email' => 'new-'.Str::uuid().'@example.test', 'record_status' => 'archived']);
    }

    private function ready(): void
    {
        Notification::fake();
        config(['app.url' => 'https://sms.example.test', 'student_accounts.mailer' => 'student_accounts',
            'mail.mailers.student_accounts' => ['transport' => 'smtp', 'scheme' => 'smtp', 'require_tls' => true, 'host' => 'smtp.example.test', 'port' => 587,
                'username' => 'test-user', 'password' => 'test-secret', 'from' => ['address' => 'qa@example.test']]]);
        $this->partialMock(StudentCredentialDelivery::class)->shouldReceive('available')->andReturn(true);
    }

    private function state(): array
    {
        $state = [];
        foreach (['users', 'students', 'user_roles', 'audit_events', 'password_reset_tokens'] as $table) {
            $state[$table] = hash('sha256', DB::table($table)->get()->map(fn ($row) => json_encode($row))->sort()->implode("\n"));
        }

        return $state;
    }

    public static function roles(): array
    {
        return [['registrar'], ['administrator']];
    }

    #[DataProvider('roles')]
    public function test_authorized_creation_is_pending_canonical_audited_and_single_use_setup_enables_only_own_profile(string $role): void
    {
        $this->ready();
        $actor = $this->actor($role);
        $student = $this->student();
        $original = $student->fresh()->getAttributes();
        $result = app(LinkStudentAccount::class)->createForActor($actor, $student->student_number, $student->email, 'Verified fictional identity', true);
        $this->assertTrue($result->successful);
        $this->assertSame('created', $result->code);
        $user = User::findOrFail($result->userId);
        $this->assertSame($user->id, $student->fresh()->user_id);
        $this->assertSame(['student'], $user->roles()->pluck('role')->all());
        $this->assertTrue($user->student_setup_required);
        $this->assertFalse(app(StudentAccess::class)->authenticationAllowed($user));
        $this->assertSame($actor->id, AuditEvent::sole()->actor_id);
        $this->assertSame('student_account_created', AuditEvent::sole()->action);
        $this->assertStringNotContainsString($user->password, AuditEvent::sole()->toJson());
        $this->assertStringNotContainsString($user->password, json_encode($result));
        unset($original['user_id'], $original['updated_at']);
        $this->assertSame($original, array_intersect_key($student->fresh()->getAttributes(), $original));
        $token = Password::createToken($user);
        $chosen = Str::random(30);
        $this->post('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => $chosen, 'password_confirmation' => $chosen])->assertRedirect('/login')->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->student_setup_required);
        $this->assertFalse(Password::tokenExists($user, $token));
        $this->post('/login', ['email' => $user->email, 'password' => $chosen])->assertRedirect('/student/profile');
        $this->get('/student/profile')->assertOk()->assertSeeText($student->student_number);
        $this->get('/student-accounts')->assertForbidden();
    }

    public function test_unverified_delivery_allows_pending_creation_without_email_or_existing_account_linking(): void
    {
        Notification::fake();
        $actor = $this->actor();
        $student = $this->student();
        $this->assertFalse(app(StudentCredentialDelivery::class)->available());
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $this->get(route('student-accounts.show', $student))->assertOk()->assertSeeText('Create new account')
            ->assertDontSeeText('Send password setup link')->assertDontSeeText('Send password reset link')->assertDontSeeText('Link existing account');
        $this->post(route('student-accounts.create', $student), ['login_email' => $student->email, 'reason' => 'Reviewed', 'identity_confirmed' => '1'])->assertStatus(303)->assertSessionHasNoErrors();
        $user = $student->fresh()->user;
        $this->assertNotNull($user);
        $this->assertTrue($user->student_setup_required);
        $this->assertSame(['student'], $user->roles()->pluck('role')->all());
        $this->assertFalse(app(StudentAccess::class)->authenticationAllowed($user));
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->get(route('student-accounts.show', $student))->assertOk()->assertSeeText('Pending password setup')
            ->assertDontSeeText('Send password setup link')->assertDontSeeText('Send password reset link');
        $this->post('/student-accounts/'.$student->id.'/provision')->assertNotFound();
        Notification::assertNothingSent();
    }

    public static function denied(): array
    {
        return [['student'], ['coordinator'], ['finance'], ['viewer'], ['REGISTRAR']];
    }

    public function test_linked_pending_active_and_inactive_pages_do_not_offer_password_link_buttons(): void
    {
        Notification::fake();
        $actor = $this->actor();
        $student = $this->student();
        $result = app(LinkStudentAccount::class)->createForActor($actor, $student->student_number, $student->email, 'Reviewed identity', true);
        $this->assertTrue($result->successful);
        $user = User::findOrFail($result->userId);
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        foreach ([[true, null, 'Pending password setup'], [false, null, 'Linked and configured'], [false, now(), 'Inactive']] as [$pending, $disabled, $label]) {
            $user->forceFill(['student_setup_required' => $pending, 'student_access_disabled_at' => $disabled])->save();
            $this->get(route('student-accounts.show', $student))->assertOk()->assertSeeText($label)
                ->assertDontSeeText('Send password setup link')->assertDontSeeText('Send password reset link')->assertDontSeeText('Create new account');
        }
        Notification::assertNothingSent();
    }

    #[DataProvider('denied')]
    public function test_unauthorized_roles_cannot_create_accounts(string $role): void
    {
        $this->ready();
        $actor = $this->actor($role);
        $student = $this->student();
        $before = $this->state();
        $r = app(LinkStudentAccount::class)->createForActor($actor, $student->student_number, $student->email, 'Reviewed', true);
        $this->assertSame('forbidden', $r->code);
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => now()->timestamp])->postJson(route('student-accounts.create', $student), ['login_email' => $student->email, 'reason' => 'Reviewed', 'identity_confirmed' => '1'])->assertForbidden();
        $this->assertSame($before, $this->state());
    }

    public function test_same_association_repeats_preserve_credentials_flags_and_audit(): void
    {
        $this->ready();
        $actor = $this->actor();
        $student = $this->student();
        $action = app(LinkStudentAccount::class);
        $r = $action->createForActor($actor, $student->student_number, $student->email, 'Reviewed', true);
        $user = User::findOrFail($r->userId);
        $user->forceFill(['student_access_disabled_at' => now()])->save();
        $before = $this->state();
        $repeat = $action->createForActor($actor, $student->student_number, $student->email, 'Reviewed repeat', true);
        $this->assertTrue($repeat->successful);
        $this->assertFalse($repeat->changed);
        $this->assertSame($before, $this->state());
    }

    public function test_existing_accounts_including_staff_are_never_linked_by_create(): void
    {
        $this->ready();
        $actor = $this->actor();
        foreach (['student', 'registrar', 'unknown'] as $role) {
            $existing = $this->actor($role);
            $student = $this->student();
            $student->update(['email' => $existing->email]);
            $before = $this->state();
            $r = app(LinkStudentAccount::class)->createForActor($actor, $student->student_number, $student->email, 'Reviewed', true);
            $this->assertSame('email_conflict', $r->code);
            $this->assertSame($before, $this->state());
        }
    }

    public function test_duplicate_missing_changed_contacts_and_conflicting_associations_roll_back_new_users(): void
    {
        $this->ready();
        $actor = $this->actor();
        $action = app(LinkStudentAccount::class);
        $student = $this->student();
        $other = $this->student();
        $other->update(['email' => $student->email]);
        $before = $this->state();
        $this->assertSame('duplicate_contact_email', $action->createForActor($actor, $student->student_number, $student->email, 'Reviewed', true)->code);
        $this->assertSame($before, $this->state());
        $other->update(['email' => null]);
        $this->assertSame('email_conflict', $action->createForActor($actor, $student->student_number, 'different@example.test', 'Reviewed', true)->code);
        $student->update(['user_id' => $this->actor('student')->id]);
        $before = $this->state();
        $this->assertSame('association_conflict', $action->createForActor($actor, $student->student_number, $student->email, 'Reviewed', true)->code);
        $this->assertSame($before, $this->state());
        $this->assertSame('not_found', $action->createForActor($actor, 'NOT-PRESENT', $student->email, 'Reviewed', true)->code);
    }

    public function test_canceled_audit_and_failed_pending_postcondition_leave_no_partial_account(): void
    {
        $this->ready();
        $actor = $this->actor();
        $student = $this->student();
        $before = $this->state();
        AuditEvent::creating(fn () => false);
        try {
            $r = app(LinkStudentAccount::class)->createForActor($actor, $student->student_number, $student->email, 'Reviewed', true);
            $this->assertSame('audit_failed', $r->code);
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertSame($before, $this->state());
        AuditEvent::created(fn ($event) => DB::table('users')->where('id', $event->after_state['user_id'])->update(['student_setup_required' => false]));
        try {
            $r = app(LinkStudentAccount::class)->createForActor($actor, $student->student_number, $student->email, 'Reviewed', true);
            $this->assertSame('postcondition_failed', $r->code);
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertSame($before, $this->state());
    }

    public function test_ready_creation_form_and_http_action_use_new_account_mode_only(): void
    {
        $this->ready();
        $actor = $this->actor();
        $student = $this->student();
        $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $this->get(route('student-accounts.show', $student))->assertOk()->assertSee('action="'.route('student-accounts.create', $student).'"', false)->assertDontSeeText('Link existing account');
        $this->post(route('student-accounts.create', $student), ['login_email' => $student->email, 'reason' => 'Reviewed', 'identity_confirmed' => '1'])
            ->assertStatus(303)->assertSessionHasNoErrors();
        $this->assertNotNull($student->fresh()->user_id);
        $this->assertTrue($student->fresh()->user->student_setup_required);
        $this->post(route('student-accounts.create', $student), ['login_email' => $student->email, 'reason' => 'Reviewed', 'identity_confirmed' => '1', 'roles' => ['administrator'], 'password' => 'UNAPPROVED-PAYLOAD'])
            ->assertSessionHasErrors(['roles', 'password']);
    }

    public function test_student_cannot_change_password_through_restricted_settings_endpoint(): void
    {
        $user = $this->actor('student');
        $student = $this->student();
        $student->update(['user_id' => $user->id]);
        $token = Password::createToken($user);
        $this->actingAs($user)->put('/password', ['current_password' => 'password', 'password' => 'Student-chosen-password', 'password_confirmation' => 'Student-chosen-password'])->assertForbidden();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Password::tokenExists($user, $token));
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_credential_service_still_revokes_tokens_on_a_password_change(): void
    {
        $user = $this->actor('student');
        $student = $this->student();
        $student->update(['user_id' => $user->id]);
        $token = Password::createToken($user);
        app(StudentCredentials::class)->changeOwnPassword($user, 'password', 'Student-chosen-password');
        $this->assertFalse(Password::tokenExists($user, $token));
        $this->assertTrue(Hash::check('Student-chosen-password', $user->fresh()->password));
    }
}
