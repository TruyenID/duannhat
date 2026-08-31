<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Plan 030 — POST /pos/till/sessions.
 *
 * Cashier opens a new shift. Either opened_by_id (the logged-in cashier) OR
 * opener_name (the "Khác…" free-text option) may be sent; if neither is
 * present, the service defaults opened_by_id to Auth::id(). UI may send
 * both when the cashier opens on behalf of another staff member.
 */
class OpenTillSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is enforced by TillSessionPolicy@open in the controller.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'till_code' => ['nullable', 'string', 'max:50'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'opening_counts' => ['required', 'array', 'min:1'],
            'opening_counts.*.denomination_id' => ['required', 'string', 'exists:denominations,id'],
            'opening_counts.*.quantity' => ['required', 'integer', 'min:0'],
            'opening_note' => ['nullable', 'string', 'max:2000'],
            'opened_by_id' => ['nullable', 'string', 'uuid'],
            'opener_name' => ['nullable', 'string', 'max:255'],
            // Plan-044 R2 — gap-payment claim. Ids the cashier confirmed on the
            // shift-open gap panel; open() stamps only the branch-owned, NULL,
            // in-window ones to the new session (cash included — held aside).
            // Invalid ids are skipped, never 422 (R8). The ack records that the
            // held gap cash is NOT in the opening float (accountability only).
            'claimed_gap_payment_ids' => ['nullable', 'array'],
            'claimed_gap_payment_ids.*' => ['string', 'uuid'],
            'gap_cash_held_separately_ack' => ['nullable', 'boolean'],
        ];
    }

    // Intentionally no after-validator: when both opened_by_id and
    // opener_name are omitted (the default "Tôi (đang đăng nhập)" mode
    // on pos-web), TillSessionService::open() falls back to Auth::id()
    // from the SSO bearer token. "Khác" mode sends opener_name explicitly.
}
