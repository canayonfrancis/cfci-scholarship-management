<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

abstract class RecordPolicy
{
    protected const WRITERS = ['administrator'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(User::STAFF_ROLES);
    }

    public function view(User $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(static::WRITERS);
    }

    public function update(User $user, Model $record): bool
    {
        return $user->hasAnyRole(static::WRITERS);
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }
}
