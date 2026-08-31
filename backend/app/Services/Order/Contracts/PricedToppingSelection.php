<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 · 7a-8 — kết quả định giá topping cho MỘT dòng đơn.
 *
 * Hai trường, y hệt mảng `['topping_subtotal' => …, 'rows' => …]` mà
 * `ToppingSelectionPricer` trả về trước khi nó dọn sang Catalog. Đổi tên khoá
 * thành thuộc tính chứ KHÔNG đổi số: `subtotal` vẫn là tổng **trên một đơn vị**
 * đã áp chiến lược nhóm (free_up_to_n…) và đã `round(…, 2)`, còn `rows` vẫn là
 * từng dòng ở **giá đầy đủ** — phần giảm sống ở mức DÒNG, không ở mức row
 * (plan-015 DESIGN.md Decision 6). Gộp hai thứ đó lại là ghi sai
 * `order_item_toppings`.
 *
 * `rows` cố ý giữ nguyên hình dạng mảng thô: ba chỗ gọi đổ thẳng nó vào
 * `OrderItemTopping::create()` và `OrderToppingPayload`, nên bọc thêm một lớp
 * value-object nữa chỉ để mở ra ngay là công không mang lại gì.
 */
final readonly class PricedToppingSelection
{
    /**
     * `waived_quantity` (#2619): số đơn vị của TỪNG row được free_up_to_n
     * miễn — dấu vết CÁI NÀO miễn, persist thẳng vào
     * `order_item_toppings.waived_quantity`. 0 dưới flat.
     *
     * @param  array<int, array{topping_group_item_id: string, product_sku_id: string, quantity: int, unit_price: float, note: string|null, waived_quantity: int}>  $rows
     */
    public function __construct(
        public float $subtotal,
        public array $rows,
    ) {}
}
