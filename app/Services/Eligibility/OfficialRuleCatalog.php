<?php

namespace App\Services\Eligibility;

use App\Models\AcademicPeriod;
use App\Models\Document;
use App\Models\ProgramPeriodTerm;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfficialRuleCatalog
{
    public const INSTITUTIONAL_MODE = 'institutional_certification';
    public const AUTHORIZATION_DOCUMENT = 'compliance_authorization';
    public const DEMO_PROGRAM = 'UAT-COSCHO';
    public const DEMO_LABEL = 'UAT SAMPLE — NOT FOR OFFICIAL SUBMISSION';

    public static function demonstrationDatabase(): bool
    {
        $connection = DB::connection();

        return $connection->getDriverName() === 'mysql'
            && $connection->getDatabaseName() === 'cfci_sms_defense_demo';
    }

    public static function demonstrationEnabled(): bool
    {
        return app()->environment('local') && config('sample-data.defense_demo') === true
            && self::demonstrationDatabase();
    }

    public static function demonstrationProgram(string $code): bool
    {
        return in_array($code, [self::DEMO_PROGRAM, 'COSCHO'], true) && self::demonstrationEnabled();
    }

    public static function demonstrationRecord(string $code, ?array $policy = null): bool
    {
        return $code === self::DEMO_PROGRAM || ($policy['demonstration'] ?? false) === true
            || ($code === 'COSCHO' && self::demonstrationDatabase());
    }

    public static function fictionalStudent(Student $student): bool
    {
        return str_starts_with($student->student_number, 'UAT-DEF-')
            && str_ends_with(strtolower($student->email ?? ''), '@example.invalid');
    }

    public function assertDemonstrationStudent(string $program, Student $student): void
    {
        if (self::demonstrationRecord($program) && (! self::demonstrationEnabled() || ! self::fictionalStudent($student))) {
            throw ValidationException::withMessages(['demonstration' => 'Fictional compliance requires the isolated local defense database and a UAT-DEF- scholar with an @example.invalid email. It cannot authorize actual scholarship records.']);
        }
    }

    public const FACTS = [
        'filipino' => 'Filipino citizenship verified',
        'recognized_program' => 'HEI/program recognition and CHED-UniFAST MOA verified',
        'first_degree' => 'First undergraduate degree verified',
        'permitted_aid' => 'Other national assistance checked, including applicable one-time-grant exceptions',
        'retention' => 'Admission/retention compliance certified by the HEI',
        'attendance' => 'Enrollment history and any LOA/return requirements checked',
        'discipline' => 'No disqualifying suspension or expulsion',
        'authenticity' => 'Supporting documents authenticated',
        'continuing_award' => 'Continuing-grantee award history verified',
        'reporting' => 'Required transfer/dropout/deferment notifications verified',
    ];

    public const DOCUMENTS = [
        'eligibility_support' => 'Evidence for the verified facts / school certifications',
        'enrollment_certificate' => 'Current COR/COE or permitted certified enrollment list',
        'application_form' => 'TDP application form',
        'income_evidence' => 'Indigency certificate or permitted substitute',
        'registrar_certificate' => 'Notarized Registrar certificate',
        'annex_forms' => 'Annex 2 forms 1, 2 and 3',
        'approved_loa' => 'Approved LOA for returning grantee',
        'previous_enrollment' => 'Previous-semester COR/COE for transferee',
        'residency_certificate' => 'Residency certificate where required for legacy PNSL transfer',
        'pwd_id' => 'PDAO-issued PWD ID when claiming TES-3A',
    ];

    public static function supportsInstitutionalCertification(string $code, ?ProgramPeriodTerm $terms = null): bool
    {
        return $code === 'COSCHO' && ! self::demonstrationRecord($code, $terms?->criteria);
    }

    public static function sampleText(string ...$values): bool
    {
        return preg_match('/\bUAT(?:\b|_)|NOT FOR OFFICIAL SUBMISSION|\bFICTIONAL\b|\bDEMONSTRATION\b/iu', implode(' ', $values)) === 1;
    }

    public static function operationalStudent(Student $student): bool
    {
        return ! str_starts_with($student->student_number, 'UAT-DEF-')
            && ! str_ends_with(strtolower($student->email ?? ''), '.invalid');
    }

    public function assertInstitutionalStudent(Student $student): void
    {
        if (! self::operationalStudent($student)) {
            throw ValidationException::withMessages(['certification_reference' => 'Fictional sample identities cannot receive an operational institutional certification. Use genuine existing beneficiary records and original authorized evidence.']);
        }
    }

    /** Recorded requirements confirmed by an authorized evaluator; prior evidence-backed versions remain intact. */
    public function institutionalPolicy(ProgramPeriodTerm $terms, array $basis, ?Document $document = null): array
    {
        $legacy = [];
        if ($document) {
            $approval = app(PrivateEvidence::class)->snapshot($document);
            $approval['reviewed_at'] = $document->reviewed_at?->toIso8601String();
            $legacy = ['authority' => $basis['authority'], 'authorization_document' => $approval];
        }

        return [
            'version' => $document ? 'INSTITUTIONAL-CERTIFICATION-v1' : 'INSTITUTIONAL-CERTIFICATION-v2', 'mode' => self::INSTITUTIONAL_MODE,
            'program_id' => $terms->program_id, 'academic_period_id' => $terms->academic_period_id,
            ...$legacy,
            'source' => $basis['source'], 'approved_on' => $basis['approved_on'],
            'requirements_reference' => $basis['requirements_reference'],
            'facts' => ['authenticity' => 'Institution-certified determination against the referenced approved requirements'],
            'documents' => ['eligibility_support', 'enrollment_certificate'],
            'scope' => 'School compliance only under the recorded approved certification basis. Mandatory provider requirements and external award approval remain separate.',
        ];
    }

    public function forProgram(string $code, ?ProgramPeriodTerm $terms = null): ?array
    {
        if (self::demonstrationProgram($code)) {
            return [
                'version' => 'UAT-INSTITUTIONAL-CERTIFICATION-v1',
                'source' => self::DEMO_LABEL.' / fictional institutional certification scenario v1',
                'demonstration' => true,
                'facts' => ['authenticity' => 'Fictional school certification confirms this sample meets the demonstration requirements'],
                'documents' => ['eligibility_support', 'enrollment_certificate'],
                'scope' => 'Fictional CoScho walkthrough only. Not an official CoScho policy or Form 2/Form 3 definition.',
            ];
        }
        if (! in_array($code, ['TDP', 'TES'], true)) {
            $basis = $terms?->criteria;
            if (self::supportsInstitutionalCertification($code, $terms) && ($basis['mode'] ?? null) === self::INSTITUTIONAL_MODE
                && $terms->confirmed_by && $terms->confirmed_at
                && in_array($terms->rule_version, ['INSTITUTIONAL-CERTIFICATION-v1', 'INSTITUTIONAL-CERTIFICATION-v2'], true)) {
                $legacy = $terms->rule_version === 'INSTITUTIONAL-CERTIFICATION-v1';
                foreach (['source', 'approved_on', 'requirements_reference', ...($legacy ? ['authority'] : [])] as $field) {
                    if (! is_string($basis[$field] ?? null) || trim($basis[$field]) === '' || self::sampleText($basis[$field])) {
                        return null;
                    }
                }
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $basis['approved_on'], $date) !== 1
                    || ! checkdate((int) $date[2], (int) $date[3], (int) $date[1])
                    || $basis['approved_on'] > now('Asia/Manila')->toDateString()) {
                    return null;
                }
                if (! $legacy) {
                    $policy = $this->institutionalPolicy($terms, $basis);

                    return $basis == $policy ? $policy : null;
                }
                if (! is_array($basis['authorization_document'] ?? null)) {
                    return null;
                }
                $document = Document::with('application')->find($basis['authorization_document']['id'] ?? 0);
                try {
                    if ($document && $document->document_type === self::AUTHORIZATION_DOCUMENT
                        && $document->application?->program_period_term_id === $terms->id
                        && ! self::sampleText($document->original_name) && app(PrivateEvidence::class)->usable($document)) {
                        $policy = $this->institutionalPolicy($terms, $basis, $document);

                        return $basis == $policy ? $policy : null;
                    }
                } catch (\Throwable) {
                    // Missing/unreadable approval evidence cannot establish an applicable basis.
                }
            }
            return null;
        }

        return [
            'version' => $code === 'TDP' ? 'UNIFAST-TDP-MC02-2026-v1' : 'UNIFAST-TES-MC01-2026-v1',
            'source' => $code === 'TDP' ? 'https://unifast.gov.ph/assets/pdf/guidelines/revised-tdp-pr.pdf' : 'https://unifast.gov.ph/assets/pdf/guidelines/revised-tes-pr-v2.pdf',
            'sha256' => $code === 'TDP' ? 'ef4e55b035bf83b2aaca73ca998c67c27564d43ba5e0d9db8ad56166522753ec' : '56876b55509f9ef38a18ecf207512c9f91c2eba5e163bf00f7381a250675e61c',
            'effective_from' => '2026-2027 semester 1',
            'facts' => self::FACTS,
            'documents' => self::DOCUMENTS,
            'duration' => 'Documented review of actual curriculum/award history, grace period, LOA, transfer rules and approved exceptions; never the program planning duration.',
            'scope' => 'School compliance assessment only; national cross-matching, prioritization, funding and external approval remain separate.',
        ];
    }

    public function appliesTo(AcademicPeriod $period): bool
    {
        // Unknown/summer/custom semester labels need an explicitly reviewed mapping.
        return preg_match('/^(\d{4})-(\d{4})$/', $period->academic_year, $parts) === 1
            && (int) $parts[1] >= 2026 && (int) $parts[2] === (int) $parts[1] + 1
            && in_array($period->semester_code, ['1', '2'], true);
    }

    public function requiredFacts(string $program, string $kind, ?ProgramPeriodTerm $terms = null): array
    {
        $policy = $this->forProgram($program, $terms);
        if (! $policy) {
            return [];
        }
        if (self::demonstrationProgram($program) || ($policy['mode'] ?? null) === self::INSTITUTIONAL_MODE) {
            return ['authenticity'];
        }
        $keys = ['filipino', 'recognized_program', 'permitted_aid', 'authenticity'];
        if ($kind === 'new') {
            $keys[] = 'first_degree';
        }
        if ($program === 'TDP' || $kind === 'continuing') {
            array_push($keys, 'retention', 'attendance', 'discipline');
        }
        if ($kind === 'continuing') {
            $keys[] = 'continuing_award';
            if ($program === 'TDP') {
                $keys[] = 'reporting';
            }
        }

        return $keys;
    }

    public function requiredDocuments(string $program, array $data, ?ProgramPeriodTerm $terms = null): array
    {
        if (! $this->forProgram($program, $terms)) {
            return [];
        }
        $keys = ['eligibility_support', 'enrollment_certificate'];
        if (($data['returning'] ?? 'unverified') === 'yes') {
            $keys[] = 'approved_loa';
        }
        if (($data['transferee'] ?? 'unverified') === 'yes') {
            $keys[] = 'previous_enrollment';
        }
        if ($program === 'TES' && ($data['legacy_pnsl_transfer'] ?? 'unverified') === 'yes') {
            $keys[] = 'residency_certificate';
        }
        if ($program === 'TES' && ($data['claiming_pwd'] ?? 'unverified') === 'yes') {
            $keys[] = 'pwd_id';
        }

        return $keys;
    }

    public function validationDocuments(?string $program = null): array
    {
        // Retain legacy source-profile metadata/snapshots; expose no intake or billing forms.
        $documents = array_diff_key(self::DOCUMENTS, array_flip(['application_form', 'income_evidence', 'annex_forms', 'registrar_certificate']));
        if ($program === null || $program === 'TES') {
            return $documents;
        }
        $types = $program === 'TDP'
            ? ['eligibility_support', 'enrollment_certificate', 'approved_loa', 'previous_enrollment']
            : ['eligibility_support', 'enrollment_certificate'];

        return array_intersect_key($documents, array_flip($types));
    }
}
