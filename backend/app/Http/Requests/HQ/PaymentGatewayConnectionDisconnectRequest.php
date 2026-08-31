<?php

namespace App\Http\Requests\HQ;

use Illuminate\Foundation\Http\FormRequest;

class PaymentGatewayConnectionDisconnectRequest extends FormRequest
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
            'confirm' => ['sometimes', 'boolean'],
            'acknowledge_shop_impact' => ['sometimes', 'boolean'],
        ];
    }
}
