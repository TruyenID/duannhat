<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\OrderCondition;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Services\Order\Contracts\BranchOrderReads;
use App\Services\Order\Contracts\OpenOrderSummary;
use App\Services\Order\Contracts\OrderRevenueRollup;
use App\Services\Order\Contracts\OrderStatusVocabulary;
use App\Services\Order\Contracts\UnresolvedOrderSummary;
use App\Services\Order\Contracts\VoidedOrderSummary;
use Carbon\Carbon;

/**
 * #1647 — hiện thực {@see BranchOrderReads}.
 *
 * Mọi truy vấn ở đây chỉ chạm bảng của chính Ordering — `customer_orders` và
 * (từ #962) `customer_order_items`. Đó là toàn bộ điểm của cổng này: phần cần
 * `order_payments` ở lại phía Payments.
 */
final class EloquentBranchOrderReads implements BranchOrderReads
{
    public function openForBranch(string $branchId): array
    {
        return CustomerOrder::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', OrderStatusVocabulary::OPEN)
            ->orderBy('created_at')
            ->get(['id', 'order_code', 'status', 'total_amount', 'created_at'])
            ->map(fn (CustomerOrder $o): OpenOrderSummary => new OpenOrderSummary(
                orderId: (string) $o->id,
                orderCode: $o->order_code === null ? null : (string) $o->order_code,
                status: $o->status instanceof \BackedEnum ? $o->status->value : (string) $o->status,
                totalAmount: (float) $o->total_amount,
                createdAt: Carbon::parse($o->created_at)->toIso8601String(),
            ))
            ->all();
    }

    public function unresolvedForBranchBefore(string $branchId, string $cutoffIso): array
    {
        return CustomerOrder::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', OrderStatusVocabulary::UNRESOLVED_MONEY)
            // PHẢI parse: so thẳng chuỗi ISO-8601 với cột datetime là so CHUỖI.
            // `2026-08-12 21:56:13` vs `2026-08-12T21:51:13+00:00` lệch ở ký tự
            // thứ 11 (dấu cách < 'T'), nên MỌI đơn đều lọt qua và bộ lọc ranh ca
            // im lặng thành vô dụng. Bài test âm "đơn sinh SAU ranh ca" bắt được.
            ->where('created_at', '<', Carbon::parse($cutoffIso))
            ->orderBy('created_at')
            ->get(['id', 'order_code', 'status', 'total_amount', 'table_id', 'created_at'])
            ->map(fn (CustomerOrder $o): UnresolvedOrderSummary => new UnresolvedOrderSummary(
                orderId: (string) $o->id,
                orderCode: $o->order_code === null ? null : (string) $o->order_code,
                status: $o->status instanceof \BackedEnum ? $o->status->value : (string) $o->status,
                totalAmount: (float) $o->total_amount,
                tableReleased: $o->table_id === null,
                createdAt: Carbon::parse($o->created_at)->toIso8601String(),
            ))
            ->all();
    }

    public function voidedForBranchBetween(string $branchId, ?string $from, ?string $to): array
    {
        return CustomerOrder::query()
            ->where('branch_id', $branchId)
            ->where('status', CustomerOrderStatusEnum::Voided->value)
            ->whereNotNull('voided_at')
            ->when($from, fn ($q, $start) => $q->where('voided_at', '>=', $start))
            ->when($to, fn ($q, $end) => $q->where('voided_at', '<=', $end))
            ->get(['id', 'total_amount'])
            ->map(fn (CustomerOrder $o): VoidedOrderSummary => new VoidedOrderSummary(
                orderId: (string) $o->id,
                totalAmount: (float) $o->total_amount,
            ))
            ->all();
    }

    public function guestCountForOrders(array $orderIds): int
    {
        if ($orderIds === []) {
            return 0;
        }

        return (int) CustomerOrder::query()
            ->whereIn('id', $orderIds)
            ->sum('guest_count');
    }

    public function itemQuantityForOrders(array $orderIds): int
    {
        if ($orderIds === []) {
            return 0;
        }

        return (int) CustomerOrderItem::query()
            ->whereIn('customer_order_id', $orderIds)
            ->where('status', '!=', OrderItemStatusEnum::Voided->value)
            ->sum('quantity');
    }

    public function revenueForOrders(array $orderIds): OrderRevenueRollup
    {
        if ($orderIds === []) {
            return OrderRevenueRollup::empty();
        }

        $ledger = OrderCondition::query()
            ->selectRaw("conditionable_id,
                SUM(CASE WHEN type = 'tax' THEN amount ELSE 0 END) AS tax,
                SUM(CASE WHEN type = 'discount' THEN amount ELSE 0 END) AS discount")
            ->where('conditionable_type', (new CustomerOrder)->getMorphClass())
            ->whereIn('type', ['tax', 'discount'])
            ->groupBy('conditionable_id');

        // `net` vẫn là total - tax; các thành phần tiền nay chỉ đọc từ sổ.
        $row = CustomerOrder::query()
            ->leftJoinSub($ledger, 'condition_ledger', 'condition_ledger.conditionable_id', '=', 'customer_orders.id')
            ->whereIn('id', $orderIds)
            ->selectRaw('
                SUM(total_amount) as gross,
                SUM(total_amount - COALESCE(condition_ledger.tax, 0)) as net,
                SUM(COALESCE(condition_ledger.tax, 0)) as tax,
                SUM(ABS(COALESCE(condition_ledger.discount, 0))) as discount
            ')
            ->first();

        if ($row === null) {
            return OrderRevenueRollup::empty();
        }

        return new OrderRevenueRollup(
            gross: (float) ($row->gross ?? 0),
            net: (float) ($row->net ?? 0),
            tax: (float) ($row->tax ?? 0),
            discount: (float) ($row->discount ?? 0),
        );
    }
}
