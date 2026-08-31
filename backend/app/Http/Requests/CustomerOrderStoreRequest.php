<?php

/**
 * CustomerOrder Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\CustomerOrder\Requests\CustomerOrderStoreRequestBase;

/**
 * CustomerOrderStoreRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class CustomerOrderStoreRequest extends CustomerOrderStoreRequestBase
{
    public function rules(): array
    {
        return [
            'order_type' => ['nullable', 'string', 'in:spot,dine_in,takeaway'],
            'customer_id' => ['nullable', 'uuid', 'exists:customers,id'],
            'table_ids' => ['nullable', 'array'],
            'table_ids.*' => ['uuid', 'exists:tables,id'],
            'guest_count' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string'],
        ];
    }
}
