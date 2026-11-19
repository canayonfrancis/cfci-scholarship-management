<?php

namespace App\Support;

use App\Services\Billing\AllocationSummary;
use App\Services\Records\ScholarProfile;
use Illuminate\Support\Str;

/** Search values follow the order and wording of the visible table columns. */
class TableColumns
{
    public static function values(string $table, mixed $row): array
    {
        return match ($table) {
            'student_accounts' => [
                $row->studentNumber, $row->name, $row->contactEmail ?: 'Not recorded',
                $row->loginEmail ?: ($row->status === 'restricted' ? 'Restricted account' : ($row->linked ? 'Unavailable for review' : 'Not linked')),
                $row->statusLabel, $row->profileAllowed ? 'Configured' : 'Not configured',
            ],
            'scholars' => [
                $row->student_number,
                $row->full_name.' '.$row->assignments_count.' scholarship '.Str::plural('record', $row->assignments_count),
                ($row->academicProgram?->name ?? 'Not provided').' '.($row->year_level ? (ScholarProfile::yearLevels()[$row->year_level] ?? 'Year '.$row->year_level) : 'Year level not provided'),
                self::period($row->academicPeriod, 'Not provided', true),
                $row->activeAssignment?->program?->code ?? 'No active scholarship',
                ucfirst($row->record_status), $row->hasCompleteProfile() ? 'Complete' : 'Incomplete',
            ],
            'programs' => [
                $row->code.' '.$row->name, self::money($row->school_stated_semester_amount),
                $row->planning_duration_years ? $row->planning_duration_years.' years' : 'Not recorded',
                $row->is_active ? 'Available' : 'Unavailable for new active assignments',
            ],
            'periods' => [$row->academic_year, $row->semester_code, $row->starts_on?->format('M j, Y') ?? 'Not recorded', $row->ends_on?->format('M j, Y') ?? 'Not recorded', $row->is_open ? 'Open' : 'Closed'],
            'academic_years' => [$row->academic_year, explode('-', $row->academic_year)[0], explode('-', $row->academic_year)[1] ?? '', $row->semester_count, $row->open_count],
            'delisting_reasons' => [$row->name, $row->is_active ? 'Active' : 'Inactive'],
            'program_assignments' => [$row->student->student_number, $row->student->full_name, $row->startPeriod ? $row->startPeriod->academic_year.' · '.$row->startPeriod->semester_code : 'Not recorded', $row->statusLabel()],
            'period_assignments' => [$row->student->student_number, $row->student->full_name, $row->program->code, $row->statusLabel()],
            'rosters' => [$row->batch_number, $row->program->code, self::period($row->academicPeriod), $row->memberships_count],
            'members' => self::member($row),
            'roster_candidates' => [null, $row['student']->student_number, $row['student']->full_name,
                $row['student']->academicProgram?->name ?? 'Not provided',
                $row['student']->year_level ? (ScholarProfile::yearLevels()[$row['student']->year_level] ?? 'Year '.$row['student']->year_level) : 'Not provided',
                $row['selection']['reason']],
            'academic_records' => [
                self::student($row->student), self::period($row->academicPeriod, semesterLabel: true),
                $row->revision.' '.($row->isLatestRevision() ? 'Latest revision' : 'Superseded'),
                Str::headline($row->enrollment_status ?? 'unverified'), ucfirst($row->academic_standing),
                ucfirst($row->verification_status).' '.$row->verifiedBy?->name,
            ],
            'evaluations' => self::evaluation($row),
            'billing_batches' => [
                $row->batch_number,
                $row->program->code, self::period($row->academicPeriod), $row->allocationStatus() === 'submitted' ? 'Awaiting Coordinator approval' : ucfirst($row->allocationStatus()), $row->items_count,
                self::money($row->totalAmount(), 'Unconfirmed total'),
            ],
            'billing_items' => [$row->student->student_number, $row->student->full_name, self::money($row->gross_amount), 'Validation #'.$row->evaluation_run_id.' / Approval #'.$row->external_approval_id, ucfirst($row->status)],
            'candidates' => [$row['application']->student->student_number, $row['application']->student->full_name, $row['reason'] ?? 'Permitted under the confirmed billing policy'],
            'allocations' => [
                self::student($row->student), $row->program->code.' '.self::period($row->academicPeriod),
                $row->batch->batch_number, ucfirst($row->batch->allocationStatus()), self::money($row->gross_amount),
                self::money($row->gross_amount === null ? null : $row->effectiveAmount()),
                ($row->batch->approval?->reference ?? 'No separate approval recorded').' '.$row->batch->approval?->approvedBy?->name,
            ],
            'financial_batches' => [$row->program->code.' · '.self::period($row->academicPeriod), $row->batch_number, ucfirst($row->allocationStatus())],
            'receipts' => [
                ($row->source ?: 'Unconfirmed source').' '.$row->reference,
                $row->program->code.' · '.self::period($row->academicPeriod), $row->received_on?->toDateString() ?? 'Unconfirmed', $row->amount,
                $row->status === 'posted' ? 'Verified actual receipt' : 'Draft — not counted as received',
            ],
            'funding_status' => [
                $row['batch']->batch_number.' '.$row['batch']->program->code.' · '.self::period($row['batch']->academicPeriod),
                ucfirst($row['batch']->allocationStatus()), $row['label'].' '.implode(' ', $row['issues']),
                $row['effective'] ?? 'Unconfirmed', $row['allocated'].' / '.$row['usable_funding'],
                $row['direct_applied'] ?? 'Unconfirmed', $row['outstanding'] ?? 'Unconfirmed', $row['shortfall'] ?? 'Not approved / unconfirmed',
            ],
            'budget_scopes' => self::budget(app(AllocationSummary::class)->forTerms($row)),
            default => throw new \InvalidArgumentException('Unknown table: '.$table),
        };
    }

