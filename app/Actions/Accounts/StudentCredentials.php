<?php

namespace App\Actions\Accounts;

use App\Models\Student;
use App\Models\User;
use App\Services\Accounts\StudentAccess;
use App\Services\Accounts\StudentCredentialDelivery;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentCredentials
{
    public static function initialPasswordRules(): array
    {
        return ['required', 'string', 'max:255', 'confirmed', \Illuminate\Validation\Rules\Password::defaults(),
            function ($attribute, $value, $fail) {
                if (is_string($value) && str_contains($value, "\0")) {
                    $fail('The password contains an unsupported character.');
                } elseif (is_string($value) && config('hashing.driver') === 'bcrypt' && strlen($value) > 72) {
                    $fail('The password must not exceed 72 bytes. Choose a shorter password.');
                }
            },
        ];
    }

    /** Authorized initial setup only; active passwords cannot be replaced here. */
    public function setInitialPassword(User $actor, int $studentId, #[\SensitiveParameter] array $input): void
    {
        $freshActor = $actor->fresh(['roles']);
        abort_unless($freshActor && Gate::forUser($freshActor)->allows('student-accounts.provision'), 403);
        $data = Validator::make($input, [
            'login_email' => ['required', 'string', 'email', 'max:255'],
            'reason' => ['required', 'string', 'max:1000'], 'identity_confirmed' => ['accepted'],
            'password' => self::initialPasswordRules(),
            'password_confirmation' => ['required', 'string', 'max:255'],
        ])->validate();

        DB::transaction(function () use ($actor, $studentId, $data) {
            $reference = Student::findOrFail($studentId);
            $user = User::whereKey($reference->user_id)->lockForUpdate()->first();
            $student = Student::whereKey($studentId)->lockForUpdate()->firstOrFail();
            $freshActor = User::whereKey($actor->id)->lockForUpdate()->first();
            if ($freshActor) {
                $freshActor->setRelation('roles', $freshActor->roles()->lockForUpdate()->get());
            }
            abort_unless($freshActor && Gate::forUser($freshActor)->allows('student-accounts.provision'), 403);
            if ($user) {
                $user->setRelation('roles', $user->roles()->lockForUpdate()->get());
            }
            $access = app(StudentAccess::class);
            if (! $user || ! $access->validAssociation($user, $student)
                || ! Gate::forUser($freshActor)->allows('student-accounts.link', $user)
                || filter_var($user->email, FILTER_VALIDATE_EMAIL) === false
                || Str::lower(trim($user->email)) !== Str::lower(trim($data['login_email']))
                || $user->student_access_disabled_at !== null) {
                throw ValidationException::withMessages(['account' => 'The linked Student account, confirmed email or access state requires review. No password was changed.']);
            }
            if (! $user->student_setup_required) {
                throw ValidationException::withMessages(['account' => 'Password setup is already complete. This form cannot replace an existing password.']);
            }
            if (! $user->forceFill(['password' => Hash::make($data['password']), 'student_setup_required' => false])->saveOrFail()) {
                throw new \RuntimeException('Student initial password could not be saved.');
            }
            $access->revokeCredentials($user);
            StudentAccountLifecycle::audit($user, $student, 'student_account_activated',
                ['state' => 'pending_setup'], ['state' => 'linked', 'source' => 'operator_password_form'], $freshActor, trim($data['reason']));
            $fresh = $user->fresh(['roles']);
            if (! $access->validAssociation($fresh, $student->fresh()) || ! $access->enabled($fresh)
                || ! Hash::check($data['password'], $fresh->password)
                || ! Gate::forUser($fresh)->allows('viewOwnProfile', $student->fresh())) {
                throw new \RuntimeException('Student initial password postconditions failed.');
            }
        }, 3);
    }

    public function issue(User $target, ?User $actor = null, ?string $reason = null, bool $identityConfirmed = false, ?int $studentId = null): string
    {
        $deliveryFailed = false;
        $result = DB::transaction(function () use ($target, $actor, $reason, $identityConfirmed, $studentId, &$deliveryFailed) {
            $user = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $student = $studentId === null ? $user->student()->lockForUpdate()->first() : Student::whereKey($studentId)->lockForUpdate()->first();
            $user->setRelation('roles', $user->roles()->lockForUpdate()->get());
            if ($actor !== null) {
                $actor = $actor->fresh(['roles']);
                abort_unless($actor && Gate::forUser($actor)->allows('student-accounts.provision'), 403);
                if ($studentId === null || ! $identityConfirmed || blank($reason) || mb_strlen($reason) > 1000) {
                    throw ValidationException::withMessages(['account' => 'Identity confirmation and a management reason are required.']);
                }
            }
            $access = app(StudentAccess::class);
            if ($user->email !== $target->email || ! $access->validAssociation($user, $student) || $user->student_access_disabled_at !== null
                || ($actor !== null && ! Gate::forUser($actor)->allows('student-accounts.link', $user))) {
                return Password::INVALID_USER;
            }
            if (! app(StudentCredentialDelivery::class)->available()) {
                StudentAccountLifecycle::audit($user, $student, 'student_credential_request_blocked', [], ['result' => 'delivery_not_approved'], $actor, $reason, $actor === null);

                return 'credential_delivery_not_approved';
            }
            $broker = Password::broker();
            if ($broker->getRepository()->recentlyCreatedToken($user)) {
                return Password::RESET_THROTTLED;
            }
            $token = $broker->createToken($user);
            StudentAccountLifecycle::audit($user, $student, $user->student_setup_required ? 'student_setup_requested' : 'student_password_reset_requested',
                [], ['result' => 'request_recorded'], $actor, $reason, $actor === null);
            DB::afterCommit(function () use ($user, $token, $actor, $reason, &$deliveryFailed) {
                // No delivery inside a retried transaction and no token in exception logs.
                try {
                    $fresh = $user->fresh(['roles']);
                    if (! $fresh || $fresh->email !== $user->email || $fresh->student_access_disabled_at !== null
                        || ! app(StudentAccess::class)->validAssociation($fresh, $fresh->student)
                        || ! Password::broker()->tokenExists($fresh, $token)) {
                        return;
                    }
                    app(StudentCredentialDelivery::class)->send($fresh, $token);
                    StudentAccountLifecycle::audit($fresh, $fresh->student, 'student_credential_transport_accepted', [], ['result' => 'transport_accepted'], $actor, $reason, $actor === null);
                    event(new PasswordResetLinkSent($fresh));
                } catch (\Throwable) {
                    $deliveryFailed = true;
                    try {
                        StudentAccountLifecycle::audit($user, $user->student, 'student_credential_delivery_failed', [], ['result' => 'delivery_unconfirmed'], $actor, $reason, $actor === null);
                    } catch (\Throwable) {
                        // Audit failures must not cause a credential-bearing exception to be logged.
                    }
                    report(new \RuntimeException('Student credential transport failed; delivery is unconfirmed.'));
                }
            });

            return Password::RESET_LINK_SENT;
        }, 3);

        return $deliveryFailed ? 'credential_delivery_failed' : $result;
    }

    public function reset(#[\SensitiveParameter] array $credentials): string
    {
        Validator::make($credentials, [
            'email' => ['required', 'string', 'email', 'max:255'], 'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()],
        ])->validate();

        return DB::transaction(function () use ($credentials) {
            $user = User::where('email', $credentials['email'])->lockForUpdate()->first();
            if (! $user || Str::lower(trim($user->email)) !== Str::lower(trim($credentials['email']))) {
                return Password::INVALID_TOKEN;
            }
            $student = $user->student()->lockForUpdate()->first();
            $user->setRelation('roles', $user->roles()->lockForUpdate()->get());
            $access = app(StudentAccess::class);
            if (! $access->validAssociation($user, $student) || $user->student_access_disabled_at !== null) {
                return Password::INVALID_TOKEN;
            }

            $status = Password::broker()->reset($credentials, function (User $resolved, string $password) use ($user, $student, $access) {
                if ($resolved->id !== $user->id) {
                    throw new \RuntimeException('Credential account identity changed.');
                }
                $pending = $user->student_setup_required;
                if (! $user->forceFill(['password' => Hash::make($password), 'student_setup_required' => false])->saveOrFail()) {
                    throw new \RuntimeException('Student credential write was canceled.');
                }
                $access->revokeCredentials($user);
                StudentAccountLifecycle::audit($user, $student, $pending ? 'student_account_activated' : 'student_password_reset_completed',
                    ['state' => $pending ? 'pending_setup' : 'linked'], ['state' => 'linked']);
                if (! Gate::forUser($user->fresh(['roles']))->allows('viewOwnProfile', $student->fresh())) {
                    throw new \RuntimeException('Credential access postcondition failed.');
                }
                DB::afterCommit(fn () => event(new PasswordReset($user)));
            });
            if ($status === Password::PASSWORD_RESET && (Password::broker()->tokenExists($user, $credentials['token'])
                || ! Hash::check($credentials['password'], $user->fresh()->password))) {
                throw new \RuntimeException('Student credential postconditions failed.');
            }

            return $status;
        }, 3);
    }

    public function updateProfile(User $target, array $attributes): void
    {
        DB::transaction(function () use ($target, $attributes) {
            $user = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            if (! app(StudentAccess::class)->authenticationAllowed($user)) {
                throw ValidationException::withMessages(['email' => 'Student login access is unavailable.']);
            }
            if ($user->email !== $attributes['email']) {
                Password::broker()->deleteToken($user);
                $user->email_verified_at = null;
            }
            if (! $user->fill($attributes)->saveOrFail()) {
                throw new \RuntimeException('Student account update was canceled.');
            }
            if ($user->wasChanged('email')) {
                // A newly adopted address must not inherit a former owner's broker token.
                Password::broker()->deleteToken($user);
            }
        }, 3);
    }

    public function changeOwnPassword(User $target, #[\SensitiveParameter] string $currentPassword, #[\SensitiveParameter] string $newPassword): void
    {
        DB::transaction(function () use ($target, $currentPassword, $newPassword) {
            $user = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $student = $user->student()->lockForUpdate()->first();
            $user->setRelation('roles', $user->roles()->lockForUpdate()->get());
            $access = app(StudentAccess::class);
            if (! $access->validAssociation($user, $student) || ! $access->enabled($user) || ! Hash::check($currentPassword, $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'The current password or Student access is no longer valid.'])->errorBag('updatePassword');
            }
            if (! $user->forceFill(['password' => Hash::make($newPassword)])->saveOrFail()) {
                throw new \RuntimeException('Student password update could not be saved.');
            }
            $access->revokeCredentials($user);
            StudentAccountLifecycle::audit($user, $student, 'student_password_changed', [], ['result' => 'completed']);
            if (! Hash::check($newPassword, $user->fresh()->password)) {
                throw new \RuntimeException('Student password update postcondition failed.');
            }
        }, 3);
    }
}
