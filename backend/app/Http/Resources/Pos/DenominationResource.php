<?php

namespace App\Http\Resources\Pos;

use App\Models\Denomination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plan 030 — Denomination master row.
 *
 * `organization_id` + `is_active` are part of the contract (#1178): admin-web
 * reads them to tell a global (read-only, organization_id NULL) row from an
 * org-scoped one, and to render active rows. Omitting `is_active` silently
 * broke the admin manual-settle grid — the client filtered on a field the API
 * never sent and dropped every denomination.
 */
class DenominationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Denomination $d */
        $d = $this->resource;

        return [
            'id' => $d->id,
            'organization_id' => $d->organization_id,
            'currency_code' => $d->currency_code,
            'value' => (float) $d->value,
            'kind' => $d->kind instanceof \BackedEnum ? $d->kind->value : $d->kind,
            'label' => $d->label,
            'sort_order' => (int) ($d->sort_order ?? 0),
            'is_active' => (bool) $d->is_active,
        ];
    }
}
