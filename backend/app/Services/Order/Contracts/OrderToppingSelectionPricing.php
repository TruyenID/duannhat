<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

use Illuminate\Validation\ValidationException;

/**
 * #962 · 7a-8 — Ordering hỏi Catalog "khách chọn từng này topping thì hết bao nhiêu,
 * và lựa chọn đó có hợp lệ không".
 *
 * ## Vì sao cả CLASS chuyển sang Catalog, không phải bọc từng model
 *
 * `App\Services\Order\Internal\ToppingSelectionPricer` là 297 dòng luật **Catalog**
 * sống nhầm trong Ordering: nhóm topping gắn vào sản phẩm (`product_topping_groups`
 * + override trên pivot), `is_default` của từng item, `sort_order`, `price_strategy`
 * và `free_quantity` của nhóm, mã sản phẩm `combo`. Không có dòng nào trong đó là
 * luật của ĐƠN HÀNG.
 *
 * Đã đo cả hai hướng trước khi chọn:
 *
 * | hướng | cổng phải khai | cạnh gỡ được | thứ còn lại ở Ordering |
 * |---|---|---|---|
 * | bọc từng model | ≥6 (item topping, nhóm+pivot override, item theo nhóm, sku của item, productType, giá) | 2 | vẫn 297 dòng luật Catalog |
 * | **chuyển cả class** | **1** | 2 | 0 |
 *
 * Hướng "bọc" gỡ đúng bằng ấy cạnh **đếm được** nhưng dựng lại toàn bộ bề mặt đọc
 * của Catalog dưới dạng sáu cổng — cùng một mức phụ thuộc, thêm một lớp gián tiếp,
 * và luật topping vẫn nằm ở module không sở hữu bảng nào của nó. Nên: chuyển.
 *
 * ## Đường vào là ID, không phải model
 *
 * Chỗ gọi từng truyền `ProductSku` đã nạp sẵn. Cổng nhận `string $productSkuId` và
 * hiện thực tự nạp — đó là +1 truy vấn cho mỗi dòng đơn, chấp nhận có ý thức: một
 * cổng mang model là cạnh y như cũ, chỉ khoác áo interface.
 *
 * ## Lỗi vẫn là `ValidationException` với khoá `items.*`
 *
 * Sáu mã lỗi có cấu trúc (`toppings_below_min`, `toppings_above_max`,
 * `topping_qty_above_max`, `topping_group_not_attached`, `topping_item_inactive`,
 * `topping_item_no_price`) được FE đọc đích danh và có tài liệu ở
 * plan-015 DESIGN (đã archive rồi xoá khỏi cây #2188 — xem git history). Giữ NGUYÊN, kể cả tiền tố khoá `items.` vốn
 * mang hình dạng payload của Ordering: đây là PR ranh giới, đổi hợp đồng lỗi kèm
 * theo là đổi thứ mà không test nào ở đây bắt được.
 */
interface OrderToppingSelectionPricing
{
    /**
     * Hợp lệ hoá + định giá các lựa chọn topping của một dòng đơn.
     *
     * `$menuProductId` mở tầng SHOP (`menu_product_topping_item_overrides`) và
     * `$floatingSectionProductId` mở tầng floating-section (#1180). Hai cái này
     * KHÔNG thay thế nhau: người gọi phải truyền đúng cái ứng với bề mặt mà khách
     * đã nhìn thấy giá, nếu không guest được hiện một giá và bị tính một giá khác.
     *
     * @param  array<int, array{topping_group_item_id: string, product_sku_id: string, quantity: int, note?: string|null}>  $toppings
     *
     * @throws ValidationException mã lỗi có cấu trúc, xem docblock của interface
     */
    public function priceForSku(
        string $productSkuId,
        array $toppings,
        ?string $menuProductId = null,
        ?string $floatingSectionProductId = null,
    ): PricedToppingSelection;
}
