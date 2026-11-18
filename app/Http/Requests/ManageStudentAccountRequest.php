<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageStudentAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('student-accounts.provision');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('login_email'))) {
            $this->merge(['login_email' => Str::lower(trim($this->input('login_email')))]);
        }
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    public function rules(): array
    {
        return [
            'login_email' => ['required', 'string', 'email', 'max:255'],
            'reason' => ['required', 'string', 'max:1000'],
            'identity_confirmed' => ['accepted'],
            'user_id' => ['prohibited'], 'student_id' => ['prohibited'], 'student_number' => ['prohibited'],
            'actor_id' => ['prohibited'], 'role' => ['prohibited'], 'roles' => ['prohibited'],
            'mode' => ['prohibited'], 'source' => ['prohibited'], 'create_account' => ['prohibited'],
            'password' => ['prohibited'], 'token' => ['prohibited'], 'remember_token' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['identity_confirmed.accepted' => 'Confirm that you checked this student and the existing account belong to the same person.'];
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        // Do not flash arbitrary credential/role payloads or auto-confirm identity on retry.
        $response = redirect()->route('student-accounts.show', $this->route('student'), 303)
            ->withErrors($validator)->withInput($this->only(['login_email', 'reason']));
        throw new ValidationException($validator, $response);
    }
}
