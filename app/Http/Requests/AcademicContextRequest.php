<?php

namespace App\Http\Requests;

use App\Models\AcademicPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class AcademicContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', AcademicPeriod::class);
    }

    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:0'],
            'active_period_id' => ['required', 'integer', 'exists:academic_periods,id'],
        ];
    }
}
