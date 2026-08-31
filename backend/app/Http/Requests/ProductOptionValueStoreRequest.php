<?php

/**
 * ProductOptionValue Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\ProductOptionValue\Requests\ProductOptionValueStoreRequestBase;
use Illuminate\Validation\Rule;

/**
 * Validation rules for `POST /hq/{brandSlug}/options/{option}/values`.
 */
class ProductOptionValueStoreRequest extends ProductOptionValueStoreRequestBase
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->schemaRules();

        unset($rules['option_id']);

        $optionId = $this->route('option')?->id ?? $this->route('option');

        $rules['value'] = [
            'required', 'string', 'max:60', 'regex:/^[a-z0-9_-]+$/',
            Rule::unique('product_option_values', 'value')
                ->where('option_id', $optionId)
                ->whereNull('deleted_at'),
        ];
        $rules['label'] = ['required', 'string', 'max:120'];
        $rules['position'] = ['nullable', 'integer', 'min:1'];
        $rules['is_active'] = ['nullable', 'boolean'];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'value.required' => 'The value slug is required.',
            'value.regex' => 'The value slug may only contain lowercase letters, digits, underscores, and hyphens.',
            'value.unique' => 'This slug already exists for the selected option.',
            'label.required' => 'The label is required.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'is_active' => true,
        ]);
    }
}
