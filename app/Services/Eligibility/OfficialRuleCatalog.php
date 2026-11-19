<?php

namespace App\Services\Eligibility;

use App\Models\AcademicPeriod;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfficialRuleCatalog
{
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

    public function assertDemonstrationStudent(string $program, Student $student): void
    {
        if ($program === self::DEMO_PROGRAM && (! self::demonstrationEnabled()
            || ! str_starts_with($student->student_number, 'UAT-DEF-')
            || ! str_ends_with(strtolower($student->email ?? ''), '@example.invalid'))) {
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

    public function forProgram(string $code): ?array
    {
        if ($code === self::DEMO_PROGRAM && self::demonstrationEnabled()) {
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

    public function requiredFacts(string $program, string $kind): array
    {
        if (! $this->forProgram($program)) {
            return [];
        }
        if ($program === self::DEMO_PROGRAM) {
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

    public function requiredDocuments(string $program, array $data): array
    {
        if (! $this->forProgram($program)) {
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
