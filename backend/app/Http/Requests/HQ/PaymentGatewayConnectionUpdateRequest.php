<?php

namespace App\Http\Requests\HQ;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentGatewayConnectionUpdateRequest extends FormRequest
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
            'merchant_store_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'merchant_terminal_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'merchant_display_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'charge_model' => ['sometimes', 'string', Rule::in(['direct', 'destination', 'separate_charges_and_transfers', 'provider_native'])],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
