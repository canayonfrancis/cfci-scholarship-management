<?php

namespace App\Policies;

use App\Models\User;

class FinancialRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['registrar', 'finance', 'administrator', 'coordinator', 'viewer']);
    }

    public function prepare(User $user): bool
    {
        return $user->hasAnyRole(['registrar', 'coordinator', 'administrator', 'finance']);
    }

    public function verify(User $user): bool
    {
        return $user->hasAnyRole(['coordinator', 'administrator']);
    }

    public function evidence(User $user): bool
    {
        return $user->hasAnyRole(['registrar', 'coordinator', 'finance', 'administrator']);
    }
}
