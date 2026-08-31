<?php

namespace App\Services\Shop;

use App\Models\ShopOrderSetting;
use App\Omnify\Enums\OrderItemStatusEnum;

/**
 * plan-051 (#1149) — THE canonical resolution of "in which item statuses may
 * a line be VOIDED" for a shop. Every consumer (Cloud void gate, settings
 * serializers, the workstation settings payload) goes through this class so
 * the three tiers can never drift apart.
 *
 * Semantics (DESIGN §1.2):
 *   - `item_voidable_statuses` non-null → union of the stored list with
 *     ['pending'] — pending is a HARD guarantee, it cannot be configured away.
 *   - null (not yet configured) → legacy `allow_item_edit_any_status` flag:
 *     true → all four active statuses, false → pending-only. This fallback
 *     keeps deploys backfill-free; the flag is removed in a later plan.
 *   - Unknown entries are filtered against the real status set. `voided` is
 *     never voidable (terminal), so the filter runs against the four ACTIVE
 *     statuses only.
 *
 * The result is normalized to canonical enum order (pending, preparing,
 * ready, served) so serialized payloads are stable.
 */
final class VoidableStatusResolver
{
    /**
     * Resolve the effective set of item statuses in which a VOID is allowed.
     *
     * @return list<string>
     */
    public static function resolve(?ShopOrderSetting $setting): array
    {
        $activeStatuses = self::activeStatuses();

        $configured = $setting?->item_voidable_statuses;

        if (is_array($configured)) {
            $allowed = array_merge(
                [OrderItemStatusEnum::Pending->value],
                // Non-string garbage (numbers, nested arrays…) can never be a
                // status — drop it before the intersect so a malformed stored
                // value degrades safely instead of warning on string casts.
                array_filter($configured, is_string(...)),
            );

            // Intersect in canonical enum order — filters garbage entries
            // (non-strings, unknown values, `voided`) and yields stable output.
            return array_values(array_intersect($activeStatuses, $allowed));
        }

        return ($setting?->allow_item_edit_any_status ?? false)
            ? $activeStatuses
            : [OrderItemStatusEnum::Pending->value];
    }

    /**
     * The four ACTIVE order-item statuses (everything but terminal `voided`),
     * in canonical enum order.
     *
     * @return list<string>
     */
    public static function activeStatuses(): array
    {
        return array_values(array_diff(
            OrderItemStatusEnum::values(),
            [OrderItemStatusEnum::Voided->value],
        ));
    }
}
