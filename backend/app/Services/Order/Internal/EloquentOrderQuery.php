<?php

namespace App\Services\Order\Internal;

use App\Models\CustomerOrder;
use App\Services\Customer\OrderClosingService;
use App\Services\Order\Contracts\OrderQueryPort;
use App\Services\Order\Contracts\OrderSnapshot;

/**
 * The read side of the Ordering boundary (#1544).
 *
 * `OrderQueryPort` and `OrderSnapshot` existed as interfaces with NO
 * implementation and NO container binding — `app()->make(OrderQueryPort::class)`
 * would throw. They were there only so `DomainMutationContractsTest` could see a
 * complete facade/persistence/query triplet, and that test reflects over method
 * signatures without ever resolving the port. A pipe with nothing in it passed
 * the gate for as long as it existed.
 *
 * Meanwhile Payments read orders by importing `App\Models\CustomerOrder`
 * directly in ten files. The opposite direction was already done right —
 * `PaymentQueryPort` + `EloquentPaymentQuery` + a binding — so this class is
 * deliberately shaped after that one rather than invented.
 */
final class EloquentOrderQuery implements OrderQueryPort
{
    public function findById(string $organizationId, string $orderId): ?OrderSnapshot
    {
        $order = CustomerOrder::query()
            ->where('organization_id', $organizationId)
            ->where('id', $orderId)
            ->first();

        return $order === null ? null : CustomerOrderSnapshot::fromModel($order);
    }

    /**
     * Same row, read inside the caller's transaction with a row lock.
     *
     * Settlement decides whether an order is paid in full and must not race a
     * concurrent payment writing the same row — `EloquentPaymentPersistence`
     * already takes this lock by hand today. Keeping the lock INSIDE the port is
     * what lets that caller drop its direct model import instead of keeping one
     * exception that quietly re-opens the boundary.
     */
    public function findForSettlement(string $organizationId, string $orderId): ?OrderSnapshot
    {
        $order = CustomerOrder::query()
            ->where('organization_id', $organizationId)
            ->where('id', $orderId)
            ->lockForUpdate()
            ->first();

        return $order === null ? null : CustomerOrderSnapshot::fromModel($order);
    }

    public function isPaidInFull(string $organizationId, string $orderId): bool
    {
        $order = CustomerOrder::query()
            ->where('organization_id', $organizationId)
            ->where('id', $orderId)
            ->first();

        // Đơn không tồn tại KHÔNG phải "đã trả đủ". Trước đây người gọi viết
        // `$order !== null && isPaidEnough($order)`; giữ nguyên nghĩa đó ở đây
        // để việc dời chỗ không lặng lẽ biến một đơn đã bị xoá thành một đơn
        // cần tất toán.
        return $order !== null && OrderClosingService::isPaidEnough($order);
    }
}
