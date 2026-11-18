<?php

namespace App\Actions\Accounts;

use App\Models\AuditEvent;
use App\Models\Student;
use App\Models\User;
use App\Models\UserRole;
use App\Services\Accounts\StudentAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LinkStudentAccount
{
    /** Trusted server-console entry point; never select this boundary from HTTP input. */
    public function fromTrustedConsole(string $email, string $studentNumber): StudentAccountProvisioningResult
    {
        return $this->link($email, $studentNumber);
    }

    /** The future interface must supply its authenticated actor, not an input User ID. */
    public function forActor(User $actor, string $email, string $studentNumber, string $reason, bool $identityConfirmed): StudentAccountProvisioningResult
    {
        $actor = $this->authorizedActor($actor);
        if ($actor === null) {
            return StudentAccountProvisioningResult::failure('forbidden', 'You are not authorized to manage student login accounts.');
        }
        $input = Validator::make([
            'email' => Str::lower(trim($email)), 'student_number' => trim($studentNumber),
            'reason' => trim($reason), 'identity_confirmed' => $identityConfirmed,
        ], [
            'email' => ['required', 'email', 'max:255'],
            'student_number' => ['required', 'string', 'max:50'],
            'reason' => ['required', 'string', 'max:1000'], 'identity_confirmed' => ['accepted'],
        ]);
        if ($input->fails()) {
            return StudentAccountProvisioningResult::failure('invalid_input', 'Confirm the intended identity, existing login email, student number and management reason.');
        }

        return $this->link($email, $studentNumber, $actor, trim($reason));
    }

    /** Creates only a new account; an existing unassociated account is never claimed. */
    public function createForActor(User $actor, string $studentNumber, ?string $email = null, string $reason = '', bool $identityConfirmed = false): StudentAccountProvisioningResult
    {
        if ($this->authorizedActor($actor) === null) {
            return StudentAccountProvisioningResult::failure('forbidden', 'You are not authorized to manage student login accounts.');
        }
        if (! Student::where('student_number', trim($studentNumber))->exists()) {
            return StudentAccountProvisioningResult::failure('not_found', 'An existing student record is required.');
        }

        $input = Validator::make(['email' => Str::lower(trim($email ?? '')), 'reason' => trim($reason), 'identity_confirmed' => $identityConfirmed],
            ['email' => ['required', 'string', 'email', 'max:255'], 'reason' => ['required', 'string', 'max:1000'], 'identity_confirmed' => ['accepted']]);
        if ($input->fails()) {
            return StudentAccountProvisioningResult::failure('invalid_input', 'Review the Student identity, recorded contact email and management reason.');
        }
        $email = $input->validated()['email'];
        try {
            return DB::transaction(function () use ($actor, $studentNumber, $email, $reason) {
                $reference = Student::where('student_number', trim($studentNumber))->first();
                if (! $reference) {
                    $this->reject('not_found', 'An existing student record is required.');
                }
                $user = User::where('email', $email)->lockForUpdate()->first();
                $created = $user === null;
                $secret = Str::random(64);
                if ($created) {
                    $user = new User(['name' => Str::limit($reference->full_name, 255, ''), 'email' => $email, 'password' => Hash::make($secret)]);
                    $user->forceFill(['student_setup_required' => true, 'student_access_disabled_at' => null]);
                    if (! $user->saveOrFail()) {
                        $this->reject('account_failed', 'The account could not be saved. No account was created.');
                    }
                }
                $student = Student::whereKey($reference->id)->lockForUpdate()->first();
                if (! $student) {
                    $this->reject('not_found', 'An existing student record is required.');
                }
                $user->setRelation('roles', $user->roles()->lockForUpdate()->get());
                $freshActor = $this->authorizedActor($actor, true);
                if (! $freshActor) {
                    $this->reject('forbidden', 'Account creation is no longer authorized. No account was created.');
                }
                if (! $created) {
                    if ($user->email !== $email || ! app(StudentAccess::class)->validAssociation($user, $student)
                        || ! Gate::forUser($freshActor)->allows('student-accounts.link', $user)) {
                        $this->reject('email_conflict', 'This login email is already in use. Request authorized review; no account was linked.');
                    }

                    return StudentAccountProvisioningResult::created($user->id, $student->id, false);
                }
                if ($student->user_id !== null) {
                    $this->reject('association_conflict', 'This student already has an associated login account. No new account was created.');
                }
                if (filter_var($student->email, FILTER_VALIDATE_EMAIL) === false || Str::lower(trim($student->email)) !== $email) {
                    $this->reject('email_conflict', 'Review the Student contact email before creating the login account.');
                }
                if (Student::whereKeyNot($student->id)->whereRaw('LOWER(TRIM(email)) = ?', [$email])->exists()) {
                    $this->reject('duplicate_contact_email', 'The Student contact email is shared by another record. Review identity before account creation.');
                }
                $user->name = Str::limit($student->full_name, 255, '');
                if (! $user->saveOrFail() || ! $user->roles()->create(['role' => User::STUDENT_ROLE])->exists) {
                    $this->reject('account_failed', 'Student account permissions could not be saved. No account was created.');
                }
                $student->user()->associate($user);
                if (! $student->saveOrFail()) {
                    $this->reject('association_failed', 'The Student account association could not be saved.');
                }
                if (! AuditEvent::create(['actor_id' => $freshActor->id, 'actor_snapshot' => ['name' => $freshActor->name, 'email' => $freshActor->email],
                    'action' => 'student_account_created', 'subject_type' => Student::class, 'subject_id' => $student->id, 'occurred_at' => now(), 'reason' => trim($reason),
                    'before_state' => ['user_id' => null, 'state' => 'not_linked'],
                    'after_state' => ['user_id' => $user->id, 'role' => User::STUDENT_ROLE, 'state' => 'pending_setup', 'result' => 'created']])->exists) {
                    $this->reject('audit_failed', 'Account creation could not be audited. No account was created.');
                }
                $fresh = $user->fresh(['roles', 'student']);
                if (! app(StudentAccess::class)->validAssociation($fresh, $student->fresh()) || $fresh->email !== $email || ! $fresh->student_setup_required
                    || $fresh->student_access_disabled_at !== null || ! Hash::check($secret, $fresh->password)
                    || Gate::forUser($fresh)->allows('viewOwnProfile', $student->fresh())) {
                    $this->reject('postcondition_failed', 'Pending Student account access could not be verified. No account was created.');
                }

                return StudentAccountProvisioningResult::created($user->id, $student->id, true);
            }, 3);
        } catch (ValidationException $error) {
            return StudentAccountProvisioningResult::failure(array_key_first($error->errors()), collect($error->errors())->flatten()->first());
        } catch (UniqueConstraintViolationException) {
            return StudentAccountProvisioningResult::failure('uniqueness_conflict', 'The Student or email was provisioned by another operation. Review the current account status.');
        }
    }

    private function authorizedActor(User $actor, bool $lockMemberships = false): ?User
    {
        $fresh = $actor->exists ? $actor->fresh(['roles']) : null;
        if ($fresh !== null && $lockMemberships) {
            $fresh->setRelation('roles', $fresh->roles()->lockForUpdate()->get());
        }

        return $fresh !== null && Gate::forUser($fresh)->allows('student-accounts.provision') ? $fresh : null;
    }

    private function link(string $email, string $studentNumber, ?User $actor = null, ?string $reason = null): StudentAccountProvisioningResult
    {
        try {
            return DB::transaction(function () use ($email, $studentNumber, $actor, $reason) {
                $email = Str::lower(trim($email));
                $user = User::where('email', $email)->lockForUpdate()->first();
                $student = Student::where('student_number', trim($studentNumber))->lockForUpdate()->first();
                if (! $user || ! $student) {
                    $this->reject('not_found', 'An existing account and an existing student record are required.');
                }
                if ($actor !== null && Str::lower(trim($user->email)) !== $email) {
                    $this->reject('email_conflict', 'The login email requires identity review. No access was changed.');
                }
                if (($student->user_id !== null && (int) $student->user_id !== (int) $user->id)
                    || $user->student()->whereKeyNot($student->id)->exists()) {
                    $this->reject('association_conflict', 'This account or student record is already linked elsewhere. No access was changed.');
                }

                $allMemberships = $user->roles()->lockForUpdate()->get();
                $user->setRelation('roles', $allMemberships);
                if ($actor !== null) {
                    $freshActor = $this->authorizedActor($actor);
                    if ($freshActor === null || ! Gate::forUser($freshActor)->allows('student-accounts.link', $user)) {
                        $this->reject('protected_account', 'This account requires trusted technical review. No access was changed.');
                    }
                    $actor = $freshActor;
                }
                // SQL equality can treat "Student" as "student"; authorization cannot.
                $memberships = $allMemberships->filter(fn (UserRole $membership) => $this->isStudentMembership($membership));
                if ($memberships->count() > 1) {
                    $this->reject('ambiguous_memberships', 'This account has multiple Student memberships. Review them before linking. No access was changed.');
                }
                $membership = $memberships->first();
                $roleChanged = $membership === null || $membership->role !== User::STUDENT_ROLE;
                $before = ['user_id' => $student->user_id];
                if ($membership !== null && $roleChanged) {
                    $before['role'] = $membership->role;
                }
                $linked = $student->user_id === null;
                if ($actor !== null && $linked && filled($student->email)
                    && Student::whereKeyNot($student->id)->whereRaw('LOWER(TRIM(email)) = ?', [Str::lower(trim($student->email))])->exists()) {
                    $this->reject('duplicate_contact_email', 'The student contact email requires identity review. No access was changed.');
                }
                if ($linked) {
                    $student->user()->associate($user);
                    $student->save();
                }
                if ($membership === null) {
                    $user->roles()->create(['role' => User::STUDENT_ROLE]);
                } elseif ($roleChanged) {
                    $membership->update(['role' => User::STUDENT_ROLE]);
                }
                if ($linked || $roleChanged) {
                    $event = [
                        'actor_snapshot' => ['source' => 'trusted console operator'],
                        'action' => 'student_account_linked', 'subject_type' => Student::class,
                        'subject_id' => $student->id, 'occurred_at' => now(),
                        'before_state' => $before,
                        'after_state' => ['user_id' => $user->id, 'role' => User::STUDENT_ROLE],
                    ];
                    if ($actor !== null) {
                        $event['actor_id'] = $actor->id;
                        $event['actor_snapshot'] = ['name' => $actor->name, 'email' => $actor->email];
                        $event['reason'] = $reason;
                    }
                    if (! AuditEvent::create($event)->exists) {
                        $this->reject('audit_failed', 'The access audit could not be saved. No access was changed.');
                    }
                }

                $freshUser = $user->fresh(['roles', 'student']);
                $freshStudent = $student->fresh();
                $persistedMemberships = $freshUser?->roles->filter(fn (UserRole $membership) => $this->isStudentMembership($membership));
                if ($freshUser === null || $freshStudent === null
                    || $persistedMemberships->count() !== 1
                    || $persistedMemberships->first()->role !== User::STUDENT_ROLE
                    || $freshUser->student?->id !== $student->id
                    || ! Gate::forUser($freshUser)->allows('viewOwnProfile', $freshStudent)) {
                    $this->reject('postcondition_failed', 'Student access could not be verified. No access was changed.');
                }

                return StudentAccountProvisioningResult::linked($user->id, $student->id, $linked || $roleChanged);
            }, 3);
        } catch (ValidationException $error) {
            return StudentAccountProvisioningResult::failure(array_key_first($error->errors()), collect($error->errors())->flatten()->first());
        } catch (UniqueConstraintViolationException) {
            return StudentAccountProvisioningResult::failure('uniqueness_conflict', 'The account or student record was linked by another operation. No access was changed.');
        }
    }

    private function isStudentMembership(UserRole $membership): bool
    {
        return strtolower(trim($membership->role)) === User::STUDENT_ROLE;
    }

    private function reject(string $code, string $message): never
    {
        throw ValidationException::withMessages([$code => $message]);
    }
}
