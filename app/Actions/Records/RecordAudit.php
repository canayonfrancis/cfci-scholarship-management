<?php

namespace App\Actions\Records;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RecordAudit
{
    public function record(User $actor, Model $subject, string $action, array $before = [], ?string $reason = null): void
    {
        AuditEvent::create([
            'actor_id' => $actor->id,
            'actor_snapshot' => ['name' => $actor->name, 'email' => $actor->email],
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'occurred_at' => now(),
            'reason' => $reason,
            'before_state' => $before,
            'after_state' => $subject->only($subject->getFillable()),
        ]);
    }
}
