<?php

/**
 * ProductOption Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\ProductOption\Requests\ProductOptionStoreRequestBase;
use App\Services\Product\ProductOptionService;
use Illuminate\Validation\Rule;

/**
 * Validation rules for `POST /hq/{brandSlug}/products/{product}/options`.
 *
 * Position must be 1, 2, or 3 — additional uniqueness within the product is
 * enforced in {@see ProductOptionService::create()}.
 */
class ProductOptionStoreRequest extends ProductOptionStoreRequestBase
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->schemaRules();

        unset($rules['product_id']);

        $rules['key'] = ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'];
        $rules['name'] = ['required', 'string', 'max:120'];
        $rules['position'] = ['required', 'integer', Rule::in([1, 2, 3])];
        $rules['is_active'] = ['nullable', 'boolean'];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'key.required' => 'The option key is required.',
            'key.regex' => 'The option key may only contain lowercase letters, digits, and underscores.',
            'name.required' => 'The option name is required.',
            'position.required' => 'The position field is required.',
            'position.in' => 'The position must be 1, 2, or 3.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'is_active' => true,
        ]);
    }
}
