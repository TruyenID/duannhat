<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #1605 — Ordering công bố cách tra {@see OrderStockContext} theo id đơn.
 *
 * Trước PR này `StockDeductionService` (Inventory) gọi `CustomerOrder::find()` ở
 * năm chỗ chỉ để lấy sáu trường vô hướng. Cổng này thay đúng năm lời gọi đó.
 *
 * Không tìm thấy ⇒ `null`, KHÔNG ném. Bản cũ cũng vậy (`if ($order === null)
 * return;` ở cả năm chỗ), và lý do thì không đổi: người gọi thường đang ở giữa
 * một transaction bán hàng, nên ném ở đây sẽ cuộn ngược cả việc bán hàng vì một
 * đơn vừa bị xoá song song.
 */
interface OrderStockContextReads
{
    public function find(string $orderId): ?OrderStockContext;
}
