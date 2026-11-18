<?php

namespace Tests\Feature\Auth;

use App\Models\AuditEvent;
use App\Models\Student;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class StudentProvisioningRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function record(): Student
    {
        return Student::create(['student_number' => (string) Str::uuid(), 'first_name' => 'Provisioning', 'last_name' => 'Fixture']);
    }

    private function link(User $user, Student $student): int
    {
        return Artisan::call('sms:link-student', ['email' => $user->email, 'student_number' => $student->student_number]);
    }

    public function test_mysql_capitalized_student_membership_is_repaired_before_reporting_success(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('SR-01 requires MySQL with a case-insensitive role collation.');
        }

        $user = User::factory()->create();
        $membership = $user->roles()->create(['role' => 'Student']);
        $student = $this->record();
        $other = $this->record();
        $before = $user->fresh()->getAttributes();
        $this->assertTrue($user->roles()->where('role', User::STUDENT_ROLE)->exists());
        $this->assertFalse($user->fresh()->hasAnyRole([User::STUDENT_ROLE]));

        $this->assertSame(0, $this->link($user, $student));
        $this->actingAs($user->fresh())->get(route('student.profile'))->assertOk()->assertSeeText($student->student_number);
        $this->assertSame('student', $membership->fresh()->role);
        $this->assertSame([$membership->id], $user->roles()->pluck('id')->all());
        $this->assertSame($user->id, $student->fresh()->user_id);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertFalse(Gate::forUser($user->fresh())->allows('viewOwnProfile', $other));
        $this->get(route('scholars.show', $other))->assertForbidden();
        $this->get(route('scholars.index'))->assertForbidden();
        $this->assertSame(['user_id' => null, 'role' => 'Student'], AuditEvent::sole()->before_state);
        $this->assertSame(['user_id' => $user->id, 'role' => 'student'], AuditEvent::sole()->after_state);
        $this->assertSame(0, $this->link($user, $student));
        $this->assertDatabaseCount('user_roles', 1);
        $this->assertDatabaseCount('audit_events', 1);

        $this->post(route('logout'));
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('student.profile', absolute: false));
        $this->assertAuthenticatedAs($user);
        $this->get(route('student.profile'))->assertOk();
    }

    public static function studentVariants(): array
    {
        return [['Student'], ['STUDENT'], [' student '], ['student ']];
    }

    public function test_mysql_collation_aliases_are_not_silently_granted_student_access(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Accent-insensitive SQL equality requires the MySQL role collation.');
        }
        $user = User::factory()->create();
        $role = "stud\u{00e9}nt";
        $membership = $user->roles()->create(['role' => $role]);
        $student = $this->record();
        $this->assertTrue($user->roles()->where('role', 'student')->exists());

        $this->assertSame(1, $this->link($user, $student));
        $this->assertNull($student->fresh()->user_id);
        $this->assertSame($role, $membership->fresh()->role);
        $this->assertFalse($user->fresh()->hasAnyRole(['student']));
        $this->assertDatabaseCount('user_roles', 1);
        $this->assertDatabaseCount('audit_events', 0);
    }

    #[DataProvider('studentVariants')]
    public function test_only_the_selected_student_membership_is_canonicalized_in_place(string $role): void
    {
        $user = User::factory()->create();
        $membership = $user->roles()->create(['role' => $role]);
        $staff = $user->roles()->create(['role' => 'registrar']);
        $unrecognized = $user->roles()->create(['role' => 'Administrator']);
        $unrelated = User::factory()->create()->roles()->create(['role' => 'Student']);
        $student = $this->record();
        $student->update(['user_id' => $user->id]);
        $beforeStudent = $student->fresh()->getAttributes();
        $beforeUser = $user->fresh()->getAttributes();
        $beforeStaff = $staff->fresh()->getAttributes();
        $beforeUnrecognized = $unrecognized->fresh()->getAttributes();
        $beforeUnrelated = $unrelated->fresh()->getAttributes();

        $this->assertSame(0, $this->link($user, $student));
        $this->assertSame('student', $membership->fresh()->role);
        $this->assertSame($beforeStudent, $student->fresh()->getAttributes());
        $this->assertSame($beforeUser, $user->fresh()->getAttributes());
        $this->assertSame($beforeStaff, $staff->fresh()->getAttributes());
        $this->assertSame($beforeUnrecognized, $unrecognized->fresh()->getAttributes());
        $this->assertSame($beforeUnrelated, $unrelated->fresh()->getAttributes());
        $this->assertTrue($user->fresh()->hasAnyRole(['registrar', 'student']));
        $this->assertFalse($user->fresh()->hasAnyRole(['administrator']));
        $this->assertSame($role, AuditEvent::sole()->before_state['role']);
        $this->assertSame(0, $this->link($user, $student));
        $this->assertDatabaseCount('user_roles', 4);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_ambiguous_student_memberships_are_rejected_without_deleting_or_linking_records(): void
    {
        $user = User::factory()->create();
        $user->roles()->createMany([['role' => 'student'], ['role' => ' Student']]);
        $student = $this->record();
        $before = DB::table('user_roles')->orderBy('id')->get()->toJson();

        $this->assertSame(1, $this->link($user, $student));
        $this->assertStringContainsString('multiple Student memberships', Artisan::output());
        $this->assertNull($student->fresh()->user_id);
        $this->assertSame($before, DB::table('user_roles')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_conflicting_links_do_not_canonicalize_memberships(): void
    {
        $user = User::factory()->create();
        $membership = $user->roles()->create(['role' => 'Student']);
        $own = $this->record();
        $other = $this->record();
        $own->update(['user_id' => $user->id]);
        $this->assertSame(1, $this->link($user, $other));
        $this->assertSame(1, $this->link(User::factory()->create(), $own));
        $this->assertSame('Student', $membership->fresh()->role);
        $this->assertSame($user->id, $own->fresh()->user_id);
        $this->assertNull($other->fresh()->user_id);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_audit_failure_rolls_back_canonicalization_and_link(): void
    {
        $user = User::factory()->create();
        $membership = $user->roles()->create(['role' => 'Student']);
        $student = $this->record();
        $before = $membership->fresh()->getAttributes();
        AuditEvent::creating(fn () => throw new RuntimeException('Synthetic audit failure'));
        try {
            $this->link($user, $student);
            $this->fail('Auditing must complete before committing access changes.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic audit failure', $error->getMessage());
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertSame($before, $membership->fresh()->getAttributes());
        $this->assertNull($student->fresh()->user_id);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public static function cancelledWrites(): array
    {
        return [[Student::class], [UserRole::class], [AuditEvent::class]];
    }

    #[DataProvider('cancelledWrites')]
    public function test_cancelled_required_writes_report_failure_and_roll_back(string $model): void
    {
        $user = User::factory()->create();
        $student = $this->record();
        $model::saving(fn () => false);
        try {
            $this->assertSame(1, $this->link($user, $student));
            $this->assertStringContainsString('No access was changed', Artisan::output());
        } finally {
            $model::flushEventListeners();
        }
        $this->assertNull($student->fresh()->user_id);
        $this->assertDatabaseCount('user_roles', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_postconditions_are_checked_again_after_auditing(): void
    {
        $user = User::factory()->create();
        $membership = $user->roles()->create(['role' => 'Student']);
        $student = $this->record();
        AuditEvent::created(fn () => DB::table('user_roles')->where('id', $membership->id)->update(['role' => 'Student']));
        try {
            $this->assertSame(1, $this->link($user, $student));
            $this->assertStringContainsString('No access was changed', Artisan::output());
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertSame('Student', $membership->fresh()->role);
        $this->assertNull($student->fresh()->user_id);
        $this->assertDatabaseCount('audit_events', 0);
    }
}
