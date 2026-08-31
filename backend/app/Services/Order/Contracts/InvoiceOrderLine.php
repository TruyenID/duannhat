<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 (7b) — một dòng món CHƯA VOID của đơn, đã giải sẵn mọi thứ mà một
 * 適格請求書 / hoá đơn GTGT cần in.
 *
 * ## Vì sao `name` đã giải ngôn ngữ ở phía Ordering
 *
 * Tên MÓN phải là tên **sản phẩm** trong ngôn ngữ in của cửa hàng, KHÔNG phải
 * `sku.name` — với SKU theo tuỳ chọn thì `sku.name` là nhãn BIẾN THỂ ("Thường"),
 * và `CustomerOrderItem::menu_item_name` trả `ProductSku.name` TRƯỚC, nên tin nó
 * đã từng in "Thường" thay cho "Gỏi Đu Đủ". Chuỗi dự phòng đúng là
 * `product->localizedName($locale) ?: product->name ?: menu_item_name`, và nó đi
 * qua ba quan hệ của Catalog.
 *
 * Giải ở đây (Ordering) là cố ý: nếu cổng trả về id sản phẩm và để Payments tự
 * giải, thì Payments phải đọc `products` + `product_translations` — một cạnh
 * Payments→Catalog mới, đổi một cạnh lấy một cạnh.
 *
 * ## Vì sao tiền là CHUỖI
 *
 * `unitPrice` / `toppingSubtotal` / `subtotal` / `taxRate` / `taxAmount` giữ
 * nguyên kiểu chuỗi mà đường cũ ghi vào `items_json`. Đó là snapshot pháp lý:
 * số đã đóng băng lúc thanh toán, không phải số để tính tiếp. Đưa qua float ở
 * tầng trung gian là mời một lần làm tròn không ai yêu cầu vào giữa đơn hàng và
 * hoá đơn.
 */
final readonly class InvoiceOrderLine
{
    /** @param list<InvoiceOrderLineTopping> $toppings */
    public function __construct(
        public string $itemId,
        public ?string $name,
        public ?string $variant,
        public int $quantity,
        public string $unitPrice,
        public string $toppingSubtotal,
        public string $subtotal,
        public ?string $taxRate,
        public ?string $taxAmount,
        public ?string $note,
        public array $toppings,
    ) {}
}
