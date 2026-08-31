<?php

/**
 * MenuProduct Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\MenuProduct\Requests\MenuProductStoreRequestBase;

/**
 * MenuProductStoreRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 *
 * Note: topping-product guard lives in MenuAddProductsRequest, not here.
 * MenuAddProductsRequest handles POST /hq/{brand}/menus/{menu}/products (bulk add).
 */
class MenuProductStoreRequest extends MenuProductStoreRequestBase
{
    //
}
