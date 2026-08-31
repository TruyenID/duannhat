<?php

/**
 * ProductionOrder Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ScopesWarehouseToOrganization;
use App\Omnify\Modules\ProductionOrder\Requests\ProductionOrderStoreRequestBase;

/**
 * ProductionOrderStoreRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class ProductionOrderStoreRequest extends ProductionOrderStoreRequestBase
{
    use ScopesWarehouseToOrganization;

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'string', $this->warehouseExistsRule()],
            'output_variant_id' => ['required', 'string', 'exists:product_skus,id'],
            'planned_quantity' => ['required', 'numeric', 'gt:0'],
            'output_unit' => ['required', 'string', 'max:50'],
            // recipe_multiplier lives on ProductSku — caller may override per
            // batch but the service falls back to sku.recipe_multiplier (and
            // 1.0 in turn) when omitted, matching the existing FE behaviour
            // at /shop/.../production/orders/new which only sends warehouse,
            // output_variant, planned_quantity, and output_unit.
            'recipe_multiplier' => ['sometimes', 'numeric', 'gt:0'],
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
