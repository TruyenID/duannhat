<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 — một DÒNG MÓN của đơn đã đóng, ở mức Ordering thật sự sở hữu.
 *
 * Ba trường, và cố ý không hơn:
 *
 *   id            khoá của lượt đánh giá (`product_reviews.customer_order_item_id`)
 *   productSkuId  BIẾN THỂ đã bán — mỏ neo để hỏi Catalog tên/ảnh/tên biến thể
 *   unitPrice     đơn giá hiển thị trên thẻ đánh giá ("¥1,650")
 *
 * KHÔNG có `productId` ở đây: Ordering chỉ ghi `product_sku_id` trên dòng món,
 * còn "SKU này thuộc sản phẩm nào" là dữ liệu của Catalog. Nhét nó vào đây buộc
 * Ordering phải đi qua `product_skus` để trả lời một câu hỏi của module khác —
 * đúng cái {@see BranchOrderReads} đã ghi là "sai chiều".
 *
 * `unitPrice` giữ nguyên giá trị model trả về (cast thập phân ⇒ chuỗi) vì phía
 * gọi ép `(float)` khi dựng payload; ép sẵn ở đây là đoán thay chỗ gọi.
 */
final readonly class ReviewableOrderLine
{
    public function __construct(
        public string $id,
        public ?string $productSkuId,
        public mixed $unitPrice,
    ) {}
}
