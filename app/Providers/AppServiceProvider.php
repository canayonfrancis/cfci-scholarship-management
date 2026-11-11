<?php

namespace App\Providers;

use App\Models\AcademicPeriod;
use App\Models\AcademicRecord;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipAssignment;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Policies\AcademicPeriodPolicy;
use App\Policies\AcademicRecordPolicy;
use App\Policies\FinancialRecordPolicy;
use App\Policies\ScholarshipApplicationPolicy;
use App\Policies\ScholarshipAssignmentPolicy;
use App\Policies\ScholarshipProgramPolicy;
use App\Policies\StudentPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('financial-view', fn ($user) => app(FinancialRecordPolicy::class)->viewAny($user));
        Gate::define('financial-prepare', fn ($user) => app(FinancialRecordPolicy::class)->prepare($user));
        Gate::define('financial-verify', fn ($user) => app(FinancialRecordPolicy::class)->verify($user));
        Gate::define('financial-evidence', fn ($user) => app(FinancialRecordPolicy::class)->evidence($user));
        Gate::policy(Student::class, StudentPolicy::class);
        Gate::policy(AcademicRecord::class, AcademicRecordPolicy::class);
        Gate::policy(ScholarshipApplication::class, ScholarshipApplicationPolicy::class);
        Gate::policy(ScholarshipProgram::class, ScholarshipProgramPolicy::class);
        Gate::policy(AcademicPeriod::class, AcademicPeriodPolicy::class);
        Gate::policy(ScholarshipAssignment::class, ScholarshipAssignmentPolicy::class);
    }
}
