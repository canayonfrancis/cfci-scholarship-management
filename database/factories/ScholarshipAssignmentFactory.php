<?php

namespace Database\Factories;

use App\Models\AcademicPeriod;
use App\Models\ScholarshipAssignment;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use Database\Seeders\Support\SampleData;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScholarshipAssignment> */
class ScholarshipAssignmentFactory extends Factory
{
    protected $model = ScholarshipAssignment::class;

    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'program_id' => fn () => ScholarshipProgram::firstOrCreate(['code' => 'TES'], ['name' => 'TES'])->id,
            'start_period_id' => fn () => AcademicPeriod::firstOrCreate(['academic_year' => SampleData::academicYear(), 'semester_code' => '1'])->id,
            'status' => 'pending',
            'status_reason' => 'SAMPLE: pending assignment; not an active award or financial authorization.',
        ];
    }
}
