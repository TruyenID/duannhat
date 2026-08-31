<?php

namespace App\Services\Customer;

use App\Models\ProductReview;
use App\Omnify\Enums\ReviewSentimentEnum;
use App\Services\Customer\ValueObjects\ReviewedOrder;
use App\Services\Order\Contracts\ReviewableOrderLine;
use App\Services\Order\Contracts\ReviewableOrderLines;
use App\Services\Product\Contracts\ProductReviewAggregates;
use App\Services\Product\Contracts\ReviewedSku;
use App\Services\Product\Contracts\ReviewedSkuDirectory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * #962 — service này giữ nguyên việc của nó (ai được chấm sao, chống giả mạo,
 * idempotent) nhưng thôi tự đọc dữ liệu của module khác:
 *
 *   dòng món của đơn      → `ReviewableOrderLines`   (Ordering công bố)
 *   tên/ảnh/biến thể      → `ReviewedSkuDirectory`   (Catalog công bố)
 *   bộ đếm trên `products`→ `ProductReviewAggregates` (Catalog công bố)
 *
 * Cái ĐỔI là số truy vấn: bản cũ đi một câu duy nhất `items` + eager-load sang
 * `product_skus`/`products`, giờ là hai câu (đơn, rồi biến thể). Có trần theo
 * đơn của một bàn, không phải "toàn bộ dòng món" — xem `ReviewedSkuDirectory`.
 */
class ProductReviewService
{
    public function __construct(
        private readonly ReviewableOrderLines $orderLines,
        private readonly ReviewedSkuDirectory $skus,
        private readonly ProductReviewAggregates $aggregates,
    ) {}

    /**
     * Submit a batch of reviews for items in a closed order.
     *
     * Runs inside a single DB transaction with a row lock on the product to
     * prevent aggregate drift from concurrent submissions.
     *
     * Already-reviewed items and voided items are silently skipped (idempotent).
     *
     * Each review carries a 1-5 star `rating` (plan-026). `sentiment` is derived
     * (rating >= 3 => up) for backward-compat with the up/total recommend
     * aggregate; `review_rating_sum` tracks the star total for averages.
     *
     * @param  array<int, array{order_item_id: string, product_id: string, rating: int, tags?: array<int, string>|null, comment?: string|null}>  $reviews
     * @return array{created: int, skipped: int}
     */
    public function submit(ReviewedOrder $order, array $reviews, ?string $customerId = null): array
    {
        $created = 0;
        $skipped = 0;

        // Pre-load order items for validation (exclude voided). Resolve each
        // item's REAL product_id (via its SKU) so we can reject a spoofed
        // product_id — the client-supplied product_id is attacker-controlled and
        // must be cross-checked against what was actually ordered, otherwise a
        // holder of the order UUID could inflate/deflate any product's rating.
        $itemProductMap = $this->itemProductMap($this->orderLines->forOrder($order->id));

        // Pre-load already-reviewed item IDs for this order
        $alreadyReviewed = ProductReview::where('customer_order_id', $order->id)
            ->pluck('customer_order_item_id')
            ->flip();

        return DB::transaction(function () use ($order, $reviews, $customerId, $itemProductMap, $alreadyReviewed, &$created, &$skipped) {
            foreach ($reviews as $review) {
                $orderItemId = $review['order_item_id'];

                // Skip if item doesn't belong to this order or is voided
                if (! array_key_exists($orderItemId, $itemProductMap)) {
                    $skipped++;

                    continue;
                }

                // Skip if already reviewed (idempotent)
                if ($alreadyReviewed->has($orderItemId)) {
                    $skipped++;

                    continue;
                }

                // Anti-spoof: the submitted product_id MUST match the product
                // actually ordered under this item. A mismatch is a tampering
                // attempt, not a recoverable skip — reject the whole batch (422).
                $realProductId = $itemProductMap[$orderItemId];
                if ($realProductId === null || (string) $review['product_id'] !== $realProductId) {
                    throw ValidationException::withMessages([
                        'reviews' => 'product_id does not match the ordered item.',
                    ]);
                }

                $rating = (int) $review['rating'];
                // Derive binary sentiment from the star rating (3+ = recommend).
                $sentiment = $rating >= 3 ? ReviewSentimentEnum::Up : ReviewSentimentEnum::Down;
                $tags = $review['tags'] ?? null;

                // Lock product row for aggregate update. Use the VERIFIED product
                // id (not the client input) so bookkeeping can never drift.
                if (! $this->aggregates->lockForAggregateUpdate($realProductId)) {
                    $skipped++;

                    continue;
                }

                try {
                    ProductReview::create([
                        'product_id' => $realProductId,
                        'customer_order_id' => $order->id,
                        'customer_order_item_id' => $orderItemId,
                        'customer_id' => $customerId,
                        'organization_id' => $order->organizationId,
                        'brand_id' => $order->brandId,
                        'branch_id' => $order->branchId,
                        'rating' => $rating,
                        'sentiment' => $sentiment,
                        'tags' => ! empty($tags) ? array_values($tags) : null,
                        'comment' => $review['comment'] ?? null,
                    ]);
                } catch (UniqueConstraintViolationException $e) {
                    // A concurrent double-submit committed a review for this
                    // order item after our pre-check snapshot was taken and lost
                    // the race on the unique customer_order_item_id index. Treat
                    // it as an idempotent skip (never a 500) and do NOT touch the
                    // aggregate — the winning request already counted it.
                    $alreadyReviewed[$orderItemId] = true;
                    $skipped++;

                    continue;
                }

                // Increment aggregate (total, up, star-sum)
                $this->aggregates->recordReview($realProductId, $rating, $sentiment);

                // Track for idempotency within this batch
                $alreadyReviewed[$orderItemId] = true;
                $created++;
            }

            return ['created' => $created, 'skipped' => $skipped];
        });
    }

