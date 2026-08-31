<?php

/**
 * Table Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\Table\Resources\TableResourceBase;
use BackedEnum;
use Illuminate\Http\Request;

class TableResource extends TableResourceBase
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'seat_count' => (int) $this->seat_count,
            'status' => $this->status instanceof BackedEnum ? $this->status->value : $this->status,
            'is_active' => (bool) $this->is_active,
            'qr_token' => $this->qr_token,
            // issue #890 / BR-T09: non-null ⇒ copied from the HQ default layout
            // (shop cannot delete it; UI hides the delete action).
            'table_template_id' => $this->table_template_id,
            'current_order_id' => $this->current_order_id,
            'zone' => $this->whenLoaded('zone', fn () => [
                'id' => $this->zone->id,
                'code' => $this->zone->code,
                'name' => $this->zone->name,
            ]),
            'last_status_change' => $this->whenLoaded('lastStatusChange', function () {
                if ($this->lastStatusChange === null) {
                    return null;
                }

                return [
                    'from' => $this->lastStatusChange->from_status instanceof BackedEnum
                        ? $this->lastStatusChange->from_status->value
                        : $this->lastStatusChange->from_status,
                    'to' => $this->lastStatusChange->to_status instanceof BackedEnum
                        ? $this->lastStatusChange->to_status->value
                        : $this->lastStatusChange->to_status,
                    'changed_by_id' => $this->lastStatusChange->changed_by_id,
                    'changed_at' => $this->lastStatusChange->changed_at?->toISOString(),
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
