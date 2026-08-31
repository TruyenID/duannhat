<?php

/**
 * Recipe Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\Recipe\Requests\RecipeStoreRequestBase;

/**
 * RecipeStoreRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class RecipeStoreRequest extends RecipeStoreRequestBase
{
    public function rules(): array
    {
        $rules = $this->schemaRules();

        // organization_id is injected by the controller from the request
        // context (brand → organization), not supplied by the client.
        unset($rules['organization_id']);

        // Optional output side: a recipe may declare which ProductSkus it
        // produces. RecipeService writes ProductSku.recipe_id = $recipe->id
        // for each id, so the SKU edit form's recipe picker stays in sync.
        // Brand scope is enforced inside RecipeService.
        $rules['sku_ids'] = ['nullable', 'array'];
        $rules['sku_ids.*'] = ['uuid', 'exists:product_skus,id'];

        return $rules;
    }
}
