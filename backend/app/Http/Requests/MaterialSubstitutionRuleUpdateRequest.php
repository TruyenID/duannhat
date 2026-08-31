<?php

/**
 * MaterialSubstitutionRule Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Models\MaterialSubstitutionRule;
use App\Omnify\Modules\MaterialSubstitutionRule\Requests\MaterialSubstitutionRuleUpdateRequestBase;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * MaterialSubstitutionRuleUpdateRequest — partial update from the edit dialog.
 * Org/brand scope is immutable post-create, so those keys are never accepted.
 */
class MaterialSubstitutionRuleUpdateRequest extends MaterialSubstitutionRuleUpdateRequestBase
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = $this->schemaRules();

        // Scope columns are fixed once the rule exists.
        unset($rules['organization_id'], $rules['brand_id'], $rules['primary_material_id']);

        // plan-040 NEW-MR-6/H6: org/brand-scoped exists + self-substitution
        // guard. `primary_material_id` is immutable (stripped above) so the
        // self-sub check compares against the EXISTING rule's primary rather
        // than a request field (`different:` can't reach it).
        $rules['substitute_material_id'] = [
            'sometimes', 'uuid',
            $this->scopedMaterialExists(),
            $this->differsFromPrimary(),
        ];
        $rules['conversion_factor'] = ['sometimes', 'numeric', 'gt:0'];
        $rules['priority'] = ['sometimes', 'integer', 'min:0'];
        $rules['is_active'] = ['sometimes', 'boolean'];
        $rules['notes'] = ['nullable', 'string', 'max:1000'];

        // plan-018 audit fix (Finding 4) — per-rule opt-in + temporal window.
        $rules['auto_substitute'] = ['sometimes', 'boolean'];
        $rules['valid_from'] = ['sometimes', 'nullable', 'date'];
        $rules['valid_until'] = ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'];

        return $rules;
    }

    /**
     * plan-040 NEW-MR-6: org/brand-scoped `exists` (org/brand ride in the
     * request attributes from ResolveBrandFromSlug).
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

    /**
     * plan-040 NEW-MR-6: reject substitute == the rule's own primary material
     * (self-substitution). The route model carries the immutable primary.
     */
    private function differsFromPrimary(): \Closure
    {
        $rule = $this->route('rule');
        $primaryId = $rule instanceof MaterialSubstitutionRule ? (string) $rule->primary_material_id : null;

        return function (string $attribute, mixed $value, \Closure $fail) use ($primaryId): void {
            if ($primaryId !== null && (string) $value === $primaryId) {
                $fail('A material may never substitute for itself.');
            }
        };
    }
}
