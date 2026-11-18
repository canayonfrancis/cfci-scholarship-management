<?php

namespace Tests\Feature\Records;

use App\Actions\Records\UpdateOwnStudentProfile;
use App\Models\AuditEvent;
use App\Models\Student;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StudentProfileEditingTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $role = 'student'): array
    {
        Notification::fake();
        Mail::fake();
        $user = User::factory()->create();
        $user->roles()->create(['role' => $role]);
        $student = Student::create(['student_number' => (string) Str::uuid(), 'first_name' => 'Fictional', 'last_name' => 'Profile',
            'email' => $user->email, 'phone' => '9171234567', 'user_id' => $user->id, 'record_status' => 'archived',
            'lrn' => '123456789012', 'philsys_id' => '1234-5678-9012-3456', 'street_barangay' => 'Original fictional address']);

        return [$user->fresh(), $student->fresh()];
    }

    private function payload(Student $student, array $fields = []): array
    {
        return ['profile_version' => UpdateOwnStudentProfile::version($student)] + $fields;
    }

    public function test_student_can_edit_only_own_profile_and_save_personal_details_without_changing_account(): void
    {
        [$user, $student] = $this->fixture();
        [$otherUser, $other] = $this->fixture();
        $other->update(['first_name' => 'OtherPrivateStudent']);
        $beforeUser = $user->getRawOriginal();
        $beforeOther = $other->fresh()->getRawOriginal();
        $this->actingAs($user)->get(route('student.profile'))->assertOk()->assertSeeText('Edit my information');
        $this->get(route('student.profile.edit', ['student_id' => $other->id]))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertViewHas('student', fn ($record) => $record->id === $student->id)
            ->assertSeeText('Edit my information')->assertDontSeeText('OtherPrivateStudent')
            ->assertDontSee('name="student_number"', false)->assertDontSee('name="lrn"', false)
            ->assertDontSee('name="academic_program_id"', false)->assertDontSee('name="password"', false);
        $fields = [
            'first_name' => ' Updated ', 'middle_name' => 'Test', 'last_name' => 'Student', 'suffix' => 'Jr.',
            'sex' => '0', 'birthdate' => '2004-05-12', 'email' => ' UPDATED.CONTACT@example.test ', 'phone' => '+639181234567',
            'street_barangay' => 'Fictional new street', 'city_municipality' => 'Fictional City', 'province' => 'Fictional Province',
            'region' => 'Test Region', 'zip_code' => '9500', 'father_first_name' => 'Test Father', 'father_middle_name' => null,
            'father_last_name' => 'Student', 'mother_maiden_first_name' => 'Test Mother', 'mother_maiden_middle_name' => null, 'mother_maiden_last_name' => 'Fictional',
        ];
        $this->patch(route('student.profile.update'), $this->payload($student, $fields))->assertStatus(303)
            ->assertRedirect(route('student.profile'))->assertSessionHasNoErrors();
        $fresh = $student->fresh();
        $this->assertSame('Updated', $fresh->first_name);
        $this->assertSame(0, $fresh->sex);
        $this->assertSame('2004-05-12', $fresh->birthdate->toDateString());
        $this->assertSame('updated.contact@example.test', $fresh->email);
        $this->assertSame('9181234567', $fresh->phone);
        $this->assertSame('archived', $fresh->record_status);
        $this->assertSame($beforeUser, $user->fresh()->getRawOriginal());
        $this->assertSame($beforeOther, $other->fresh()->getRawOriginal());
        $this->assertSame($otherUser->id, $other->fresh()->user_id);
        $audit = AuditEvent::sole();
        $this->assertSame('student_profile_updated', $audit->action);
        $this->assertSame($user->id, $audit->actor_id);
        $this->assertSame($student->id, $audit->subject_id);
        $this->assertArrayNotHasKey('lrn', $audit->after_state);
        $this->assertArrayNotHasKey('password', $audit->after_state);
        $this->get(route('student.profile'))->assertOk()->assertSeeText('Updated Test Student Jr.')->assertSeeText('Fictional new street');
        $this->get(route('scholars.edit', $student))->assertForbidden();
        $this->get(route('scholars.show', $other))->assertForbidden();
        Notification::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_partial_updates_and_optional_clearing_preserve_login_and_broker_token(): void
    {
        [$user, $student] = $this->fixture();
        $token = Password::createToken($user);
        $before = $user->getRawOriginal();
        $this->actingAs($user)->patch(route('student.profile.update'), $this->payload($student, ['email' => 'new-contact@example.test']))->assertSessionHasNoErrors();
        $this->assertSame('Fictional', $student->fresh()->first_name);
        $this->assertSame('9171234567', $student->fresh()->phone);
        $this->assertTrue(Password::tokenExists($user, $token));
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $fresh = $student->fresh();
        $this->patch(route('student.profile.update'), $this->payload($fresh, ['email' => '', 'middle_name' => '']))->assertSessionHasNoErrors();
        $this->assertNull($student->fresh()->email);
        $this->assertNull($student->fresh()->middle_name);
    }

    public static function forbiddenFields(): array
    {
        return array_map(fn ($field) => [$field], ['id', 'student_id', 'user_id', 'student_number', 'role', 'roles', 'password', 'remember_token',
            'student_setup_required', 'student_access_disabled_at', 'record_status', 'academic_program_id', 'academic_period_id', 'year_level',
            'lrn', 'philsys_id', 'four_ps_id', 'disability_type', 'solo_parent_dependent', 'first_generation_student', 'indigenous_people_group']);
    }

    #[DataProvider('forbiddenFields')]
    public function test_protected_or_forged_fields_reject_the_entire_update(string $field): void
    {
        [$user, $student] = $this->fixture();
        $before = $student->getRawOriginal();
        $this->actingAs($user)->patchJson(route('student.profile.update'), $this->payload($student, ['first_name' => 'MustNotSave', $field => 'forged']))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $student->fresh()->getRawOriginal());
        $this->assertDatabaseCount('audit_events', 0);
    }

    public static function invalidFields(): array
    {
        return [['email', 'invalid'], ['phone', 'bad-number'], ['first_name', ' '], ['last_name', ['bad']], ['sex', '99'], ['birthdate', '2999-01-01'], ['birthdate', '2004-02-31'], ['zip_code', str_repeat('1', 21)]];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_profile_data_returns_errors_without_changing_records(string $field, mixed $value): void
    {
        [$user, $student] = $this->fixture();
        $before = $student->getRawOriginal();
        $this->actingAs($user)->patch(route('student.profile.update'), $this->payload($student, [$field => $value]))
            ->assertStatus(303)->assertRedirect(route('student.profile.edit'))->assertSessionHasErrors($field);
        $this->assertSame($before, $student->fresh()->getRawOriginal());
        $this->assertDatabaseCount('audit_events', 0);
    }

    public static function unauthorizedRoles(): array
    {
        return [['registrar'], ['administrator'], ['coordinator'], ['finance'], ['viewer'], ['Student']];
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_other_roles_cannot_use_student_self_service_even_when_linked(string $role): void
    {
        [$user, $student] = $this->fixture($role);
        $before = $student->getRawOriginal();
        $this->actingAs($user)->get(route('student.profile.edit'))->assertForbidden();
        $this->patchJson(route('student.profile.update'), $this->payload($student, ['first_name' => 'Forbidden']))->assertForbidden();
        $this->assertSame($before, $student->fresh()->getRawOriginal());
    }

    public function test_guests_missing_links_and_mixed_memberships_cannot_edit_records(): void
    {
        [$user, $student] = $this->fixture();
        $this->get(route('student.profile.edit'))->assertRedirect('/login');
        $this->patch(route('student.profile.update'), [])->assertRedirect('/login');
        $student->update(['user_id' => null]);
        $this->actingAs($user)->get(route('student.profile'))->assertSeeText('No student profile linked')->assertDontSeeText('Edit my information');
        $this->get(route('student.profile.edit'))->assertForbidden();
        $this->patchJson(route('student.profile.update'), $this->payload($student, ['first_name' => 'Claimed']))->assertForbidden();
        $student->update(['user_id' => $user->id]);
        $user->roles()->create(['role' => 'registrar']);
        $this->get(route('student.profile.edit'))->assertForbidden();
        $this->assertSame('Fictional', $student->fresh()->first_name);
    }

    public static function unavailableStates(): array
    {
        return [['student_setup_required', true], ['student_access_disabled_at', '2026-11-01 00:00:00']];
    }

    #[DataProvider('unavailableStates')]
    public function test_pending_or_deactivated_sessions_cannot_save_profile(string $field, mixed $value): void
    {
        [$user, $student] = $this->fixture();
        $user->forceFill([$field => $value])->save();
        $this->actingAs($user)->patchJson(route('student.profile.update'), $this->payload($student, ['first_name' => 'Denied']))->assertForbidden();
        $this->assertGuest();
        $this->assertSame('Fictional', $student->fresh()->first_name);
    }

    public function test_stale_or_another_students_version_cannot_overwrite_current_profile(): void
    {
        [$user, $student] = $this->fixture();
        [, $other] = $this->fixture();
        $oldPayload = $this->payload($student, ['first_name' => 'Stale edit']);
        DB::table('students')->where('id', $student->id)->update(['first_name' => 'Registrar latest']);
        $this->actingAs($user)->withSession(['_old_input' => ['first_name' => 'Unsaved values from another tab']])
            ->patch(route('student.profile.update'), $oldPayload)->assertSessionHasErrors('profile_version');
        $this->assertSame([], session('_old_input', []));
        $this->assertSame('Registrar latest', $student->fresh()->first_name);
        $this->patchJson(route('student.profile.update'), $this->payload($other, ['first_name' => 'Foreign version']))->assertUnprocessable()->assertJsonValidationErrors('profile_version');
        $this->assertSame('Registrar latest', $student->fresh()->first_name);
        $this->assertSame('Fictional', $other->fresh()->first_name);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_unchanged_save_retains_timestamp_and_creates_no_audit(): void
    {
        [$user, $student] = $this->fixture();
        $before = $student->getRawOriginal();
        $this->actingAs($user)->patch(route('student.profile.update'), $this->payload($student, ['first_name' => 'Fictional']))->assertSessionHasNoErrors()->assertSessionHas('status', 'No changes to save. Your information is already up to date.');
        $this->assertSame($before, $student->fresh()->getRawOriginal());
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_audit_failure_and_protected_field_tampering_roll_back_all_changes(): void
    {
        Exceptions::fake();
        [$user, $student] = $this->fixture();
        $before = $student->getRawOriginal();
        $this->actingAs($user);
        AuditEvent::creating(fn () => false);
        try {
            $this->patch(route('student.profile.update'), $this->payload($student, ['email' => 'new@example.test']))->assertSessionHasErrors('profile');
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertSame($before, $student->fresh()->getRawOriginal());
        AuditEvent::created(fn () => DB::table('students')->where('id', $student->id)->update(['student_number' => 'CANCELED-MUTATION']));
        try {
            $this->patch(route('student.profile.update'), $this->payload($student, ['email' => 'new@example.test']))->assertSessionHasErrors('profile');
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertSame($before, $student->fresh()->getRawOriginal());
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_authorization_failure_during_the_save_returns_access_denied(): void
    {
        [$user, $student] = $this->fixture();
        $before = $student->getRawOriginal();
        $this->mock(UpdateOwnStudentProfile::class)->shouldReceive('execute')->once()
            ->andThrow(new AuthorizationException);
        $this->actingAs($user)->patchJson(route('student.profile.update'), $this->payload($student, ['first_name' => 'Denied']))->assertForbidden();
        $this->assertSame($before, $student->fresh()->getRawOriginal());
        $this->assertDatabaseCount('audit_events', 0);
    }
}
