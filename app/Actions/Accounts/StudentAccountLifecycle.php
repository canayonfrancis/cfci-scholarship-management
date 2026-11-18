<?php

namespace App\Actions\Accounts;

use App\Models\AuditEvent;
use App\Models\Student;
use App\Models\User;
use App\Services\Accounts\StudentAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentAccountLifecycle
{
    public function transition(User $actor, int $studentId, string $email, string $reason, bool $identityConfirmed, bool $reactivate): bool
    {
        return DB::transaction(function () use ($actor, $studentId, $email, $reason, $identityConfirmed, $reactivate) {
            $actor = $actor->fresh(['roles']);
            abort_unless($actor && Gate::forUser($actor)->allows('student-accounts.provision'), 403);
            Validator::make(['login_email' => $email, 'reason' => trim($reason), 'identity_confirmed' => $identityConfirmed], [
                'login_email' => ['required', 'email', 'max:255'], 'reason' => ['required', 'string', 'max:1000'], 'identity_confirmed' => ['accepted'],
            ])->validate();
            $reference = Student::findOrFail($studentId);
            $user = User::whereKey($reference->user_id)->lockForUpdate()->first();
            $student = Student::whereKey($studentId)->lockForUpdate()->firstOrFail();
            if ($user) {
                $user->setRelation('roles', $user->roles()->lockForUpdate()->get());
            }
            $access = app(StudentAccess::class);
            if (! $user || ! $access->validAssociation($user, $student)
                || ! Gate::forUser($actor)->allows('student-accounts.link', $user)
                || Str::lower(trim($user->email)) !== Str::lower(trim($email))) {
                throw ValidationException::withMessages(['account' => 'The account association, role or confirmed email requires technical review. No access was changed.']);
            }
            $before = $access->state($user);
            if ($reactivate && $user->student_access_disabled_at === null) {
                return false;
            }
            if (! $reactivate && $user->student_access_disabled_at !== null) {
                $access->revokeCredentials($user);

                return false;
            }
            if (! $user->forceFill(['student_access_disabled_at' => $reactivate ? null : now()])->saveOrFail()) {
                throw new \RuntimeException('Student lifecycle write was canceled.');
            }
            // Reactivation also rotates/revokes credentials; it never revives an old session.
            $access->revokeCredentials($user);
            $action = $reactivate ? 'student_access_reactivated' : 'student_access_deactivated';
            self::audit($user, $student, $action, ['state' => $before], ['state' => $access->state($user)], $actor, trim($reason));
            $fresh = $user->fresh(['roles']);
            if (! $access->validAssociation($fresh, $student->fresh())
                || Gate::forUser($fresh)->allows('viewOwnProfile', $student->fresh()) !== $access->enabled($fresh)
                || ($reactivate ? $fresh->student_access_disabled_at !== null : $fresh->student_access_disabled_at === null)) {
                throw new \RuntimeException('Student lifecycle postconditions failed.');
            }

            return true;
        }, 3);
    }

    public static function audit(User $user, Student $student, string $action, array $before, array $after, ?User $actor = null, ?string $reason = null, bool $publicRequest = false): void
    {
        $event = AuditEvent::create([
            'actor_id' => $publicRequest ? null : ($actor?->id ?? $user->id),
            'actor_snapshot' => $actor ? ['name' => $actor->name, 'email' => $actor->email] : ['source' => $publicRequest ? 'public credential request' : 'student credential holder'],
            'action' => $action, 'subject_type' => Student::class, 'subject_id' => $student->id,
            'occurred_at' => now(), 'reason' => $reason,
            'before_state' => ['user_id' => $user->id] + $before,
            'after_state' => ['user_id' => $user->id] + $after + ['result' => 'completed'],
        ]);
        if (! $event->exists) {
            throw new \RuntimeException('Student account audit could not be saved.');
        }
    }
}
