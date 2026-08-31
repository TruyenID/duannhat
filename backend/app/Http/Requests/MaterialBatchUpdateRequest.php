<?php

/**
 * MaterialBatch Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\MaterialBatch\Requests\MaterialBatchUpdateRequestBase;

/**
 * MaterialBatchUpdateRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class MaterialBatchUpdateRequest extends MaterialBatchUpdateRequestBase
{
    public function rules(): array
    {
        return [
            'multiplier' => ['sometimes', 'numeric', 'gt:0'],
            'planned_yield' => ['sometimes', 'numeric', 'gt:0'],
            'yield_unit' => ['sometimes', 'string', 'max:50'],
            'expiry_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
            'items' => ['sometimes', 'array'],
            'items.*.component_type' => ['required', 'string', 'in:material,variant'],
            'items.*.product_sku_id' => ['nullable', 'string', 'exists:product_skus,id'],
            'items.*.material_id' => ['nullable', 'string', 'exists:materials,id'],
            'items.*.planned_quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
        ];
    }
}
