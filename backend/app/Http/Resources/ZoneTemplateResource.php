<?php

/**
 * ZoneTemplate Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\ZoneTemplate\Resources\ZoneTemplateResourceBase;
use Illuminate\Http\Request;

class ZoneTemplateResource extends ZoneTemplateResourceBase
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
            // Target branch — null means "all branches" (brand-wide default).
            'branch' => $this->whenLoaded('branch', fn () => $this->branch === null ? null : [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ]),
            'table_templates_count' => $this->when(isset($this->table_templates_count), fn () => (int) $this->table_templates_count),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
