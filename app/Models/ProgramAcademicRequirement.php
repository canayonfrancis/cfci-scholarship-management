<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class ProgramAcademicRequirement extends Model
{
    protected $fillable = ['program_period_term_id', 'revision', 'criteria', 'version_reference', 'source_reference', 'status', 'created_by', 'confirmed_by', 'confirmed_at'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'criteria' => 'array', 'confirmed_at' => 'immutable_datetime'];
    }

    public function terms(): BelongsTo
    {
        return $this->belongsTo(ProgramPeriodTerm::class, 'program_period_term_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function snapshot(): array
    {
        return $this->only($this->getFillable()) + ['id' => $this->id];
    }

    public static function storageReady(): bool
    {
        return Schema::hasTable('program_academic_requirements')
            && Schema::hasColumns('academic_records', ['certified_percentage_gwa', 'certified_gwa_reference'])
            && Schema::hasColumn('academic_record_grades', 'reported_status');
    }
}
