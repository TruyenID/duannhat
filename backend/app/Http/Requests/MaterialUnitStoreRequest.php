<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MaterialUnitStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'unit' => ['required', 'string', 'max:50'],
            // Stored as decimal(10,4): up to 6 integer + 4 fractional digits.
            // Regex (not `decimal:0,4`) is used because a tiny JSON number like
            // 0.00001 arrives as the float 1.0E-5, which the `decimal` rule
            // mis-parses — the regex rejects both >4 decimals and that
            // scientific-notation form (TC-UNIT-104).
            'ratio' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,6}(\.\d{1,4})?$/'],
            'is_base' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ratio.regex' => 'The ratio may have at most 4 decimal places.',
        ];
    }
}
