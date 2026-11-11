<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ExternalApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('evaluate', $this->route('application'));
    }

    public function rules(): array
    {
        return [
            'previous_approval' => ['required', 'integer', 'min:0'],
            'decision' => ['required', Rule::in(['approved', 'rejected', 'revoked'])],
            'authority' => ['required', 'string', 'max:255', 'regex:/\\S/', ...($this->route('application')->program->expectedApprovalAuthority() ? [Rule::in([$this->route('application')->program->expectedApprovalAuthority()])] : [])],
            'reference' => ['required', 'string', 'max:255'],
            'decided_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Manila')->toDateString()],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', ...($this->filled('valid_from') ? ['after_or_equal:valid_from'] : [])],
            'verified' => ['accepted'],
            'certificate' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:2048'],
            'verification_notes' => ['required', 'string', 'max:3000'],
        ];
    }
}
