<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Plan 030 — POST /pos/till/sessions/{session}/close.
 *
 * Closing counts are required; tender details validated against the active
 * tender_key whitelist resolved server-side (TillSessionService keeps the
 * authoritative list — request layer only checks shape/types).
 */
class CloseTillSessionRequest extends FormRequest
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
            'closing_counts' => ['required', 'array', 'min:1'],
            'closing_counts.*.denomination_id' => ['required', 'string', 'exists:denominations,id'],
            'closing_counts.*.quantity' => ['required', 'integer', 'min:0'],

            // "Tiền lẻ / điều chỉnh" — phần tiền mặt không biểu diễn được bằng
            // mệnh giá (lẻ dưới mệnh giá nhỏ nhất). Cộng vào counted cash.
            'closing_cash_adjustment' => ['nullable', 'numeric', 'min:0'],

            // `present` (not `required`): the client must SEND tender_details, but
            // it may be EMPTY — a cash-only shift has no non-cash tender to declare
            // (pos-web's buildPayload filters out zero-value tenders, so it legitimately
            // posts []). `required` rejects an empty array → "The tender details field
            // is required." on handover/close of a cash-only shift. This matches the
            // workstation settle-accept endpoint, which already allows nullable.
            'tender_details' => ['present', 'array'],
            'tender_details.*.tender_key' => ['required', 'string', 'max:50'],
            'tender_details.*.gross_amount' => ['nullable', 'numeric', 'min:0'],
            'tender_details.*.cancel_amount' => ['nullable', 'numeric', 'min:0'],
            'tender_details.*.terminal_batch_total' => ['nullable', 'numeric', 'min:0'],
            'tender_details.*.variance_reason' => ['nullable', 'string', 'max:2000'],

            'closing_note' => ['nullable', 'string', 'max:2000'],
            'closed_by_id' => ['nullable', 'string', 'uuid'],
        ];
    }
}
