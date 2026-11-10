<?php

namespace App\Providers;

use App\Models\AcademicPeriod;
use App\Models\ScholarshipAssignment;
use App\Models\ScholarshipProgram;
use App\Models\Student;
use App\Policies\AcademicPeriodPolicy;
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
        Gate::policy(Student::class, StudentPolicy::class);
        Gate::policy(ScholarshipProgram::class, ScholarshipProgramPolicy::class);
        Gate::policy(AcademicPeriod::class, AcademicPeriodPolicy::class);
        Gate::policy(ScholarshipAssignment::class, ScholarshipAssignmentPolicy::class);
    }
}
