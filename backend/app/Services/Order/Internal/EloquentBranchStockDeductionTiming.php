<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Models\ShopOrderSetting;
use App\Services\Order\Contracts\BranchStockDeductionTiming;
use Illuminate\Database\QueryException;

/**
 * #962 — hiện thực {@see BranchStockDeductionTiming}.
 *
 * Truy vấn chép nguyên từ `StockDeductionService::timingForBranch()`, kể cả
 * `catch (QueryException)`: chỗ gọi cũ nuốt lỗi truy vấn để một cấu hình không
 * đọc được không làm hỏng cả đường bán hàng. Bắt lỗi đi CÙNG truy vấn, vì nó nói
 * về truy vấn — nhưng nó chỉ trả `null`, còn *chọn cái gì khi null* vẫn là việc
 * của Inventory.
 *
 * Cột có cast enum ở model, nên `value()` có thể trả về `StockDeductionTimingEnum`
 * chứ không phải chuỗi. Ép về `->value` tại đây để cổng luôn giữ đúng lời hứa
 * `?string` — đây là chi tiết lưu trữ của Ordering, không phải thứ người gọi
 * phải đoán.
 */
final class EloquentBranchStockDeductionTiming implements BranchStockDeductionTiming
{
    public function rawTimingFor(string $branchId): ?string
    {
        try {
            $raw = ShopOrderSetting::query()
                ->where('branch_id', $branchId)
                ->value('stock_deduction_timing');
        } catch (QueryException) {
            return null;
        }

        if ($raw === null) {
            return null;
        }

        return $raw instanceof \BackedEnum ? (string) $raw->value : (string) $raw;
    }
}
