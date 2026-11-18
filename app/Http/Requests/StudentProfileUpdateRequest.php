<?php

namespace App\Http\Requests;

use App\Actions\Records\UpdateOwnStudentProfile;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class StudentProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $student = $this->user()?->student()->first();

        return $student !== null && Gate::allows('updateOwnProfile', $student);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(UpdateOwnStudentProfile::normalize($this->only(UpdateOwnStudentProfile::FIELDS)));
    }

    public function rules(): array
    {
        return UpdateOwnStudentProfile::rules($this->user()?->student()->first());
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach (array_diff(array_keys($this->all()), [...UpdateOwnStudentProfile::FIELDS, 'profile_version', '_token', '_method']) as $field) {
                $validator->errors()->add($field, 'This field cannot be changed from your Student profile.');
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }
        $response = to_route('student.profile.edit', [], 303)->withErrors($validator)
            ->withInput($this->only(UpdateOwnStudentProfile::FIELDS));
        throw new ValidationException($validator, $response);
    }
}
