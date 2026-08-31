<?php

/**
 * StockTransaction Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\StockTransaction\Requests\StockTransactionUpdateRequestBase;

/**
 * StockTransactionUpdateRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class StockTransactionUpdateRequest extends StockTransactionUpdateRequestBase
{
    public function rules(): array
    {
        return [
            'reference_type' => ['nullable', 'string', 'max:50'],
            'reference_id' => ['nullable', 'string', 'uuid'],
            'note' => ['nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.product_sku_id' => ['nullable', 'string', 'exists:product_skus,id'],
            'items.*.material_id' => ['nullable', 'string', 'exists:materials,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.note' => ['nullable', 'string'],
        ];
    }
}
