<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #1731 — ảnh chụp một DÒNG ĐƠN, đúng phần mà động cơ trừ kho đọc.
 *
 * Bảy trường + danh sách topping là TOÀN BỘ những gì
 * `App\Services\Inventory\StockDeductionService` từng lấy ra khỏi
 * `App\Models\CustomerOrderItem` — đo bằng cách liệt kê mọi `$item->…` trong
 * thân class, không đọc lướt:
 *
 * | trường                  | dùng ở đâu                                             |
 * |-------------------------|--------------------------------------------------------|
 * | `id`                    | nhãn ghi chú phiếu kho, dấu đã-trừ, khoá gộp            |
 * | `orderId`               | tra {@see OrderStockContext}; lọc dòng theo đơn         |
 * | `productSkuId`          | dòng phiếu xuất kho + tra công thức                     |
 * | `quantity`              | hệ số nhân công thức, số lượng xuất                     |
 * | `unitPrice`             | `stock_transaction_items.unit_price`                    |
 * | `stockDeductedAt`       | cổng chặn: đã trừ rồi thì mọi hook sau là no-op         |
 * | `stockOutTransactionId` | neo của bút toán bù khi huỷ món                         |
 * | `toppings`              | plan-040 M5 (TH.3) — nguyên liệu topping                |
 *
 * ## Vì sao KHÔNG dùng lại {@see ReviewableOrderLine} hay `InvoiceOrderLine`
 *
 * Hai cổng đó mang **tiền và tên** (thành tiền, thuế, nhãn hiển thị) và không
 * mang `stock_deducted_at` — thứ quyết định của đường này. Nhồi thêm hai cột
 * đánh dấu kho vào một cổng hoá đơn là mở rộng cổng đã công bố cho một người
 * dùng thứ hai không liên quan; luật này đã ghi ở docblock của
 * {@see OrderSnapshot} và áp y hệt ở đây.
 *
 * ## KHÔNG mang `status`
 *
 * Trạng thái dòng chỉ được dùng làm **bộ lọc truy vấn** (`!= voided`), và bộ lọc
 * đó nằm bên trong {@see OrderStockLineReads} — tức bên Ordering, chủ sở hữu
 * bảng. Mang `status` ra đây là mời Inventory tự quyết định lại nghĩa của trạng
 * thái đơn hàng.
 */
final readonly class OrderLineStockSnapshot
{
    /**
     * @param  list<OrderLineToppingStockSnapshot>  $toppings
     */
    public function __construct(
        public string $id,
        public string $orderId,
        public ?string $productSkuId,
        public float $quantity,
        public ?float $unitPrice,
        public ?\DateTimeImmutable $stockDeductedAt,
        public ?string $stockOutTransactionId,
        public array $toppings = [],
    ) {}

    public function isDeducted(): bool
    {
        return $this->stockDeductedAt !== null;
    }
}
