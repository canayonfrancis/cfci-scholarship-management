<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcademicRecordGrade extends Model
{
    use HasFactory;

    public const COLUMNS = ['units' => 'Units', 'prelim' => 'Prelim', 'midterm' => 'Midterm', 'semi_final' => 'Semi-Final', 'final' => 'Final', 'average' => 'Average', 'equivalent' => 'Equivalent'];

    protected $fillable = ['subject_code', 'subject_name', 'units', 'prelim', 'midterm', 'semi_final', 'final', 'average', 'equivalent', 'remarks'];

    public function academicRecord(): BelongsTo
    {
        return $this->belongsTo(AcademicRecord::class);
    }
}
