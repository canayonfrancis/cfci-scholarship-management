<?php

namespace App\Services\Eligibility;

use App\Models\AcademicRecord;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class AcademicGradeComputation
{
    public const VERSION = 'cfci-percentage-four-period-75-v1';

    public const COMPONENTS = ['prelim', 'midterm', 'semi_final', 'final'];

    public static function number(mixed $value): ?BigDecimal
    {
        if (! is_scalar($value) || is_bool($value) || ! preg_match('/\A\+?\d+(?:\.\d+)?\z/D', trim((string) $value)) || strlen(trim((string) $value)) > 30) {
            return null;
        }

        return BigDecimal::of(trim((string) $value));
    }

    public static function percentage(mixed $value): ?BigDecimal
    {
        $number = self::number($value);

        return $number !== null && $number->isGreaterThanOrEqualTo(0) && $number->isLessThanOrEqualTo(100) ? $number : null;
    }

    public function forRecord(AcademicRecord $record): array
    {
        return $this->summarize($record->grades->map(fn ($grade) => $grade->only($grade->getFillable()))->all(),
            $record->verification_status === 'verified' && $record->verified_by !== null && $record->verified_at !== null,
            $record->certified_percentage_gwa, $record->certified_gwa_reference);
    }

    public function summarize(array $subjects, bool $verified = false, mixed $certifiedGwa = null, ?string $certifiedReference = null): array
    {
        $rows = [];
        $counts = array_fill_keys(['subjects', 'passed', 'failed', 'incomplete', 'dropped', 'missing', 'reconciliation', 'unverified', 'included'], 0);
        $totalUnits = BigDecimal::zero();
        $includedUnits = BigDecimal::zero();
        $weighted = BigDecimal::zero();
        foreach ($subjects as $subject) {
            $row = $this->subject($subject, $verified);
            $counts['subjects']++;
            foreach (['passed', 'failed'] as $result) {
                $counts[$result] += $row['numeric_result'] === $result ? 1 : 0;
            }
            $counts['incomplete'] += in_array('inc', $row['special_marks'], true) ? 1 : 0;
            $counts['dropped'] += in_array('dropped', $row['special_marks'], true) ? 1 : 0;
            $counts['missing'] += $row['missing'] ? 1 : 0;
            $counts['reconciliation'] += $row['reconciliation'] ? 1 : 0;
            $counts['unverified'] += ! $verified || ($subject['reported_status'] ?? null) === 'unverified' ? 1 : 0;
            $units = self::number($subject['units'] ?? null);
            if ($units !== null) {
                $totalUnits = $totalUnits->plus($units);
            }
            if ($row['included']) {
                $counts['included']++;
                $includedUnits = $includedUnits->plus($units);
                $weighted = $weighted->plus(BigDecimal::of($row['computed_average'])->multipliedBy($units));
            }
            $rows[] = $row;
        }
        $gwa = $includedUnits->isGreaterThan(0) ? $weighted->dividedBy($includedUnits, 18, RoundingMode::HalfUp) : null;
        $certified = $verified && trim((string) $certifiedReference) !== '' ? self::percentage($certifiedGwa) : null;

        return [
            'version' => self::VERSION, 'rows' => $rows, 'counts' => $counts,
            'total_units' => (string) $totalUnits->stripTrailingZeros(), 'included_units' => (string) $includedUnits->stripTrailingZeros(),
            'weighted_numerator' => (string) $weighted->stripTrailingZeros(),
            'provisional_gwa' => $gwa ? (string) $gwa->stripTrailingZeros() : null,
            'gwa_display' => $gwa ? (string) $weighted->dividedBy($includedUnits, 2, RoundingMode::HalfUp) : null,
            'gwa_status' => 'provisional', 'verified' => $verified,
            'certified_gwa' => $certified ? (string) $certified->stripTrailingZeros() : null,
            'reported_gwa' => is_scalar($certifiedGwa) && trim((string) $certifiedGwa) !== '' ? (string) $certifiedGwa : null,
            'certified_display' => $certified ? (string) $certified->toScale(2, RoundingMode::HalfUp) : null,
            'certified_reference' => $certified ? $certifiedReference : null,
            'excluded' => array_values(array_filter($rows, fn ($row) => ! $row['included'])),
            'warnings' => array_values(array_filter([
                'Subject averages use the confirmed four-period percentage formula and a minimum passing grade of 75%. Results use unrounded values; Equivalent is never used.',
                'Unit-weighted GWA is provisional. Official GWA inclusion and rounding rules remain subject to school confirmation; it cannot authorize a final eligibility decision.',
                ! $verified ? 'This revision is not Registrar-verified. Numeric calculations are preview information, not verified academic evidence.' : null,
                $counts['incomplete'] || $counts['dropped'] ? 'Academic review is required because incomplete or dropped subjects exist.' : null,
                $counts['missing'] ? 'Academic review is required because grading components are missing.' : null,
                $counts['reconciliation'] ? 'Registrar reconciliation is required for reported/computed discrepancies, conflicting marks or unsupported historical values.' : null,
                $counts['failed'] ? 'Failed subjects are identified. They do not automatically disqualify the scholar.' : null,
                $counts['subjects'] === 0 ? 'No subject grades are recorded on this revision.' : null,
            ])),
        ];
    }

    private function subject(array $subject, bool $verified): array
    {
        $reasons = [];
        $marks = [];
        $reportedStatus = $subject['reported_status'] ?? 'regular';
        if (in_array($reportedStatus, ['inc', 'dropped'], true)) {
            $marks[] = $reportedStatus;
        }
        foreach ([...self::COMPONENTS, 'average'] as $field) {
            $value = strtoupper(trim((string) ($subject[$field] ?? '')));
            if (in_array($value, ['INC', 'INCOMPLETE'], true)) {
                $marks[] = 'inc';
            } elseif (in_array($value, ['DRP', 'DROPPED'], true)) {
                $marks[] = 'dropped';
            }
        }
        if (preg_match('/\A\s*(INC|INCOMPLETE|DRP|DROPPED)\b/i', (string) ($subject['remarks'] ?? ''), $match)) {
            $marks[] = in_array(strtoupper($match[1]), ['INC', 'INCOMPLETE'], true) ? 'inc' : 'dropped';
        }
        $marks = array_values(array_unique($marks));
        $special = $marks[0] ?? null;
        $reconciliation = count($marks) > 1 || ! in_array($reportedStatus, ['regular', 'inc', 'dropped', 'unverified'], true);
        $missing = false;
        $components = [];
        foreach (self::COMPONENTS as $field) {
            $raw = $subject[$field] ?? null;
            $number = self::percentage($raw);
            if ($number === null) {
                if ($raw === null || trim((string) $raw) === '') {
                    $missing = true;
                } elseif ($special === null) {
                    $reconciliation = true;
                }
            } else {
                $components[] = $number;
            }
        }
        $average = null;
        if ($special === null && count($components) === 4) {
            $average = array_reduce($components, fn ($sum, $value) => $sum->plus($value), BigDecimal::zero())->dividedBy(4, 32, RoundingMode::Unnecessary)->stripTrailingZeros();
        }
        $reported = $subject['average'] ?? null;
        if ($reported !== null && trim((string) $reported) !== '' && $special === null) {
            $number = self::percentage($reported);
            if ($number === null || ($average !== null && ! $number->isEqualTo($average))) {
                $reconciliation = true;
                $reasons[] = 'Reported and computed averages differ, or the reported average is not a valid percentage. Registrar review is required.';
            }
        }
        $numericResult = $average !== null ? ($average->isGreaterThanOrEqualTo(75) ? 'passed' : 'failed') : null;
        if ($special !== null) {
            $reasons[] = $special === 'inc' ? 'INC / Incomplete requires academic review; it is not zero.' : 'Dropped requires academic review; it is not a failed grade or zero.';
            $missing = false;
        } elseif ($missing) {
            $reasons[] = 'All four numeric component grades are required; missing values are not zero.';
        }
        if ($reconciliation && $reasons === []) {
            $reasons[] = 'Conflicting marks or unsupported percentage data require Registrar reconciliation.';
        }
        $units = self::number($subject['units'] ?? null);
        if ($units === null || ! $units->isGreaterThan(0)) {
            $reasons[] = $units?->isEqualTo(0) ? 'Zero recorded units: excluded from the provisional weighted calculation.' : 'Missing or invalid units: excluded from the provisional weighted calculation.';
        }
        if ($reportedStatus === 'unverified') {
            $reasons[] = 'This subject is explicitly unverified.';
        }
        $included = $average !== null && $special === null && ! $reconciliation && $reportedStatus !== 'unverified' && $units?->isGreaterThan(0);
        $result = $reconciliation ? 'requires_reconciliation' : ($special ?? ($missing ? 'missing' : ($reportedStatus === 'unverified' ? 'unverified' : ($numericResult ?? 'requires_reconciliation'))));

        return [
            'subject_code' => $subject['subject_code'] ?? null, 'subject_name' => $subject['subject_name'] ?? '',
            'units' => $subject['units'] ?? null, 'reported_average' => $reported,
            'computed_average' => $average !== null ? (string) $average : null,
            'average_display' => $average !== null ? (string) $average->toScale(2, RoundingMode::HalfUp) : null,
            'numeric_result' => $numericResult, 'result' => $result, 'special' => $special, 'special_marks' => $marks,
            'grade_status' => ! $verified || $reportedStatus === 'unverified' ? 'Unverified' : ($reconciliation ? 'Requires reconciliation' : 'Registrar-verified revision'),
            'missing' => $missing, 'reconciliation' => $reconciliation, 'included' => (bool) $included, 'reasons' => $reasons,
        ];
    }
}
