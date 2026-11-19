<?php

namespace App\Services\Eligibility;

class EligibilityEvaluator
{
    public const SCOPE = 'enrollment_school_compliance_v2';

    public function __construct(private OfficialRuleCatalog $catalog) {}

    /** Pure checks over server-assembled, snapshotted input; no request or database writes. */
    public function evaluate(array $input, bool $policyConfirmed): array
    {
        $checks = [];
        $add = function (string $key, string $status, string $reason) use (&$checks) {
            $checks[$key] = compact('status', 'reason');
        };
        $context = $input['academic_context'] ?? [];
        $add('active_period', ($context['active_period_id'] ?? null) === $input['academic_period_id'] ? 'pass' : 'review', 'Validation must use the configured active academic year and semester.');
        $record = $input['academic'] ?? null;
        $data = $input['facts_input'];
        $verified = $record && ($record['verification_status'] ?? null) === 'verified' && ! empty($record['verified_by']) && ! empty($record['verified_at']);
        $add('enrollment', $verified && $record['enrollment_status'] === 'enrolled' ? 'pass'
            : ($verified && $record['enrollment_status'] === 'not_enrolled' && ($data['applicant_type'] ?? null) === 'new' ? 'fail' : 'review'),
            'Current-semester enrollment must be Registrar-verified; continuing LOA cases need individual review.');
        if ($input['program'] === 'EDUKAR') {
            $add('external_application_scope', 'review', 'EDUKAR/AKLAT application processing and its requirements are outside this system. Enrollment and external award status can be reviewed; no EDUKAR application rules are implemented.');

            return $this->result($checks);
        }
        if (! $policyConfirmed || ! $this->catalog->forProgram($input['program'])) {
            $add('policy', 'review', 'An approved program/semester compliance basis is unavailable or unconfirmed. School/provider certifications can be retained as evidence, but cannot establish eligibility until their approved applicability and acceptance rules are confirmed.');

            return $this->result($checks);
        }
        $program = $input['program'];
        if (OfficialRuleCatalog::demonstrationProgram($program)) {
            $add('certification_verified', ($data['certification_verified'] ?? false) === true ? 'pass' : 'review',
                'The evaluator must explicitly confirm review of the fictional institutional certification.');
            $source = trim($data['fact_sources']['authenticity'] ?? '');
            $finding = $data['facts']['authenticity'] ?? 'unverified';
            $add('fictional_certification', ! str_starts_with($source, 'UAT-') || $finding === 'unverified'
                ? 'review' : ($finding === 'yes' ? 'pass' : 'fail'),
                OfficialRuleCatalog::DEMO_LABEL.': record a reviewed certification finding and UAT- evidence reference.');
            foreach (['eligibility_support', 'enrollment_certificate'] as $key) {
                $add('document_'.$key, isset($input['evidence'][$key]) ? 'pass' : 'review', 'Accepted fictional evidence required: '.$key.'.');
            }
            if ($input['conflicting_assignment_id']) {
                $add('active_award', 'review', 'Another active scholarship must be resolved before activation.');
            }

            return $this->result($checks);
        }
        $add('special_case', ($data['special_case'] ?? 'unverified') === 'none' ? 'pass' : 'review', 'Special categories require their separately approved guidelines and review.');
        foreach ($this->catalog->requiredFacts($program, $data['applicant_type']) as $key) {
            $value = $data['facts'][$key] ?? 'unverified';
            $source = trim($data['fact_sources'][$key] ?? '');
            $status = $source === '' || $value === 'unverified' ? 'review' : ($value === 'yes' ? 'pass' : 'fail');
            // Duration and exceptions are handled separately; no numeric grade cutoff exists here.
            $add($key, $status, OfficialRuleCatalog::FACTS[$key].($source === '' ? ': a verified source is missing.' : ': '.$source));
        }
        foreach (['returning', 'transferee', ...($program === 'TES' ? ['legacy_pnsl_transfer', 'claiming_pwd'] : [])] as $key) {
            $add($key, ($data[$key] ?? 'unverified') === 'unverified' ? 'review' : 'pass', 'Confirm whether additional evidence is required: '.$key.'.');
        }
        $duration = $data['duration_review'] ?? 'unverified';
        $durationDocumented = trim($data['duration_basis'] ?? '') !== '' && ($data['exceptions_reviewed'] ?? false);
        $add('duration', $durationDocumented && in_array($duration, ['within_limit', 'approved_exception'], true) ? 'pass' : 'review', 'Duration requires an evidenced review including grace periods and approved exceptions. '.($data['duration_basis'] ?? ''));
        if ($program === 'TDP') {
            $add('smart', ($data['smart_same_semester'] ?? 'unverified') === 'unverified' ? 'review' : ($data['smart_same_semester'] === 'yes' ? 'fail' : 'pass'), 'TDP and SMART cannot be received in the same semester (2026 MC02 section 4.9).');
        }
        foreach ($this->catalog->requiredDocuments($program, $data) as $key) {
            $add('document_'.$key, isset($input['evidence'][$key]) ? 'pass' : 'review', OfficialRuleCatalog::DOCUMENTS[$key].': verified private evidence is required.');
        }
        if ($input['conflicting_assignment_id']) {
            $add('active_award', 'review', 'Another active scholarship must be resolved before award activation.');
        }

        return $this->result($checks);
    }

    private function result(array $checks): array
    {
        $statuses = array_column($checks, 'status');
        // Incomplete evidence takes precedence; it must never yield an eligible decision.
        $outcome = in_array('review', $statuses, true) ? 'needs_review' : (in_array('fail', $statuses, true) ? 'failed' : 'passed');
        $reasons = array_map(fn ($check) => $check['reason'], array_filter($checks, fn ($check) => $check['status'] !== 'pass'));

        return ['outcome' => $outcome, 'checks' => $checks, 'reason' => $reasons ? implode("\n", $reasons) : 'All applicable school compliance checks and mandatory evidence passed. External approval is still separate.'];
    }
}
