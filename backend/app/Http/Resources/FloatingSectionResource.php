<?php

/**
 * FloatingSectionResource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\FloatingSection\Resources\FloatingSectionResourceBase;
use Illuminate\Http\Request;

class FloatingSectionResource extends FloatingSectionResourceBase
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
