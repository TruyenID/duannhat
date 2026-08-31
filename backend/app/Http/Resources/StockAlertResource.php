<?php

/**
 * StockAlert Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Models\StockLevel;
use App\Omnify\Modules\StockAlert\Resources\StockAlertResourceBase;

/**
 * StockAlertResource — add project-specific serialization here.
 *
 * Inherited from base:
 *   - toArray(Request \$request): array  (returns schemaArray(\$request) — override to add fields)
 */
class StockAlertResource extends StockAlertResourceBase
{
    /**
     * Plan-024 — append the matching StockLevel id so the admin-web
     * alerts page can drive the inline threshold-edit sheet via
     * `PUT /stock-levels/{id}` without a separate lookup round-trip.
     *
     * The lookup is best-effort. When the alert was fired against a
     * StockLevel that has since been deleted, the field is null and
     * the FE hides the "Configure threshold" action for that row.
     */
    public function toArray($request): array
    {
        $payload = parent::toArray($request);

        $payload['stock_level_id'] = $this->resolveStockLevelId();

        return $payload;
    }

    private function resolveStockLevelId(): ?string
    {
        $query = StockLevel::query()
            ->where('warehouse_id', $this->warehouse_id);

        if ($this->product_sku_id) {
            $query->where('product_sku_id', $this->product_sku_id)
                ->whereNull('material_lot_id');
        } elseif ($this->material_id) {
            $query->where('material_id', $this->material_id)
                ->whereNull('material_lot_id');
        } else {
            return null;
        }

        return $query->value('id');
    }
}
