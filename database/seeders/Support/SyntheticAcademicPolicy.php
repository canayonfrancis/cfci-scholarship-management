<?php

namespace Database\Seeders\Support;

use App\Services\Eligibility\SchoolGradingPolicy;

/** A test fixture prerequisite, never a CFCI grade scale, conversion or production policy. */
final class SyntheticAcademicPolicy extends SchoolGradingPolicy
{
    public function check(?array $gradeSource): array
    {
        SampleData::guardSynthetic();

        return ['status' => 'pass', 'reason' => 'SYNTHETIC DEMO ONLY: resolved academic prerequisite for financial workflow testing. Not an approved CFCI grading policy.'];
    }
}
