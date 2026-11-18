<?php

namespace App\Http\Requests;

use App\Models\ScholarshipProgram;
use App\Services\Eligibility\AcademicGradeComputation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AcademicRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $program = $this->route('program');

        return $program instanceof ScholarshipProgram && Gate::allows('update', $program);
    }

    public function rules(): array
    {
        $confirming = $this->input('action') === 'confirm';
        // "accepted" is implicit: attach it only when confirmation needs this approval.
        // filled() distinguishes an unconfigured blank/null from an explicit zero.
        $failedLimitApprovalRequired = $confirming && $this->filled('max_failed_subjects');
        $rules = [
            'academic_policy_period_id' => ['required', 'integer', 'exists:academic_periods,id'],
            'previous_version' => ['required', 'integer', 'min:0'],
            'action' => ['required', Rule::in(['draft', 'confirm'])],
            'version_reference' => [$confirming ? 'required' : 'nullable', 'string', 'max:100'],
            'source_reference' => [$confirming ? 'required' : 'nullable', 'string', 'max:4000'],
            'minimum_gwa' => [Rule::requiredIf($confirming && $this->boolean('minimum_gwa_required')), 'nullable', 'string', 'max:30', function ($attribute, $value, $fail) {
                if (AcademicGradeComputation::percentage($value) === null) {
                    $fail('Minimum GWA must be a percentage from 0 to 100 written as a decimal.');
                }
            }],
            'max_failed_subjects' => ['nullable', 'integer', 'min:0'],
            'failed_limit_approved' => $failedLimitApprovalRequired ? ['accepted'] : ['nullable', 'boolean'],
            'incomplete_handling' => [$confirming ? 'required' : 'nullable', Rule::in(['review'])],
            'dropped_handling' => [$confirming ? 'required' : 'nullable', Rule::in(['review'])],
            'confirm_authority' => [$confirming ? 'accepted' : 'nullable'],
        ];
        foreach (['minimum_gwa_required', 'require_verified_standing', 'require_current_enrollment', 'require_completed_grades'] as $flag) {
            $rules[$flag] = [$confirming ? 'required' : 'nullable', 'boolean'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'failed_limit_approved.accepted' => 'Confirm the approved failed-subject limit before confirming this academic policy.',
            'failed_limit_approved.boolean' => 'Select or clear the failed-subject limit approval checkbox.',
            'confirm_authority.accepted' => 'Confirm that you reviewed the approved requirements and their applicability before confirming this academic policy.',
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($this->filled('minimum_gwa') && ! $this->boolean('minimum_gwa_required')) {
                $validator->errors()->add('minimum_gwa', 'Explicitly select that a minimum GWA is required before configuring its threshold.');
            }
        }];
    }
}
