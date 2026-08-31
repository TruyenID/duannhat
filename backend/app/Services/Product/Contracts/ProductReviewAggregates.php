<?php

declare(strict_types=1);

namespace App\Services\Product\Contracts;

use App\Omnify\Enums\ReviewSentimentEnum;

/**
 * #962 — cổng Catalog công bố cho CustomerEngagement: **cộng dồn đánh giá lên
 * bộ đếm của sản phẩm**.
 *
 * Ba cột `review_total_count` / `review_up_count` / `review_rating_sum` sống
 * trên `products`, tức Catalog sở hữu chúng. `ProductReviewService` trước đây
 * tự `Product::…->lockForUpdate()` rồi gọi thẳng
 * `App\Services\Product\Internal\EloquentProductPersistence` — với tay vào phần
 * **Internal** của module khác, đúng thứ #1583 đã đóng cửa.
 *
 * ## Vì sao HAI method chứ không phải một "khoá-và-cộng"
 *
 * Thứ tự là load-bearing và gộp lại sẽ phá nó:
 *
 *   1. khoá dòng sản phẩm — nếu không có dòng thì BỎ QUA, **không ghi review**;
 *   2. ghi `product_reviews` (có thể thua đua tranh ⇒ `UniqueConstraintViolation`);
 *   3. chỉ khi (2) thắng mới cộng bộ đếm.
 *
 * Một method "khoá và cộng" gọi trước (2) sẽ cộng cho cả lượt thua đua tranh —
 * người thắng đã cộng rồi, nên bộ đếm chạy gấp đôi. Gọi sau (2) thì mất khoá:
 * hai lượt đồng thời trên cùng sản phẩm cùng đọc-sửa-ghi.
 *
 * Cả hai method BẮT BUỘC chạy trong transaction của phía gọi — khoá dòng không
 * có transaction là khoá rồi nhả ngay.
 */
interface ProductReviewAggregates
{
    /**
     * Khoá dòng sản phẩm cho tới hết transaction hiện tại.
     *
     * `false` nghĩa là không có sản phẩm nào mang id đó — phía gọi ĐẾM LÀ BỎ QUA
     * (bản cũ: `$skipped++; continue;`), không phải lỗi.
     */
    public function lockForAggregateUpdate(string $productId): bool;

    /**
     * Cộng một lượt đánh giá vào bộ đếm: tổng lượt, tổng sao, và lượt "khuyên
     * dùng" khi `$sentiment` là `Up`.
     */
    public function recordReview(string $productId, int $rating, ReviewSentimentEnum $sentiment): void;
}
