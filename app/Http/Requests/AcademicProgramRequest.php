<?php

namespace App\Http\Requests;

use App\Models\AcademicProgram;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AcademicProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        $program = $this->route('academic_program');

        return $program ? Gate::allows('update', $program) : Gate::allows('create', AcademicProgram::class);
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255', Rule::unique('academic_programs')->ignore($this->route('academic_program'))], 'is_active' => ['required', 'boolean']];
    }
}
