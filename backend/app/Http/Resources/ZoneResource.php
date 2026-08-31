<?php

/**
 * Zone Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\Zone\Resources\ZoneResourceBase;
use Illuminate\Http\Request;

class ZoneResource extends ZoneResourceBase
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
            'description' => $this->description,
            'display_order' => $this->display_order,
            'is_active' => (bool) $this->is_active,
            // issue #890 / BR-Z04: non-null ⇒ copied from the HQ default layout.
            'zone_template_id' => $this->zone_template_id,
            'tables_count' => $this->when(isset($this->tables_count), fn () => (int) $this->tables_count),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
