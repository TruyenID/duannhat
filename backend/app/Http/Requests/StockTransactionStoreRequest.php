<?php

/**
 * StockTransaction Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ScopesWarehouseToOrganization;
use App\Omnify\Enums\StockTransactionSubTypeEnum;
use App\Omnify\Enums\StockTransactionTypeEnum;
use App\Omnify\Modules\StockTransaction\Requests\StockTransactionStoreRequestBase;
use Illuminate\Validation\Rule;

/**
 * StockTransactionStoreRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class StockTransactionStoreRequest extends StockTransactionStoreRequestBase
{
    use ScopesWarehouseToOrganization;

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'string', $this->warehouseExistsRule()],
            'type' => ['required', 'string', Rule::in(StockTransactionTypeEnum::values())],
            'sub_type' => ['required', 'string', Rule::in(StockTransactionSubTypeEnum::values())],
            'reference_type' => ['nullable', 'string', 'max:50'],
            'reference_id' => ['nullable', 'string', 'uuid'],
            'note' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_sku_id' => ['nullable', 'string', 'exists:product_skus,id'],
            'items.*.material_id' => ['nullable', 'string', 'exists:materials,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit' => ['nullable', 'string', 'max:50'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.note' => ['nullable', 'string'],
        ];
    }
}
