<?php

/**
 * Coupon Preview Request — plan-019, endpoint #10.
 *
 * Read-only validation used by the customer-web checkout debounced
 * preview. Throttled at the route layer (60/min — see plan).
 *
 * SAFE TO EDIT - Plan-019 only.
 */

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CouponPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
            'brand_id' => ['required', 'uuid', 'exists:brands,id'],
            'branch_id' => ['required', 'uuid', 'exists:branches,id'],
            'customer_id' => ['nullable', 'uuid', 'exists:customers,id'],
            'subtotal' => ['required', 'numeric', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }
}
