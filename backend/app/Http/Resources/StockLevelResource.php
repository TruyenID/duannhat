<?php

/**
 * StockLevel Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\StockLevel\Resources\StockLevelResourceBase;
use Illuminate\Http\Request;

/**
 * StockLevelResource — add project-specific serialization here.
 *
 * Inherited from base:
 *   - toArray(Request \$request): array  (returns schemaArray(\$request) — override to add fields)
 */
class StockLevelResource extends StockLevelResourceBase
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->schemaArray($request);

        // Laravel's `decimal:N` cast on the base model serializes as STRING.
        // Cast to float here so JSON consumers receive numbers and don't need
        // to coerce on the client (a real foot-gun: `"10" <= "5"` is `true`
        // because string compare is lexicographic).
        $data['quantity'] = (float) $this->quantity;
        $data['min_stock'] = $this->min_stock !== null ? (float) $this->min_stock : null;
        $data['max_stock'] = $this->max_stock !== null ? (float) $this->max_stock : null;

        // Computed status from quantity vs min_stock — surfaced so the client
        // doesn't have to derive it (and can filter/sort on it server-side).
        $data['stock_status'] = $this->resolveStockStatus();

        $data['warehouse_name'] = $this->whenLoaded('warehouse', fn () => $this->warehouse->name);
        $data['item_name'] = $this->whenLoaded('productSku', fn () => $this->productSku?->name)
            ?? $this->whenLoaded('material', fn () => $this->material?->name);

        return $data;
    }

    /**
     * Compute `normal` / `low` / `out` from raw quantity vs min_stock.
     */
    private function resolveStockStatus(): string
    {
        $qty = (float) $this->quantity;

        if ($qty <= 0) {
            return 'out';
        }

        if ($this->min_stock !== null && $qty <= (float) $this->min_stock) {
            return 'low';
        }

        return 'normal';
    }
}
