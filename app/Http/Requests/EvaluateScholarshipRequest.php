<?php

namespace App\Http\Requests;

use App\Services\Eligibility\OfficialRuleCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EvaluateScholarshipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('evaluate', $this->route('application'));
    }

    public function rules(): array
    {
        $application = $this->route('application');
        $catalog = app(OfficialRuleCatalog::class);
        $policy = $catalog->forProgram($application->program->code, $application->terms);
        $institutional = ($policy['mode'] ?? null) === OfficialRuleCatalog::INSTITUTIONAL_MODE;
        if (OfficialRuleCatalog::demonstrationProgram($application->program->code) || $institutional) {
            return [
                'submission_revision' => ['required', 'integer', 'min:1'],
                'previous_run' => ['required', 'integer', 'min:0'],
                'school_decision' => ['required', Rule::in(['qualified', 'not_qualified', 'needs_information'])],
                'certification_reference' => ['required', 'string', 'max:1000', 'regex:/\S/', ...($institutional ? [] : ['starts_with:UAT-'])],
                'certification_verified' => ['accepted'],
                'evaluator_notes' => ['required', 'string', 'max:3000', 'regex:/\S/'],
            ];
        }
        if (! $policy || ! $catalog->appliesTo($application->academicPeriod)) {
            return [
                'submission_revision' => ['required', 'integer', 'min:1'], 'previous_run' => ['required', 'integer', 'min:0'],
                'evaluator_notes' => ['nullable', 'string', 'max:3000'],
            ];
        }

        return [
            'submission_revision' => ['required', 'integer', 'min:1'],
            'previous_run' => ['required', 'integer', 'min:0'],
            'applicant_type' => ['required', Rule::in(['new', 'continuing'])],
            'facts' => ['nullable', 'array:'.implode(',', array_keys(OfficialRuleCatalog::FACTS))],
            'facts.*' => ['nullable', Rule::in(['yes', 'no', 'unverified'])],
            'fact_sources' => ['nullable', 'array:'.implode(',', array_keys(OfficialRuleCatalog::FACTS))],
            'fact_sources.*' => ['nullable', 'string', 'max:1000'],
            'returning' => ['nullable', Rule::in(['yes', 'no', 'unverified'])],
            'transferee' => ['nullable', Rule::in(['yes', 'no', 'unverified'])],
            ...($application->program->code === 'TES' ? [
                'legacy_pnsl_transfer' => ['nullable', Rule::in(['yes', 'no', 'unverified'])],
                'claiming_pwd' => ['nullable', Rule::in(['yes', 'no', 'unverified'])],
            ] : []),
            ...($application->program->code === 'TDP' ? ['smart_same_semester' => ['nullable', Rule::in(['yes', 'no', 'unverified'])]] : []),
            'special_case' => ['nullable', Rule::in(['none', 'pnsl', 'sardo', 'other', 'unverified'])],
            'duration_review' => ['nullable', Rule::in(['within_limit', 'approved_exception', 'exceeded', 'unverified'])],
            'duration_basis' => ['nullable', 'string', 'max:3000'],
            'exceptions_reviewed' => ['nullable', 'boolean'],
            'evaluator_notes' => ['nullable', 'string', 'max:3000'],
        ];
    }
}
