<?php

namespace Tests\Feature\Auth;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsScholarshipRecords;
use Tests\TestCase;

class StudentRoleTest extends TestCase
{
    use BuildsScholarshipRecords, RefreshDatabase;

    private function studentAccount(bool $linked = true): array
    {
        $user = User::factory()->create();
        $user->roles()->create(['role' => User::STUDENT_ROLE]);
        $student = $linked ? Student::create([
            'student_number' => Str::upper((string) Str::uuid()), 'user_id' => $user->id,
            'first_name' => 'PrivateStudent', 'last_name' => 'OwnProfile', 'email' => $user->email,
            'lrn' => '123456789012', 'philsys_id' => '1234-5678-9012-3456',
            'street_barangay' => 'Own private address', 'mother_maiden_first_name' => 'Own private parent',
        ]) : null;

        return [$user, $student];
    }

    public function test_students_login_with_existing_credentials_rotate_sessions_and_logout(): void
    {
        [$user, $student] = $this->studentAccount();
        $this->get(route('login'))->assertOk();
        $oldSession = session()->getId();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasNoErrors()->assertRedirect('/student/profile');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSession, session()->getId());
        $this->get(route('student.profile'))->assertOk()->assertSeeText($student->student_number);
        $this->get(route('dashboard'))->assertRedirect(route('student.profile'));
        $this->withSession(['student_private_marker' => 'discard'])->post(route('logout'))
            ->assertRedirect('/')->assertSessionMissing('student_private_marker');
        $this->assertGuest();
        $this->get(route('student.profile'))->assertRedirect(route('login'));
    }

    public function test_wrong_password_and_forged_login_role_do_not_authenticate_or_grant_access(): void
    {
        [$user] = $this->studentAccount();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password', 'role' => 'administrator'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password', 'roles' => ['administrator']])
            ->assertRedirect('/student/profile');
        $this->assertSame([User::STUDENT_ROLE], $user->roles()->pluck('role')->all());
        $this->get(route('scholars.index'))->assertForbidden();
    }

    public function test_existing_login_rate_limit_applies_to_students(): void
    {
        [$user] = $this->studentAccount();
        foreach (range(1, 5) as $attempt) {
            $this->post(route('login'), ['email' => $user->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        }
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_student_intended_profile_redirect_uses_the_existing_login_flow(): void
    {
        [$user] = $this->studentAccount();
        $this->get(route('student.profile'))->assertRedirect(route('login'));
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('student.profile'));
        $this->get(route('student.profile'))->assertOk();
    }

    public function test_a_staff_intended_url_does_not_bypass_student_authorization_after_login(): void
    {
        [$user] = $this->studentAccount();
        $this->get(route('scholars.index'))->assertRedirect(route('login'));
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('scholars.index'));
        $this->get(route('scholars.index'))->assertForbidden();
        $this->get(route('student.profile'))->assertOk();
    }

    public function test_own_profile_is_private_and_navigation_contains_no_staff_links(): void
    {
        [$user, $student] = $this->studentAccount();
        [$otherUser, $other] = $this->studentAccount();
        $other->update(['first_name' => 'OtherPrivateStudent', 'lrn' => '987654321098', 'street_barangay' => 'Other secret address']);
        $response = $this->actingAs($user)->get(route('student.profile'))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertViewHas('student', fn ($record) => $record->is($student))
            ->assertSeeText('Student portal')->assertSeeText('My student profile')
            ->assertSeeText('Student')->assertDontSeeText('Unrecognized role')
            ->assertSeeText($student->student_number)->assertSeeText($student->lrn)
            ->assertSeeText($student->philsys_id)->assertSeeText('Own private address')
            ->assertDontSeeText($other->student_number)->assertDontSeeText($other->lrn)
            ->assertDontSeeText('Other secret address')->assertDontSeeText('OtherPrivateStudent')
            ->assertDontSeeText('Academic profile activity')->assertDontSeeText('Total scholars')
            ->assertDontSee('href="'.route('profile.edit').'"', false)
            ->assertSee('href="'.route('student.profile.edit').'"', false);
        $this->assertGreaterThanOrEqual(2, substr_count($response->getContent(), 'href="'.route('student.profile').'"'));
        foreach (['scholars.index', 'evaluations.index', 'academic-verifications.index', 'billing-batches.index', 'financial.index', 'scholarship-reports.index', 'academic-periods.index'] as $route) {
            $response->assertDontSee('href="'.route($route).'"', false);
        }
        $this->assertTrue(Gate::forUser($user)->allows('viewOwnProfile', $student));
        $this->assertTrue(Gate::forUser($user)->allows('viewProtectedInformation', $student));
        $this->assertFalse(Gate::forUser($user)->allows('viewOwnProfile', $other));
        $this->assertFalse(Gate::forUser($user)->allows('viewProtectedInformation', $other));
        $this->assertFalse(Gate::forUser($user)->allows('update', $student));
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', Student::class));
        $this->assertSame($otherUser->id, $other->fresh()->user_id);
    }

    public function test_url_and_query_identifiers_cannot_select_another_students_record(): void
    {
        [$user, $student] = $this->studentAccount();
        [$otherUser, $other] = $this->studentAccount();
        $this->actingAs($user)->get(route('student.profile', ['student_id' => $other->id, 'user_id' => $otherUser->id, 'id' => $other->id, 'student_number' => $other->student_number]))
            ->assertOk()->assertViewHas('student', fn ($record) => $record->is($student))
            ->assertDontSeeText($other->student_number);
        $this->get('/student/profile/'.$other->id)->assertNotFound();
        foreach ([$student, $other] as $record) {
            $this->get(route('scholars.show', $record))->assertForbidden();
            $this->get(route('scholars.edit', $record))->assertForbidden();
            $this->patch(route('scholars.update', $record), ['user_id' => $user->id, 'first_name' => 'Hijacked'])->assertForbidden();
        }
        $this->patch(route('student.profile.update'), ['student_id' => $other->id])->assertStatus(303)->assertSessionHasErrors('student_id');
        $this->assertSame($otherUser->id, $other->fresh()->user_id);
        $this->assertSame('PrivateStudent', $student->fresh()->first_name);
    }

    public function test_unlinked_students_do_not_claim_a_profile_by_matching_email(): void
    {
        [$user] = $this->studentAccount(linked: false);
        $record = Student::create(['student_number' => (string) Str::uuid(), 'first_name' => 'MustNotBeExposed', 'last_name' => 'Unlinked', 'email' => $user->email]);
        $this->actingAs($user)->get(route('student.profile'))->assertOk()
            ->assertViewHas('student', null)->assertSeeText('No student profile linked')
            ->assertDontSeeText($record->student_number)->assertDontSeeText('MustNotBeExposed');
        $this->assertNull($record->fresh()->user_id);
        $this->get(route('dashboard'))->assertRedirect(route('student.profile'));
    }

    public function test_account_link_and_student_membership_are_both_required(): void
    {
        [$user, $student] = $this->studentAccount();
        $user->roles()->delete();
        $this->actingAs($user->fresh())->get(route('student.profile'))->assertForbidden();
        $this->assertFalse(Gate::forUser($user->fresh())->allows('viewOwnProfile', $student));
        $user->roles()->create(['role' => User::STUDENT_ROLE]);
        $student->update(['user_id' => null]);
        $this->actingAs($user->fresh())->get(route('student.profile'))->assertOk()->assertViewHas('student', null);
    }

    public function test_student_account_settings_and_direct_updates_are_forbidden_without_changes(): void
    {
        [$user, $student] = $this->studentAccount();
        [$otherUser, $other] = $this->studentAccount();
        $beforeStudent = $student->fresh()->getAttributes();
        $beforeOther = $otherUser->fresh()->getAttributes();
        $beforeUser = $user->fresh()->getAttributes();
        $this->actingAs($user)->get(route('profile.edit'))->assertForbidden();
        $this->getJson(route('profile.edit'))->assertForbidden();
        $this->patch(route('profile.update'), [
            'name' => 'Updated account name', 'email' => $user->email, 'id' => $otherUser->id,
            'user_id' => $otherUser->id, 'student_id' => $other->id, 'student_number' => $other->student_number,
            'role' => 'administrator', 'roles' => ['coordinator'], 'first_name' => 'Forged official name',
        ])->assertForbidden();
        $this->patchJson(route('profile.update'), ['name' => 'Forged name', 'email' => 'forged@example.test'])->assertForbidden();
        $this->assertSame([User::STUDENT_ROLE], $user->roles()->pluck('role')->all());
        $this->assertSame($beforeStudent, $student->fresh()->getAttributes());
        $this->assertSame($beforeOther, $otherUser->fresh()->getAttributes());
        $this->put(route('password.update'), ['current_password' => 'password', 'password' => 'StudentUpdatedPassword123!', 'password_confirmation' => 'StudentUpdatedPassword123!'])
            ->assertForbidden();
        $this->putJson(route('password.update'), [])->assertForbidden();
        $this->assertSame($beforeUser, $user->fresh()->getAttributes());
    }

    public function test_linked_student_account_deletion_preserves_retained_records(): void
    {
        [$user, $student] = $this->studentAccount();
        $this->actingAs($user)->from(route('profile.edit'))->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertForbidden();
        $this->deleteJson(route('profile.destroy'), ['password' => 'password'])->assertForbidden();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh());
        $this->assertSame($user->id, $student->fresh()->user_id);
    }

    public static function restrictedSettingsAccounts(): array
    {
        return [
            'unlinked canonical student' => ['student', false],
            'legacy capitalized membership' => ['Student', false],
            'linked missing membership' => [null, true],
        ];
    }

    #[DataProvider('restrictedSettingsAccounts')]
    public function test_incomplete_or_legacy_student_accounts_cannot_bypass_settings_restriction(?string $role, bool $linked): void
    {
        [$user] = $this->studentAccount($linked);
        $user->roles()->delete();
        if ($role !== null) {
            $user->roles()->create(['role' => $role]);
        }
        $this->actingAs($user->fresh())->get(route('profile.edit'))->assertForbidden();
        $this->patchJson(route('profile.update'), [])->assertForbidden();
        $this->putJson(route('password.update'), [])->assertForbidden();
        $this->deleteJson(route('profile.destroy'), [])->assertForbidden();
    }

    public function test_student_cannot_read_staff_records_reports_or_private_evidence(): void
    {
        $g = $this->financialContext();
        [$user] = $this->studentAccount();
        $document = $g['receipt']->documents()->create(['disk' => 'scholarship', 'storage_path' => 'synthetic/student-denied.pdf', 'original_name' => 'denied.pdf', 'document_type' => 'receipt_evidence', 'mime_type' => 'application/pdf', 'size_bytes' => 10]);
        $this->actingAs($user);
        foreach (['scholars.index', 'scholarship-programs.index', 'academic-periods.index', 'academic-programs.index', 'academic-verifications.index', 'evaluations.index', 'billing-batches.index', 'billing-batches.options', 'billing-batches.report', 'financial.index', 'financial.funding-status', 'scholarship-batches.index'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        foreach ([
            ['scholars.show', $g['student']], ['scholarship-programs.show', $g['program']], ['academic-periods.show', $g['period']],
            ['academic-verifications.show', $g['record']], ['evaluations.show', $g['application']], ['billing-batches.show', $g['batch']],
            ['financial.batch', $g['batch']], ['financial.receipt', $g['receipt']], ['financial.payout', $g['payout']],
            ['financial.liquidation', $g['liquidation']], ['financial.liquidations.print', $g['liquidation']],
            ['financial.evidence.download', ['receipt', $g['receipt'], $document]],
            ['academic-verifications.certificate', [$g['record'], $document]], ['evaluations.evidence.download', [$g['application'], $document]],
        ] as [$route, $parameters]) {
            $this->get(route($route, $parameters))->assertForbidden();
        }
        foreach (['grants', 'billed', 'not-billed', 'awaiting-funds', 'paid-signed', 'liquidated'] as $report) {
            foreach (['html', 'csv', 'print'] as $format) {
                $this->get(route('scholarship-reports.index', compact('report', 'format')))->assertForbidden();
            }
        }
    }

    public function test_student_cannot_mutate_school_records_even_with_forged_privileged_roles(): void
    {
        $g = $this->financialContext();
        [$user, $student] = $this->studentAccount();
        $this->actingAs($user);
        $tables = ['users', 'user_roles', 'students', 'scholarship_assignments', 'scholarship_applications', 'academic_records', 'evaluation_runs', 'billing_batches', 'billing_items', 'funding_receipts', 'funding_allocations', 'payouts', 'deductions', 'liquidations', 'documents', 'audit_events'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()]);
        foreach ([
            ['POST', 'scholars.store', []], ['PATCH', 'scholars.update', $student], ['PATCH', 'scholars.update', $g['student']],
            ['POST', 'scholars.assignments.store', $g['student']], ['PATCH', 'scholars.assignments.update', [$g['student'], $g['assignment']]],
            ['POST', 'scholarship-programs.store', []], ['PATCH', 'scholarship-programs.update', $g['program']],
            ['POST', 'academic-periods.store', []], ['PATCH', 'academic-periods.update', $g['period']],
            ['POST', 'academic-periods.context', []], ['POST', 'academic-programs.store', []], ['POST', 'scholarship-batches.store', []],
            ['POST', 'scholars.batch-memberships.store', $g['student']], ['POST', 'academic-verifications.store', []],
            ['PATCH', 'academic-verifications.update', $g['record']], ['POST', 'academic-verifications.correct', $g['record']],
            ['POST', 'evaluations.store', []], ['POST', 'evaluations.run', $g['application']],
            ['POST', 'evaluations.policy', $g['application']], ['POST', 'evaluations.external', $g['application']], ['POST', 'evaluations.activate', $g['application']],
            ['POST', 'billing-batches.store', []], ['PATCH', 'billing-batches.update', $g['batch']],
            ['POST', 'billing-batches.items.store', $g['batch']], ['DELETE', 'billing-batches.items.destroy', [$g['batch'], $g['item']]],
            ['POST', 'billing-batches.submit', $g['batch']], ['POST', 'billing-batches.terms', []], ['POST', 'billing-batches.budget', []],
            ['POST', 'billing-batches.approve', $g['batch']], ['POST', 'billing-batches.items.adjust', [$g['batch'], $g['item']]],
            ['POST', 'financial.receipts.store', []], ['POST', 'financial.receipts.post', $g['receipt']], ['POST', 'financial.allocate', $g['batch']],
            ['POST', 'financial.payouts.store', []], ['POST', 'financial.payouts.post', $g['payout']], ['POST', 'financial.deduct', $g['payout']],
            ['POST', 'financial.settle', $g['deduction']], ['POST', 'financial.payrolls.store', $g['batch']],
            ['POST', 'financial.acknowledgments.store', $g['payout']], ['POST', 'financial.liquidations.store', $g['batch']],
            ['POST', 'financial.liquidations.refresh', $g['liquidation']], ['POST', 'financial.liquidations.submit', $g['liquidation']],
            ['POST', 'financial.liquidations.review', $g['liquidation']], ['POST', 'financial.evidence.store', ['receipt', $g['receipt']]],
        ] as [$method, $route, $parameters]) {
            $this->call($method, route($route, $parameters), ['role' => 'administrator', 'roles' => ['coordinator'], 'user_id' => $g['actor']->id])->assertForbidden();
        }
        foreach ($before as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->orderBy('id')->get()->toJson(), $table.' must remain unchanged');
        }
    }

    public static function staffRoles(): array
    {
        return array_map(fn ($role) => [$role], User::STAFF_ROLES);
    }

    #[DataProvider('staffRoles')]
    public function test_existing_staff_roles_keep_their_dashboard_and_record_permissions(string $role): void
    {
        $user = User::factory()->create();
        $user->roles()->create(['role' => $role]);
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get(route('dashboard'))->assertOk()->assertSeeText('Total scholars');
        $this->get(route('scholars.index'))->assertOk();
        $this->get(route('financial.index'))->assertOk();
        $this->get(route('student.profile'))->assertForbidden();
        $this->get(route('profile.edit'))->assertOk()->assertSee('href="'.route('profile.edit').'"', false);
        $this->patch(route('profile.update'), ['name' => 'Staff account', 'email' => $user->email])
            ->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));
        $this->from(route('profile.edit'))->put(route('password.update'), [
            'current_password' => 'password', 'password' => 'StaffUpdatedPassword123!', 'password_confirmation' => 'StaffUpdatedPassword123!',
        ])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));
        $this->assertTrue(Hash::check('StaffUpdatedPassword123!', $user->fresh()->password));
    }

    public function test_explicit_existing_staff_memberships_are_preserved_on_a_student_account(): void
    {
        [$user, $student] = $this->studentAccount();
        $user->roles()->create(['role' => 'coordinator']);
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->get(route('dashboard'))->assertOk()->assertSeeText('Total scholars');
        $this->get(route('student.profile'))->assertOk()->assertSeeText($student->student_number);
        $this->get(route('profile.edit'))->assertOk();
        $this->assertSame(['coordinator', User::STUDENT_ROLE], $user->roles()->orderBy('role')->pluck('role')->all());
    }

    public function test_registration_and_http_role_provisioning_remain_unavailable(): void
    {
        [$user] = $this->studentAccount();
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['role' => 'student', 'email' => 'new@example.test'])->assertNotFound();
        $this->actingAs($user)->post('/sms/link-student', ['email' => $user->email])->assertNotFound();
        $this->post('/users', ['role' => 'administrator'])->assertNotFound();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame([User::STUDENT_ROLE], $user->roles()->pluck('role')->all());
    }
}
