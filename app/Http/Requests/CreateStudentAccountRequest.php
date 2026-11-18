<?php

namespace App\Http\Requests;

class CreateStudentAccountRequest extends ManageStudentAccountRequest
{
    public function messages(): array
    {
        return ['identity_confirmed.accepted' => 'Confirm that you reviewed this Student identity and the recorded login email.'];
    }
}
