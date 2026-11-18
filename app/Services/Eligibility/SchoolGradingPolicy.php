<?php

namespace App\Services\Eligibility;

class SchoolGradingPolicy
{
    public const SCOPE = 'cfci-percentage-validation-v2';

    public function check(?array $gradeSource, ?array $requirements = null, ?array $enrollment = null): array
    {
        $summary = app(AcademicGradeComputation::class)->summarize($gradeSource['subjects'] ?? [],
            ($gradeSource['verification_status'] ?? null) === 'verified' && ! empty($gradeSource['verified_by']) && ! empty($gradeSource['verified_at']),
            $gradeSource['certified_percentage_gwa'] ?? null, $gradeSource['certified_gwa_reference'] ?? null);
        $review = [];
        $fail = [];
        if (! $summary['verified']) {
            $review[] = 'The latest source-semester revision must be Registrar-verified; an older revision is not substituted.';
        }
        if (! $requirements || ($requirements['status'] ?? null) !== 'confirmed' || empty($requirements['confirmed_by']) || empty($requirements['confirmed_at'])
            || trim((string) ($requirements['source_reference'] ?? '')) === '' || trim((string) ($requirements['version_reference'] ?? '')) === '') {
            $review[] = 'Program-specific academic requirements have not yet been confirmed for this evaluation semester.';
        }
        $criteria = $requirements['criteria'] ?? [];
        foreach (['minimum_gwa_required', 'require_verified_standing', 'require_current_enrollment', 'require_completed_grades'] as $flag) {
            if (! array_key_exists($flag, $criteria) || ! is_bool($criteria[$flag])) {
                $review[] = 'Academic requirement selections remain unconfigured.';
                break;
            }
        }
        if ($summary['counts']['subjects'] === 0) {
            $review[] = 'The configured source semester has no subject grades.';
        }
        foreach (['incomplete' => 'INC subjects', 'dropped' => 'dropped subjects', 'missing' => 'missing component grades', 'reconciliation' => 'grade discrepancies', 'unverified' => 'unverified subjects'] as $key => $label) {
            if ($summary['counts'][$key] > 0) {
                $review[] = 'Academic review is required for '.$label.'.';
            }
        }
        if (count($summary['excluded']) > 0) {
            $review[] = 'The provisional GWA excludes subjects with unresolved grades or nonpositive/missing units; review the exclusion reasons.';
        }
        if ($summary['counts']['failed'] > 0) {
            $limit = $criteria['max_failed_subjects'] ?? null;
            $approved = ($requirements['status'] ?? null) === 'confirmed' && ($criteria['failed_limit_approved'] ?? false) === true;
            $limitDescription = $limit === null ? 'No maximum failed-subject count is configured.'
                : ($approved ? 'Recorded approved maximum: '.$limit.'; this is a manual-review reference only.'
                    : 'Recorded unconfirmed maximum: '.$limit.'; this is not an approved requirement and remains for manual review.');
            $review[] = 'Failed subjects require an academic decision; no automatic disqualification is applied. '.$limitDescription;
        }
        if (($gradeSource['academic_standing'] ?? 'unverified') === 'failing') {
            $review[] = 'Registrar-certified failing academic standing requires review; it has not been overwritten by calculated grades.';
        } elseif (($criteria['require_verified_standing'] ?? null) === true && ($gradeSource['academic_standing'] ?? 'unverified') === 'unverified') {
            $review[] = 'The approved program requirement needs verified Registrar academic standing.';
        }
        if (($criteria['require_current_enrollment'] ?? null) === true && (($enrollment['verification_status'] ?? null) !== 'verified' || ($enrollment['enrollment_status'] ?? null) !== 'enrolled')) {
            $review[] = 'Current enrollment must be verified for the active validation period.';
        }
        if (($criteria['incomplete_handling'] ?? null) !== 'review' || ($criteria['dropped_handling'] ?? null) !== 'review') {
            $review[] = 'Incomplete/dropped-subject review handling is unconfirmed.';
        }
        if (($criteria['minimum_gwa_required'] ?? null) === true) {
            $minimum = AcademicGradeComputation::percentage($criteria['minimum_gwa'] ?? null);
            $certified = AcademicGradeComputation::percentage($summary['certified_gwa']);
            if ($minimum === null || $certified === null) {
                $review[] = 'A confirmed percentage minimum and Registrar-certified percentage GWA are required. Provisional GWA cannot decide compliance.';
            } elseif ($certified->isLessThan($minimum)) {
                $fail[] = 'Registrar-certified percentage GWA is below this program-period approved minimum.';
            }
        }
        $status = $review !== [] ? 'review' : ($fail !== [] ? 'fail' : 'pass');

        return ['status' => $status, 'reason' => implode(' ', array_unique([...$review, ...$fail])) ?: 'The confirmed program academic requirements are met using Registrar-verified evidence. Provisional GWA was not used as an official decision basis.', 'summary' => $summary];
    }
}
