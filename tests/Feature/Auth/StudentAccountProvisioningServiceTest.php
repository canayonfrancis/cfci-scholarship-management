<?php

namespace Tests\Feature\Auth;

use App\Actions\Accounts\LinkStudentAccount;
use App\Models\AuditEvent;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class StudentAccountProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    private function actor(?string $role = 'registrar'): User
    {
        $user = User::factory()->create();
        if ($role !== null) {
            $user->roles()->create(['role' => $role]);
        }

        return $user;
    }

    private function record(): Student
    {
        return Student::create(['student_number' => (string) Str::uuid(), 'first_name' => 'Service', 'last_name' => 'Fixture']);
    }

    private function state(): array
    {
        $state = [];
        foreach (['users', 'user_roles', 'students', 'audit_events', 'password_reset_tokens'] as $table) {
            $rows = DB::table($table)->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->sort()->values()->all();
            $state[$table] = hash('sha256', implode("\n", $rows));
        }

        return $state;
    }

    public static function validLinks(): array
    {
        $cases = [];
        foreach (['registrar', 'administrator'] as $actorRole) {
            foreach ([null, 'student', 'Student', ' STUDENT '] as $membership) {
                $cases[] = [$actorRole, $membership];
            }
        }

        return $cases;
    }

    #[DataProvider('validLinks')]
    public function test_approved_actors_link_existing_accounts_with_canonical_membership_and_safe_results(string $actorRole, ?string $role): void
    {
        $actor = $this->actor($actorRole);
        $target = User::factory()->create();
        $membership = $role === null ? null : $target->roles()->create(['role' => $role]);
        $student = $this->record();
        $student->update(['record_status' => 'archived']);
        $foreign = $this->record();
        $beforeCredentials = $target->fresh()->getAttributes();
        $service = app(LinkStudentAccount::class);

        $result = $service->forActor($actor, $target->email, $student->student_number, '  Institutional identity checked  ', true);
        $this->assertTrue($result->successful);
        $this->assertSame('linked', $result->code);
        $this->assertTrue($result->changed);
        $this->assertFalse($result->blocked);
        $this->assertSame($target->id, $result->userId);
        $this->assertSame($student->id, $result->studentId);
        $this->assertSame($target->id, $student->fresh()->user_id);
        $this->assertSame(['student'], $target->roles()->pluck('role')->all());
        if ($membership !== null) {
            $this->assertSame('student', $membership->fresh()->role);
        }
        $this->assertSame($beforeCredentials, $target->fresh()->getAttributes());
        $this->assertTrue(Hash::check('password', $target->fresh()->password));
        $event = AuditEvent::sole();
        $this->assertSame($actor->id, $event->actor_id);
        $this->assertSame(['name' => $actor->name, 'email' => $actor->email], $event->actor_snapshot);
        $this->assertSame('Institutional identity checked', $event->reason);
        $this->assertSame(['user_id' => $target->id, 'role' => 'student'], $event->after_state);
        $payload = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertSame(['successful', 'code', 'message', 'user_id', 'student_id', 'changed', 'blocked'], array_keys(json_decode($payload, true)));
        $this->assertStringNotContainsString($target->password, $payload);
        $this->assertStringNotContainsString($target->remember_token, $payload);
        $beforeRepeat = $this->state();
        $repeat = $service->forActor($actor, $target->email, $student->student_number, 'Institutional identity checked', true);
        $this->assertTrue($repeat->successful);
        $this->assertSame('unchanged', $repeat->code);
        $this->assertFalse($repeat->changed);
        $this->assertSame($beforeRepeat, $this->state());
        $this->actingAs($target->fresh())->get(route('student.profile'))->assertOk()->assertSeeText($student->student_number);
        $this->get(route('scholars.show', $foreign))->assertForbidden();
        $this->get(route('financial.index'))->assertForbidden();
    }

    public static function unauthorizedActors(): array
    {
        return [['coordinator'], ['student'], ['finance'], ['viewer'], ['REGISTRAR'], ['admin'], [null]];
    }

    #[DataProvider('unauthorizedActors')]
    public function test_unapproved_actors_cannot_link_or_create_accounts(?string $role): void
    {
        $actor = $this->actor($role);
        $target = User::factory()->create();
        $student = $this->record();
        $before = $this->state();
        $service = app(LinkStudentAccount::class);
        foreach ([$service->forActor($actor, $target->email, $student->student_number, 'Checked', true), $service->createForActor($actor, $student->student_number)] as $result) {
            $this->assertFalse($result->successful);
            $this->assertSame('forbidden', $result->code);
            $this->assertNull($result->userId);
        }
        $this->assertSame($before, $this->state());
    }

    public static function protectedMemberships(): array
    {
        return [['registrar'], ['administrator'], ['coordinator'], ['finance'], ['viewer'], ['Administrator'], ['unknown']];
    }

    #[DataProvider('protectedMemberships')]
    public function test_privileged_and_unknown_targets_cannot_be_claimed_even_by_an_approved_actor(string $role): void
    {
        $actor = $this->actor();
        $target = $this->actor($role);
        $target->roles()->create(['role' => 'Student']);
        $student = $this->record();
        $before = $this->state();
        $result = app(LinkStudentAccount::class)->forActor($actor, $target->email, $student->student_number, 'Checked', true);
        $this->assertSame('protected_account', $result->code);
        $this->assertFalse($result->successful);
        $this->assertSame($before, $this->state());
    }

    public function test_actor_authorization_does_not_trust_cached_memberships(): void
    {
        $actor = $this->actor();
        $this->assertTrue($actor->hasAnyRole(['registrar']));
        $actor->roles()->delete();
        $target = User::factory()->create();
        $student = $this->record();
        $before = $this->state();
        $result = app(LinkStudentAccount::class)->forActor($actor, $target->email, $student->student_number, 'Checked', true);
        $this->assertSame('forbidden', $result->code);
        $this->assertSame($before, $this->state());
    }

    public function test_identity_reason_and_valid_input_are_required_before_linking(): void
    {
        $actor = $this->actor();
        $target = User::factory()->create();
        $student = $this->record();
        $before = $this->state();
        foreach ([[$target->email, 'Checked', false], [$target->email, ' ', true], ['not-an-email', 'Checked', true]] as [$email, $reason, $confirmed]) {
            $result = app(LinkStudentAccount::class)->forActor($actor, $email, $student->student_number, $reason, $confirmed);
            $this->assertSame('invalid_input', $result->code);
            $this->assertFalse($result->successful);
        }
        $this->assertSame($before, $this->state());
    }

    public function test_missing_accounts_or_student_records_fail_without_creating_anything(): void
    {
        $actor = $this->actor();
        $target = User::factory()->create();
        $student = $this->record();
        $before = $this->state();
        foreach ([[$target->email, 'MISSING'], ['missing@example.test', $student->student_number]] as [$email, $number]) {
            $result = app(LinkStudentAccount::class)->forActor($actor, $email, $number, 'Checked', true);
            $this->assertSame('not_found', $result->code);
            $this->assertFalse($result->successful);
        }
        $this->assertSame('not_found', app(LinkStudentAccount::class)->createForActor($actor, 'MISSING')->code);
        $this->assertSame($before, $this->state());
    }

    public function test_conflicts_in_both_directions_preserve_memberships_and_links(): void
    {
        $actor = $this->actor();
        $first = $this->actor('Student');
        $second = $this->actor('student');
        $owned = $this->record();
        $owned->update(['user_id' => $first->id]);
        $unlinked = $this->record();
        $before = $this->state();
        foreach ([[$second, $owned], [$first, $unlinked]] as [$target, $student]) {
            $result = app(LinkStudentAccount::class)->forActor($actor, $target->email, $student->student_number, 'Checked', true);
            $this->assertSame('association_conflict', $result->code);
        }
        $this->assertSame($before, $this->state());
    }

    public function test_duplicate_contact_emails_require_review_without_breaking_valid_repeats(): void
    {
        $actor = $this->actor();
        $target = User::factory()->create();
        $student = $this->record();
        $student->update(['email' => $target->email]);
        $other = $this->record();
        $other->update(['email' => Str::upper($target->email)]);
        $service = app(LinkStudentAccount::class);
        $before = $this->state();
        $this->assertSame('duplicate_contact_email', $service->forActor($actor, $target->email, $student->student_number, 'Checked', true)->code);
        $this->assertSame($before, $this->state());
        $student->update(['user_id' => $target->id]);
        $target->roles()->create(['role' => 'student']);
        $before = $this->state();
        $this->assertSame('unchanged', $service->forActor($actor, $target->email, $student->student_number, 'Checked', true)->code);
        $this->assertSame($before, $this->state());
    }

    public function test_new_account_creation_is_independent_of_mail_but_still_validates_identity(): void
    {
        Notification::fake();
        $actor = $this->actor();
        foreach (['log', 'smtp'] as $mailer) {
            config(['mail.default' => $mailer]);
            $student = $this->record();
            $email = Str::uuid().'@example.test';
            $student->update(['email' => $email]);
            $before = $this->state();
            $invalid = app(LinkStudentAccount::class)->createForActor($actor, $student->student_number);
            $this->assertSame('invalid_input', $invalid->code);
            $this->assertSame($before, $this->state());
            $result = app(LinkStudentAccount::class)->createForActor($actor, $student->student_number, $email, 'Reviewed fictional identity', true);
            $this->assertTrue($result->successful);
            $this->assertTrue($student->fresh()->user->student_setup_required);
            $this->assertSame(['student'], $student->fresh()->user->roles()->pluck('role')->all());
        }
        Notification::assertNothingSent();
    }

    public function test_audit_exceptions_roll_back_actor_linkage_and_canonicalization(): void
    {
        $actor = $this->actor();
        $target = $this->actor('Student');
        $student = $this->record();
        $before = $this->state();
        AuditEvent::creating(fn () => throw new RuntimeException('Synthetic service audit failure'));
        try {
            app(LinkStudentAccount::class)->forActor($actor, $target->email, $student->student_number, 'Checked', true);
            $this->fail('An infrastructure failure must abort the transaction.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic service audit failure', $error->getMessage());
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertSame($before, $this->state());
    }

    public function test_failed_policy_postcondition_returns_failure_and_rolls_back(): void
    {
        $actor = $this->actor();
        $target = $this->actor('Student');
        $student = $this->record();
        $before = $this->state();
        Gate::before(fn ($user, $ability) => $ability === 'viewOwnProfile' ? false : null);
        $result = app(LinkStudentAccount::class)->forActor($actor, $target->email, $student->student_number, 'Checked', true);
        $this->assertSame('postcondition_failed', $result->code);
        $this->assertFalse($result->successful);
        $this->assertSame($before, $this->state());
    }

    public function test_mysql_email_collation_alias_does_not_select_another_login_identity(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Email alias identity requires the case/accent-insensitive MySQL collation.');
        }
        $actor = $this->actor();
        $target = User::factory()->create(['email' => "stud\u{00e9}nt@example.test"]);
        $student = $this->record();
        $before = $this->state();
        $this->assertTrue(User::where('email', 'student@example.test')->exists());
        $result = app(LinkStudentAccount::class)->forActor($actor, 'student@example.test', $student->student_number, 'Checked', true);
        $this->assertSame('email_conflict', $result->code);
        $this->assertSame($before, $this->state());
    }
}
