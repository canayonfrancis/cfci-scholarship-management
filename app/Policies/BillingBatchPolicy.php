<?php

namespace App\Policies;

use App\Models\BillingBatch;
use App\Models\User;

class BillingBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['administrator', 'registrar', 'finance', 'coordinator', 'viewer']);
    }

    public function view(User $user, BillingBatch $batch): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['coordinator', 'registrar', 'administrator', 'finance']);
    }

    public function update(User $user, BillingBatch $batch): bool
    {
        return $this->create($user) && $batch->status === 'draft' && ! $batch->submitted_at;
    }

    public function submit(User $user, BillingBatch $batch): bool
    {
        return $this->update($user, $batch);
    }

    public function recordExclusion(User $user, BillingBatch $batch): bool
    {
        return $user->hasAnyRole(['registrar', 'coordinator', 'administrator']) && $this->update($user, $batch);
    }

    public function configure(User $user): bool
    {
        return $user->hasAnyRole(['coordinator', 'administrator']);
    }

    public function approve(User $user, BillingBatch $batch): bool
    {
        return $this->configure($user) && $batch->status === 'submitted' && $batch->submitted_at && ! $batch->approval()->exists()
            && $batch->prepared_by !== $user->id && $batch->submitted_by !== $user->id;
    }

    public function adjust(User $user, BillingBatch $batch): bool
    {
        return $this->configure($user) && ($batch->approval()->exists() || ($batch->status === 'finalized' && $batch->finalized_by && $batch->finalized_at));
    }
}
