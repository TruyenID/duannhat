<?php

namespace App\Http\Requests\HQ;

use Illuminate\Foundation\Http\FormRequest;

class PaymentGatewayConnectionRotateRequest extends FormRequest
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
            'api_secret' => ['required', 'string', 'min:8', 'max:512'],
        ];
    }
}
