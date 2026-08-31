<?php

/**
 * StockTransfer Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ScopesWarehouseToOrganization;
use App\Omnify\Modules\StockTransfer\Requests\StockTransferStoreRequestBase;

/**
 * StockTransferStoreRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class StockTransferStoreRequest extends StockTransferStoreRequestBase
{
    use ScopesWarehouseToOrganization;

    public function rules(): array
    {
        return [
            'source_warehouse_id' => ['required', 'string', $this->warehouseExistsRule()],
            'destination_warehouse_id' => ['required', 'string', $this->warehouseExistsRule(), 'different:source_warehouse_id'],
            'note' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_sku_id' => ['nullable', 'string', 'exists:product_skus,id'],
            'items.*.material_id' => ['nullable', 'string', 'exists:materials,id'],
            'items.*.sent_quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.note' => ['nullable', 'string'],
        ];
    }
}
