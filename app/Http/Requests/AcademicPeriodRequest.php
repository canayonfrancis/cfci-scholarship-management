<?php

namespace App\Http\Requests;

use App\Models\AcademicPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AcademicPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        $period = $this->route('period');

        return $period instanceof AcademicPeriod ? Gate::allows('update', $period) : Gate::allows('create', AcademicPeriod::class);
    }

    protected function prepareForValidation(): void
    {
        foreach (['academic_year', 'semester_code'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $field === 'semester_code' ? Str::upper($value) : $value]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'academic_year' => ['bail', 'required', 'string', 'max:30'],
            'semester_code' => ['bail', 'required', 'string', 'max:30', Rule::unique('academic_periods')->where('academic_year', is_string($this->input('academic_year')) ? $this->input('academic_year') : '')->ignore($this->route('period'))],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', ...($this->filled('starts_on') ? ['after_or_equal:starts_on'] : [])],
            'is_open' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['semester_code.unique' => 'This academic year and semester already exist.'];
    }
}
