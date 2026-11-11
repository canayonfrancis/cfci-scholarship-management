<?php

namespace Database\Seeders\Support;

use App\Models\AcademicValidationContext;
use App\Models\AuditEvent;
use App\Models\ScholarshipProgram;
use App\Models\User;
use App\Services\Eligibility\OfficialRuleCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SampleData
{
    public const CREATED = 'development_sample_created';

    public static function guard(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Development sample data is allowed only in local/testing environments.');
        }
    }

    public static function guardSynthetic(): void
    {
        self::guard();
        $connection = DB::connection();
        $memoryTest = app()->environment('testing') && $connection->getDriverName() === 'sqlite' && $connection->getDatabaseName() === ':memory:';
        $ownedDemo = $connection->getDriverName() === 'mysql' && preg_match('/^cfci_sms(?:_[a-z0-9]+)*_demo$/D', $connection->getDatabaseName())
            && AuditEvent::where('action', 'development_demo_database_initialized')->where('reason', $connection->getDatabaseName())->exists();
        if (! config('sample-data.synthetic_demo') || (! $memoryTest && ! $ownedDemo)) {
            throw new RuntimeException('Synthetic eligibility is restricted to the explicitly enabled, owned demo database. It cannot be seeded into the application database.');
        }
    }

    public static function academicYear(): string
    {
        $year = config('sample-data.academic_year') ?: max(
            AcademicValidationContext::current()->activePeriod?->academic_year ?? '',
            config('sample-data.confirmed_academic_year'),
        );
        if (! is_string($year) || ! preg_match('/^(\d{4})-(\d{4})$/D', $year, $parts) || (int) $parts[1] < 2007 || (int) $parts[1] >= 9999 || (int) $parts[2] !== (int) $parts[1] + 1) {
            throw new RuntimeException('Select a consecutive academic year from 2007-2008 onward.');
        }

        return $year;
    }

    public static function key(string $profile): string
    {
        return 'cfci-samples:v1:'.$profile.':'.self::academicYear();
    }

    public static function completed(string $profile): bool
    {
        return AuditEvent::where('action', 'development_sample_dataset_completed')->where('reason', self::key($profile))->exists();
    }

    public static function complete(string $profile): void
    {
        AuditEvent::create([
            'subject_type' => AcademicValidationContext::class, 'subject_id' => AcademicValidationContext::current()->id,
            'actor_snapshot' => ['source' => 'CFCI development fixtures', 'synthetic_policy' => $profile === 'synthetic-demo'],
            'action' => 'development_sample_dataset_completed', 'reason' => self::key($profile), 'occurred_at' => now(),
        ]);
    }

    public static function mark(Model $model): void
    {
        AuditEvent::create([
            'actor_snapshot' => ['source' => 'CFCI fictional development fixtures'], 'action' => self::CREATED,
            'subject_type' => $model::class, 'subject_id' => $model->id, 'occurred_at' => now(),
        ]);
    }

    public static function owned(Model $model): bool
    {
        return AuditEvent::where('action', self::CREATED)->where('subject_type', $model::class)->where('subject_id', $model->id)->exists();
    }

    public static function staff(string $role): User
    {
        $labels = ['administrator' => 'Mara Dela Cruz', 'finance' => 'Paolo Reyes', 'registrar' => 'Camille Santos', 'coordinator' => 'Rafael Mendoza', 'viewer' => 'Bianca Garcia'];
        $email = 'sample.'.$role.'@example.test';
        $user = User::where('email', $email)->first();
        if ($user) {
            if (! self::owned($user)) {
                throw new RuntimeException('Reserved sample account already exists without seed ownership: '.$email.'. No credentials or roles were changed.');
            }

            return $user;
        }
        $user = User::create(['name' => '[SAMPLE] '.$labels[$role], 'email' => $email, 'password' => config('sample-data.password')]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->roles()->create(['role' => $role]);
        self::mark($user);

        return $user;
    }

    public static function programs(): array
    {
        $programs = [];
        foreach (config('sample-data.program_defaults') as $code => $amount) {
            $programs[$code] = ScholarshipProgram::firstOrCreate(['code' => $code], [
                'name' => $code === 'COSCHO' ? 'CoScho' : $code, 'school_stated_semester_amount' => $amount,
                'source_notes' => 'Confirmed program reference only; semester authorization remains separate.',
            ]);
        }

        return $programs;
    }

    public static function pdf(string $reference): UploadedFile
    {
        $reference = preg_replace('/[^a-z0-9-]/i', '-', $reference);
        $stream = 'BT /F1 12 Tf 50 760 Td (FICTIONAL DEVELOPMENT SAMPLE ONLY) Tj 0 -24 Td (Not an actual school or scholarship certificate.) Tj 0 -24 Td ('.$reference.') Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($stream).">>\nstream\n".$stream."\nendstream",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";

        return UploadedFile::fake()->createWithContent('SAMPLE-'.$reference.'.pdf', $pdf);
    }

    public static function facts(): array
    {
        $keys = array_keys(OfficialRuleCatalog::FACTS);

        return [
            'applicant_type' => 'new', 'facts' => array_fill_keys($keys, 'yes'),
            'fact_sources' => array_fill_keys($keys, 'SAMPLE: fictional certified facts for a development scenario only.'),
            'returning' => 'no', 'transferee' => 'no', 'legacy_pnsl_transfer' => 'no', 'claiming_pwd' => 'no', 'smart_same_semester' => 'no',
            'special_case' => 'none', 'duration_review' => 'within_limit', 'duration_basis' => 'SAMPLE curriculum/award-history review, not a real entitlement.',
            'exceptions_reviewed' => 1, 'evaluator_notes' => 'SAMPLE: fictional development validation. Production grading rules are unchanged.',
        ];
    }

    public static function counts(): array
    {
        $tables = ['academic_periods', 'academic_programs', 'scholarship_programs', 'users', 'user_roles', 'students', 'scholarship_assignments', 'academic_records', 'academic_record_grades', 'program_period_terms', 'scholarship_applications', 'evaluation_runs', 'external_approvals', 'scholarship_rates', 'scholarship_budgets', 'billing_batches', 'billing_items', 'billing_batch_approvals', 'billing_adjustments', 'funding_receipts', 'documents', 'audit_events', 'payouts'];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $counts['academic_years'] = DB::table('academic_periods')->distinct()->count('academic_year');

        return $counts;
    }
}
