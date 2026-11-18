<?php

namespace App\Services\Records;

use App\Models\Student;

class ScholarProfile
{
    public const SEX = [0 => 'Male', 1 => 'Female'];

    public const DISABILITIES = [
        'none' => 'No Disability',
        'communication' => 'Communication Disability',
        'chronic_illness' => 'Disability due to Chronic Illness',
        'learning' => 'Learning Disability',
        'intellectual' => 'Intellectual Disability',
        'orthopedic' => 'Orthopedic Disability',
        'physical' => 'Physical Disability',
        'hearing' => 'Hearing Disability',
        'visual' => 'Visual Disability',
        'cancer' => 'Cancer',
        'mental_psychosocial' => 'Mental/Psychosocial Disability',
    ];

    public const REQUIRED = [
        'student_number' => 'Student ID', 'lrn' => 'LRN (12 digits or 0)',
        'last_name' => 'Last name', 'first_name' => 'Given name', 'sex' => 'Sex', 'birthdate' => 'Birthdate',
        'academic_program_id' => 'Academic program', 'year_level' => 'Year level', 'academic_period_id' => 'Academic year and semester',
        'mother_maiden_last_name' => 'Mother’s maiden last name', 'mother_maiden_first_name' => 'Mother’s maiden given name',
        'street_barangay' => 'Street and barangay', 'city_municipality' => 'City / municipality', 'province' => 'Province', 'region' => 'Region', 'zip_code' => 'ZIP code',
        'phone' => 'Official contact number', 'email' => 'Email address', 'solo_parent_dependent' => 'Solo-parent dependent indicator',
    ];

    /** Technical entry choices, not a scholarship duration or eligibility rule. */
    public static function yearLevels(): array
    {
        return config('scholar-profile.year_levels');
    }

    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }
        $digits = preg_replace('/[\s().-]+/', '', trim($phone));
        if (preg_match('/^(?:0|\+63|0063)(9[0-9]{9})$/D', $digits, $match)) {
            return $match[1];
        }

        return preg_match('/^(?:9[0-9]{9}|[0-9]{8})$/D', $digits) ? $digits : null;
    }

    /** Import normalization only; legacy saved suffixes are not rewritten. */
    public static function normalizeSuffix(?string $suffix): ?string
    {
        $suffix = trim((string) $suffix);
        if ($suffix === '') {
            return null;
        }

        return match (strtoupper($suffix)) {
            'JR', 'JR.' => 'Jr.',
            'SR', 'SR.' => 'Sr.',
            'II' => 'II', 'III' => 'III', 'IV' => 'IV',
            default => $suffix, // Existing schema permits other legitimate suffixes up to 30 characters.
        };
    }

    public static function missing(Student $student): array
    {
        $missing = [];
        foreach (self::REQUIRED as $field => $label) {
            // false/0 are answered values for official binary fields.
            $value = $student->getAttribute($field);
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $missing[$field] = $label;
            }
        }
        if ($student->lrn !== null && ! preg_match('/^(?:0|[0-9]{12})$/D', $student->lrn)) {
            $missing['lrn'] = self::REQUIRED['lrn'];
        }
        if (self::normalizePhone($student->phone) === null) {
            $missing['phone'] = self::REQUIRED['phone'];
        }
        if (! filter_var($student->email, FILTER_VALIDATE_EMAIL)) {
            $missing['email'] = self::REQUIRED['email'];
        }

        return $missing;
    }
}