    /**
     * List order items eligible for review, with already_reviewed flag.
     *
     * @return array<int, array{order_item_id: string, product_id: string|null, name: string, image: string|null, variant_name: string|null, price: float, already_reviewed: bool}>
     */
    public function reviewableItems(string $orderId): array
    {
        $lines = $this->orderLines->forOrder($orderId);
        $skus = $this->skusForLines($lines);

        $reviewedItemIds = ProductReview::where('customer_order_id', $orderId)
            ->pluck('customer_order_item_id')
            ->flip();

        return array_map(function (ReviewableOrderLine $line) use ($skus, $reviewedItemIds): array {
            $sku = $line->productSkuId === null ? null : ($skus[$line->productSkuId] ?? null);
            $product = $sku?->product;

            return [
                'order_item_id' => $line->id,
                'product_id' => $product?->id,
                'name' => $product?->name ?? 'Unknown',
                'image' => $product?->imageUrl,
                'variant_name' => $sku?->name,
                // Unit price for display on the review card (e.g. "¥1,650").
                'price' => (float) $line->unitPrice,
                'already_reviewed' => $reviewedItemIds->has($line->id),
            ];
        }, $lines);
    }

    /**
     * itemId => product_id THẬT của món đó (cột thô trên `product_skus`, nên nó
     * còn đúng cả khi sản phẩm đã bị xoá mềm — xem `ReviewedSku`).
     *
     * @param  list<ReviewableOrderLine>  $lines
     * @return array<string, string|null>
     */
    private function itemProductMap(array $lines): array
    {
        $skus = $this->skusForLines($lines);

        $map = [];
        foreach ($lines as $line) {
            $sku = $line->productSkuId === null ? null : ($skus[$line->productSkuId] ?? null);
            $map[$line->id] = $sku?->productId;
        }

        return $map;
    }

    /**
     * @param  list<ReviewableOrderLine>  $lines
     * @return array<string, ReviewedSku>
     */
    private function skusForLines(array $lines): array
    {
        $skuIds = [];
        foreach ($lines as $line) {
            if ($line->productSkuId !== null) {
                $skuIds[$line->productSkuId] = true;
            }
        }

        return $this->skus->byIds(array_keys($skuIds));
    }
}
