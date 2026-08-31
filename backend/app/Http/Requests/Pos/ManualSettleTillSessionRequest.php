<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Plan 032 — POST /pos/till/sessions/{session}/manual-settle.
 *
 * Manager reconcile of an expired session. Body shape mirrors CloseTillSessionRequest
 * (same closing_counts + tender_details rules) plus three plan-032 fields:
 *
 *   - manual_settle_reason: required free text (≥ 20 chars). Audit log.
 *   - opening_counts_override: OPTIONAL. When supplied, replaces the session's
 *     recorded opening counts and emits a separate `till_session_opening_overridden`
 *     audit row with before+after payloads (Decision 8b).
 *   - post_hoc_cash_events: OPTIONAL. Cash events the cashier never logged
 *     before disappearing — each inserted with `manual_adjustment=true`
 *     and recorded_by=manager.id.
 */
class ManualSettleTillSessionRequest extends FormRequest
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
            // Inherits close()'s closing-counts + tender-details shape.
            'closing_counts' => ['required', 'array', 'min:1'],
            'closing_counts.*.denomination_id' => ['required', 'string', 'exists:denominations,id'],
            'closing_counts.*.quantity' => ['required', 'integer', 'min:0'],

            'tender_details' => ['present', 'array'],
            'tender_details.*.tender_key' => ['required', 'string', 'max:50'],
            'tender_details.*.gross_amount' => ['nullable', 'numeric', 'min:0'],
            'tender_details.*.cancel_amount' => ['nullable', 'numeric', 'min:0'],
            'tender_details.*.terminal_batch_total' => ['nullable', 'numeric', 'min:0'],
            'tender_details.*.variance_reason' => ['nullable', 'string', 'max:2000'],

            'closing_note' => ['nullable', 'string', 'max:2000'],

            // Plan-032 — manual-settle required.
            'manual_settle_reason' => ['required', 'string', 'min:20', 'max:2000'],

            // Plan-032 Decision 8b — optional override of opening counts. When
            // the array is supplied, each entry MUST have denomination_id +
            // quantity (Laravel applies the inner rules per item).
            'opening_counts_override' => ['nullable', 'array'],
            'opening_counts_override.*.denomination_id' => ['required', 'string', 'exists:denominations,id'],
            'opening_counts_override.*.quantity' => ['required', 'integer', 'min:0'],

            // Plan-032 Decision 8b — optional post-hoc cash events the cashier never logged.
            'post_hoc_cash_events' => ['nullable', 'array'],
            'post_hoc_cash_events.*.event_type' => ['required', 'string', 'max:50'],
            'post_hoc_cash_events.*.amount' => ['required', 'numeric', 'min:0'],
            'post_hoc_cash_events.*.currency_code' => ['nullable', 'string', 'size:3'],
            'post_hoc_cash_events.*.reason' => ['nullable', 'string', 'max:500'],
            'post_hoc_cash_events.*.occurred_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'manual_settle_reason.required' => 'A manual-settle reason is required (≥ 20 characters) for audit.',
            'manual_settle_reason.min' => 'The manual-settle reason must be at least 20 characters.',
        ];
    }
}
