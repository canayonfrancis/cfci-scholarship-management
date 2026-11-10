<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEvent extends Model
{
    protected $fillable = [
        'actor_id',
        'actor_snapshot',
        'action',
        'subject_type',
        'subject_id',
        'occurred_at',
        'effective_at',
        'reason',
        'before_state',
        'after_state',
        'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'actor_snapshot' => 'array',
            'subject_id' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'effective_at' => 'immutable_datetime',
            'before_state' => 'array',
            'after_state' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
