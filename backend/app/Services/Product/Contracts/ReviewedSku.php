<?php

declare(strict_types=1);

namespace App\Services\Product\Contracts;

/**
 * #962 — ảnh chụp một BIẾN THỂ đã bán, đủ để dựng thẻ đánh giá sau đơn.
 *
 * ## Vì sao `productId` và `product` là HAI trường, không phải một
 *
 * Đây không phải dư thừa mà là hai câu hỏi khác nhau, và luồng đánh giá dùng cả
 * hai theo hai đường:
 *
 * - `productId` là **khoá ngoại thô** trên `product_skus`. Nó còn đó kể cả khi
 *   sản phẩm đã bị xoá mềm. Luồng CHỐNG GIẢ MẠO dùng đúng cột này: `product_id`
 *   client gửi lên phải khớp sản phẩm đã thực sự được đặt, và một sản phẩm bị
 *   xoá sau khi đơn đóng không được phép biến phép so sánh đó thành "khớp với
 *   null".
 * - `product` là **quan hệ đã phân giải** — `null` khi sản phẩm không còn đọc
 *   được. Thẻ đánh giá hiển thị theo trường này, nên món có sản phẩm đã xoá hiện
 *   nhãn mặc định thay vì tên rác.
 *
 * Gộp làm một là ép hai đường phải chọn cùng một câu trả lời, và đường thua sẽ
 * là đường bảo mật.
 */
final readonly class ReviewedSku
{
    public function __construct(
        public string $id,
        public ?string $productId,
        /** Tên biến thể (`product_skus.name`) — nhãn phụ trên thẻ. */
        public ?string $name,
        public ?ReviewedProduct $product,
    ) {}
}
