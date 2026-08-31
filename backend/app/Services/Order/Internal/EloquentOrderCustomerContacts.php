<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Models\CustomerOrder;
use App\Services\Order\Contracts\OrderCustomerContacts;

/**
 * #962 — hiện thực {@see OrderCustomerContacts}.
 *
 * Hai truy vấn chép NGUYÊN từ `RecallService::notify()` và
 * `RecallDrillService::computeCompleteness()`, kể cả hình dạng mệnh đề `where`
 * lồng của câu thứ hai — `customer_takeaway_phone IS NOT NULL OR customer_id IS
 * NOT NULL`. Đổi nó thành hai `orWhere` phẳng sẽ nuốt mất cặp ngoặc và làm điều
 * kiện `whereIn` bị `OR` cuốn theo, tức diễn tập sẽ đếm cả đơn ngoài phạm vi.
 *
 * Danh sách rỗng được chặn TRƯỚC khi chạm DB: `whereIn('id', [])` là một truy vấn
 * chắc chắn không trả gì, và cả hai chỗ gọi đều có ca rỗng thật (thu hồi chưa
 * chạm đơn nào).
 */
final class EloquentOrderCustomerContacts implements OrderCustomerContacts
{
    public function customerIdsByOrderId(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return CustomerOrder::query()
            ->whereIn('id', $orderIds)
            ->pluck('customer_id', 'id')
            ->map(static fn ($customerId): ?string => $customerId === null ? null : (string) $customerId)
            ->all();
    }

    public function orderIdsWithReachableContact(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        return CustomerOrder::query()
            ->whereIn('id', $orderIds)
            ->where(function ($q): void {
                $q->whereNotNull('customer_takeaway_phone')
                    ->orWhereNotNull('customer_id');
            })
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();
    }
}
