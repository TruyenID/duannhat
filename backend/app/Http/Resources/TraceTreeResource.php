<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * TraceTreeResource — pass-through wrapper for the recursive tree returned
 * by TraceService::traceLot() / traceCustomerOrder().
 *
 * The service already shapes nodes per DESIGN.md endpoint #10: each node
 * carries lot_id, lot_code, material{id, sku}, warehouse{id, name},
 * source, status, qty_on_hand, unit, expiry_date, plus parents[] /
 * children[] arrays of recursively-shaped child nodes (or terminal sales /
 * disposal leaves with leaf_type + customer_order_id).
 *
 * This resource exists for API consistency — controllers wrap recursive
 * payloads in TraceTreeResource so future field-level redaction (e.g.
 * hide cost_per_unit from non-admin roles) has a single hook.
 */
class TraceTreeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return is_array($this->resource) ? $this->resource : (array) $this->resource;
    }
}
