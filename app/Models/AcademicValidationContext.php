<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicValidationContext extends Model
{
    protected $table = 'academic_validation_context';

    public $incrementing = false;

    protected $fillable = ['active_period_id', 'source_period_id', 'source_policy_reference', 'confirmed_by', 'confirmed_at', 'revision'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'confirmed_at' => 'immutable_datetime'];
    }

    public static function current(): self
    {
        return static::findOrFail(1);
    }

    public function snapshot(): array
    {
        return $this->only(['active_period_id', 'source_period_id', 'source_policy_reference', 'confirmed_by', 'confirmed_at', 'revision']);
    }

    public function activePeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'active_period_id');
    }

    public function sourcePeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class, 'source_period_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
