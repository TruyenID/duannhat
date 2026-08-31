<?php

/**
 * TableTemplate Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\TableTemplate\Resources\TableTemplateResourceBase;
use Illuminate\Http\Request;

class TableTemplateResource extends TableTemplateResourceBase
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
            'is_active' => (bool) $this->is_active,
            // Target branch — null means "all branches" (brand-wide default).
            'branch' => $this->whenLoaded('branch', fn () => $this->branch === null ? null : [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),
            'zone_template' => $this->whenLoaded('zoneTemplate', fn () => [
                'id' => $this->zoneTemplate->id,
                'code' => $this->zoneTemplate->code,
                'name' => $this->zoneTemplate->name,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
