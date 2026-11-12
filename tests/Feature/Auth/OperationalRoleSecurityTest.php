<?php

namespace Tests\Feature\Auth;

use App\Models\AcademicProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsScholarshipRecords;
use Tests\TestCase;

class OperationalRoleSecurityTest extends TestCase
{
    use BuildsScholarshipRecords, RefreshDatabase;

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->create(['role' => $role]);

        return $user;
    }

    public static function operationalRoles(): array
    {
        return [['coordinator', 'School Scholarship Coordinator'], ['registrar', 'Registrar Staff']];
    }

    #[DataProvider('operationalRoles')]
    public function test_both_roles_can_login_follow_an_authorized_intended_url_and_logout(string $role, string $label): void
    {
        $user = $this->staff($role);
        $this->get(route('scholars.index'))->assertRedirect(route('login'));
        $oldSession = session()->getId();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasNoErrors()->assertRedirect(route('scholars.index'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSession, session()->getId());
        foreach (['scholars.index', 'scholarship-programs.index', 'academic-verifications.index', 'evaluations.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $response = $this->get(route('dashboard'))->assertOk()->assertSeeText($label);
        if ($role === 'registrar') {
            $response->assertDontSee('href="'.route('financial.index').'"', false)
                ->assertDontSee('href="'.route('billing-batches.index').'"', false);
        } else {
            $response->assertSee('href="'.route('financial.index').'"', false)
                ->assertSee('href="'.route('billing-batches.index').'"', false);
        }
        $this->withSession(['private_marker' => 'remove on logout'])->post(route('logout'))->assertRedirect('/')->assertSessionMissing('private_marker');
        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_registrar_cannot_mutate_approvals_configuration_assignments_or_any_financial_endpoint(): void
    {
        $g = $this->financialContext();
        $course = AcademicProgram::create(['name' => 'Synthetic course']);
        $document = $g['receipt']->documents()->create([
            'disk' => 'scholarship', 'storage_path' => 'synthetic/denied.pdf', 'original_name' => 'denied.pdf',
            'document_type' => 'receipt_evidence', 'mime_type' => 'application/pdf', 'size_bytes' => 10,
        ]);
        $this->actingAs($this->staff('registrar'));
        $tables = ['scholarship_programs', 'academic_periods', 'academic_programs', 'scholarship_assignments', 'scholarship_applications', 'evaluation_runs', 'external_approvals', 'billing_batches', 'billing_items', 'funding_receipts', 'funding_allocations', 'payouts', 'deductions', 'liquidations', 'documents', 'audit_events', 'user_roles'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $routes = [
            ['POST', 'scholarship-programs.store', []], ['PUT', 'scholarship-programs.update', [$g['program']]],
            ['POST', 'academic-periods.store', []], ['PUT', 'academic-periods.update', [$g['period']]],
            ['POST', 'academic-periods.context', []], ['POST', 'academic-programs.store', []], ['PUT', 'academic-programs.update', [$course]],
            ['POST', 'scholars.assignments.store', [$g['student']]], ['PATCH', 'scholars.assignments.update', [$g['student'], $g['assignment']]],
            ['POST', 'evaluations.store', []], ['POST', 'evaluations.policy', [$g['application']]], ['POST', 'evaluations.run', [$g['application']]],
            ['POST', 'evaluations.evidence', [$g['application']]], ['PATCH', 'evaluations.evidence.review', [$g['application'], $document]],
            ['POST', 'evaluations.external', [$g['application']]], ['POST', 'evaluations.activate', [$g['application']]],
            ['POST', 'billing-batches.terms', []], ['POST', 'billing-batches.store', []], ['PATCH', 'billing-batches.update', [$g['batch']]],
            ['POST', 'billing-batches.items.store', [$g['batch']]], ['DELETE', 'billing-batches.items.destroy', [$g['batch'], $g['item']]],
            ['POST', 'billing-batches.submit', [$g['batch']]], ['POST', 'billing-batches.approve', [$g['batch']]],
            ['POST', 'billing-batches.budget', []], ['POST', 'billing-batches.items.adjust', [$g['batch'], $g['item']]],
            ['POST', 'financial.receipts.store', []], ['POST', 'financial.receipts.post', [$g['receipt']]], ['POST', 'financial.allocate', [$g['batch']]],
            ['POST', 'financial.payouts.store', []], ['POST', 'financial.payouts.post', [$g['payout']]], ['POST', 'financial.deduct', [$g['payout']]],
            ['POST', 'financial.settle', [$g['deduction']]], ['POST', 'financial.liquidations.store', [$g['batch']]],
            ['POST', 'financial.liquidations.refresh', [$g['liquidation']]], ['POST', 'financial.liquidations.submit', [$g['liquidation']]],
            ['POST', 'financial.liquidations.review', [$g['liquidation']]], ['POST', 'financial.evidence.store', ['receipt', $g['receipt']]],
            ['POST', 'financial.evidence.review', ['receipt', $g['receipt'], $document]],
        ];
        foreach ($routes as [$method, $route, $parameters]) {
            $this->call($method, route($route, $parameters), ['role' => 'coordinator', 'roles' => ['coordinator']])->assertForbidden();
        }
        foreach (['financial.index', 'billing-batches.index', 'billing-batches.report'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->get(route('financial.evidence.download', ['receipt', $g['receipt'], $document]))->assertForbidden();
        foreach ($before as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->orderBy('id')->get()->toJson(), $table.' must be unchanged');
        }
    }

    public static function unexpectedRoles(): array
    {
        // Known legacy roles are explicitly retained pending the account review.
        return [['admin'], ['student'], ['applicant'], ['super_admin'], ['COORDINATOR'], ['unknown'], [null]];
    }

    #[DataProvider('unexpectedRoles')]
    public function test_unrecognized_roles_and_roleless_accounts_have_no_privileged_access(?string $role): void
    {
        $g = $this->applicationContext();
        $user = $role === null ? User::factory()->create() : $this->staff($role);
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertViewHas('canReport', false)->assertDontSeeText('Total scholars');
        foreach (['scholars.index', 'scholarship-programs.index', 'academic-verifications.index', 'evaluations.index', 'billing-batches.index', 'financial.index'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->post(route('evaluations.external', $g['application']), [])->assertForbidden();
        $this->post(route('billing-batches.store'), [])->assertForbidden();
        $this->post(route('financial.receipts.store'), [])->assertForbidden();
    }

    public function test_profile_and_login_payloads_cannot_assign_roles_or_change_accounts(): void
    {
        $user = $this->staff('registrar');
        $other = $this->staff('coordinator');
        $before = $other->fresh()->getAttributes();
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password', 'role' => 'coordinator', 'roles' => ['coordinator']])->assertRedirect('/dashboard');
        $this->patch(route('profile.update'), ['name' => 'Registrar Name', 'email' => $user->email, 'id' => $other->id, 'role' => 'coordinator', 'roles' => ['coordinator'], 'user_id' => $other->id])->assertSessionHasNoErrors();
        $this->assertSame(['registrar'], $user->roles()->pluck('role')->all());
        $this->assertSame($before, $other->fresh()->getAttributes());
        $this->post(route('financial.receipts.store'), [])->assertForbidden();
    }
}
