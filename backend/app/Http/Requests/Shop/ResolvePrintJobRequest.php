<?php

namespace App\Http\Requests\Shop;

use App\Services\Printing\Enums\PrintJobResolutionKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * plan-052 M2 / T2.2 — "a person dealt with this job".
 *
 * `reason` is REQUIRED, and required means required: the string is trimmed
 * before validation so a space bar cannot satisfy an audit field. A resolution
 * without a why is a checkbox, and a checkbox tells the next person nothing
 * about a receipt that never came out.
 *
 * There is no `reprint` value (see {@see PrintJobResolutionKind}). Reprinting a
 * money document goes through the reprint gate so it earns 「Bản in #N」 and an
 * actor — RISKS PR1.
 */
class ResolvePrintJobRequest extends FormRequest
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
            'resolution' => ['required', 'string', Rule::in(PrintJobResolutionKind::values())],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');

        if (is_string($reason)) {
            // Whitespace-only collapses to '' and then fails `required` — the
            // 422 is the point, not a silently stored blank.
            $this->merge(['reason' => trim($reason)]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'RESOLUTION_REASON_REQUIRED: a print-job resolution must say why (plan-052 T2.2).',
            'reason.min' => 'RESOLUTION_REASON_REQUIRED: a print-job resolution must say why (plan-052 T2.2).',
            'resolution.in' => 'RESOLUTION_INVALID: resolution must be printed_by_hand or discarded — reprinting a money document goes through the reprint gate (P-10).',
        ];
    }
}
