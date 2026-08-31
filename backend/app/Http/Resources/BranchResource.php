<?php

/**
 * Branch Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\Branch\Resources\BranchResourceBase;
use Illuminate\Http\Request;

/**
 * BranchResource — add project-specific serialization here.
 *
 * Inherited from base:
 *   - toArray(Request \$request): array  (returns schemaArray(\$request) — override to add fields)
 */
class BranchResource extends BranchResourceBase
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'locale' => $this->locale,
            'is_headquarters' => $this->is_headquarters,
            'is_active' => $this->is_active,
            'address' => $this->address,
            'phone' => $this->phone,
            'img_branches' => $this->img_branches,
            // #936 — per-breakpoint banners (admin edits these directly, so
            // they are exposed raw/unresolved).
            ...$this->resource->bannerUrls(),
            'logo' => $this->logo,
            'seat_capacity' => $this->seat_capacity,
            'business_hours' => $this->business_hours,
            'weekly_hours' => $this->weekly_hours,
        ];
    }
}
