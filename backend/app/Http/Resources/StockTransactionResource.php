<?php

/**
 * StockTransaction Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\StockTransaction\Resources\StockTransactionResourceBase;
use Illuminate\Http\Request;

/**
 * StockTransactionResource — add project-specific serialization here.
 *
 * Inherited from base:
 *   - toArray(Request \$request): array  (returns schemaArray(\$request) — override to add fields)
 */
class StockTransactionResource extends StockTransactionResourceBase
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

        // Surface a small user payload for the "Created by" / "Approved by"
        // columns. The relation is eager-loaded by StockTransactionService.
        $data['created_by'] = $this->whenLoaded('createdBy', fn () => $this->createdBy ? [
            'id' => $this->createdBy->id,
            'name' => $this->createdBy->name,
            'email' => $this->createdBy->email,
        ] : null);

        $data['approved_by'] = $this->whenLoaded('approvedBy', fn () => $this->approvedBy ? [
            'id' => $this->approvedBy->id,
            'name' => $this->approvedBy->name,
            'email' => $this->approvedBy->email,
        ] : null);

        return $data;
    }
}
