<?php

namespace App\Policies;

use App\Models\ScholarshipApplication;
use App\Models\User;

class ScholarshipApplicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['administrator', 'coordinator', 'registrar']);
    }

    public function view(User $user, ScholarshipApplication $application): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['coordinator', 'administrator']);
    }

    public function evaluate(User $user, ScholarshipApplication $application): bool
    {
        return $this->create($user) && $application->status !== 'withdrawn';
    }
}
