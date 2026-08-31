<?php

declare(strict_types=1);

namespace App\Services\Product\Contracts;

/**
 * #962 — SẢN PHẨM đứng sau một biến thể đã bán, ở mức thẻ đánh giá cần.
 *
 * `imageUrl` là URL đã phân giải chứ không phải id file: bản cũ gọi
 * `$product->galleryFirst?->getUrl()` ngay tại chỗ dựng payload, và trả id ra
 * ngoài sẽ buộc phía tiêu thụ phải biết model `File` để tự dựng URL — đổi
 * một cạnh model lấy một cạnh model khác.
 */
final readonly class ReviewedProduct
{
    public function __construct(
        public string $id,
        public ?string $name,
        public ?string $imageUrl,
    ) {}
}
