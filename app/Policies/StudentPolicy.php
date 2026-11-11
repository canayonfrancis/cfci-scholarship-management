<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;

class StudentPolicy extends RecordPolicy
{
    protected const WRITERS = ['administrator', 'registrar', 'coordinator'];

    public function viewProtectedInformation(User $user, Student $student): bool
    {
        return $user->hasAnyRole(static::WRITERS);
    }
}
