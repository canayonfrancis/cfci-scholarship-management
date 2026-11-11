<?php

namespace App\Policies;

use App\Models\AcademicRecord;
use App\Models\User;

class AcademicRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['registrar', 'coordinator', 'administrator']);
    }

    public function view(User $user, AcademicRecord $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['registrar']);
    }

    public function update(User $user, AcademicRecord $record): bool
    {
        return $this->create($user) && $record->verification_status === 'pending' && $record->verified_at === null && $record->isLatestRevision();
    }

    public function correct(User $user, AcademicRecord $record): bool
    {
        return $this->create($user) && $record->verification_status !== 'pending' && $record->isLatestRevision();
    }
}
