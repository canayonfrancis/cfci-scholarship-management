<?php

namespace App\Http\Requests;

use App\Models\Student;
use App\Services\Records\ScholarProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ScholarRequest extends FormRequest
{
    public function authorize(): bool
    {
        $scholar = $this->route('scholar');

        return $scholar instanceof Student && Gate::allows('update', $scholar);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('student_number'))) {
            $this->merge(['student_number' => Str::upper(trim($this->input('student_number')))]);
        }
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
        if (is_string($this->input('phone')) && ($phone = ScholarProfile::normalizePhone($this->input('phone'))) !== null) {
            $this->merge(['phone' => $phone]);
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
            'landbank_account_number' => [...ScholarProfile::bankAccountRules(), Rule::prohibitedIf(fn () => ! Gate::allows('manageBankAccount', $this->route('scholar')))],
            'clear_landbank_account_number' => ['nullable', 'boolean', Rule::prohibitedIf(fn () => ! Gate::allows('manageBankAccount', $this->route('scholar')) || $this->filled('landbank_account_number'))],
            'phone' => ['nullable', 'string', 'max:40', function ($attribute, $value, $fail) {
                if (! is_string($value)) {
                    return;
                }
                // An unchanged legacy value is retained for review, never silently discarded.
                if (ScholarProfile::normalizePhone($value) === null && $value !== $this->route('scholar')?->phone) {
                    $fail('Enter a Philippine mobile number (10 digits starting with 9) or an 8-digit landline.');
                }
            }],
            'record_status' => ['required', Rule::in(['active', 'archived'])],
            'lrn' => ['nullable', 'string', 'regex:/^(?:0|[0-9]{12})$/D'],
            'philsys_id' => ['nullable', 'string', 'regex:/^[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{4}$/D'],
            'four_ps_id' => ['nullable', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:30'],
            'sex' => ['nullable', 'integer', Rule::in([0, 1])],
            'birthdate' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Manila')->toDateString()],
            'academic_program_id' => ['nullable', 'integer', Rule::exists('academic_programs', 'id')->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $this->route('scholar')?->academic_program_id))],
            'year_level' => ['nullable', 'integer', Rule::in(array_keys(ScholarProfile::yearLevels()))],
            'academic_period_id' => ['nullable', 'integer', 'exists:academic_periods,id'],
            'father_last_name' => ['nullable', 'string', 'max:255'],
            'father_first_name' => ['nullable', 'string', 'max:255'],
            'father_middle_name' => ['nullable', 'string', 'max:255'],
            'mother_maiden_last_name' => ['nullable', 'string', 'max:255'],
            'mother_maiden_first_name' => ['nullable', 'string', 'max:255'],
            'mother_maiden_middle_name' => ['nullable', 'string', 'max:255'],
            'street_barangay' => ['nullable', 'string', 'max:255'],
            'city_municipality' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'zip_code' => ['nullable', 'string', 'max:20'],
            'disability_type' => ['nullable', Rule::in(array_keys(ScholarProfile::DISABILITIES))],
            'solo_parent_dependent' => ['nullable', 'boolean'],
            'first_generation_student' => ['nullable', 'boolean'],
            'indigenous_people_group' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'lrn.regex' => 'LRN must contain exactly 12 digits, or 0 if unavailable.',
            'philsys_id.regex' => 'Use the National ID format XXXX-XXXX-XXXX-XXXX (digits only).',
            'landbank_account_number.regex' => 'LANDBANK Savings Account Number must contain digits only, including any leading zeros.',
            'clear_landbank_account_number.prohibited' => 'Choose either a replacement account number or removal of the saved number, not both.',
        ];
    }
}
