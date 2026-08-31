<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #1605 — ảnh chụp phần ĐƠN mà động cơ trừ kho đọc, đúng sáu trường.
 *
 * Đo bằng cách quét thân `App\Services\Inventory\StockDeductionService`, không
 * đọc lướt: đó là TOÀN BỘ những gì nó từng lấy ra khỏi `App\Models\CustomerOrder`.
 *
 * | trường           | dùng ở đâu                                                    |
 * |------------------|---------------------------------------------------------------|
 * | `id`             | `stock_transactions.reference_id`, khoá tra dòng, mọi dòng log |
 * | `organizationId` | `stock_transactions.organization_id`                           |
 * | `branchId`       | phân giải kho mặc định                                         |
 * | `orderCode`      | ghi chú của phiếu kho (người vận hành đọc)                     |
 * | `createdById`    | `stock_transactions.created_by_id` (NOT NULL)                  |
 * | `customerId`     | mắt CUỐI của chuỗi dự phòng cho cột NOT NULL ở trên            |
 *
 * Cố ý KHÔNG tái dùng {@see OrderSnapshot}: bộ trường của nó là tập Payments đo
 * được (`status`, `totalAmount`, `paidAmount`, `brandId`) và thiếu đúng hai thứ
 * cần ở đây (`createdById`, `customerId`). Nhét thêm hai accessor vào đó là mở
 * rộng một cổng đã công bố cho một người dùng thứ hai không liên quan — chính
 * điều docblock của `OrderSnapshot` cảnh báo.
 *
 * KHÔNG có dòng đơn ở đây, và đó là ranh giới thật chứ không phải thiếu sót:
 * động cơ trừ kho còn đi tiếp qua `productSku.recipe` + `orderItemToppings` để
 * tới danh mục (Catalog), nên một ảnh chụp dòng đơn chỉ dời cạnh Catalog sang
 * Ordering. Phần đó chờ Catalog công bố cổng đọc (#1567).
 *
 * `createdById` và `customerId` đều nullable vì cột nguồn nullable — chuỗi dự
 * phòng `created_by ?? auth()->id() ?? customer_id` sống ở Inventory (nó biết
 * cột đích NOT NULL), không phải ở đây.
 */
final readonly class OrderStockContext
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $branchId,
        public string $orderCode,
        public ?string $createdById,
        public ?string $customerId,
    ) {}
}
