<?php

namespace App\Http\Requests\HQ;

use App\Omnify\Enums\PaymentConnectionHealthEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters for the HQ connection list.
 *
 * These are validated rather than accepted loosely on purpose: the list used to
 * ignore every query parameter it was given, so `?health=restricted` returned
 * every connection and looked like "no connection is restricted" (#F6). An
 * unknown filter value must now fail loudly instead of silently widening the
 * result set.
 */
class PaymentGatewayConnectionIndexRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:191'],
            'environment' => ['nullable', 'string', Rule::in(['sandbox', 'test', 'live', 'local'])],
            'health' => ['nullable', 'string', Rule::in(array_column(PaymentConnectionHealthEnum::cases(), 'value'))],
            'is_active' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
