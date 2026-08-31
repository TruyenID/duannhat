<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 · 7a-8 — mọi thứ đường ghi đơn đọc từ MỘT `product_skus`, dưới dạng scalar.
 *
 * Đo bằng cách quét thân ba chỗ gọi (`WritesCustomerOrders::addItems`, `::editItem`,
 * `CustomerOrderPricingResolution::resolveLineEntry`): `id`, `isSellable()`,
 * `selling_price`, `product_id`, `product?->tax_type_id`, `product?->brand_id`,
 * `product?->organization_id`. Không thêm trường nào chưa có chỗ gọi — cổng rộng
 * hơn nhu cầu là mời người sau với tay vào Catalog qua đường khác.
 *
 * ## `productResolved` KHÔNG suy ra được từ `productId`
 *
 * `product_id` là CỘT, `product` là QUAN HỆ có `SoftDeletingScope`. Một SKU trỏ
 * vào sản phẩm đã xoá mềm có `product_id` khác null nhưng quan hệ trả null, và
 * `editItem` phân nhánh đúng trên `$product !== null` (bỏ qua bước đóng lại thuế).
 * Gộp hai cái làm một sẽ đóng dấu một tỉ lệ mới lên dòng đơn của sản phẩm đã bị gỡ
 * khỏi catalog — sai lặng lẽ, vì tỉ lệ là snapshot bất biến không ai đối chiếu lại.
 *
 * ## `sellable` mang nguyên định nghĩa `ProductSku::isSellable()`
 *
 * `is_active` VÀ sản phẩm cha ở trạng thái `Active`. Đó là luật của Catalog nên đi
 * kèm kết quả, không để chỗ gọi tự ghép lại từ hai cờ rời — cùng lý do
 * `App\Services\Product\Contracts\SellableSkuPrice` đã chốt ở #1597.
 */
final readonly class OrderLineSkuAnchor
{
    public function __construct(
        public string $skuId,
        /** Cột `product_skus.product_id` — có thể còn giá trị khi sản phẩm đã xoá mềm. */
        public ?string $productId,
        /** Quan hệ `product` giải được hay không (xoá mềm ⇒ `false`). */
        public bool $productResolved,
        public bool $sellable,
        public float $sellingPrice,
        public ?string $productTaxTypeId,
        public ?string $productBrandId,
        public ?string $productOrganizationId,
    ) {}
}
