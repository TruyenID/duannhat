<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Models\CustomerOrder;
use App\Services\Order\Contracts\BranchDebtOrderAnchors;
use App\Services\Order\Contracts\DebtOrderAnchor;

/**
 * #1993 — hiện thực {@see BranchDebtOrderAnchors}.
 *
 * Đọc qua MODEL chứ không `DB::table`, và đó là điểm chính chứ không phải thói
 * quen: `SoftDeletes` scope tự loại đơn đã xoá, nên "nợ của một đơn đã xoá không
 * còn là nợ" đúng theo cấu trúc. Bản `DB::table` trước đây phải nhớ viết
 * `whereNull('o.deleted_at')` — và đã quên, trong khi hàm `partPaid()` ngay bên
 * cạnh thì nhớ.
 *
 * Danh sách rỗng chặn TRƯỚC khi chạm DB, cùng lý do với
 * {@see EloquentOrderCustomerContacts}: một chi nhánh chưa ai nợ là ca thật và
 * xảy ra thường xuyên.
 */
final class EloquentBranchDebtOrderAnchors implements BranchDebtOrderAnchors
{
    public function anchorsForBranch(string $branchId, array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $anchors = [];

        CustomerOrder::query()
            ->whereIn('id', $orderIds)
            ->where('branch_id', $branchId)
            ->get(['id', 'customer_id', 'order_code'])
            ->each(function (CustomerOrder $order) use (&$anchors): void {
                $anchors[(string) $order->id] = new DebtOrderAnchor(
                    orderId: (string) $order->id,
                    customerId: $order->customer_id === null ? null : (string) $order->customer_id,
                    orderCode: $order->order_code === null ? null : (string) $order->order_code,
                );
            });

        return $anchors;
    }
}
