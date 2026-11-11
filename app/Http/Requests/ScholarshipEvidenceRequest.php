<?php

namespace App\Http\Requests;

use App\Services\Eligibility\OfficialRuleCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ScholarshipEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('evaluate', $this->route('application')) && $this->route('application')->program->code !== 'EDUKAR';
    }

    public function rules(): array
    {
        return [
            'submission_revision' => ['required', 'integer', 'min:1'],
            'document_type' => ['required', Rule::in(array_keys(app(OfficialRuleCatalog::class)->validationDocuments()))],
            'certificate' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:2048'],
            'verified' => ['nullable', 'boolean'],
        ];
    }
}
