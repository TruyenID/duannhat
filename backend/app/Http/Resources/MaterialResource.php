<?php

/**
 * Material Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\Material\Resources\MaterialResourceBase;
use Illuminate\Http\Request;

/**
 * MaterialResource — add project-specific serialization here.
 *
 * Inherited from base:
 *   - toArray(Request $request): array  (returns schemaArray($request) — override to add fields)
 */
class MaterialResource extends MaterialResourceBase
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        // Plan-017 MOD-1 — surface withCount aggregates on the materials
        // list. NULL-coalesced so non-listed responses (detail / dropdown)
        // that don't withCount() these don't leak undefined-attribute errors.
        $data['active_lots_count'] = $this->active_lots_count ?? null;
        $data['expiring_soon_lots_count'] = $this->expiring_soon_lots_count ?? null;

        // Plan-022 — computed `kind` flag so the UI doesn't have to re-derive
        // raw-vs-produced from yield_unit on every render. `yield_unit` is
        // the persisted truth (NULL = raw, non-null = produced; enforced by
        // MaterialService::validateYieldShape).
        $data['kind'] = $this->yield_unit === null ? 'raw' : 'produced';

        return $data;
    }
}
