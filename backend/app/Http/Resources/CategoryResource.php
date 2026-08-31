<?php

/**
 * Category Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\Category\Resources\CategoryResourceBase;
use Illuminate\Http\Request;

/**
 * CategoryResource — add project-specific serialization here.
 */
class CategoryResource extends CategoryResourceBase
{
    public function toArray(Request $request): array
    {
        return array_merge($this->schemaArray($request), [
            'children_count' => $this->when(
                array_key_exists('children_count', $this->resource->getAttributes()),
                fn () => $this->children_count,
            ),
            'products_count' => $this->when(
                array_key_exists('products_count', $this->resource->getAttributes()),
                fn () => $this->products_count,
            ),
        ]);
    }
}
