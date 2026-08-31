<?php

/**
 * ProductOption Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\ProductOption\Resources\ProductOptionResourceBase;
use Illuminate\Http\Request;

class ProductOptionResource extends ProductOptionResourceBase
{
    public function toArray(Request $request): array
    {
        return array_merge($this->schemaArray($request), [
            'values' => $this->whenLoaded('values', fn () => ProductOptionValueResource::collection($this->values)),
            'values_count' => $this->whenCounted('values'),
        ]);
    }
}
