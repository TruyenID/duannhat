<?php

namespace App\Services\Order\Internal;

use App\Models\CustomerOrder;
use App\Services\Customer\SplitByItemsCalculator;
use App\Services\Order\Contracts\OrderSplitBillTotals;

/**
 * #1594 — mặt Ordering của {@see OrderSplitBillTotals}.
 *
 * Nạp đơn rồi giao cho đúng bộ tính cũ. Chỗ gọi duy nhất (Payments) đã cầm khoá
 * hàng của đơn trong CHÍNH transaction này, nên lần đọc thêm ở đây thấy đúng
 * hàng nó vừa khoá — không phải một ảnh chụp khác. Cái giá là một truy vấn, trên
 * một đường đã ghi ledger và có thể abort 422.
 */
final class EloquentSplitBillTotals implements OrderSplitBillTotals
{
    public function __construct(private readonly SplitByItemsCalculator $calculator) {}

    public function billTotalFor(
        string $orderId,
        array $itemAllocations,
        int $billIndex,
        string $roundingMode,
        ?string $currencyCode,
        float $taxRate,
        float $serviceChargeRate,
        int $peopleCount,
    ): float {
        $order = CustomerOrder::query()->with('items')->find($orderId);

        if ($order === null) {
            return 0.0;
        }

        return $this->calculator->computeBillTotal(
            $order,
            $itemAllocations,
            $billIndex,
            $roundingMode,
            $currencyCode,
            $taxRate,
            $serviceChargeRate,
            $peopleCount,
        );
    }

    public function splittableUnitsByItem(string $orderId): array
    {
        $order = CustomerOrder::query()->with('items')->find($orderId);

        if ($order === null) {
            return [];
        }

        $units = [];
        foreach ($order->items as $item) {
            $units[(string) $item->id] = SplitByItemsCalculator::splittableUnits($item);
        }

        return $units;
    }
}
