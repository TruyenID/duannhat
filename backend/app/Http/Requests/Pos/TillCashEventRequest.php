<?php

namespace App\Http\Requests\Pos;

use App\Omnify\Enums\TillCashEventTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Plan 030 — POST /pos/till/sessions/{session}/cash-events.
 *
 * v1 UI only exposes paid_in / paid_out; the safe-flow values
 * (loan_from_safe / pickup_to_safe) are accepted at the rules layer for
 * future use but not surfaced in pos-web yet.
 */
class TillCashEventRequest extends FormRequest
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
            'event_type' => ['required', 'string', Rule::in(array_map(
                fn (TillCashEventTypeEnum $e) => $e->value,
                TillCashEventTypeEnum::cases()
            ))],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'reason' => ['required', 'string', 'max:2000'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'occurred_at' => ['nullable', 'date'],
            'performed_by_id' => ['nullable', 'string', 'uuid'],
        ];
    }
}
