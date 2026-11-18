<?php

namespace App\Http\Requests;

use App\Actions\Accounts\StudentCredentials;

class SetStudentInitialPasswordRequest extends ManageStudentAccountRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'password' => StudentCredentials::initialPasswordRules(),
            'password_confirmation' => ['required', 'string', 'max:255'],
        ]);
    }

    public function messages(): array
    {
        return ['identity_confirmed.accepted' => 'Confirm that you reviewed this Student identity and linked login before setting their initial password.'];
    }
}
