<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Support\SampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class StaffAccessAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_counts_accounts_separately_from_memberships_without_changing_or_exposing_credentials(): void
    {
        $coordinator = User::factory()->create();
        $coordinator->roles()->createMany([['role' => 'coordinator'], ['role' => 'registrar']]);
        $legacy = User::factory()->create();
        $legacy->roles()->create(['role' => 'administrator']);
        $unexpected = User::factory()->create();
        $unexpected->roles()->create(['role' => 'student']);
        User::factory()->create();
        $users = DB::table('users')->orderBy('id')->get()->toJson();
        $memberships = DB::table('user_roles')->orderBy('id')->get()->toJson();

        $this->assertSame(0, Artisan::call('sms:audit-access'));
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(4, $report['total_accounts']);
        $this->assertNull($report['active_accounts']);
        $this->assertSame(1, $report['accounts_by_role']['coordinator']);
        $this->assertSame(1, $report['accounts_by_role']['registrar']);
        $this->assertSame([], $report['legacy_roles_pending_review']);
        $this->assertSame(['administrator' => 1], $report['approved_technical_roles']);
        $this->assertSame(['student' => 1], $report['unexpected_roles']);
        $this->assertSame(1, $report['accounts_without_roles']);
        $this->assertSame(1, $report['accounts_with_multiple_roles']);
        $this->assertFalse($report['exactly_one_coordinator_and_one_registrar']);
        $this->assertStringNotContainsString($coordinator->password, $output);
        $this->assertStringNotContainsString($coordinator->remember_token, $output);
        $this->assertSame($users, DB::table('users')->orderBy('id')->get()->toJson());
        $this->assertSame($memberships, DB::table('user_roles')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_exactly_one_each_requires_two_distinct_accounts_and_no_other_memberships(): void
    {
        foreach (User::OPERATIONAL_ROLES as $role) {
            User::factory()->create()->roles()->create(['role' => $role]);
        }
        Artisan::call('sms:audit-access');
        $this->assertTrue(json_decode(Artisan::output(), true)['exactly_one_coordinator_and_one_registrar']);
    }

    public static function rejectedRoles(): array
    {
        return array_map(fn ($role) => [$role], ['administrator', 'admin', 'finance', 'viewer', 'student', 'applicant', 'super_admin']);
    }

    #[DataProvider('rejectedRoles')]
    public function test_console_provisioning_rejects_nonoperational_roles_without_mutation(string $role): void
    {
        $user = User::factory()->create();
        $before = $user->fresh()->getAttributes();
        $this->artisan('sms:grant-role', ['email' => $user->email, 'role' => $role])->assertFailed();
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertDatabaseCount('user_roles', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public static function operationalRoles(): array
    {
        return [['coordinator'], ['registrar']];
    }

    #[DataProvider('operationalRoles')]
    public function test_valid_console_grants_are_idempotent_audited_and_preserve_credentials(string $role): void
    {
        $user = User::factory()->create();
        $before = $user->fresh()->getAttributes();
        foreach (range(1, 2) as $attempt) {
            $this->artisan('sms:grant-role', ['email' => $user->email, 'role' => $role])->assertSuccessful();
        }
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame([$role], $user->roles()->pluck('role')->all());
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_new_development_accounts_use_only_the_confirmed_roles_and_existing_legacy_accounts_are_preserved(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(['coordinator'], User::sole()->roles()->pluck('role')->all());
        $legacy = User::factory()->create();
        $legacy->roles()->create(['role' => 'administrator']);
        $before = $legacy->fresh()->getAttributes();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($before, $legacy->fresh()->getAttributes());
        $this->assertSame(['administrator'], $legacy->roles()->pluck('role')->all());
        $this->assertDatabaseCount('users', 2);
    }

    #[DataProvider('rejectedRoles')]
    public function test_sample_account_provisioning_rejects_nonoperational_roles(string $role): void
    {
        $this->expectException(RuntimeException::class);
        SampleData::staff($role);
    }
}
