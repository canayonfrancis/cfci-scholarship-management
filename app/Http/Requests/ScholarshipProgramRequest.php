<?php

namespace App\Http\Requests;

use App\Models\ScholarshipProgram;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ScholarshipProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        $program = $this->route('program');

        return $program instanceof ScholarshipProgram ? Gate::allows('update', $program) : Gate::allows('create', ScholarshipProgram::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => Str::upper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        $program = $this->route('program');
        $amount = $this->input('school_stated_semester_amount');
        $amountChanged = $program instanceof ScholarshipProgram && (
            blank($amount) ? $program->school_stated_semester_amount !== null
                : (is_scalar($amount) && preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', (string) $amount) && ($program->school_stated_semester_amount === null || ! BigDecimal::of((string) $amount)->isEqualTo($program->school_stated_semester_amount)))
        );

        return [
            'code' => ['bail', 'required', 'string', 'max:30', 'regex:/^[A-Z][A-Z0-9_-]*$/D', ...($program instanceof ScholarshipProgram ? [Rule::in([$program->code])] : []), Rule::unique('scholarship_programs')->ignore($program)],
            'name' => ['required', 'string', 'max:255'],
            'school_stated_semester_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'planning_duration_years' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'source_notes' => [Rule::requiredIf($amountChanged), 'nullable', 'string', 'max:5000'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['code.in' => 'Keep the existing program code to preserve its historical identity.', 'source_notes.required' => 'Record the school source or reason for changing the reference amount.'];
    }
}
