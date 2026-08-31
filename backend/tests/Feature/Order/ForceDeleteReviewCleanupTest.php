<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Omnify\Enums\ReviewSentimentEnum;
use App\Services\Order\Internal\EloquentOrderPersistence;

/**
 * Plan 047 T4.14 — the product-review purge that runs on a CustomerOrder
 * force-delete moved OUT of the model's `forceDeleting` closure and onto the
 * canonical order write boundary (WritesCustomerOrders::purgeProductReviews).
 *
 * Behaviour must be unchanged in BOTH directions:
 *   - through the boundary (EloquentOrderPersistence::forceDeleteOrder)
 *   - around it (a bare CustomerOrder::forceDelete, e.g. from a test or tinker)
 *
 * Either way ProductReview::deleting must fire so the `ProductReview::deleting` hook rolls
 * the denormalized Product review_* aggregates back. A raw DELETE would leave
 * them drifted above zero forever, and the customer_order_item_id FK is
 * RESTRICT so the item rows could not go at all.
 */
beforeEach(function () {
    $this->org = Organization::query()->first() ?? Organization::factory()->create();
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->org->id]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->org->id,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->org->id,
        'brand_id' => $this->brand->id,
    ]);
});

/**
 * Build one order carrying $count reviews on a single product, and seed the
 * product's aggregates to exactly match those reviews so any drift after the
 * delete is unambiguous.
 *
 * @return array{0: CustomerOrder, 1: Product, 2: list<ProductReview>}
 */
function orderWithReviews(object $ctx, int $count, ReviewSentimentEnum $sentiment = ReviewSentimentEnum::Up, int $rating = 5): array
{
    $product = Product::factory()->create([
        'organization_id' => $ctx->org->id,
        'brand_id' => $ctx->brand->id,
        'product_type_id' => $ctx->productType->id,
        'review_total_count' => $count,
        'review_up_count' => $sentiment === ReviewSentimentEnum::Up ? $count : 0,
        'review_rating_sum' => $rating * $count,
    ]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $ctx->org->id,
        'brand_id' => $ctx->brand->id,
        'branch_id' => $ctx->branch->id,
    ]);

    $reviews = [];
    for ($i = 0; $i < $count; $i++) {
        $item = CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'product_sku_id' => $sku->id,
        ]);
        $reviews[] = ProductReview::factory()->create([
            'organization_id' => $ctx->org->id,
            'product_id' => $product->id,
            'customer_order_id' => $order->id,
            'customer_order_item_id' => $item->id,
            'sentiment' => $sentiment->value,
            'rating' => $rating,
        ]);
    }

    return [$order, $product->fresh(), $reviews];
}

it('purges reviews and reverses aggregates when force-deleting through the boundary', function () {
    [$order, $product] = orderWithReviews($this, 3);

    app(EloquentOrderPersistence::class)->forceDeleteOrder($order);

    expect(CustomerOrder::withTrashed()->find($order->id))->toBeNull()
        ->and(ProductReview::query()->where('customer_order_id', $order->id)->count())->toBe(0);

    $product->refresh();
    expect($product->review_total_count)->toBe(0)
        ->and($product->review_up_count)->toBe(0)
        ->and($product->review_rating_sum)->toBe(0);
});

it('still purges reviews when force-deleting the model directly, bypassing the boundary', function () {
    [$order, $product] = orderWithReviews($this, 2);

    // The safety net: nobody called forceDeleteOrder(), so only the model's
    // forceDeleting hook can save the aggregates here.
    $order->forceDelete();

    expect(ProductReview::query()->where('customer_order_id', $order->id)->count())->toBe(0);

    $product->refresh();
    expect($product->review_total_count)->toBe(0)
        ->and($product->review_up_count)->toBe(0)
        ->and($product->review_rating_sum)->toBe(0);
});

it('is idempotent — the boundary purge and the model hook cannot double-decrement', function () {
    [$order, $product] = orderWithReviews($this, 2);

    // forceDeleteOrder() purges, then calls forceDelete() which re-enters the
    // hook and purges again. The second pass must find nothing.
    app(EloquentOrderPersistence::class)->forceDeleteOrder($order);

    $product->refresh();
    expect($product->review_total_count)->toBe(0)
        ->and($product->review_up_count)->toBe(0)
        ->and($product->review_rating_sum)->toBe(0);
});

it('purges nothing and touches no aggregate for an order with no reviews', function () {
    $product = Product::factory()->create([
        'organization_id' => $this->org->id,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->productType->id,
        'review_total_count' => 7,
        'review_up_count' => 4,
        'review_rating_sum' => 30,
    ]);
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->org->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    app(EloquentOrderPersistence::class)->forceDeleteOrder($order);

    $product->refresh();
    expect($product->review_total_count)->toBe(7)
        ->and($product->review_up_count)->toBe(4)
        ->and($product->review_rating_sum)->toBe(30);
});

it('leaves another order reviews and aggregates untouched', function () {
    [$doomed, $product] = orderWithReviews($this, 2);

    // A second order reviewing the SAME product must survive intact.
    $sku = ProductSku::query()->where('product_id', $product->id)->firstOrFail();
    $survivor = CustomerOrder::factory()->create([
        'organization_id' => $this->org->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);
    $survivorItem = CustomerOrderItem::factory()->create([
        'customer_order_id' => $survivor->id,
        'product_sku_id' => $sku->id,
    ]);
    ProductReview::factory()->create([
        'organization_id' => $this->org->id,
        'product_id' => $product->id,
        'customer_order_id' => $survivor->id,
        'customer_order_item_id' => $survivorItem->id,
        'sentiment' => ReviewSentimentEnum::Up->value,
        'rating' => 5,
    ]);
    // 2 doomed + 1 survivor.
    $product->update(['review_total_count' => 3, 'review_up_count' => 3, 'review_rating_sum' => 15]);

    app(EloquentOrderPersistence::class)->forceDeleteOrder($doomed);

    expect(ProductReview::query()->where('customer_order_id', $survivor->id)->count())->toBe(1);

    $product->refresh();
    expect($product->review_total_count)->toBe(1)
        ->and($product->review_up_count)->toBe(1)
        ->and($product->review_rating_sum)->toBe(5);
});

it('reverses only the rating sum for a down-sentiment review, never the up count', function () {
    [$order, $product] = orderWithReviews($this, 2, ReviewSentimentEnum::Down, rating: 2);
    // Seeded above as up_count=0; give the product a non-zero up count from
    // unrelated reviews so a wrongly-decremented up_count would be visible.
    $product->update(['review_up_count' => 5]);

    app(EloquentOrderPersistence::class)->forceDeleteOrder($order);

    $product->refresh();
    expect($product->review_total_count)->toBe(0)
        ->and($product->review_rating_sum)->toBe(0)
        ->and($product->review_up_count)->toBe(5);
});

it('exposes purgeProductReviews without deleting the order itself', function () {
    [$order, $product] = orderWithReviews($this, 2);

    app(EloquentOrderPersistence::class)->purgeProductReviews($order);

    expect(CustomerOrder::query()->find($order->id))->not->toBeNull()
        ->and(ProductReview::query()->where('customer_order_id', $order->id)->count())->toBe(0);

    $product->refresh();
    expect($product->review_total_count)->toBe(0);
});
