<?php

namespace App\Services\Eligibility;

class SchoolGradingPolicy
{
    public const SCOPE = 'cfci-direct-validation-v1';

    public function check(?array $gradeSource): array
    {
        // Reported column values are known; eligibility interpretation is not yet approved.
        return ['status' => 'review', 'reason' => 'CFCI grade conversion, passing thresholds and complete grading rules remain unconfirmed. Recorded grades are not automatically interpreted.'];
    }
}
