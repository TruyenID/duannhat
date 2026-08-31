<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Models\VoidReason;
use App\Services\Inventory\Contracts\VoidReasonStockEffect;
use App\Services\Inventory\Contracts\VoidReasonStockEffects;

/**
 * #962 — hiện thực {@see VoidReasonStockEffects}.
 *
 * Chuẩn hoá `stock_effect` ĐÚNG như `StockDeductionService` từng làm tại chỗ:
 * `BackedEnum` → `->value`, giá trị khác → ép chuỗi, `null` → `null`. Không map
 * sang `VoidStockEffectEnum` ở đây — xem lý do trong docblock của
 * {@see VoidReasonStockEffect}.
 */
final class EloquentVoidReasonStockEffects implements VoidReasonStockEffects
{
    public function find(string $voidReasonId): ?VoidReasonStockEffect
    {
        $reason = VoidReason::query()->find($voidReasonId);

        if ($reason === null) {
            return null;
        }

        $effect = $reason->stock_effect;

        return new VoidReasonStockEffect(
            id: (string) $reason->id,
            stockEffect: match (true) {
                $effect instanceof \BackedEnum => (string) $effect->value,
                $effect === null => null,
                default => (string) $effect,
            },
            label: $reason->localizedLabel(),
        );
    }
}
