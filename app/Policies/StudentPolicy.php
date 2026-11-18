<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;
use App\Services\Accounts\StudentAccess;

class StudentPolicy extends RecordPolicy
{
    protected const WRITERS = ['administrator', 'registrar', 'coordinator'];

    public function viewSelf(User $user): bool
    {
        return $user->hasAnyRole([User::STUDENT_ROLE]) && app(StudentAccess::class)->enabled($user);
    }

    public function viewOwnProfile(User $user, Student $student): bool
    {
        return $this->viewSelf($user) && $student->user_id !== null && (int) $student->user_id === (int) $user->id;
    }

    public function viewProtectedInformation(User $user, Student $student): bool
    {
        return $user->hasAnyRole(static::WRITERS) || $this->viewOwnProfile($user, $student);
    }

    public function updateOwnProfile(User $user, Student $student): bool
    {
        return $this->viewOwnProfile($user, $student)
            && app(StudentAccess::class)->validAssociation($user, $student);
    }
}
