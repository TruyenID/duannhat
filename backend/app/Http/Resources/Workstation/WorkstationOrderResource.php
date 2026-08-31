<?php

namespace App\Http\Resources\Workstation;

use App\Http\Resources\CustomerOrderResource;
use Illuminate\Http\Request;

/**
 * An order as the WORKSTATION PULL wants it (#2713).
 *
 * Extends `CustomerOrderResource` rather than replacing it: every order-LEVEL
 * field the Go pull decodes (`cloudOrderPayload`,
 * `workstation/internal/service/sync_pull.go:384-468`) is produced by logic
 * that already lives there and is already covered by tests — `tax_breakdown`,
 * `payment_summary`, `conditions`, `tables`, the money projections. Re-deriving
 * that here would fork a second copy of it.
 *
 * The bloat is entirely in the ITEM shape, so that is the only thing swapped.
 * See `WorkstationOrderItemResource`.
 */
class WorkstationOrderResource extends CustomerOrderResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = parent::toArray($request);

        $base['items'] = $this->whenLoaded(
            'items',
            fn () => WorkstationOrderItemResource::collection($this->items),
        );

        return $base;
    }
}
