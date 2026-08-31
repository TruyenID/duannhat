<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Plan 030 — PATCH /pos/till/sessions/{session}/draft.
 *
 * All fields are optional — the draft endpoint persists whatever the cashier
 * has entered so far (counts and/or tender declared figures).
 */
class TillDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'closing_counts' => ['nullable', 'array'],
            'closing_counts.*.denomination_id' => ['required_with:closing_counts.*', 'string', 'exists:denominations,id'],
            'closing_counts.*.quantity' => ['required_with:closing_counts.*', 'integer', 'min:0'],

            'closing_cash_adjustment' => ['nullable', 'numeric', 'min:0'],

            'tender_details' => ['nullable', 'array'],
            'tender_details.*.tender_key' => ['required_with:tender_details.*', 'string', 'max:50'],
            'tender_details.*.gross_amount' => ['nullable', 'numeric', 'min:0'],
            'tender_details.*.cancel_amount' => ['nullable', 'numeric', 'min:0'],
            'tender_details.*.terminal_batch_total' => ['nullable', 'numeric', 'min:0'],
            'tender_details.*.variance_reason' => ['nullable', 'string', 'max:2000'],

            'closing_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
