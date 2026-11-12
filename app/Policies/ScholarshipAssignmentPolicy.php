<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ScholarshipAssignmentPolicy extends RecordPolicy
{
    protected const WRITERS = ['coordinator', 'administrator'];

    public function update(User $user, Model $record): bool
    {
        return parent::update($user, $record) && in_array($record->status, ['pending', 'active'], true);
    }
}
