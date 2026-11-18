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
use App\Policies\StudentAccountProvisioningPolicy;
use App\Policies\StudentPolicy;
use App\Services\Accounts\StudentCredentialDelivery;
use App\Support\TablePagination;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        ResetPassword::toMailUsing(function ($user, #[\SensitiveParameter] $token) {
            $delivery = app(StudentCredentialDelivery::class);
            if (! $delivery->available() || ! $delivery->configured()) {
                throw new \RuntimeException('Credential delivery is unavailable.');
            }
            $pending = (bool) $user->student_setup_required;
            $url = rtrim((string) config('app.url'), '/').route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()], false);

            return (new MailMessage)->mailer($delivery->mailer())->subject($pending ? 'Set up your Student password' : 'Reset your password')
                ->line($pending ? 'Your Student login account is ready for secure password setup.' : 'A password reset was requested for your account.')
                ->action($pending ? 'Set password' : 'Reset password', $url)
                ->line('This single-use link expires in '.config('auth.passwords.users.expire').' minutes.')
                ->line('If you did not expect this message, contact the Registrar.');
        });

        Builder::macro('tablePaginate', function (string $table, string $pageName = 'page', ?callable $transform = null) {
            return TablePagination::query($this, $table, $pageName, $transform);
        });

        Gate::define('financial-view', fn ($user) => app(FinancialRecordPolicy::class)->viewAny($user));
        Gate::define('student-accounts.provision', [StudentAccountProvisioningPolicy::class, 'provision']);
        Gate::define('student-accounts.link', [StudentAccountProvisioningPolicy::class, 'linkExisting']);
        RateLimiter::for('student-account-links', function (Request $request) {
            // Unauthorized calls must not consume an authorized operator's target quota.
            if (! $request->user()?->can('student-accounts.provision')) {
                return Limit::none();
            }
            $student = $request->route('student');
            $key = $student instanceof Student ? $student->getKey() : (string) $student;

            return [Limit::perMinute(6)->by('actor:'.$request->user()->id), Limit::perMinute(3)->by('student:'.$key)];
        });
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
