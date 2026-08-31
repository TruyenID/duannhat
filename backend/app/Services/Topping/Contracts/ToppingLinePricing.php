<?php

declare(strict_types=1);

namespace App\Services\Topping\Contracts;

use App\Omnify\Enums\ToppingGroupPriceStrategyEnum;

/**
 * #1597 — cổng Catalog công bố: **tính tiền topping cho một dòng món**.
 *
 * Bản cũ nhận `App\Models\ToppingGroup` chỉ để đọc **hai trường**:
 * `price_strategy` và `free_quantity` (đo trong thân `priceLine`, không đọc
 * lướt). Bằng chứng mạnh nhất rằng tham số model là sai:
 * `OfflineOrderEvidenceVerifier` phải **dựng một `ToppingGroup` GIẢ**
 * (`new ToppingGroup([...])`, không lưu, không id) chỉ để truyền hai giá trị đó
 * qua chữ ký.
 *
 * Đây là **thu hẹp thuần** (#1612): chỗ gọi đã cầm sẵn cấu hình nhóm, nên đổi
 * chữ ký không thêm truy vấn nào — và bỏ hẳn cái model giả.
 *
 * `ToppingGroupPriceStrategyEnum` nằm trong `App\Omnify\Enums`, vốn `shared`.
 */
interface ToppingLinePricing
{
    /**
     * Tiền topping cho MỘT đơn vị của dòng cha, trong phạm vi MỘT nhóm topping.
     *
     * Chỗ gọi phải lọc theo nhóm trước: chiến lược giá và số lượng miễn phí là
     * **của từng nhóm**, trộn nhóm là ra số sai.
     *
     * `waived_by_selection` (#2619): mỗi selection ĐẦU VÀO (cùng index) được
     * miễn bao nhiêu đơn vị bởi free_up_to_n — chính là giá trị persist vào
     * `order_item_toppings.waived_quantity`. Flat thì toàn 0.
     *
     * @param  array<int, array{topping_group_item_id: string, product_sku_id: string, quantity: int, unit_price: float|string}>  $selections
     * @return array{topping_subtotal: float, breakdown: array<int, array{topping_group_item_id: string, product_sku_id: string, unit_price: float, charged: bool}>, waived_by_selection: array<int, int>}
     */
    public function priceLine(
        array $selections,
        ToppingGroupPriceStrategyEnum|string|null $priceStrategy,
        int $freeQuantity,
    ): array;

    /**
     * Như trên nhưng cho NHIỀU nhóm cùng lúc, trả breakdown phẳng.
     *
     * @param  array<string, array{price_strategy: ToppingGroupPriceStrategyEnum|string|null, free_quantity: int, selections: array<int, array{topping_group_item_id: string, product_sku_id: string, quantity: int, unit_price: float|string}>}>  $groupedSelections
     * @return array{topping_subtotal: float, breakdown: array<int, array{topping_group_id: string, topping_group_item_id: string, product_sku_id: string, unit_price: float, charged: bool}>, waived_by_selection: array<string, array<int, int>>}
     */
    public function priceLineAcrossGroups(array $groupedSelections): array;

    /**
     * Giá gốc của một dòng topping tại thời điểm bán, đã đi qua các tầng ghi đè
     * (shop → menu/floating → HQ). Ném khi không có dòng giá nào.
     */
    public function resolveSnapshotPrice(
        string $toppingGroupItemId,
        string $productSkuId,
        ?string $productId = null,
        ?string $toppingGroupId = null,
        ?string $menuProductId = null,
        ?string $floatingSectionProductId = null,
    ): float;
}
