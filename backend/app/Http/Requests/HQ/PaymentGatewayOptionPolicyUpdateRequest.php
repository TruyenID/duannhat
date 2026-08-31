<?php

namespace App\Http\Requests\HQ;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentGatewayOptionPolicyUpdateRequest extends FormRequest
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
            'preference' => ['required', 'string', Rule::in(['enabled', 'disabled', 'blocked'])],
            'change_reason' => ['nullable', 'string', 'max:500'],
            'version' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
