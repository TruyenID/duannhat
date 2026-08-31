<?php

/**
 * ProductSku Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Omnify\Modules\ProductSku\Requests\ProductSkuUpdateRequestBase;
use Illuminate\Validation\Rule;

/**
 * Validation rules for `PUT /hq/{brandSlug}/products/{product}/skus/{sku}`.
 *
 * `option_value{N}_id` is intentionally NOT accepted on update — once a SKU
 * exists its option combination is immutable. To change the combination the
 * client must delete the SKU and create a new one (preserves the
 * (product_id, option_signature) unique invariant).
 */
class ProductSkuUpdateRequest extends ProductSkuUpdateRequestBase
{
    use HasOrganizationContext;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->schemaRules();

        // Block direct edits to the option-value FKs and any derived fields.
        unset(
            $rules['product_id'],
            $rules['option_signature'],
            $rules['option_value1_id'],
            $rules['option_value2_id'],
            $rules['option_value3_id'],
        );

        $organizationId = $this->getOrganizationId();

        $rules['name'] = ['sometimes', 'nullable', 'string', 'max:255'];
        $rules['sku'] = ['sometimes', 'nullable', 'string', 'max:50'];
        $rules['recipe_id'] = [
            'sometimes', 'nullable', 'uuid',
            Rule::exists('recipes', 'id')
                ->where(fn ($q) => $q->where('organization_id', $organizationId)),
        ];
        $rules['recipe_multiplier'] = ['sometimes', 'numeric', 'min:0.0001'];
        $rules['cost_price'] = ['sometimes', 'numeric', 'min:0'];
        $rules['is_cost_override'] = ['sometimes', 'boolean'];
        $rules['selling_price'] = ['sometimes', 'numeric', 'min:0'];
        $rules['is_active'] = ['sometimes', 'boolean'];

        // Plan-024 — gate order-close stock-out + recipe-based material
        // deduction. Tightened from the generated base rule (which is
        // just `string`) to the actual enum values.
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
