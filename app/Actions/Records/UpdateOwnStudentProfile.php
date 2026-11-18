<?php

namespace App\Actions\Records;

use App\Models\AuditEvent;
use App\Models\Student;
use App\Models\User;
use App\Services\Records\ScholarProfile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateOwnStudentProfile
{
    public const FIELDS = [
        'first_name', 'middle_name', 'last_name', 'suffix', 'sex', 'birthdate',
        'email', 'phone', 'street_barangay', 'city_municipality', 'province', 'region', 'zip_code',
        'father_first_name', 'father_middle_name', 'father_last_name',
        'mother_maiden_first_name', 'mother_maiden_middle_name', 'mother_maiden_last_name',
    ];

    public static function version(Student $student): string
    {
        $state = $student->getRawOriginal();
        ksort($state);

        return hash_hmac('sha256', json_encode($state, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public static function normalize(array $input): array
    {
        foreach (self::FIELDS as $field) {
            if (! array_key_exists($field, $input) || ! is_string($input[$field])) {
                continue;
            }
            $input[$field] = trim($input[$field]);
            if ($input[$field] === '') {
                $input[$field] = null;
            }
        }
        if (is_string($input['email'] ?? null)) {
            $input['email'] = Str::lower($input['email']);
        }
        if (is_string($input['phone'] ?? null)) {
            $input['phone'] = ScholarProfile::normalizePhone($input['phone']) ?? $input['phone'];
        }

        return $input;
    }

    public static function rules(?Student $student): array
    {
        $rules = [];
        foreach (self::FIELDS as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:255'];
        }
        $rules['first_name'] = $rules['last_name'] = ['sometimes', 'required', 'string', 'max:255'];
        $rules['suffix'] = ['sometimes', 'nullable', 'string', 'max:30'];
        $rules['zip_code'] = ['sometimes', 'nullable', 'string', 'max:20'];
        $rules['sex'] = ['sometimes', 'nullable', 'integer', Rule::in(array_keys(ScholarProfile::SEX))];
        $rules['birthdate'] = ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Manila')->toDateString()];
        $rules['email'] = ['sometimes', 'nullable', 'string', 'email', 'max:255'];
        $rules['phone'] = ['sometimes', 'nullable', 'string', 'max:40', function ($attribute, $value, $fail) use ($student) {
            if (is_string($value) && ScholarProfile::normalizePhone($value) === null && $value !== trim((string) $student?->phone)) {
                $fail('Enter a Philippine mobile number (10 digits starting with 9) or an 8-digit landline.');
            }
        }];
        $rules['profile_version'] = ['required', 'string', 'size:64'];

        return $rules;
    }

    public function execute(User $actor, array $input): bool
    {
        return DB::transaction(function () use ($actor, $input) {
            $user = User::whereKey($actor->id)->lockForUpdate()->first();
            abort_unless($user, 403);
            $user->setRelation('roles', $user->roles()->lockForUpdate()->get());
            $student = $user->student()->lockForUpdate()->first();
            abort_unless($student, 403, 'No Student profile is linked to this account.');
            Gate::forUser($user)->authorize('updateOwnProfile', $student);

            $unexpected = array_diff(array_keys($input), [...self::FIELDS, 'profile_version']);
            if ($unexpected) {
                throw ValidationException::withMessages(array_fill_keys($unexpected, 'This field cannot be changed from your Student profile.'));
            }
            $data = Validator::make(self::normalize($input), self::rules($student))->validate();
            if (! hash_equals(self::version($student), $data['profile_version'])) {
                throw ValidationException::withMessages(['profile_version' => 'Your profile changed while this form was open. Review the latest saved information and enter your changes again.']);
            }
            $editable = Arr::only($data, self::FIELDS);
            $protectedBefore = Arr::except($student->getRawOriginal(), [...self::FIELDS, 'updated_at']);
            $before = $student->only(array_keys($editable));
            $expected = (clone $student)->fill($editable);
            $student->fill($editable);
            if (! $student->isDirty(self::FIELDS)) {
                return false;
            }
            if (! $student->saveOrFail()) {
                throw new \RuntimeException('Student profile update could not be saved.');
            }
            $after = $student->only(array_keys($editable));
            if (! AuditEvent::create([
                'actor_id' => $user->id, 'actor_snapshot' => ['name' => $user->name, 'email' => $user->email],
                'action' => 'student_profile_updated', 'subject_type' => Student::class, 'subject_id' => $student->id,
                'occurred_at' => now(), 'before_state' => $before, 'after_state' => $after,
            ])->exists) {
                throw new \RuntimeException('Student profile update could not be audited.');
            }
            $fresh = $student->fresh();
            $freshUser = $user->fresh(['roles']);
            if (! $fresh || ! $freshUser || ! Gate::forUser($freshUser)->allows('updateOwnProfile', $fresh)
                || Arr::except($fresh->getRawOriginal(), [...self::FIELDS, 'updated_at']) !== $protectedBefore
                || json_encode($fresh->only(array_keys($editable)), JSON_THROW_ON_ERROR) !== json_encode($expected->only(array_keys($editable)), JSON_THROW_ON_ERROR)) {
                throw new \RuntimeException('Student profile update postconditions failed.');
            }

            return true;
        }, 3);
    }
}
