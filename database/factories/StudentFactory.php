<?php

namespace Database\Factories;

use App\Models\AcademicPeriod;
use App\Models\AcademicProgram;
use App\Models\Student;
use App\Services\Records\ScholarProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Student> */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    public const FIRST_NAMES = ['Alyssa', 'Miguel', 'Camille', 'Joshua', 'Angelica', 'Paolo', 'Bianca', 'Rafael', 'Danica', 'Gabriel', 'Marielle', 'Carlo', 'Jessa', 'Nico', 'Patricia', 'Adrian', 'Trisha', 'Mark Angelo', 'Kristine', 'John Carlo'];

    public const LAST_NAMES = ['Dela Cruz', 'Reyes', 'Santos', 'Garcia', 'Mendoza', 'Ramos', 'Bautista', 'Aquino', 'Navarro', 'Villanueva', 'Castillo', 'Torres', 'Flores', 'Mercado', 'Salazar', 'Domingo', 'Valdez', 'Tolentino', 'Francisco', 'Soriano'];

    public const SAMPLE_PROGRAMS = ['[SAMPLE] Bachelor of Science in Information Technology', '[SAMPLE] Bachelor of Science in Business Administration', '[SAMPLE] Bachelor of Elementary Education', '[SAMPLE] Bachelor of Secondary Education', '[SAMPLE] Bachelor of Science in Tourism Management'];

    public function definition(): array
    {
        return [
            'student_number' => 'SAMPLE-'.Str::ulid(),
            'first_name' => fake()->randomElement(self::FIRST_NAMES),
            'middle_name' => fake()->randomElement(self::LAST_NAMES),
            'last_name' => fake()->randomElement(self::LAST_NAMES),
            'email' => 'sample.'.Str::lower(Str::random(16)).'@example.test',
            'phone' => null, // No guessed Philippine number that could belong to a real person.
            'record_status' => 'active',
        ];
    }

    public function sample(int $index, string $academicYear, bool $historical = false): static
    {
        return $this->state(fn () => [
            'student_number' => sprintf('SAMPLE-%s-%s-%03d', $historical ? 'H' : 'C', substr($academicYear, 0, 4), $index + 1),
            'first_name' => self::FIRST_NAMES[$index % count(self::FIRST_NAMES)],
            'middle_name' => self::LAST_NAMES[($index + 7) % count(self::LAST_NAMES)],
            'last_name' => self::LAST_NAMES[($index + intdiv($index, 20) * 3) % count(self::LAST_NAMES)],
            'email' => sprintf('sample.%s.%s.%03d@example.test', $historical ? 'historical' : 'current', substr($academicYear, 0, 4), $index + 1),
            'record_status' => $historical || $index >= 54 ? 'archived' : 'active',
            ...$this->sampleProfile($index, $academicYear, $historical),
        ]);
    }

    private function sampleProfile(int $index, string $year, bool $historical): array
    {
        $incomplete = $historical || $index % 4 === 3;

        return [
            'lrn' => $incomplete ? null : '0', 'philsys_id' => null, 'four_ps_id' => $index % 5 === 0 ? 'SAMPLE-000'.($index + 1) : null,
            'suffix' => $index % 10 === 1 ? 'Jr.' : null, 'sex' => $index % 2,
            'birthdate' => ((int) substr($year, 0, 4) - 18 - $index % 4).'-02-15',
            'academic_program_id' => $historical ? null : fn () => AcademicProgram::firstOrCreate(['name' => self::SAMPLE_PROGRAMS[$index % count(self::SAMPLE_PROGRAMS)]])->id,
            'academic_period_id' => fn () => AcademicPeriod::firstOrCreate(['academic_year' => $year, 'semester_code' => $historical ? '2' : '1'])->id,
            'year_level' => $historical ? null : 1 + $index % 4,
            'father_last_name' => $index % 3 ? self::LAST_NAMES[($index + 4) % 20] : null,
            'father_first_name' => $index % 3 ? 'Roberto' : null, 'father_middle_name' => null,
            'mother_maiden_last_name' => $incomplete ? null : self::LAST_NAMES[($index + 9) % 20],
            'mother_maiden_first_name' => $incomplete ? null : 'Maria Elena', 'mother_maiden_middle_name' => null,
            'street_barangay' => $incomplete ? null : 'SAMPLE '.($index + 1).' Test Street, Barangay Lagao',
            'city_municipality' => 'General Santos City', 'province' => 'South Cotabato', 'region' => 'Region XII (SOCCSKSARGEN)', 'zip_code' => '9500',
            'phone' => $incomplete ? null : '00000000', // Deliberately non-dialable fictional landline placeholder.
            'disability_type' => $index % 4 === 0 ? null : array_keys(ScholarProfile::DISABILITIES)[$index % 11],
            'solo_parent_dependent' => $incomplete ? null : $index % 2 === 1,
            'first_generation_student' => $index % 3 === 0 ? null : $index % 2 === 0,
            'indigenous_people_group' => $index % 7 === 0 ? 'SAMPLE: self-reported group scenario' : null,
        ];
    }
}
