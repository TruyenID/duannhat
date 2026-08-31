<?php

/**
 * ProductSku Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\ProductSku\Resources\ProductSkuResourceBase;
use Illuminate\Http\Request;

class ProductSkuResource extends ProductSkuResourceBase
{
    public function toArray(Request $request): array
    {
        return array_merge($this->schemaArray($request), [
            'units_count' => $this->whenCounted('units'),
            'option_value1' => $this->whenLoaded('optionValue1', fn () => new ProductOptionValueResource($this->optionValue1)),
            'option_value2' => $this->whenLoaded('optionValue2', fn () => new ProductOptionValueResource($this->optionValue2)),
            'option_value3' => $this->whenLoaded('optionValue3', fn () => new ProductOptionValueResource($this->optionValue3)),
            'recipe' => $this->whenLoaded('recipe'),
            // Full gallery — eager-loaded only on show() for the SKU edit page.
            'gallery' => $this->whenLoaded('gallery', fn () => FileResource::collection($this->gallery)),
            // Lightweight main thumbnail — eager-loaded on list endpoints for
            // the SKU sidebar. Resolves to `null` when the SKU has no images.
            'image_url' => $this->whenLoaded('galleryFirst', fn () => $this->galleryFirst?->getUrl()),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ]);
    }
}
