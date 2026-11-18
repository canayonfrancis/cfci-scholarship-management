<?php

namespace App\Services\Accounts;

use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class StudentAccess
{
    public function enabled(User $user): bool
    {
        return ! $user->student_setup_required && $user->student_access_disabled_at === null;
    }

    public function authenticationAllowed(User $user): bool
    {
        return $user->hasAnyRole(User::STAFF_ROLES) || $this->enabled($user);
    }

    public function usesStudentCredentials(User $user): bool
    {
        return ! $user->hasAnyRole(User::STAFF_ROLES) && ($user->student_setup_required
            || $user->student_access_disabled_at !== null || $user->student()->exists()
            || $user->roles->contains(fn ($role) => strtolower(trim($role->role)) === User::STUDENT_ROLE));
    }

    public function validAssociation(User $user, ?Student $student): bool
    {
        return $student !== null && (int) $student->user_id === (int) $user->id
            && $user->roles->count() === 1 && $user->roles->first()->role === User::STUDENT_ROLE
            // Use a current read when another provisioner committed after this transaction began.
            && Student::whereKey($student->id)->where('user_id', $user->id)->lockForUpdate()->first(['id']) !== null;
    }

    public function state(User $user): string
    {
        return $user->student_access_disabled_at !== null ? 'inactive' : ($user->student_setup_required ? 'pending_setup' : 'linked');
    }

    /** Called inside the locked User transaction; no other account's sessions are touched. */
    public function revokeCredentials(User $user): void
    {
        if (config('session.driver') !== 'database' && ! (app()->environment('testing') && config('session.driver') === 'array')) {
            throw new \RuntimeException('Student session revocation requires database sessions.');
        }
        if (! $user->forceFill(['remember_token' => Str::random(60)])->saveOrFail()) {
            throw new \RuntimeException('Student credential revocation failed.');
        }
        Password::broker()->deleteToken($user);
        if (config('session.driver') === 'database') {
            $sessions = DB::connection(config('session.connection'));
            if ($sessions->getDatabaseName() !== $user->getConnection()->getDatabaseName()) {
                throw new \RuntimeException('Student session revocation requires the account database connection.');
            }
            $user->getConnection()->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }
}
