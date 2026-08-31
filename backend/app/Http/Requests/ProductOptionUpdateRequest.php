<?php

/**
 * ProductOption Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\ProductOption\Requests\ProductOptionUpdateRequestBase;
use Illuminate\Validation\Rule;

/**
 * Validation rules for `PUT /hq/{brandSlug}/options/{option}`.
 *
 * Note: even though `key` and `position` are accepted here, the service layer
 * rejects them when any SKU references this option. Validation accepts the
 * input shape; the business invariant is enforced server-side.
 */
class ProductOptionUpdateRequest extends ProductOptionUpdateRequestBase
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->schemaRules();

        unset($rules['product_id']);

        $rules['key'] = ['sometimes', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'];
        $rules['name'] = ['sometimes', 'string', 'max:120'];
        $rules['position'] = ['sometimes', 'integer', Rule::in([1, 2, 3])];
        $rules['is_active'] = ['sometimes', 'boolean'];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'key.regex' => 'The option key may only contain lowercase letters, digits, and underscores.',
            'position.in' => 'The position must be 1, 2, or 3.',
        ];
    }
}
