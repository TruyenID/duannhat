<?php

declare(strict_types=1);

namespace App\Services\Customer\ValueObjects;

/**
 * #962 — cái mà luồng đánh giá thật sự cần biết về một đơn ĐÃ ĐÓNG.
 *
 * `ProductReviewService::submit()` từng nhận nguyên model Eloquent `CustomerOrder`
 * của Ordering. Đo bằng cách quét thân method: nó đọc đúng **bốn** cột — id để khoá lượt đánh
 * giá, và ba mỏ neo tenancy đóng dấu lên `product_reviews` để báo cáo lọc được
 * theo tổ chức/thương hiệu/chi nhánh. Ba mươi cột còn lại của đơn (tiền, trạng
 * thái, thanh toán) đi kèm chỉ vì kiểu tham số.
 *
 * Chốt chặn "đơn có tồn tại và đã đóng chưa" vẫn ở controller như trước — VO
 * này KHÔNG tự nhận là bằng chứng đơn hợp lệ, nó chỉ là bốn cột đã đọc xong.
 */
final readonly class ReviewedOrder
{
    public function __construct(
        public string $id,
        public ?string $organizationId,
        public ?string $brandId,
        public ?string $branchId,
    ) {}
}
