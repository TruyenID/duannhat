<?php

namespace App\Http\Requests\Pos;

use App\Omnify\Enums\ForceAbandonReasonCodeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Plan 032 — POST /pos/till/sessions/{session}/force-abandon.
 *
 * Decision 8 dual-field reason: `reason_code` is required (one of 6 categorical
 * values), `reason_detail` is required (min 20 chars) only when reason_code
 * is 'other' — otherwise optional. The CODE feeds dashboard `GROUP BY` queries
 * for pattern detection (e.g. 80% pos_device_failure → real hardware issue),
 * the DETAIL gives narrative for the "other" tail.
 */
class ForceAbandonTillSessionRequest extends FormRequest
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
            'reason_code' => [
                'required',
                'string',
                Rule::in(array_map(fn (ForceAbandonReasonCodeEnum $c) => $c->value, ForceAbandonReasonCodeEnum::cases())),
            ],
            'reason_detail' => [
                Rule::requiredIf(fn () => $this->input('reason_code') === ForceAbandonReasonCodeEnum::Other->value),
                'nullable',
                'string',
                'max:2000',
                Rule::when(
                    $this->input('reason_code') === ForceAbandonReasonCodeEnum::Other->value,
                    ['min:20'],
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason_code.required' => 'A force-abandon reason code is required.',
            'reason_code.in' => 'Invalid reason code. Must be one of the supported categorical reasons.',
            'reason_detail.required' => 'A free-text detail (≥ 20 chars) is required when reason_code is "other".',
            'reason_detail.min' => 'The reason detail must be at least 20 characters when reason_code is "other".',
        ];
    }
}
