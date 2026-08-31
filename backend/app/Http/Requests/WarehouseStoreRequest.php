<?php

/**
 * Warehouse Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\Warehouse\Requests\WarehouseStoreRequestBase;

/**
 * WarehouseStoreRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class WarehouseStoreRequest extends WarehouseStoreRequestBase
{
    public function rules(): array
    {
        $rules = $this->schemaRules();

        // organization_id is set by controller, not by client
        unset($rules['organization_id']);

        // code is optional — auto-generated if empty
        $rules['code'] = ['nullable', 'string', 'max:50'];

        // defaults handled by service
        $rules['name'] = ['required', 'string', 'max:255'];
        $rules['type'] = ['required', 'string'];
        $rules['branch_id'] = ['nullable', 'string', 'uuid'];
        $rules['address'] = ['nullable', 'string'];
        $rules['is_active'] = ['nullable', 'boolean'];
        $rules['auto_approve_stock_in'] = ['nullable', 'boolean'];
        $rules['auto_approve_stock_out'] = ['nullable', 'boolean'];
        $rules['auto_approve_batch'] = ['nullable', 'boolean'];
        $rules['auto_approve_disposal'] = ['nullable', 'boolean'];
        $rules['disposal_approval_threshold'] = ['nullable', 'numeric'];
        $rules['allow_negative_sales'] = ['nullable', 'boolean'];

        return $rules;
    }
}
