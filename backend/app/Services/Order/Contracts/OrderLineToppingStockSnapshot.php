<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #1731 — ảnh chụp một TOPPING đã chọn trên dòng đơn, phần mà trừ kho đọc.
 *
 * Đúng ba trường, đo bằng cách quét thân `StockDeductionService`:
 * `product_sku_id` (tra công thức topping), `quantity` (nhân với số lượng dòng
 * cha), và trạng thái — nhưng trạng thái CHỈ được hỏi một câu duy nhất:
 * *"topping này có bị huỷ không"*.
 *
 * Vì thế trường ở đây là `voided: bool` chứ không phải cả enum trạng thái. Mang
 * nguyên enum sang là công bố cho Inventory quyền phân biệt
 * `pending`/`preparing`/`ready`/`served` — bốn trạng thái mà đường trừ kho chưa
 * từng phân biệt, và người sau sẽ tưởng là mình được phép dựa vào.
 */
final readonly class OrderLineToppingStockSnapshot
{
    public function __construct(
        public ?string $productSkuId,
        public float $quantity,
        public bool $voided,
    ) {}
}
