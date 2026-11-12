<?php

namespace App\Http\Requests;

use App\Models\BillingBatch;
use App\Models\ScholarshipProgram;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'batch_number' => ['prohibited'],
            ...self::batchContextRules($program instanceof ScholarshipProgram ? $program : null),
        ];
    }

    public static function batchContextRules(?ScholarshipProgram $program = null): array
    {
        return [
            'batch_program_id' => ['nullable', 'required_with:billing_batch_id', 'integer', 'exists:scholarship_programs,id', ...($program?->exists ? [Rule::in([$program->id])] : [])],
            'academic_period_id' => ['nullable', 'required_with:billing_batch_id', 'integer', 'exists:academic_periods,id'],
            'billing_batch_id' => ['nullable', 'integer', 'exists:billing_batches,id'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('billing_batch_id')) {
                return;
            }
            $batch = BillingBatch::find($this->input('billing_batch_id'));
            if (! $batch || $batch->program_id !== (int) $this->input('batch_program_id') || $batch->academic_period_id !== (int) $this->input('academic_period_id')) {
                $validator->errors()->add('billing_batch_id', 'Select an existing billing batch matching the reference program and academic year / semester.');

                return;
            }
            Gate::authorize('view', $batch);
        }];
    }

    public function messages(): array
    {
        return [
            'code.in' => 'Keep the existing program code to preserve its historical identity.',
            'source_notes.required' => 'Record the school source or reason for changing the reference amount.',
            'batch_number.prohibited' => 'Manage semester batches in Billing; Batch Number is not a permanent program field.',
            'batch_program_id.required_with' => 'Select the existing program that owns this reference billing batch.',
            'batch_program_id.in' => 'Select a reference batch belonging to the program being edited.',
            'batch_program_id.exists' => 'Select a registered program for the reference billing batch.',
            'academic_period_id.required_with' => 'Select the academic year and semester of this reference billing batch.',
            'academic_period_id.exists' => 'Select a registered academic year and semester.',
            'billing_batch_id.exists' => 'Select an existing billing batch from the dropdown.',
        ];
    }
}
