<?php

/**
 * CustomerOrderItem Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\CustomerOrderItem\Resources\CustomerOrderItemResourceBase;
use Illuminate\Http\Request;

/**
 * CustomerOrderItemResource — add project-specific serialization here.
 *
 * Takes the base schemaArray (which carries all schema fields + audit
 * timestamps) and renames the Eloquent-generated camelCase relation keys
 * (customerOrder, productSku) to snake_case (customer_order, product_sku)
 * for consistency with the rest of the POS wire contract (see
 * plan-007 NOTES §"2026-04-18 — Resource override audit").
 */
class CustomerOrderItemResource extends CustomerOrderItemResourceBase
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = $this->schemaArray($request);

        // Plan 019 — orders placed before the getTranslationsArray() fix in
        // WritesCustomerOrders stored the promotion name as a nested
        // `locale => ['name' => …, 'description' => …]` map instead of
        // `locale => string`. Normalize on read so every consumer sees one
        // shape regardless of when the order was placed (no data migration
        // needed, and the snapshot stays an immutable audit record on disk).
        if (is_array($base['applied_promotion_snapshot'] ?? null)) {
            $base['applied_promotion_snapshot'] = self::normalizePromotionSnapshot(
                $base['applied_promotion_snapshot']
            );
        }

        if (array_key_exists('customerOrder', $base)) {
            $base['customer_order'] = $base['customerOrder'];
            unset($base['customerOrder']);
        }

        if (array_key_exists('productSku', $base)) {
            $base['product_sku'] = $base['productSku'];
            unset($base['productSku']);
        }

        // Plan 015 — surface chosen toppings as a flat array per line.
        // Snake-cased like the rest of the wire contract; only included
        // when the relation has been eager-loaded (controller does this
        // via findById's load chain).
        if ($this->relationLoaded('orderItemToppings')) {
            $base['toppings'] = $this->orderItemToppings->map(function ($row) {
                $groupName = null;
                $modifierType = null;
                if ($row->relationLoaded('toppingGroupItem') && $row->toppingGroupItem) {
                    $groupName = $row->toppingGroupItem->relationLoaded('toppingGroup')
                        ? $row->toppingGroupItem->toppingGroup?->name
                        : null;
                    $modifierType = $row->toppingGroupItem->relationLoaded('toppingGroup') && $row->toppingGroupItem->toppingGroup
                        ? ($row->toppingGroupItem->toppingGroup->modifier_type instanceof \BackedEnum
                            ? $row->toppingGroupItem->toppingGroup->modifier_type->value
                            : $row->toppingGroupItem->toppingGroup->modifier_type)
                        : null;
                }

                return [
                    'id' => $row->id,
                    'topping_group_id' => $row->toppingGroupItem?->topping_group_id,
                    'topping_group_name' => $groupName,
                    'topping_group_item_id' => $row->topping_group_item_id,
                    'product_sku_id' => $row->product_sku_id,
                    'name' => $row->relationLoaded('toppingGroupItem')
                        && $row->toppingGroupItem?->relationLoaded('product')
                        ? $row->toppingGroupItem->product?->name
                        : null,
                    'modifier_type' => $modifierType,
                    'quantity' => (int) $row->quantity,
                    'unit_price' => (string) $row->unit_price,
                    // #2619 — số đơn vị được `free_up_to_n` miễn. Không có nó
                    // trên dây thì tiền biến mất khỏi `topping_subtotal` mà
                    // không bề mặt nào nói vì sao; #2620 mirror nó xuống máy
                    // trạm. Mảng này dựng TAY (không đi qua resource Omnify),
                    // nên cột mới không tự lên dây — phải thêm ở đây.
                    'waived_quantity' => (int) $row->waived_quantity,
                    'note' => $row->note,
                ];
            })->values();
        }

        return $base;
    }

    /**
     * Collapse a promotion snapshot's `name` to a `locale => string` map.
     *
     * Accepts both shapes: the current flat one (already `locale => string`,
     * passed through untouched) and the legacy nested one
     * (`locale => ['name' => …, 'description' => …]`).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private static function normalizePromotionSnapshot(array $snapshot): array
    {
        if (! is_array($snapshot['name'] ?? null)) {
            return $snapshot;
        }

        $snapshot['name'] = collect($snapshot['name'])
            ->map(fn ($value) => is_array($value) ? ($value['name'] ?? null) : $value)
            ->filter(fn ($value): bool => is_string($value) && $value !== '')
            ->all();

        return $snapshot;
    }
}
