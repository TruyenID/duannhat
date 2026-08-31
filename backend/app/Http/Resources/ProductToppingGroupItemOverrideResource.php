<?php

/**
 * ProductToppingGroupItemOverride Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\ProductToppingGroupItemOverride\Resources\ProductToppingGroupItemOverrideResourceBase;
use Illuminate\Http\Request;

class ProductToppingGroupItemOverrideResource extends ProductToppingGroupItemOverrideResourceBase
{
    public function toArray(Request $request): array
    {
        return $this->schemaArray($request);
    }
}
