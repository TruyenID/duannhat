<?php

/**
 * Warehouse Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\Warehouse\Requests\WarehouseUpdateRequestBase;

/**
 * WarehouseUpdateRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class WarehouseUpdateRequest extends WarehouseUpdateRequestBase
{
    public function rules(): array
    {
        $rules = $this->schemaRules();

        // organization_id and code cannot be changed
        unset($rules['organization_id'], $rules['code']);

        // All fields optional on update
        $rules['name'] = ['sometimes', 'string', 'max:255'];
        $rules['type'] = ['sometimes', 'string'];
        $rules['branch_id'] = ['nullable', 'string', 'uuid'];
        $rules['address'] = ['nullable', 'string'];
        $rules['is_active'] = ['sometimes', 'boolean'];
        $rules['auto_approve_stock_in'] = ['sometimes', 'boolean'];
        $rules['auto_approve_stock_out'] = ['sometimes', 'boolean'];
        $rules['auto_approve_batch'] = ['sometimes', 'boolean'];
        $rules['auto_approve_disposal'] = ['sometimes', 'boolean'];
        $rules['disposal_approval_threshold'] = ['nullable', 'numeric'];

        return $rules;
    }
}
