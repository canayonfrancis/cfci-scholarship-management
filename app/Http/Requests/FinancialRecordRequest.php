<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class FinancialRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $method = $this->route()->getActionMethod();
        $ability = in_array($method, ['postReceipt', 'postPayout', 'deduct', 'settle', 'reviewLiquidation', 'reviewEvidence'], true)
            ? 'financial-verify' : ($method === 'uploadEvidence' ? 'financial-evidence' : 'financial-prepare');

        return Gate::allows($ability);
    }

    public function rules(): array
    {
        $amount = ['required', 'numeric', 'gt:0', 'max:9999999999.99', 'regex:/^[0-9]+(?:\.[0-9]{1,2})?$/D'];
        $text = ['required', 'string', 'max:255'];
        $date = ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Manila')->toDateString()];

        return match ($this->route()->getActionMethod()) {
            'createReceipt' => ['program_period_term_id' => ['required', 'integer', 'exists:program_period_terms,id'], 'amount' => $amount, 'received_on' => $date, 'source' => $text, 'reference' => $text],
            'allocate' => ['funding_receipt_id' => ['required', 'integer', 'exists:funding_receipts,id'], 'amount' => $amount, 'operation_key' => ['required', 'uuid']],
            'createPayout' => ['billing_item_id' => ['required', 'integer', 'exists:billing_items,id'], 'gross_amount' => $amount, 'net_amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'regex:/^[0-9]+(?:\.[0-9]{1,2})?$/D'], 'paid_on' => $date, 'payment_mode' => ['required', Rule::in(['school', 'direct'])], 'method' => ['required', Rule::in(['cash', 'bank_transfer', 'check', 'e_wallet', 'other'])], 'reference' => $text],
            'deduct' => ['type' => ['required', 'string', 'max:60'], 'amount' => $amount, 'basis' => ['required', 'string', 'max:4000'], 'authority_reference' => $text, 'consent_reference' => $text, 'destination' => $text, 'permitted_by_program' => ['accepted'], 'authority_evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:2048']],
            'settle' => ['settled_on' => $date, 'settlement_reference' => $text],
            'prepareLiquidation' => ['reference' => $text],
            'reviewLiquidation' => ['decision' => ['required', Rule::in(['accepted', 'returned'])], 'review_notes' => ['required', 'string', 'max:4000']],
            'uploadEvidence' => ['document_type' => ['required', Rule::in(['receipt_evidence', 'payment_evidence', 'deduction_authority', 'deduction_settlement', 'liquidation_support'])], 'evidence' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:2048']],
            'reviewEvidence' => ['decision' => ['required', Rule::in(['accepted', 'rejected'])], 'review_notes' => ['required', 'string', 'max:4000']],
            default => [],
        };
    }
}
