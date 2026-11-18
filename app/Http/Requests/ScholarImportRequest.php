<?php

namespace App\Http\Requests;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ScholarImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Student::class);
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:xlsx,csv', 'max:'.config('scholar-imports.max_upload_kb'),
                'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream,text/plain,text/csv,application/csv',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.max' => 'The upload must not exceed '.config('scholar-imports.max_upload_kb').' KB.',
            'file.extensions' => 'Upload an .xlsx workbook or UTF-8 .csv file.',
            'file.mimetypes' => 'This file is not a supported Excel or CSV file.',
            'file.uploaded' => 'The file could not be uploaded. Check its size and the server upload limits.',
        ];
    }
}
