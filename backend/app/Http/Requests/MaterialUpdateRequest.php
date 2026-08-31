<?php

/**
 * Material Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\Material\Requests\MaterialUpdateRequestBase;

/**
 * MaterialUpdateRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class MaterialUpdateRequest extends MaterialUpdateRequestBase
{
    public function rules(): array
    {
        $rules = $this->schemaRules();

        // organization_id is never accepted from the client; it's pinned at
        // create-time and the controller authorizes the resource by it.
        // plan-040 NEW-MR-2: brand_id is likewise immutable post-create — a
        // client must not be able to reassign a material to another brand
        // (especially a foreign-org one). Stripped here; the service reconciles
        // again as defence in depth for direct/import callers.
        unset($rules['organization_id'], $rules['brand_id']);

        // yield_quantity must stay strictly positive on update too — the schema
        // rule is only ['sometimes', 'numeric'], which would let a negative slip
        // in (plan-040 QA finding F3). Keep it optional but > 0 when supplied.
        $rules['yield_quantity'] = ['sometimes', 'numeric', 'gt:0'];

        // allergen_ids is a custom pivot payload consumed by
        // MaterialService::update to sync material_allergens. Validate
        // each entry references a real allergen so 422 surfaces cleanly
        // instead of a runtime FK error.
        $rules['allergen_ids'] = ['sometimes', 'array'];
        $rules['allergen_ids.*'] = ['uuid', 'exists:allergens,id'];

        // temperature_min/max are stored as decimal(5,2) — a value beyond
        // ±999.99 overflows the column and surfaces as a raw QueryException
        // (leaking SQL to the client). Bound them to the column range so an
        // out-of-range input returns a clean 422 instead.
        $rules['temperature_min'] = ['nullable', 'numeric', 'between:-999.99,999.99'];
        $rules['temperature_max'] = ['nullable', 'numeric', 'between:-999.99,999.99'];

        return $rules;
    }
}
