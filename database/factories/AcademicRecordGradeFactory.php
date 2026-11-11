<?php

namespace Database\Factories;

use App\Models\AcademicRecord;
use App\Models\AcademicRecordGrade;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AcademicRecordGrade> */
class AcademicRecordGradeFactory extends Factory
{
    protected $model = AcademicRecordGrade::class;

    public function definition(): array
    {
        return [
            'academic_record_id' => AcademicRecord::factory(),
            'subject_code' => null,
            'subject_name' => 'Sample subject', 'units' => '3.00',
            'prelim' => (string) fake()->numberBetween(78, 96),
            'midterm' => (string) fake()->numberBetween(78, 96),
            'semi_final' => (string) fake()->numberBetween(78, 96),
            'final' => (string) fake()->numberBetween(78, 96),
            'average' => fake()->randomElement(['85.5000', '89.2500', '92.7500', '94.0000']),
            'equivalent' => fake()->randomElement(['1.10', '1.50', '1.70', '2.20']),
            'remarks' => 'SAMPLE: independently reported example values; no conversion formula or eligibility interpretation is implied.',
        ];
    }
}
