<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Customer\Internal\EloquentCustomerNotifiableDirectory;
use App\Services\Inventory\Contracts\CustomerNotifiableDirectory;
use App\Services\Inventory\Contracts\VoidReasonStockEffects;
use App\Services\Order\Internal\EloquentVoidReasonStockEffects;
use Illuminate\Support\ServiceProvider;

/**
 * #962 — bind các cổng mà INVENTORY khai và module khác hiện thực.
 *
 * Bind ở provider của bên TIÊU THỤ, đúng tiền lệ `OrderServiceProvider`
 * (`CouponPricing`, `TableOccupancy`, `BranchCurrency`): người khai hợp đồng là
 * người biết vì sao nó tồn tại, nên chỗ nối dây đọc cùng chỗ với chỗ khai.
 *
 * Cổng đầu do Ordering hiện thực (`void_reasons` là bảng của Ordering); cổng sau
 * do CustomerEngagement hiện thực (`customers`).
 *
 * Hai cổng nữa mà Inventory cần — thời điểm trừ kho theo chi nhánh, và
 * `customer_id` theo đơn — KHÔNG được khai lại ở đây: Ordering đã công bố sẵn
 * `Order\Contracts\BranchStockDeductionTiming` và `Order\Contracts\
 * OrderCustomerContacts` cho cùng hai câu hỏi đó, và `OrderServiceProvider` đã
 * nối dây. Khai bản thứ hai chỉ tạo ra hai cổng cùng đọc một bảng, tức đúng thứ
 * epic này đang dọn.
 */
final class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bảng sự thật #1149 — restock / waste / không rõ.
        $this->app->bind(VoidReasonStockEffects::class, EloquentVoidReasonStockEffects::class);

        // Thu hồi lô (plan-017): khách → người nhận thông báo.
        $this->app->bind(CustomerNotifiableDirectory::class, EloquentCustomerNotifiableDirectory::class);
    }
}
