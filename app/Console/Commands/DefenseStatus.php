<?php

namespace App\Console\Commands;

use App\Models\AcademicPeriod;
use App\Models\AcademicValidationContext;
use App\Services\Eligibility\OfficialRuleCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DefenseStatus extends Command
{
    protected $signature = 'sms:defense-status';

    protected $description = 'Read-only configuration/schema check for the isolated fictional defense scenario';

    public function handle(): int
    {
        try {
            $this->table(['Setting', 'Resolved value'], [
                ['Environment', app()->environment()],
                ['Database driver', DB::connection()->getDriverName()],
                ['Database name', DB::connection()->getDatabaseName()],
                ['Defense flag', config('sample-data.defense_demo') === true ? 'true' : 'false'],
            ]);
            if (! OfficialRuleCatalog::demonstrationEnabled()) {
                $this->error('BLOCKED: use a separate local copy, local environment, mysql, cfci_sms_defense_demo and SMS_DEFENSE_DEMO=true. No database queries or writes were performed by this check.');
                $this->line('Do not rename or reuse the operational database. See docs/35-defense-readiness-and-end-to-end-workflow.md.');

                return self::FAILURE;
            }
            $this->info(OfficialRuleCatalog::DEMO_LABEL.' — configuration gates open for COSCHO and UAT-COSCHO.');
            foreach (['users', 'user_roles', 'students', 'scholarship_programs', 'academic_periods', 'academic_validation_context',
                'program_period_terms', 'scholarship_assignments', 'scholarship_batches', 'scholarship_batch_memberships',
                'academic_records', 'documents', 'scholarship_applications', 'evaluation_runs', 'external_approvals',
                'billing_batches', 'billing_items', 'scholarship_rates', 'scholarship_budgets', 'billing_batch_approvals',
                'funding_receipts', 'funding_allocations', 'payouts', 'deductions', 'payrolls', 'payroll_items',
                'payment_acknowledgments', 'liquidations', 'liquidation_items', 'delisting_reasons', 'audit_events',
            ] as $table) {
                if (! Schema::hasTable($table)) {
                    $this->error('BLOCKED: missing isolated schema table '.$table.'. The developer must apply the existing migrations in this separate database. Nothing was created.');

                    return self::FAILURE;
                }
            }
            if (! Schema::hasColumn('scholarship_batch_memberships', 'billing_exclusion')) {
                $this->error('BLOCKED: apply the existing documented exclusion migration in the isolated database to enable the complete exclusion demonstration.');

                return self::FAILURE;
            }
            $period = AcademicPeriod::where('academic_year', '2026-2027')->where('semester_code', '2')->first();
            if (! $period || ! $period->is_open || AcademicValidationContext::find(1)?->active_period_id !== $period->id) {
                $this->error('PREPARATION REQUIRED: register/open 2026-2027 Semester 2 and select it as Active validation period through Information Registry.');

                return self::FAILURE;
            }
            $this->info('Configuration, required schema and demonstration period are available. No records were changed.');
            $this->line('This does not test login, enrollment, certification, activation, submission, payment or liquidation. Follow the documented fictional workflow; real beneficiaries remain prohibited.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Unable to inspect the isolated configuration/schema. Check local database connectivity and migration prerequisites; credentials are not printed.');

            return self::FAILURE;
        }
    }
}
