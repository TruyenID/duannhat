<?php

/**
 * ToppingGroupItem Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\ToppingGroupItem\Resources\ToppingGroupItemResourceBase;
use Illuminate\Http\Request;

class ToppingGroupItemResource extends ToppingGroupItemResourceBase
{
    public function toArray(Request $request): array
    {
        return array_merge($this->schemaArray($request), [
            'variants_count' => $this->whenLoaded('product', fn () => $this->product?->skus->count() ?? 0),
            'product_skus' => $this->whenLoaded('product', function () {
                if (! $this->product?->relationLoaded('skus')) {
                    return [];
                }

                return ProductSkuResource::collection($this->product->skus);
            }),
        ]);
    }
}
