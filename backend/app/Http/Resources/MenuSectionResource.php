<?php

/**
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\MenuSection\Resources\MenuSectionResourceBase;
use Illuminate\Http\Request;

class MenuSectionResource extends MenuSectionResourceBase
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...$this->schemaArray($request),
            // #1218 — when this section is loaded THROUGH a menu, its per-menu
            // values live on the pivot, not on the section: `display_order` and
            // now `tax_type_id`. Without this the admin UI cannot show or edit a
            // section's tax type at all, because `schemaArray` only serialises
            // the section's own columns.
            //
            // Guarded on relationLoaded so a section fetched directly (no menu
            // context) does not gain a phantom `pivot` key.
            ...($this->resource->relationLoaded('pivot') || $this->resource->pivot !== null
                ? ['pivot' => [
                    'display_order' => $this->resource->pivot?->display_order,
                    'tax_type_id' => $this->resource->pivot?->tax_type_id,
                ]]
                : []),
        ];
    }
}
