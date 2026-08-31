<?php

/**
 * StockMovement Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\StockMovement\Resources\StockMovementResourceBase;
use Illuminate\Http\Request;

/**
 * StockMovementResource — add project-specific serialization here.
 *
 * Inherited from base:
 *   - toArray(Request \$request): array  (returns schemaArray(\$request) — override to add fields)
 */
class StockMovementResource extends StockMovementResourceBase
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->schemaArray($request);

        // Cast DECIMAL columns to float (see StockLevelResource for rationale).
        $data['quantity'] = (float) $this->quantity;
        $data['quantity_before'] = (float) $this->quantity_before;
        $data['quantity_after'] = (float) $this->quantity_after;

        return $data;
    }
}
