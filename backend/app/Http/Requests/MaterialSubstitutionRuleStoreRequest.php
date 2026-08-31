<?php

/**
 * MaterialSubstitutionRule Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\MaterialSubstitutionRule\Requests\MaterialSubstitutionRuleStoreRequestBase;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * MaterialSubstitutionRuleStoreRequest — validates the create payload from the
 * material detail page. `organization_id` and `brand_id` are injected by the
 * controller from the brand-scoped request context, not supplied by the client,
 * so the schema rules requiring them are stripped here.
 */
class MaterialSubstitutionRuleStoreRequest extends MaterialSubstitutionRuleStoreRequestBase
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = $this->schemaRules();

        // Injected by the controller from the URL brand context.
        unset($rules['organization_id'], $rules['brand_id']);

        // plan-040 NEW-MR-6/H6: the substitute must exist WITHIN the caller's
        // org (and brand, when resolved) — a bare `exists:materials,id` lets a
        // rule point at another tenant's material. A material may never
        // substitute for itself (`different:`).
        $rules['substitute_material_id'] = [
            'required', 'uuid', 'different:primary_material_id',
            $this->scopedMaterialExists(),
        ];

        // Conversion factor is a positive multiplier (decimal(10,4)).
        $rules['conversion_factor'] = ['required', 'numeric', 'gt:0'];

        // Priority / active flag default on the model, so accept omission.
        $rules['priority'] = ['sometimes', 'integer', 'min:0'];
        $rules['is_active'] = ['sometimes', 'boolean'];
        $rules['notes'] = ['nullable', 'string', 'max:1000'];

        // plan-018 audit fix (Finding 4) — per-rule opt-in + temporal window.
        // Not in schemaRules() yet (base regenerated from pre-fix YAML), so
        // declared explicitly. valid_until must not precede valid_from.
        $rules['auto_substitute'] = ['sometimes', 'boolean'];
        $rules['valid_from'] = ['sometimes', 'nullable', 'date'];
        $rules['valid_until'] = ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'];

        return $rules;
    }

    /**
     * plan-040 NEW-MR-6: an `exists` rule scoped to the request's org (and
     * brand when present). Org/brand ride in the request attributes, set by
     * ResolveBrandFromSlug, so a substitute material in another tenant fails
     * validation with a clean 422 instead of leaking across the boundary.
     */
    private function scopedMaterialExists(): Exists
    {
        $exists = Rule::exists('materials', 'id')
            ->where('organization_id', $this->attributes->get('organization_id'));

        if (($brandId = $this->attributes->get('brand_id')) !== null) {
            $exists->where('brand_id', $brandId);
        }

        return $exists;
    }
}
