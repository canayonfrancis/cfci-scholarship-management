<?php

namespace App\Http\Requests;

use App\Models\AcademicRecord;
use App\Models\AcademicRecordGrade;
use App\Services\Eligibility\AcademicGradeComputation;
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
                    $content = array_diff_key($row, array_flip(['grade_id', 'reported_status']));
                    if (empty($row['grade_id']) && in_array($row['reported_status'] ?? null, [null, '', 'regular'], true)
                        && ! array_filter($content, fn ($value) => $value !== null && $value !== '')) {
                        continue;
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
            'certified_percentage_gwa' => ['nullable', 'string', 'max:30', function ($attribute, $value, $fail) {
                if (AcademicGradeComputation::percentage($value) === null) {
                    $fail('Registrar-certified GWA must be a percentage from 0 to 100.');
                }
            }],
            'certified_gwa_reference' => [$this->input('action') === 'verify' && $this->filled('certified_percentage_gwa') ? 'required' : 'nullable', 'string', 'max:4000'],
            'certified_gwa_confirmed' => [$this->input('action') === 'verify' && $this->filled('certified_percentage_gwa') ? 'accepted' : 'nullable'],
            'computed_gwa' => ['prohibited'],
            'grades' => ['sometimes', 'array', 'max:60'],
            'grades.*' => ['array:grade_id,subject_code,subject_name,units,prelim,midterm,semi_final,final,average,equivalent,remarks,reported_status'],
            'grades.*.grade_id' => ['nullable', ...($record ? [] : ['prohibited']), 'integer', 'distinct', ...($record ? [Rule::exists('academic_record_grades', 'id')->where('academic_record_id', $record->id)] : [])],
            'grades.*.reported_status' => ['nullable', Rule::in(['regular', 'inc', 'dropped', 'unverified'])],
            'grades.*.equivalent' => ['prohibited'],
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
            if ($column === 'equivalent') {
                continue;
            }
            $rules['grades.*.'.$column] = ['bail', 'nullable', 'string', 'max:30', function ($attribute, $value, $fail) use ($column) {
                if ($column === 'units') {
                    if (AcademicGradeComputation::number($value) === null) {
                        $fail('Units must be a nonnegative decimal; leave unavailable units blank.');
                    }
                } elseif (! in_array(strtoupper($value), ['INC', 'INCOMPLETE', 'DRP', 'DROPPED'], true) && AcademicGradeComputation::percentage($value) === null) {
                    $fail('Grades must be percentage decimals from 0 to 100, or reported INC/DRP marks. Leave missing values blank.');
                }
            }];
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
        return [
            'grade_average.prohibited' => 'Legacy semester averages cannot be edited here. Use subject percentage components and the separate Registrar-certified percentage GWA field.',
            'grades.*.equivalent.prohibited' => 'Equivalent grades are historical only and cannot be submitted or used in calculations.',
            'computed_gwa.prohibited' => 'Computed GWA is calculated on the server and cannot be submitted.',
        ];
    }
}
