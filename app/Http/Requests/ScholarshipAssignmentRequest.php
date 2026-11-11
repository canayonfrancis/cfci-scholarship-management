<?php

namespace App\Http\Requests;

use App\Models\ScholarshipAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ScholarshipAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = $this->route('assignment');

        return $assignment instanceof ScholarshipAssignment
            ? Gate::allows('update', $assignment) : Gate::allows('create', ScholarshipAssignment::class);
    }

    public function rules(): array
    {
        $assignment = $this->route('assignment');
        $closed = in_array($this->input('status'), ['ended', 'relinquished'], true);

        return [
            'program_id' => ['required', 'integer', Rule::exists('scholarship_programs', 'id'), ...($assignment ? [Rule::in([$assignment->program_id])] : [])],
            'start_period_id' => ['required', 'integer', 'exists:academic_periods,id', ...($assignment?->start_period_id !== null && $assignment ? [Rule::in([$assignment->start_period_id])] : [])],
            'status' => ['required', Rule::in(['pending', 'active', 'ended', 'relinquished'])],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'end_period_id' => [Rule::requiredIf($closed), Rule::prohibitedIf(! $closed), 'nullable', 'integer', 'exists:academic_periods,id'],
            'ends_on' => [Rule::prohibitedIf(! $closed), 'nullable', 'date_format:Y-m-d', ...($this->filled('starts_on') ? ['after_or_equal:starts_on'] : [])],
            'status_reason' => [Rule::requiredIf($closed || ($assignment && $this->input('status') !== $assignment->status)), 'nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return ['program_id.in' => 'An assignment retains its original program. Close it and create another assignment to change programs.', 'start_period_id.in' => 'The original starting semester is part of this assignment’s history.'];
    }
}
