<?php

/**
 * StockTransactionItem Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\StockTransactionItem\Resources\StockTransactionItemResourceBase;
use Illuminate\Http\Request;

/**
 * StockTransactionItemResource — add project-specific serialization here.
 *
 * Inherited from base:
 *   - toArray(Request \$request): array  (returns schemaArray(\$request) — override to add fields)
 */
class StockTransactionItemResource extends StockTransactionItemResourceBase
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
        $data['base_quantity'] = (float) $this->base_quantity;
        $data['unit_price'] = $this->unit_price !== null ? (float) $this->unit_price : null;

        return $data;
    }
}
