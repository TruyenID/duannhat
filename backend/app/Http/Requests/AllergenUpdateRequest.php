<?php

/**
 * Allergen Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\Allergen\Requests\AllergenUpdateRequestBase;
use Illuminate\Validation\Rule;

/**
 * AllergenUpdateRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class AllergenUpdateRequest extends AllergenUpdateRequestBase
{
    public function rules(): array
    {
        $rules = $this->schemaRules();

        unset($rules['organization_id']);

        $rules['name:ja'] = ['sometimes', 'nullable', 'string', 'max:120'];
        $rules['name:en'] = ['sometimes', 'nullable', 'string', 'max:120'];
        $rules['name:vi'] = ['sometimes', 'nullable', 'string', 'max:120'];

        $rules['jurisdiction'] = ['sometimes', 'string', Rule::in(['jp', 'eu', 'us'])];
        $rules['severity'] = ['sometimes', 'string', Rule::in(['mandatory', 'recommended'])];

        return $rules;
    }
}
