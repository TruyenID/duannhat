<?php

/**
 * Apply-Coupon Request — plan-019, endpoint #8.
 *
 * SAFE TO EDIT - Plan-019 only.
 */

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplyCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
            'customer_id' => ['nullable', 'uuid', 'exists:customers,id'],
            // Plan-019 — when true, the service downgrades any
            // exclusive-with-coupons promotion-applied line back to its
            // original unit_price before the coupon runs. FE wires this on
            // a "Use coupon instead of promotion" confirmation CTA.
            'downgrade_exclusive_promotions' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            // Trim + uppercase to match the case-insensitive lookup the
            // service performs (CouponService::apply does its own
            // strtoupper, so this is a UX nicety for echo-back, not
            // load-bearing).
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }
}
