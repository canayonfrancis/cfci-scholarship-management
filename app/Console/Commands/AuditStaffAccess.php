<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditStaffAccess extends Command
{
    protected $signature = 'sms:audit-access';

    protected $description = 'Read account and role counts without credentials or account changes';

    public function handle(): int
    {
        $report = DB::transaction(function () {
            $total = DB::table('users')->count();
            $roles = DB::table('user_roles')->select('role')->selectRaw('COUNT(DISTINCT user_id) AS accounts')
                ->groupBy('role')->orderBy('role')->pluck('accounts', 'role')->map(fn ($count) => (int) $count);
            $counts = array_replace(array_fill_keys(User::OPERATIONAL_ROLES, 0), $roles->all());
            $roleless = User::doesntHave('roles')->count();
            $multiple = User::has('roles', '>', 1)->count();
            $technical = $roles->only(User::TECHNICAL_ROLES)->all();
            $legacy = $roles->except([...User::OPERATIONAL_ROLES, ...User::TECHNICAL_ROLES])->only(User::STAFF_ROLES)->all();
            $unexpected = $roles->except([...User::STAFF_ROLES, User::STUDENT_ROLE])->all();

            return [
                'connection' => DB::getDriverName(),
                'database' => DB::connection()->getDatabaseName(),
                'total_accounts' => $total,
                // users has no enabled/disabled state. Email verification and sessions
                // do not establish whether an account is administratively active.
                'active_accounts' => null,
                'active_account_status' => 'Not modeled: users has no activation or deactivation field',
                'operational_roles' => User::OPERATIONAL_ROLES,
                'accounts_by_role' => $counts,
                'student_accounts' => $roles->get(User::STUDENT_ROLE, 0),
                'student_accounts_without_profile' => User::whereHas('roles', fn ($query) => $query->where('role', User::STUDENT_ROLE))->doesntHave('student')->count(),
                'non_operational_roles' => (object) $roles->except(User::OPERATIONAL_ROLES)->all(),
                'approved_technical_roles' => (object) $technical,
                'legacy_roles_pending_review' => (object) $legacy,
                'unexpected_roles' => (object) $unexpected,
                'accounts_without_roles' => $roleless,
                'accounts_with_multiple_roles' => $multiple,
                'exactly_one_coordinator_and_one_registrar' => $total === 2 && $counts['coordinator'] === 1
                    && $counts['registrar'] === 1 && $roleless === 0 && $multiple === 0 && $legacy === [] && $unexpected === [],
            ];
        });

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
