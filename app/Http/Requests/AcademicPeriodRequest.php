<?php

namespace App\Http\Requests;

use App\Models\AcademicPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AcademicPeriodRequest extends FormRequest
{
    protected $errorBag = 'periodForm';

    public function authorize(): bool
    {
        $period = $this->route('period');

        return $period instanceof AcademicPeriod ? Gate::allows('update', $period) : Gate::allows('create', AcademicPeriod::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('_registry_form') === 'year' && ! $this->route('period')) {
            $start = $this->input('year_start');
            $end = $this->input('year_end');
            $this->merge(['academic_year' => is_scalar($start) && is_scalar($end) ? $start.'-'.$end : null]);
        }
        foreach (['academic_year', 'semester_code'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                if ($field === 'academic_year') {
                    $value = str_replace([chr(226).chr(128).chr(147), chr(226).chr(128).chr(148)], '-', $value);
                }
                $this->merge([$field => $field === 'semester_code' ? Str::upper($value) : $value]);
            }
        }
    }

    public function rules(): array
    {
        $rules = [
            'academic_year' => ['bail', 'required', 'string', 'regex:/^(20[0-9]{2}|[3-9][0-9]{3})-[0-9]{4}$/D', function ($attribute, $value, $fail) {
                [$start, $end] = array_map('intval', explode('-', $value));
                if ($start < 2007 || $end !== $start + 1) {
                    $fail('Use an academic year from 2007-2008 onward with consecutive years.');
                }
            }],
            'semester_code' => ['bail', 'required', 'string', 'max:30', Rule::unique('academic_periods')->where('academic_year', is_string($this->input('academic_year')) ? $this->input('academic_year') : '')->ignore($this->route('period'))],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', ...($this->filled('starts_on') ? ['after_or_equal:starts_on'] : [])],
            'is_open' => ['required', 'boolean'],
        ];
        if ($this->input('_registry_form') === 'year' && ! $this->route('period')) {
            $rules['year_start'] = ['required', 'integer', 'digits:4', 'min:2007', 'max:9998'];
            $rules['year_end'] = ['required', 'integer', 'digits:4', 'max:9999'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['semester_code.unique' => 'This academic year and semester already exist.'];
    }
}
