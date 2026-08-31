<?php

/**
 * ToppingGroup Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\ToppingGroup\Resources\ToppingGroupResourceBase;
use Illuminate\Http\Request;

class ToppingGroupResource extends ToppingGroupResourceBase
{
    public function toArray(Request $request): array
    {
        return $this->schemaArray($request);
    }
}
