<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Services\Order\Contracts\OrderRowLock;
use Illuminate\Support\Facades\DB;

/**
 * #1622 — hiện thực {@see OrderRowLock}.
 *
 * Chép NGUYÊN câu truy vấn của `PosInvoiceService`, kể cả việc dùng query
 * builder THÔ thay vì `CustomerOrder::query()`:
 *
 * `DB::table('customer_orders')` **không** áp global scope, nên nó khoá được cả
 * dòng đã xoá mềm. Đổi sang model builder sẽ thêm `whereNull('deleted_at')` —
 * một đơn đã xoá mềm khi đó **không bị khoá**, và hai request đồng thời lại đi
 * song song trên đúng chỗ #821 A9 dựng lock để chặn. Bảng này thuộc Ordering
 * nên đọc thẳng ở ĐÂY là trong module, không phải nợ xuyên module.
 */
final class EloquentOrderRowLock implements OrderRowLock
{
    public function lockForUpdate(string $orderId): void
    {
        DB::table('customer_orders')
            ->where('id', $orderId)
            ->lockForUpdate()
            ->first();
    }
}
