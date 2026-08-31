<?php

/**
 * TaxType Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\TaxType\Resources\TaxTypeResourceBase;
use Illuminate\Http\Request;

/**
 * TaxTypeResource — add project-specific serialization here.
 *
 * Inherited from base:
 *   - toArray(Request \$request): array  (returns schemaArray(\$request) — override to add fields)
 */
class TaxTypeResource extends TaxTypeResourceBase
{
    public function toArray(Request $request): array
    {
        return array_merge($this->schemaArray($request), [
            'products_count' => $this->when(
                array_key_exists('products_count', $this->resource->getAttributes()),
                fn () => $this->products_count,
            ),
        ]);
    }
}
