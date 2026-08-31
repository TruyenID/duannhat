<?php

/**
 * StockCount Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ScopesWarehouseToOrganization;
use App\Omnify\Modules\StockCount\Requests\StockCountStoreRequestBase;

/**
 * StockCountStoreRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class StockCountStoreRequest extends StockCountStoreRequestBase
{
    use ScopesWarehouseToOrganization;

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'string', $this->warehouseExistsRule()],
            'scope' => ['required', 'string', 'in:full,partial'],
            'note' => ['nullable', 'string'],
        ];
    }
}
