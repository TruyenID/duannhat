<?php

namespace App\Http\Resources\Pos;

use App\Models\TillTenderType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plan 030 — TillTenderType for close-screen reconcile grid.
 */
class TillTenderTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TillTenderType $t */
        $t = $this->resource;

        // Translatable name: prefer cached translation map if loaded, else
        // fall back to raw column (some tests bypass the translation table).
        $name = method_exists($t, 'getTranslations')
            ? $t->getTranslations('name')
            : $t->name;

        return [
            'id' => $t->id,
            'tender_key' => $t->tender_key,
            'name' => $name,
            'category' => $t->category instanceof \BackedEnum ? $t->category->value : $t->category,
            'parent_tender_key' => $t->parent_tender_key,
            'currency_code' => $t->currency_code,
            'payment_method_code' => $t->payment_method_code,
            'is_expected_anchor' => (bool) $t->is_expected_anchor,
            'requires_terminal_total' => (bool) $t->requires_terminal_total,
            'sort_order' => (int) ($t->sort_order ?? 0),
            // #1156 — per-branch activation surface reads this; the POS
            // close-page lists are pre-filtered to active rows so the field
            // is additive there.
            'is_active' => (bool) $t->is_active,
        ];
    }
}
