<?php

namespace App\Http\Requests;

use App\Models\AcademicRecord;
use App\Models\AcademicRecordGrade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AcademicVerificationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->boolean('grades_present') && ! $this->has('grades')) {
            $this->merge(['grades' => []]);
        }
        if (is_array($this->input('grades'))) {
            $rows = [];
            foreach ($this->input('grades') as $row) {
                if (is_array($row)) {
                    foreach ($row as $key => $value) {
                        if (is_scalar($value) && ! is_bool($value)) {
                            $row[$key] = trim((string) $value);
                        }
                    }
                    if (! array_filter($row, fn ($value) => $value !== null && $value !== '')) {
                        continue;
                    }
                    if (is_string($row['subject_code'] ?? null)) {
                        $row['subject_code'] = mb_strtoupper($row['subject_code']);
                    }
                }
                $rows[] = $row;
            }
            $this->merge(['grades' => $rows]);
        }
    }

    public function authorize(): bool
    {
        $record = $this->route('verification');
        if (! $record instanceof AcademicRecord) {
            return Gate::allows('create', AcademicRecord::class);
        }

        return Gate::allows($this->routeIs('academic-verifications.correct') ? 'correct' : 'update', $record);
    }

    public function rules(): array
    {
        $record = $this->route('verification');
        $rules = [
            'student_id' => [$record ? 'prohibited' : 'required', 'integer', 'exists:students,id'],
            'academic_period_id' => [$record ? 'prohibited' : 'required', 'integer', 'exists:academic_periods,id'],
            'lock_version' => [$record ? 'required' : 'prohibited', 'integer', 'min:1'],
            'action' => ['required', Rule::in(['draft', 'verify'])],
            'course' => ['nullable', 'string', 'max:255'],
            'year_level' => ['nullable', 'string', 'max:30'],
            'enrollment_status' => ['required', Rule::in(['unverified', 'enrolled', 'not_enrolled'])],
            'academic_standing' => ['required', Rule::in(['unverified', 'passing', 'failing'])],
            'grade_average' => ['prohibited'],
            'grades' => ['sometimes', 'array', 'max:60'],
            'grades.*' => ['array:subject_code,subject_name,units,prelim,midterm,semi_final,final,average,equivalent,remarks'],
            'grades.*.subject_code' => ['nullable', 'string', 'max:60', 'distinct:ignore_case'],
            'grades.*.subject_name' => ['required', 'string', 'max:255'],
            'grades.*.remarks' => ['nullable', 'string', 'max:2000'],
            'source_reference' => [$this->input('action') === 'verify' ? 'required' : 'nullable', 'string', 'max:4000'],
            'verification_notes' => ['nullable', 'string', 'max:4000'],
            'correction_reason' => [$this->routeIs('academic-verifications.correct') ? 'required' : 'prohibited', 'string', 'max:4000'],
            'certificates' => ['nullable', 'array', 'max:3'],
            'certificates.*' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:2048'],
        ];
        foreach (array_keys(AcademicRecordGrade::COLUMNS) as $column) {
            $rules['grades.*.'.$column] = ['nullable', 'string', 'max:30', $column === 'units' ? 'regex:/^\d+(?:\.\d+)?$/' : 'regex:/^[+-]?\d+(?:\.\d+)?$/'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->input('action') === 'verify' && $this->input('enrollment_status') === 'unverified' && $this->input('academic_standing') === 'unverified') {
                $validator->errors()->add('action', 'Confirm enrollment or academic standing before marking a record verified.');
            }
        }];
    }

    public function messages(): array
    {
        return ['grade_average.prohibited' => 'Copy reported grades into subject rows. The system does not calculate a semester average or convert grades.'];
    }
}
