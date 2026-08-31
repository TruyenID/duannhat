<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OrderCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        // BR-SOS05: tax_amount + service_charge are no longer client-supplied —
        // CustomerOrderService::checkout reads them from the branch's
        // ShopOrderSetting (tax_rate + service_charge_rate) at checkout
        // time. Only `discount_amount` remains a per-checkout input.
        return [
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            // #1124 — required whenever discount_amount > 0; requiredness is
            // enforced in the DOMAIN writer (guard-by-domain) so every route
            // shares it. Shape-only here.
            'discount_reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
