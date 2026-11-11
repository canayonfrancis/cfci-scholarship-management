<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScholarshipRate extends Model
{
    protected $fillable = ['program_period_term_id', 'revision', 'amount', 'source_reference', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'approved_at' => 'immutable_datetime', 'revision' => 'integer'];
    }

    public function terms(): BelongsTo
    {
        return $this->belongsTo(ProgramPeriodTerm::class, 'program_period_term_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
