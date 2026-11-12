<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'program_id' => ['nullable', 'integer', 'exists:scholarship_programs,id'],
            'academic_year' => ['nullable', 'string', 'regex:/^[0-9]{4}-[0-9]{4}$/D', 'exists:academic_periods,academic_year'],
            'semester_code' => ['nullable', 'string', 'max:30', 'exists:academic_periods,semester_code'],
        ];
    }
}
