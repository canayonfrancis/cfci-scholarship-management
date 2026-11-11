<?php

namespace Database\Factories;

use App\Models\AcademicPeriod;
use App\Models\AcademicRecord;
use App\Models\Student;
use Database\Seeders\Support\SampleData;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AcademicRecord> */
class AcademicRecordFactory extends Factory
{
    protected $model = AcademicRecord::class;

    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'academic_period_id' => fn () => AcademicPeriod::firstOrCreate(['academic_year' => SampleData::academicYear(), 'semester_code' => '1'])->id,
            'revision' => 1,
            'course' => fake()->randomElement(['BS Information Technology', 'BS Business Administration', 'Bachelor of Elementary Education', 'Bachelor of Secondary Education', 'BS Tourism Management']),
            'year_level' => (string) fake()->numberBetween(1, 4),
            'enrollment_status' => 'enrolled',
            'academic_standing' => 'unverified',
            'verification_status' => 'pending',
            'source_reference' => 'SAMPLE: fictional academic record; not an actual Registrar certification.',
            'verification_notes' => 'Development data. Reported values are examples, not an approved grade conversion.',
        ];
    }
}
