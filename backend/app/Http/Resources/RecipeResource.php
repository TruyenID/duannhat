<?php

/**
 * Recipe Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\Recipe\Resources\RecipeResourceBase;
use Illuminate\Http\Request;

class RecipeResource extends RecipeResourceBase
{
    public function toArray(Request $request): array
    {
        $base = $this->schemaArray($request);

        // Compact shape for output-SKU listing on the recipes form. The base
        // emits a full ProductSkuResource collection (image_url, gallery,
        // option_value*…) which is too heavy for the picker — we only need
        // id / name / sku / product_id / product.name to render the chip and
        // hydrate the selected list. Always present (defaults to []) so the
        // FE doesn't have to null-check.
        $base['skus'] = $this->whenLoaded(
            'skus',
            fn () => $this->skus->map(fn ($sku) => [
                'id' => $sku->id,
                'name' => $sku->name,
                'sku' => $sku->sku,
                'product_id' => $sku->product_id,
                'product' => $sku->relationLoaded('product') && $sku->product
                    ? ['id' => $sku->product->id, 'name' => $sku->product->name]
                    : null,
            ])->all(),
            [],
        );

        return $base;
    }
}
