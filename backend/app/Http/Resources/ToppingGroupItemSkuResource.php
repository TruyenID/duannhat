<?php

/**
 * ToppingGroupItemSku Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\ToppingGroupItemSku\Resources\ToppingGroupItemSkuResourceBase;
use Illuminate\Http\Request;

class ToppingGroupItemSkuResource extends ToppingGroupItemSkuResourceBase
{
    public function toArray(Request $request): array
    {
        return $this->schemaArray($request);
    }
}
