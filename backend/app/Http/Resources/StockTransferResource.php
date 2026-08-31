<?php

/**
 * StockTransfer Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\StockTransfer\Resources\StockTransferResourceBase;
use Illuminate\Http\Request;

/**
 * StockTransferResource — add project-specific serialization here.
 *
 * Inherited from base:
 *   - toArray(Request \$request): array  (returns schemaArray(\$request) — override to add fields)
 */
class StockTransferResource extends StockTransferResourceBase
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->schemaArray($request);

        $data['items_count'] = $this->whenCounted('items');

        return $data;
    }
}
