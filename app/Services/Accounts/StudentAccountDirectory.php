<?php

namespace App\Services\Accounts;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class StudentAccountDirectory
{
    public const STATUSES = [
        'not_linked' => 'No account linked',
        'linked' => 'Linked and configured',
        'needs_review' => 'Access needs review',
        'restricted' => 'Management restricted',
        'inactive' => 'Inactive',
        'pending_setup' => 'Pending password setup',
    ];

    public function rows(User $actor): Collection
    {
        return $this->query()->orderBy('last_name')->orderBy('first_name')->orderBy('id')->get()
            ->map(fn (Student $student) => $this->describe($student, $actor));
    }

    public function find(int $id, User $actor): object
    {
        return $this->describe($this->query()->findOrFail($id), $actor);
    }

    private function query(): Builder
    {
        return Student::select(['id', 'student_number', 'first_name', 'middle_name', 'last_name', 'suffix', 'email', 'user_id', 'record_status'])
            ->with(['user' => fn ($query) => $query->select(['id', 'email', 'student_setup_required', 'student_access_disabled_at']), 'user.roles:id,user_id,role']);
    }

    private function describe(Student $student, User $actor): object
    {
        $user = $student->user;
        $status = 'not_linked';
        $manageable = $student->user_id === null;
        $profileAllowed = false;
        if ($student->user_id !== null) {
            $status = 'needs_review';
            if ($user !== null) {
                // Project exact stored values; a case-insensitive SQL match is not access.
                $studentRoles = $user->roles->filter(fn ($membership) => strtolower(trim($membership->role)) === User::STUDENT_ROLE);
                $targetAllowed = Gate::forUser($actor)->allows('student-accounts.link', $user);
                $manageable = $targetAllowed && $studentRoles->count() <= 1;
                $profileAllowed = Gate::forUser($user)->allows('viewOwnProfile', $student);
                if (! $targetAllowed) {
                    $status = 'restricted';
                } elseif ($studentRoles->count() === 1 && $studentRoles->first()->role === User::STUDENT_ROLE) {
                    $status = app(StudentAccess::class)->state($user);
                }
            }
        }

        // Views receive only these fields, never User credentials or full student records.
        return (object) [
            'id' => $student->id, 'studentNumber' => $student->student_number,
            'name' => $student->full_name, 'contactEmail' => $student->email,
            'loginEmail' => $manageable ? $user?->email : null,
            'recordStatus' => $student->record_status, 'linked' => $student->user_id !== null,
            'status' => $status, 'statusLabel' => self::STATUSES[$status],
            'profileAllowed' => $profileAllowed, 'manageable' => $manageable,
        ];
    }
}
