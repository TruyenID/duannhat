<?php

/**
 * Customer Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\Customer\Requests\CustomerStoreRequestBase;

/**
 * CustomerStoreRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class CustomerStoreRequest extends CustomerStoreRequestBase
{
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string', 'max:500'],
            'tax_code' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string'],
        ];
    }
}
