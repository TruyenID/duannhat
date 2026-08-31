<?php

declare(strict_types=1);

namespace App\Services\Product\Internal;

use App\Models\Product;
use App\Models\ProductSku;
use App\Omnify\Enums\ReviewSentimentEnum;
use App\Services\Product\Contracts\ProductReviewAggregates;
use App\Services\Product\Contracts\ReviewedProduct;
use App\Services\Product\Contracts\ReviewedSku;
use App\Services\Product\Contracts\ReviewedSkuDirectory;

/**
 * #962 — hiện thực {@see ReviewedSkuDirectory} và {@see ProductReviewAggregates}.
 *
 * Một class cho hai cổng vì chúng dùng chung đúng một hiểu biết — dữ liệu đánh
 * giá sống trên `products` — và tách đôi chỉ để "mỗi interface một file thực
 * thi" sẽ nhân đôi chỗ phải sửa khi cột đổi.
 *
 * `with('product.galleryFirst')` chép NGUYÊN bản cũ (`productSku.product.galleryFirst`):
 * bỏ đi là một truy vấn ảnh cho MỖI món trong đơn.
 *
 * Ba lệnh cộng dồn chép nguyên `EloquentProductPersistence::recordReviewAggregates()`
 * và **cố ý không gọi lại** class đó: nó nhận 9 dependency của cả đường ghi
 * catalog, nên tiêm nó vào một cổng cộng ba cột là đổi một cạnh ranh giới lấy
 * chín cạnh khởi tạo. Vẫn cộng qua MODEL chứ không qua query builder — bản cũ
 * như vậy, và `Model::increment()` còn chạm `updated_at` mà builder thì không.
 */
final class EloquentReviewedSkuDirectory implements ProductReviewAggregates, ReviewedSkuDirectory
{
    public function byIds(array $skuIds): array
    {
        if ($skuIds === []) {
            return [];
        }

        return ProductSku::query()
            ->whereIn('id', $skuIds)
            ->with('product.galleryFirst')
            ->get()
            ->mapWithKeys(function (ProductSku $sku): array {
                $product = $sku->product;

                return [(string) $sku->id => new ReviewedSku(
                    id: (string) $sku->id,
                    // Cột thô, KHÔNG phải `$product?->id`: nó phải sống sót qua
                    // việc sản phẩm bị xoá mềm, vì phép chống giả mạo so với nó.
                    productId: $sku->product_id === null ? null : (string) $sku->product_id,
                    name: $sku->name,
                    product: $product === null ? null : new ReviewedProduct(
                        id: (string) $product->id,
                        name: $product->name,
                        imageUrl: $product->galleryFirst?->getUrl(),
                    ),
                )];
            })
            ->all();
    }

    public function lockForAggregateUpdate(string $productId): bool
    {
        return Product::query()
            ->where('id', $productId)
            ->lockForUpdate()
            ->first() !== null;
    }

    /**
     * Đọc lại dòng sản phẩm là CÓ CHỦ Ý, không phải quên tối ưu: `lockForAggregateUpdate()`
     * không được trả model ra ngoài (cổng công bố không mang model), nên chỗ này
     * lấy lại handle để cộng. Một lượt đọc theo khoá chính, trên dòng vừa bị
     * chính transaction này khoá.
     */
    public function recordReview(string $productId, int $rating, ReviewSentimentEnum $sentiment): void
    {
        $product = Product::query()->where('id', $productId)->first();
        if ($product === null) {
            return;
        }

        $product->increment('review_total_count');
        $product->increment('review_rating_sum', $rating);
        if ($sentiment === ReviewSentimentEnum::Up) {
            $product->increment('review_up_count');
        }
    }
}
