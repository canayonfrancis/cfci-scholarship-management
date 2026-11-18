<?php

namespace Tests\Feature\Auth;

use App\Models\AuditEvent;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class StudentAccountInterfaceTest extends TestCase
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

    private function student(array $attributes = []): Student
    {
        return Student::create(array_replace([
            'student_number' => (string) Str::uuid(), 'first_name' => 'Interface', 'last_name' => 'Fixture',
        ], $attributes));
    }

    private function payload(User $target): array
    {
        return ['login_email' => $target->email, 'reason' => 'Institutional identity reviewed', 'identity_confirmed' => '1'];
    }

    private function confirmed(User $actor): static
    {
        return $this->actingAs($actor)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
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

    public static function managers(): array
    {
        return [['registrar'], ['administrator']];
    }

    #[DataProvider('managers')]
    public function test_approved_roles_see_navigation_directory_and_safe_account_details(string $role): void
    {
        $actor = $this->actor($role);
        $target = $this->actor('student');
        $student = $this->student(['user_id' => $target->id, 'lrn' => '123456789012', 'street_barangay' => 'Unnecessary private address']);
        $response = $this->actingAs($actor)->get(route('student-accounts.index'))->assertOk()->assertSeeText('Student accounts')
            ->assertSeeText($student->student_number)->assertSeeText('Linked and configured')
            ->assertDontSeeText('Unnecessary private address')->assertDontSeeText('123456789012')
            ->assertDontSee($target->password, false)->assertDontSee($target->remember_token, false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get(route('student-accounts.show', $student))->assertOk()->assertSeeText('No account change needed')->assertSeeText($target->email);
        $this->get(route('scholars.show', $student))->assertOk()->assertSee('href="'.route('student-accounts.show', $student).'"', false);
        $this->get(route('dashboard'))->assertOk()->assertSee('href="'.route('student-accounts.index').'"', false);
    }

    public static function unauthorizedRoles(): array
    {
        return [['coordinator'], ['finance'], ['viewer'], ['student'], ['REGISTRAR'], [null]];
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_unauthorized_roles_cannot_read_or_post_even_with_valid_password_confirmation(?string $role): void
    {
        $actor = $this->actor($role);
        $target = User::factory()->create();
        $student = $this->student();
        $before = $this->state();
        $this->confirmed($actor)->get(route('student-accounts.index'))->assertForbidden();
        $this->get(route('student-accounts.show', $student))->assertForbidden();
        $this->get(route('student-accounts.confirm', $student))->assertForbidden();
        $this->patchJson(route('student-accounts.deactivate', $student), $this->payload($target))->assertForbidden();
        $this->assertSame($before, $this->state());
    }

    public function test_guests_are_redirected_to_existing_login(): void
    {
        $student = $this->student();
        $this->get(route('student-accounts.index'))->assertRedirect(route('login'));
        $this->get(route('student-accounts.show', $student))->assertRedirect(route('login'));
        $this->patch(route('student-accounts.deactivate', $student))->assertRedirect(route('login'));
    }

    public function test_status_filters_use_exact_memberships_and_do_not_invent_activation_states(): void
    {
        $unlinked = $this->student();
        $canonical = $this->student(['user_id' => $this->actor('student')->id]);
        $capitalized = $this->student(['user_id' => $this->actor('Student')->id]);
        $roleless = $this->student(['user_id' => User::factory()->create()->id]);
        $ambiguousAccount = $this->actor('Student');
        $ambiguousAccount->roles()->create(['role' => ' student']);
        $ambiguous = $this->student(['user_id' => $ambiguousAccount->id]);
        $staff = $this->actor('registrar');
        $restricted = $this->student(['user_id' => $staff->id]);
        $this->actingAs($this->actor());
        foreach (['not_linked' => [$unlinked->id], 'linked' => [$canonical->id], 'needs_review' => [$capitalized->id, $roleless->id, $ambiguous->id], 'restricted' => [$restricted->id]] as $status => $ids) {
            $this->get(route('student-accounts.index', ['account_status' => $status]))->assertOk()
                ->assertViewHas('students', fn ($rows) => $rows->pluck('id')->sort()->values()->all() === collect($ids)->sort()->values()->all());
        }
        $this->get(route('student-accounts.show', $restricted))->assertOk()->assertSeeText('Authorized technical review required')
            ->assertDontSeeText($staff->email)->assertDontSee('name="login_email"', false);
        $this->get(route('student-accounts.show', $ambiguous))->assertOk()->assertSeeText('Authorized technical review required')
            ->assertSeeText('Unavailable for review')->assertDontSeeText($ambiguousAccount->email)->assertDontSee('name="login_email"', false);
        $this->get(route('student-accounts.index', ['tables' => ['student_accounts' => ['filters' => [3 => 'Unavailable for review']]]]))
            ->assertOk()->assertViewHas('students', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $ambiguous->id);
        $this->get(route('student-accounts.index', ['account_status' => 'suspended']))->assertSessionHasErrors('account_status');
        $this->get(route('student-accounts.index', ['q' => ['bad']]))->assertSessionHasErrors('q');
    }

    public function test_search_status_and_pagination_cover_all_matching_students(): void
    {
        for ($i = 1; $i <= 23; $i++) {
            $this->student(['student_number' => 'SEARCH-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'first_name' => 'Searchable', 'email' => 'search'.$i.'@example.test']);
        }
        $other = $this->student(['first_name' => 'Other']);
        $this->actingAs($this->actor());
        $parameters = ['q' => 'Searchable', 'account_status' => 'not_linked', 'tables' => ['student_accounts' => ['size' => 20, 'sort' => ['column' => 0, 'direction' => 'desc']]]];
        $response = $this->get(route('student-accounts.index', $parameters))->assertOk()->assertDontSeeText($other->student_number)
            ->assertViewHas('students', fn ($rows) => $rows->total() === 23 && $rows->count() === 20 && $rows->first()->studentNumber === 'SEARCH-023');
        parse_str(parse_url($response->viewData('students')->nextPageUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('Searchable', $query['q']);
        $this->assertSame('not_linked', $query['account_status']);
        $this->get(route('student-accounts.index', $parameters + ['page' => 2]))->assertOk()
            ->assertViewHas('students', fn ($rows) => $rows->count() === 3 && $rows->first()->studentNumber === 'SEARCH-003');
        $this->get(route('student-accounts.index', ['q' => 'search23@example.test']))->assertViewHas('students', fn ($rows) => $rows->total() === 1);
        $this->get(route('student-accounts.index', ['q' => 'NO-SUCH-STUDENT']))->assertSeeText('No students match these filters');
    }

    public function test_new_directory_has_an_honest_empty_state(): void
    {
        $this->actingAs($this->actor())->get(route('student-accounts.index'))->assertOk()->assertSeeText('No students match these filters');
    }

    #[DataProvider('managers')]
    public function test_existing_account_linking_is_unavailable_on_the_page_and_direct_endpoint(string $role): void
    {
        $actor = $this->actor($role);
        $target = $this->actor('Student');
        $unlinked = $this->student(['record_status' => 'archived', 'email' => $target->email]);
        $needsReview = $this->student(['user_id' => $target->id]);
        $existing = $this->actor('student');
        $linked = $this->student(['user_id' => $existing->id]);
        $before = $this->state();
        $this->assertFalse(Route::has('student-accounts.provision'));
        foreach ([$unlinked, $needsReview, $linked] as $student) {
            $this->confirmed($actor)->get(route('student-accounts.show', $student))->assertOk()
                ->assertDontSeeText('Link existing account')->assertDontSeeText('Link an existing login account')
                ->assertDontSee('id="link-account-title"', false)->assertDontSee('action="'.url('/student-accounts/'.$student->id.'/provision').'"', false);
            $this->postJson('/student-accounts/'.$student->id.'/provision', $this->payload($target))->assertNotFound();
        }
        $this->get(route('student-accounts.show', $unlinked))->assertSeeText('No login account')->assertSee('name="login_email"', false);
        $this->post(route('student-accounts.create', $unlinked), $this->payload($target))->assertStatus(303)->assertSessionHasErrors('account');
        $this->get(route('student-accounts.show', $needsReview))->assertSeeText('Authorized technical review required')->assertDontSee('name="login_email"', false);
        $this->assertSame($before, $this->state());
        $this->post(route('logout'));
        $this->post(route('login'), ['email' => $existing->email, 'password' => 'password'])->assertRedirect('/student/profile');
        $this->get(route('student.profile'))->assertOk()->assertSeeText($linked->student_number);
        $this->get(route('student-accounts.index'))->assertForbidden();
        $this->get(route('financial.index'))->assertForbidden();
    }

    public function test_password_confirmation_returns_to_a_safe_get_and_does_not_replay_the_write(): void
    {
        $actor = $this->actor();
        $target = $this->actor('student');
        $student = $this->student(['user_id' => $target->id]);
        $before = $this->state();
        $this->actingAs($actor)->get(route('student-accounts.show', $student))->assertOk()->assertSeeText('Confirm password to continue');
        $this->patch(route('student-accounts.deactivate', $student), $this->payload($target))->assertRedirect(route('password.confirm'))
            ->assertSessionHas('url.intended', route('student-accounts.show', $student));
        $this->assertSame($before, $this->state());
        $this->post(route('password.confirm'), ['password' => 'password'])->assertRedirect(route('student-accounts.show', $student));
        $this->get(route('student-accounts.show', $student))->assertOk()->assertSee('name="login_email"', false);
        $this->assertNull($target->fresh()->student_access_disabled_at);
        $this->travel(901)->seconds();
        $this->patchJson(route('student-accounts.deactivate', $student), $this->payload($target))->assertStatus(423);
        $this->assertNull($target->fresh()->student_access_disabled_at);
    }

    public function test_get_confirmation_entry_uses_existing_password_confirmation(): void
    {
        $student = $this->student();
        $this->actingAs($this->actor())->get(route('student-accounts.confirm', $student))->assertRedirect(route('password.confirm'));
        $this->post(route('password.confirm'), ['password' => 'password'])->assertRedirect(route('student-accounts.confirm', $student));
        $this->get(route('student-accounts.confirm', $student))->assertRedirect(route('student-accounts.show', $student));
    }

    public static function invalidInputs(): array
    {
        return [['login_email', ''], ['login_email', 'invalid-address'], ['reason', ''], ['identity_confirmed', '0'],
            ['user_id', 9999], ['roles', ['administrator']], ['mode', 'create'], ['source', 'console'], ['password', 'SENSITIVE-PAYLOAD'], ['token', 'SENSITIVE-PAYLOAD']];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_and_privilege_payloads_do_not_change_accounts_or_flash_secrets(string $field, mixed $value): void
    {
        $actor = $this->actor();
        $target = $this->actor('student');
        $student = $this->student(['user_id' => $target->id]);
        $before = $this->state();
        $response = $this->confirmed($actor)->patch(route('student-accounts.deactivate', $student), array_replace($this->payload($target), [$field => $value]));
        $response->assertRedirect(route('student-accounts.show', $student))->assertSessionHasErrors($field);
        $this->assertSame($before, $this->state());
        $this->assertSame([], array_diff(array_keys(session('_old_input', [])), ['login_email', 'reason']));
        $this->get(route('student-accounts.show', $student))->assertDontSeeText('SENSITIVE-PAYLOAD');
    }

    public function test_mismatched_unlinked_and_protected_account_requests_are_rejected_without_changes(): void
    {
        $actor = $this->actor();
        $owner = $this->actor('student');
        $other = User::factory()->create();
        $owned = $this->student(['user_id' => $owner->id]);
        $unlinked = $this->student();
        $protected = $this->student(['user_id' => $actor->id]);
        $before = $this->state();
        $this->confirmed($actor)->patch(route('student-accounts.deactivate', $owned), $this->payload($other))->assertSessionHasErrors('account');
        $this->patch(route('student-accounts.deactivate', $unlinked), $this->payload($owner))->assertSessionHasErrors('account');
        $this->patch(route('student-accounts.deactivate', $protected), $this->payload($actor))->assertSessionHasErrors('account');
        $this->assertSame($before, $this->state());
    }

    public function test_matching_contact_emails_never_assign_an_account_from_the_management_page(): void
    {
        $actor = $this->actor();
        $target = User::factory()->create();
        $student = $this->student(['email' => $target->email]);
        $this->student(['email' => $target->email]);
        $before = $this->state();
        $this->confirmed($actor)->get(route('student-accounts.show', $student))->assertOk()->assertSeeText('No login account')->assertSee('name="login_email"', false);
        $this->post(route('student-accounts.create', $student), $this->payload($target))->assertStatus(303)->assertSessionHasErrors('account');
        $this->post('/student-accounts/'.$student->id.'/provision', $this->payload($target))->assertNotFound();
        $this->post('/student-accounts/'.$student->id.'/provision', array_replace($this->payload($target), ['login_email' => 'missing@example.test']))->assertNotFound();
        $this->assertSame($before, $this->state());
    }

    public function test_unexpected_audit_failure_is_generic_and_rolls_back(): void
    {
        $actor = $this->actor();
        $target = $this->actor('student');
        $student = $this->student(['user_id' => $target->id]);
        $before = $this->state();
        AuditEvent::creating(fn () => throw new RuntimeException('SENSITIVE-INTERNAL-ERROR'));
        try {
            $this->confirmed($actor)->patch(route('student-accounts.deactivate', $student), $this->payload($target))->assertSessionHasErrors('account');
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertSame($before, $this->state());
        $this->get(route('student-accounts.show', $student))->assertDontSeeText('SENSITIVE-INTERNAL-ERROR')->assertSeeText('Account access could not be confirmed.');
    }

    public function test_postcondition_failure_is_never_reported_as_success(): void
    {
        $actor = $this->actor();
        $target = $this->actor('student');
        $student = $this->student(['user_id' => $target->id]);
        $before = $this->state();
        AuditEvent::created(fn () => DB::table('user_roles')->where('user_id', $target->id)->update(['role' => 'Student']));
        try {
            $this->confirmed($actor)->patch(route('student-accounts.deactivate', $student), $this->payload($target))->assertSessionHasErrors('account')->assertSessionMissing('status');
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertSame($before, $this->state());
    }

    public function test_delivery_controls_are_removed_and_unlinked_records_cannot_request_credentials(): void
    {
        Notification::fake();
        $student = $this->student();
        $actor = $this->actor();
        $before = $this->state();
        $this->confirmed($actor)->get(route('student-accounts.show', $student))->assertOk()->assertSeeText('Create Student login account')
            ->assertDontSeeText('Send password setup link')->assertDontSeeText('Send password reset link');
        foreach (['setup-link', 'password-reset'] as $path) {
            $this->post('/student-accounts/'.$student->id.'/'.$path, ['login_email' => 'unlinked@example.test', 'reason' => 'Identity reviewed', 'identity_confirmed' => '1'])->assertSessionHasErrors('account');
        }
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
        $this->assertSame($before, $this->state());
        Notification::assertNothingSent();
    }

    public function test_repeated_http_requests_are_rate_limited_without_duplicate_transitions(): void
    {
        $actor = $this->actor();
        $target = $this->actor('student');
        $student = $this->student(['user_id' => $target->id]);
        $this->confirmed($actor);
        foreach (range(1, 3) as $attempt) {
            $this->patch(route('student-accounts.deactivate', $student), $this->payload($target))->assertRedirect();
        }
        $before = $this->state();
        $this->patchJson(route('student-accounts.deactivate', $student), $this->payload($target))->assertTooManyRequests();
        $this->assertSame($before, $this->state());
        $this->assertSame(1, AuditEvent::count());
    }
}
