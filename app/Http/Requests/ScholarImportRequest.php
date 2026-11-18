<?php

namespace App\Http\Requests;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
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
        $file = $this->file('file');
        $macro = $file instanceof UploadedFile && $file->isValid()
            && (in_array(strtolower($file->getClientOriginalExtension()), ['xlsm', 'xltm'], true)
                || str_contains(strtolower((string) $file->getMimeType()), 'macroenabled'));
        $formatMessage = $macro ? 'Macro-enabled workbooks are not supported. Save the file as an ordinary macro-free Excel Workbook (.xlsx).' : null;

        return [
            'file.max' => 'The upload must not exceed '.config('scholar-imports.max_upload_kb').' KB.',
            'file.extensions' => $formatMessage ?? 'Upload an .xlsx workbook or UTF-8 .csv file.',
            'file.mimetypes' => $formatMessage ?? 'This file is not a supported Excel or CSV file.',
            'file.uploaded' => 'The file could not be uploaded. Check its size and the server upload limits.',
        ];
    }
}
