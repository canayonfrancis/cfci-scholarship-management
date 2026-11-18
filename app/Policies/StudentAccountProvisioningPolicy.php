<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserRole;

class StudentAccountProvisioningPolicy
{
    public function provision(User $actor): bool
    {
        return $actor->hasAnyRole(['registrar', 'administrator']);
    }

    public function linkExisting(User $actor, User $target): bool
    {
        $target->loadMissing('roles');

        // This application entry point cannot manage staff or unknown memberships.
        // Intentional console multi-role linkage retains its existing separate boundary.
        return $this->provision($actor) && $target->roles->every(
            fn (UserRole $membership) => strtolower(trim($membership->role)) === User::STUDENT_ROLE
        );
    }
}
