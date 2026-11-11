<?php

namespace App\Console\Commands;

use App\Models\AcademicValidationContext;
use App\Models\AuditEvent;
use App\Models\ScholarshipProgram;
use Database\Seeders\Support\SampleData;
use Database\Seeders\SyntheticDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SeedScholarshipDemo extends Command
{
    protected $signature = 'sms:seed-demo {--database=cfci_sms_samples_demo : Separate database ending in _demo} {--academic-year= : Terminal academic year, such as 2026-2027} {--synthetic-policy : Explicitly enable labeled synthetic academic prerequisites in the separate demo only}';

    protected $description = 'Create repeatable, isolated scholarship development fixtures without changing the application database or .env';

    public function handle(): int
    {
        $previous = DB::getDefaultConnection();
        $previousYear = config('sample-data.academic_year');
        $previousFlag = config('sample-data.synthetic_demo');
        $previousDefaults = config('sample-data.program_defaults');
        try {
            SampleData::guard();
            if (! $this->option('synthetic-policy')) {
                $this->error('Use --synthetic-policy to explicitly request fictional eligible/billing fixtures in the separate demo database. The production academic policy is not modified.');

                return self::FAILURE;
            }
            $name = $this->option('database');
            $source = DB::connection();
            if ($source->getDriverName() !== 'mysql' || ! is_string($name) || ! preg_match('/^cfci_sms(?:_[a-z0-9]+)*_demo$/D', $name) || $source->getDatabaseName() === $name) {
                $this->error('Run from the local application connection and select a different MySQL database named cfci_sms_*_demo.');

                return self::FAILURE;
            }
            if ($this->option('academic-year')) {
                config(['sample-data.academic_year' => $this->option('academic-year')]);
            }
            // Resolve the requested scope against the existing application's configured year before switching.
            config(['sample-data.academic_year' => SampleData::academicYear()]);
            $defaults = config('sample-data.program_defaults');
            foreach (ScholarshipProgram::whereIn('code', array_keys($defaults))->get() as $program) {
                $defaults[$program->code] = $program->school_stated_semester_amount;
            }
            $exists = (bool) $source->select('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$name]);
            $connection = $source->getConfig();
            unset($connection['name']);
            $connection['database'] = $name;
            $connection['url'] = null;
            config(['database.connections.sample_demo' => $connection]);
            if ($exists) {
                $demo = DB::connection('sample_demo');
                if (! $demo->getSchemaBuilder()->hasTable('audit_events') || ! $demo->table('audit_events')->where('action', 'development_demo_database_initialized')->where('reason', $name)->exists()) {
                    $this->error('The selected database already exists without a demo ownership marker. It was not migrated, seeded or overwritten.');

                    return self::FAILURE;
                }
                $completed = $demo->table('audit_events')->where('action', 'development_sample_dataset_completed')->pluck('reason');
                if ($completed->contains(fn ($key) => ! str_ends_with($key, ':'.SampleData::academicYear()))) {
                    $this->error('This demo already contains another academic-year dataset. Choose a different demo database name to preserve its records.');

                    return self::FAILURE;
                }
            } else {
                $source->statement('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            }
            DB::setDefaultConnection('sample_demo');
            Schema::clearResolvedInstance('db.schema');
            $exit = Artisan::call('migrate', ['--database' => 'sample_demo', '--force' => true, '--no-interaction' => true]);
            $this->output->write(Artisan::output());
            if ($exit !== 0) {
                return self::FAILURE;
            }
            AuditEvent::firstOrCreate(['action' => 'development_demo_database_initialized', 'reason' => $name], [
                'subject_type' => AcademicValidationContext::class, 'subject_id' => AcademicValidationContext::current()->id,
                'actor_snapshot' => ['source' => 'Explicit isolated development demo creation'], 'occurred_at' => now(),
            ]);
            config(['sample-data.synthetic_demo' => true, 'sample-data.program_defaults' => $defaults]);
            app(SyntheticDemoSeeder::class)->run();
            $this->info('Synthetic demonstration database: '.$name.'. The application database and .env were not changed.');
            $this->table(['Collection', 'Total rows in demo database'], collect(SampleData::counts())->map(fn ($count, $table) => [$table, $count])->all());
            $this->line('Only one demo semester is active. No historical scholarship transactions were assumed for older academic years.');
            $this->line('Eligibility examples are fictional; re-evaluation uses the unchanged production policy. No actual funding was received and no payments were generated.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            DB::purge('sample_demo');
            DB::setDefaultConnection($previous);
            Schema::clearResolvedInstance('db.schema');
            config(['sample-data.academic_year' => $previousYear, 'sample-data.synthetic_demo' => $previousFlag, 'sample-data.program_defaults' => $previousDefaults]);
        }
    }
}
