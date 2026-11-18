<?php

namespace Tests\Feature\Auth;

use App\Models\AuditEvent;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class StudentAccountLinkTest extends TestCase
{
    use RefreshDatabase;

    private function record(): Student
    {
        return Student::create(['student_number' => Str::upper((string) Str::uuid()), 'first_name' => 'Synthetic', 'last_name' => 'Student']);
    }

    public function test_trusted_link_is_idempotent_and_audited_without_changing_credentials(): void
    {
        $user = User::factory()->create();
        $student = $this->record();
        $before = $user->fresh()->getAttributes();
        foreach (range(1, 2) as $attempt) {
            $this->artisan('sms:link-student', ['email' => $user->email, 'student_number' => $student->student_number])->assertSuccessful();
        }
        $this->assertSame($user->id, $student->fresh()->user_id);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame([User::STUDENT_ROLE], $user->roles()->pluck('role')->all());
        $this->assertDatabaseCount('user_roles', 1);
        $this->assertDatabaseCount('audit_events', 1);
        $event = AuditEvent::sole();
        $this->assertSame('student_account_linked', $event->action);
        $this->assertSame(['user_id' => null], $event->before_state);
        $this->assertSame(['user_id' => $user->id, 'role' => User::STUDENT_ROLE], $event->after_state);
        $this->assertSame(['source' => 'trusted console operator'], $event->actor_snapshot);
        $this->actingAs($user->fresh())->get(route('student.profile'))->assertOk()->assertSeeText($student->student_number);
    }

    public function test_an_existing_student_membership_is_reused_without_duplication(): void
    {
        $user = User::factory()->create();
        $membership = $user->roles()->create(['role' => User::STUDENT_ROLE]);
        $student = $this->record();
        $this->artisan('sms:link-student', ['email' => $user->email, 'student_number' => $student->student_number])->assertSuccessful();
        $this->assertSame([$membership->id], $user->roles()->pluck('id')->all());
        $this->assertSame($user->id, $student->fresh()->user_id);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_an_existing_account_link_can_receive_the_missing_student_membership(): void
    {
        $user = User::factory()->create();
        $student = $this->record();
        $student->update(['user_id' => $user->id]);
        $beforeStudent = $student->fresh()->getAttributes();
        $this->artisan('sms:link-student', ['email' => $user->email, 'student_number' => $student->student_number])->assertSuccessful();
        $this->assertSame($beforeStudent, $student->fresh()->getAttributes());
        $this->assertSame([User::STUDENT_ROLE], $user->roles()->pluck('role')->all());
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_a_student_record_linked_to_another_account_is_not_reassigned(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create();
        $student = $this->record();
        $student->update(['user_id' => $owner->id]);
        $before = $student->fresh()->getAttributes();
        $this->artisan('sms:link-student', ['email' => $user->email, 'student_number' => $student->student_number])->assertFailed();
        $this->assertSame($before, $student->fresh()->getAttributes());
        $this->assertDatabaseCount('user_roles', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_an_account_already_linked_to_another_student_is_not_reassigned(): void
    {
        $user = User::factory()->create();
        $own = $this->record();
        $other = $this->record();
        $own->update(['user_id' => $user->id]);
        $this->artisan('sms:link-student', ['email' => $user->email, 'student_number' => $other->student_number])->assertFailed();
        $this->assertSame($user->id, $own->fresh()->user_id);
        $this->assertNull($other->fresh()->user_id);
        $this->assertDatabaseCount('user_roles', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_missing_accounts_or_records_fail_without_creating_anything(): void
    {
        $user = User::factory()->create();
        $student = $this->record();
        foreach ([['missing@example.test', $student->student_number], [$user->email, 'NO-SUCH-RECORD']] as [$email, $number]) {
            $this->artisan('sms:link-student', ['email' => $email, 'student_number' => $number])->assertFailed();
        }
        $this->assertNull($student->fresh()->user_id);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('students', 1);
        $this->assertDatabaseCount('user_roles', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_existing_staff_memberships_and_passwords_are_preserved(): void
    {
        $user = User::factory()->create();
        $user->roles()->create(['role' => 'registrar']);
        $student = $this->record();
        $before = $user->fresh()->getAttributes();
        $this->artisan('sms:link-student', ['email' => $user->email, 'student_number' => $student->student_number])->assertSuccessful();
        $this->assertSame(['registrar', User::STUDENT_ROLE], $user->roles()->orderBy('role')->pluck('role')->all());
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertFalse($user->fresh()->isStudentOnly());
    }

    public function test_a_failed_audit_rolls_back_the_link_and_membership_together(): void
    {
        $user = User::factory()->create();
        $student = $this->record();
        AuditEvent::creating(fn () => throw new RuntimeException('Synthetic audit failure'));
        try {
            Artisan::call('sms:link-student', ['email' => $user->email, 'student_number' => $student->student_number]);
            $this->fail('The audit failure must abort the link.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic audit failure', $error->getMessage());
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertNull($student->fresh()->user_id);
        $this->assertDatabaseCount('user_roles', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_audit_recognizes_student_memberships_and_flags_missing_links_without_mutation(): void
    {
        $linked = User::factory()->create();
        $unlinked = User::factory()->create();
        $linked->roles()->create(['role' => User::STUDENT_ROLE]);
        $unlinked->roles()->create(['role' => User::STUDENT_ROLE]);
        $student = $this->record();
        $student->update(['user_id' => $linked->id]);
        $before = DB::table('user_roles')->orderBy('id')->get()->toJson();
        $this->assertSame(0, Artisan::call('sms:audit-access'));
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(2, $report['student_accounts']);
        $this->assertSame(1, $report['student_accounts_without_profile']);
        $this->assertSame([], $report['unexpected_roles']);
        $this->assertSame(['coordinator', 'registrar'], $report['operational_roles']);
        $this->assertStringNotContainsString($linked->email, $output);
        $this->assertStringNotContainsString($linked->password, $output);
        $this->assertSame($before, DB::table('user_roles')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('audit_events', 0);
    }
}
