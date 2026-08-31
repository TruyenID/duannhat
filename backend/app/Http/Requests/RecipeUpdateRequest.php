<?php

/**
 * Recipe Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\Recipe\Requests\RecipeUpdateRequestBase;

/**
 * RecipeUpdateRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class RecipeUpdateRequest extends RecipeUpdateRequestBase
{
    /**
     * Approval-workflow + ownership columns that must NEVER be set through the
     * generic update path. plan-040 NEW-MR-1: approval state is owned solely by
     * the approve()/reject() service actions; accepting them here would let an
     * editor forge an `approved` recipe via a crafted PUT (privilege
     * escalation). Per DESIGN Decision 5 they are removed from rules() so they
     * never reach validated() (a silent drop, not a 422), with a service-layer
     * assertion as defence in depth.
     *
     * `is_active` is intentionally NOT stripped — it is the activate/deactivate
     * toggle the admin-web recipe page drives through this same endpoint
     * (recipes/[id]/page.tsx), and there is no separate toggle route. Stripping
     * it would silently break activation. It is not an approval-state field, so
     * it is outside the NEW-MR-1 trust boundary.
     */
    public const FORBIDDEN_UPDATE_FIELDS = [
        'approval_status',
        'approved_by_id',
        'approved_at',
        'rejected_by_id',
        'rejected_at',
    ];

    public function rules(): array
    {
        $rules = $this->schemaRules();

        // plan-040 NEW-MR-1: strip approval-workflow + ownership columns.
        foreach (self::FORBIDDEN_UPDATE_FIELDS as $field) {
            unset($rules[$field]);
        }

        // plan-040 NEW-MR-2: org/brand scope is immutable post-create — a
        // material/recipe can never be reassigned to another brand (let alone a
        // foreign-org one) through update. Stripped here; reconciled again in
        // the service as defence in depth.
        unset($rules['organization_id'], $rules['brand_id']);

        $rules['sku_ids'] = ['nullable', 'array'];
        $rules['sku_ids.*'] = ['uuid', 'exists:product_skus,id'];

        return $rules;
    }
}
