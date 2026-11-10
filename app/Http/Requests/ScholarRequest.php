<?php

namespace App\Http\Requests;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ScholarRequest extends FormRequest
{
    public function authorize(): bool
    {
        $scholar = $this->route('scholar');

        return $scholar instanceof Student ? Gate::allows('update', $scholar) : Gate::allows('create', Student::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('student_number'))) {
            $this->merge(['student_number' => Str::upper(trim($this->input('student_number')))]);
        }
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'student_number' => ['bail', 'required', 'string', 'max:50', Rule::unique('students')->ignore($this->route('scholar'))],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'record_status' => ['required', Rule::in(['active', 'archived'])],
        ];
    }
}
