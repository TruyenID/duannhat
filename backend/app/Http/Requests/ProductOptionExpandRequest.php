<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for `POST /hq/{brandSlug}/products/{product}/options/expand`.
 *
 * Adds a new option (with values) to an existing product and updates
 * existing SKUs to include a default value for the new option.
 */
class ProductOptionExpandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'name' => ['required', 'string', 'max:120'],
            'position' => ['required', 'integer', Rule::in([1, 2, 3])],
            'is_active' => ['nullable', 'boolean'],

            'values' => ['required', 'array', 'min:1'],
            'values.*.value' => ['required', 'string', 'max:60'],
            'values.*.label' => ['required', 'string', 'max:120'],

            'default_value_index' => ['required', 'integer', 'min:0'],
            'generate_combinations' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'key.regex' => 'The option key may only contain lowercase letters, digits, and underscores.',
            'values.required' => 'At least one option value is required.',
            'values.min' => 'At least one option value is required.',
            'default_value_index.required' => 'You must specify which value to assign to existing SKUs.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function after(): array
    {
        return [
            function ($validator) {
                $values = $this->input('values', []);
                $index = $this->input('default_value_index');

                if (is_array($values) && is_int($index) && ($index < 0 || $index >= count($values))) {
                    $validator->errors()->add(
                        'default_value_index',
                        'The default_value_index must be a valid index within the values array.',
                    );
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'is_active' => true,
            'generate_combinations' => true,
        ]);
    }
}