    private static function student($student): string
    {
        return $student->full_name.' '.$student->student_number;
    }

    private static function period($period, string $fallback = 'Not recorded', bool $semesterLabel = false): string
    {
        return $period ? $period->academic_year.($semesterLabel ? ' Semester ' : ' / ').$period->semester_code : $fallback;
    }

    private static function money($amount, string $fallback = 'Unconfirmed'): string
    {
        return $amount === null ? $fallback : '₱'.number_format((float) $amount, 2);
    }

    private static function evaluation($row): array
    {
        $run = $row->evaluationRuns->first();

        return [self::student($row->student), $row->program->code, self::period($row->academicPeriod), $run ? Str::headline($run->resultLabel()).($run->isCurrent() ? '' : ' Requires reassessment') : 'Not evaluated'];
    }

    private static function member($row): array
    {
        $record = $row->enrollmentRecord;
        $known = $record && $record->verification_status === 'verified' && $record->verified_by && $record->verified_at && in_array($record->enrollment_status, ['enrolled', 'not_enrolled'], true);

        return [
            $row->student->student_number, $row->student->full_name,
            $row->student->academicProgram?->name ?? 'Not provided',
            $row->student->year_level ? (ScholarProfile::yearLevels()[$row->student->year_level] ?? 'Year '.$row->student->year_level) : 'Not provided',
            $row->assignment->statusLabel(),
            $record ? 'Revision '.$record->revision.' '.$record->verifiedBy?->name.' '.$record->verified_at?->timezone('Asia/Manila')->format('M j, Y g:i A') : 'No academic record',
            ($known ? ($record->enrollment_status === 'enrolled' ? 'Verified enrolled' : 'Verified not enrolled') : 'Awaiting Registrar verification').' Form classification pending approved template',
            ($row->submitted_billing_id ? 'Submitted billing item #'.$row->submitted_billing_id : 'Unbilled — no submitted billing').(! $row->submitted_billing_id && $row->reserved_billing_id ? ' Existing semester reservation #'.$row->reserved_billing_id.'; review its draft/history before preparing another bill.' : ''),
        ];
    }

    private static function budget(array $summary): array
    {
        return [
            $summary['terms']->program->code.' '.$summary['terms']->academicPeriod->academic_year.' / Semester '.$summary['terms']->academicPeriod->semester_code,
            self::money($summary['budget']?->amount, 'Unconfigured'),
            self::money($summary['allocated']).' '.$summary['scholar_count'].' scholars; adjustments '.self::money($summary['adjustments']),
            self::money($summary['remaining'], 'Unconfigured'), self::money($summary['pending']), self::money($summary['received']),
            self::money($summary['released_net']).' Direct-to-student: '.self::money($summary['direct_paid_net']).' (excluded from school cash)',
        ];
    }
}
