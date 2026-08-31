<?php

/**
 * StockTransfer Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ScopesWarehouseToOrganization;
use App\Omnify\Modules\StockTransfer\Requests\StockTransferUpdateRequestBase;

/**
 * StockTransferUpdateRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class StockTransferUpdateRequest extends StockTransferUpdateRequestBase
{
    use ScopesWarehouseToOrganization;

    public function rules(): array
    {
        return [
            'source_warehouse_id' => ['sometimes', 'string', $this->warehouseExistsRule()],
            'destination_warehouse_id' => ['sometimes', 'string', $this->warehouseExistsRule()],
            'note' => ['nullable', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.product_sku_id' => ['nullable', 'string', 'exists:product_skus,id'],
            'items.*.material_id' => ['nullable', 'string', 'exists:materials,id'],
            'items.*.sent_quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.note' => ['nullable', 'string'],
        ];
    }
}
