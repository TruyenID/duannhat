<?php

/**
 * ProductOptionValue Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\ProductOptionValue\Requests\ProductOptionValueUpdateRequestBase;
use App\Services\Product\ProductOptionValueService;

/**
 * Validation rules for `PUT /hq/{brandSlug}/option-values/{value}`.
 *
 * Note: `label` is always editable; `value` (slug) is only editable while no
 * SKU references it. The slug invariant is enforced server-side in
 * {@see ProductOptionValueService::update()}.
 */
class ProductOptionValueUpdateRequest extends ProductOptionValueUpdateRequestBase
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->schemaRules();

        unset($rules['option_id']);

        $rules['value'] = ['sometimes', 'string', 'max:60', 'regex:/^[a-z0-9_-]+$/'];
        $rules['label'] = ['sometimes', 'string', 'max:120'];
        $rules['position'] = ['sometimes', 'integer', 'min:1'];
        $rules['is_active'] = ['sometimes', 'boolean'];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'value.regex' => 'スラッグは小文字の英数字、アンダースコア、ハイフンのみ使用できます。',
        ];
    }
}
