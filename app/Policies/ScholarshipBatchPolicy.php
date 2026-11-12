<?php

namespace App\Policies;

use App\Models\User;

class ScholarshipBatchPolicy extends RecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['coordinator', 'registrar', 'administrator']);
    }
}
