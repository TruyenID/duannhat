<?php

/**
 * ProductSku Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Omnify\Modules\ProductSku\Requests\ProductSkuStoreRequestBase;
use Illuminate\Validation\Rule;

/**
 * Validation rules for `POST /hq/{brandSlug}/products/{product}/skus`.
 */
class ProductSkuStoreRequest extends ProductSkuStoreRequestBase
{
    use HasOrganizationContext;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->schemaRules();

        unset($rules['product_id'], $rules['option_signature']);

        $organizationId = $this->getOrganizationId();

        $rules['name'] = ['nullable', 'string', 'max:255'];
        $rules['sku'] = ['nullable', 'string', 'max:50'];
        $rules['option_value1_id'] = ['nullable', 'uuid', 'exists:product_option_values,id'];
        $rules['option_value2_id'] = ['nullable', 'uuid', 'exists:product_option_values,id'];
        $rules['option_value3_id'] = ['nullable', 'uuid', 'exists:product_option_values,id'];
        $rules['recipe_id'] = [
            'nullable', 'uuid',
            Rule::exists('recipes', 'id')
                ->where(fn ($q) => $q->where('organization_id', $organizationId)),
        ];
        $rules['recipe_multiplier'] = ['nullable', 'numeric', 'min:0.0001'];
        $rules['cost_price'] = ['nullable', 'numeric', 'min:0'];
        $rules['cost_price_auto'] = ['sometimes', 'numeric', 'min:0'];
        $rules['is_cost_override'] = ['nullable', 'boolean'];
        $rules['selling_price'] = ['nullable', 'numeric', 'min:0'];
        $rules['is_active'] = ['nullable', 'boolean'];

        // Plan-024 — inventory mode is optional on create (defaults to
        // made_to_order at DB level when omitted).
        $rules['inventory_mode'] = ['sometimes', 'string', 'in:made_to_order,track_stock'];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'sku.max' => 'The SKU code may not exceed 50 characters.',
            'recipe_id.exists' => 'The selected recipe does not exist.',
            'recipe_multiplier.min' => 'The recipe multiplier must be at least 0.0001.',
            'cost_price.min' => 'The cost price must be at least 0.',
        ];
    }
}
