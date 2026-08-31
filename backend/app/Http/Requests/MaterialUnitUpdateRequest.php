<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MaterialUnitUpdateRequest extends FormRequest
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
            'unit' => ['sometimes', 'string', 'max:50'],
            // See MaterialUnitStoreRequest — regex rejects >4 decimals and the
            // scientific-notation form of tiny floats (TC-UNIT-104).
            'ratio' => ['sometimes', 'numeric', 'gt:0', 'regex:/^\d{1,6}(\.\d{1,4})?$/'],
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
