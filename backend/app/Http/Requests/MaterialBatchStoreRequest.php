<?php

/**
 * MaterialBatch Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ScopesWarehouseToOrganization;
use App\Omnify\Modules\MaterialBatch\Requests\MaterialBatchStoreRequestBase;

/**
 * MaterialBatchStoreRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class MaterialBatchStoreRequest extends MaterialBatchStoreRequestBase
{
    use ScopesWarehouseToOrganization;

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'string', $this->warehouseExistsRule()],
            'material_id' => ['required', 'string', 'exists:materials,id'],
            // Plan-022 T3.5 — recipe_id is optional on the wire so legacy
            // callers (Plan017DemoSeeder, ad-hoc batches) keep working, but
            // MaterialBatchService::resolveRecipeForBatch will reject the
            // request if it can't pick a valid recipe even via fallback.
            'recipe_id' => ['nullable', 'string', 'exists:recipes,id'],
            'multiplier' => ['required', 'numeric', 'gt:0'],
            'planned_yield' => ['required', 'numeric', 'gt:0'],
            'yield_unit' => ['required', 'string', 'max:50'],
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
