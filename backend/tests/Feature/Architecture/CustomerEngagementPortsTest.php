<?php

declare(strict_types=1);

/**
 * #962 (cụm CustomerEngagement) — sáu cổng vừa dựng, và câu hỏi duy nhất đáng
 * hỏi về chúng: **có giữ đúng ngữ nghĩa cũ không**.
 *
 * Bốn service (`CustomerCouponWalletService`, `CustomerMenuService`,
 * `ProductReviewService`, `MenuLocalizationIntegrityReporter`) từng tự truy vấn
 * model của Pricing · Catalog · Ordering. Dời truy vấn ra sau một interface làm
 * deptrac xanh **kể cả khi truy vấn mới sai** — đó là cách một PR ranh giới có
 * thể phá dữ liệu trong im lặng. Nên mỗi bài dưới đây ghim một chi tiết mà nếu
 * viết lại từ đầu sẽ rất dễ làm khác đi.
 *
 * Bài đầu tiên là rào chống decoration: một interface không resolve được thì
 * không phải ranh giới (`CanonicalPortsAreBindableTest` ghi lý do đầy đủ).
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\MenuPromotion;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Omnify\Enums\CouponStatusEnum;
use App\Omnify\Enums\ReviewSentimentEnum;
use App\Services\Order\Contracts\ReviewableOrderLines;
use App\Services\Product\Contracts\ProductReviewAggregates;
use App\Services\Product\Contracts\ReviewedSkuDirectory;
use App\Services\Promotion\Contracts\CustomerOwnedCoupons;
use App\Services\Promotion\Contracts\MenuDisplayPromotions;
use App\Services\Topping\Contracts\ToppingGroupItemIntegrity;
use Illuminate\Support\Carbon;

it('P0: mọi cổng của cụm CustomerEngagement resolve được từ container', function (string $port) {
    expect(app()->make($port))->toBeInstanceOf($port);
})->with([
    ReviewableOrderLines::class,
    ReviewedSkuDirectory::class,
    ProductReviewAggregates::class,
    CustomerOwnedCoupons::class,
    MenuDisplayPromotions::class,
    ToppingGroupItemIntegrity::class,
]);

// =========================================================================
//  Ordering → dòng món được chấm sao
// =========================================================================

it('P1: ReviewableOrderLines loại món VOID và giữ nguyên đơn giá', function () {
    $order = CustomerOrder::factory()->closed()->create();
    $sku = ProductSku::factory()->create();

    $served = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'status' => 'served',
        'unit_price' => 1650,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'status' => 'voided',
    ]);

    $lines = app(ReviewableOrderLines::class)->forOrder((string) $order->id);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]->id)->toBe((string) $served->id)
        ->and($lines[0]->productSkuId)->toBe((string) $sku->id)
        ->and((float) $lines[0]->unitPrice)->toBe(1650.0);
});

it('P1b: ReviewableOrderLines trả mảng rỗng cho đơn không tồn tại, không throw', function () {
    expect(app(ReviewableOrderLines::class)->forOrder('00000000-0000-0000-0000-000000000000'))->toBe([]);
});

// =========================================================================
//  Catalog → thẻ đánh giá + bộ đếm
// =========================================================================

it('P2: ReviewedSkuDirectory giữ product_id THÔ khi sản phẩm đã xoá mềm', function () {
    /*
     * Đây là bài quan trọng nhất trong file. Phép chống giả mạo của
     * `ProductReviewService` so `product_id` client gửi lên với cột thô trên
     * `product_skus`. Nếu cổng trả `$sku->product?->id` thì sản phẩm bị xoá sau
     * khi đơn đóng sẽ biến phép so đó thành "so với null" — và bản cũ CÓ phân
     * biệt hai đường này (`with('productSku:id,product_id')` cho chống giả mạo,
     * `productSku.product` cho hiển thị).
     */
    $product = Product::factory()->active()->create();
    $sku = ProductSku::factory()->create(['product_id' => $product->id]);
    $product->delete();

    $found = app(ReviewedSkuDirectory::class)->byIds([(string) $sku->id]);

    expect($found)->toHaveKey((string) $sku->id)
        ->and($found[(string) $sku->id]->productId)->toBe((string) $product->id)
        ->and($found[(string) $sku->id]->product)->toBeNull();
});

it('P2b: ReviewedSkuDirectory bỏ QUA id không tra được thay vì trả mục null', function () {
    expect(app(ReviewedSkuDirectory::class)->byIds([]))->toBe([]);
    expect(app(ReviewedSkuDirectory::class)->byIds(['00000000-0000-0000-0000-000000000000']))->toBe([]);
});

it('P3: ProductReviewAggregates cộng ba cột, "không khuyên dùng" không chạm review_up_count', function () {
    $product = Product::factory()->active()->create([
        'review_total_count' => 0,
        'review_up_count' => 0,
        'review_rating_sum' => 0,
    ]);

    $aggregates = app(ProductReviewAggregates::class);
    $aggregates->recordReview((string) $product->id, 5, ReviewSentimentEnum::Up);
    $aggregates->recordReview((string) $product->id, 1, ReviewSentimentEnum::Down);

    $product->refresh();
    expect($product->review_total_count)->toEqual(2)
        ->and($product->review_up_count)->toEqual(1)
        ->and($product->review_rating_sum)->toEqual(6);
});

it('P3b: lockForAggregateUpdate trả false cho sản phẩm không tồn tại — phía gọi BỎ QUA', function () {
    $product = Product::factory()->active()->create();

    expect(app(ProductReviewAggregates::class)->lockForAggregateUpdate((string) $product->id))->toBeTrue();
    expect(app(ProductReviewAggregates::class)->lockForAggregateUpdate('00000000-0000-0000-0000-000000000000'))->toBeFalse();
});

// =========================================================================
//  Catalog → dòng topping hỏng
// =========================================================================

it('P4: ToppingGroupItemIntegrity đếm CẢ mồ côi lẫn sản phẩm ngưng bán', function () {
    $group = ToppingGroup::factory()->create();

    $active = Product::factory()->active()->create();
    $inactive = Product::factory()->create(['status' => 'inactive']);
    $orphaned = Product::factory()->active()->create();

    ToppingGroupItem::factory()->create(['topping_group_id' => $group->id, 'product_id' => $active->id]);
    ToppingGroupItem::factory()->create(['topping_group_id' => $group->id, 'product_id' => $inactive->id]);
    $orphanItem = ToppingGroupItem::factory()->create(['topping_group_id' => $group->id, 'product_id' => $orphaned->id]);
    $orphaned->delete();

    expect($orphanItem->exists)->toBeTrue();
    expect(app(ToppingGroupItemIntegrity::class)->unusableItemCountForGroups([(string) $group->id]))->toBe(2);
});

it('P4b: danh sách nhóm rỗng cho 0 và KHÔNG truy vấn', function () {
    ToppingGroupItem::factory()->create();

    expect(app(ToppingGroupItemIntegrity::class)->unusableItemCountForGroups([]))->toBe(0);
});

// =========================================================================
//  Pricing → ví coupon
// =========================================================================

function portTestCoupon(Customer $owner, array $attrs = []): Coupon
{
    return Coupon::factory()->create([
        'customer_id' => $owner->id,
        'status' => CouponStatusEnum::Draft,
        'usage_limit_total' => 5,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(30),
        ...$attrs,
    ]);
}

it('P5: CustomerOwnedCoupons phân loại theo ĐÚNG ba điều kiện của validateForApply', function () {
    $owner = Customer::factory()->selfRegistered()->create();

    $usable = portTestCoupon($owner);
    $paused = portTestCoupon($owner, ['status' => CouponStatusEnum::Paused]);
    $lapsed = portTestCoupon($owner, ['valid_until' => now()->subHour()]);
    $burned = portTestCoupon($owner, ['usage_limit_total' => 2, 'times_used' => 2]);
    portTestCoupon(Customer::factory()->selfRegistered()->create());

    $wallet = app(CustomerOwnedCoupons::class)->ownedFor((string) $owner->id, Carbon::now());

    expect(array_column($wallet['available'], 'id'))->toBe([(string) $usable->id]);
    expect(array_column($wallet['expired'], 'id'))
        ->toHaveCount(3)
        ->each->toBeIn([(string) $paused->id, (string) $lapsed->id, (string) $burned->id]);
});

it('P5b: redemptionsFor bỏ lượt đã NHẢ và tôn trọng giới hạn của phía gọi', function () {
    $owner = Customer::factory()->selfRegistered()->create();
    $coupon = portTestCoupon($owner);

    // `coupon_redemptions.customer_order_id` là UNIQUE — mỗi lượt một đơn.
    foreach (range(1, 3) as $i) {
        CouponRedemption::factory()->create([
            'customer_id' => $owner->id,
            'coupon_id' => $coupon->id,
            'customer_order_id' => CustomerOrder::factory()->create()->id,
            'released_at' => null,
            'redeemed_at' => now()->subMinutes($i),
            'coupon_snapshot' => ['code' => 'KEEP', 'name' => ['ja' => 'クーポン']],
        ]);
    }
    CouponRedemption::factory()->create([
        'customer_id' => $owner->id,
        'coupon_id' => $coupon->id,
        'customer_order_id' => CustomerOrder::factory()->create()->id,
        'released_at' => now(),
    ]);

    $rows = app(CustomerOwnedCoupons::class)->redemptionsFor((string) $owner->id, 2);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['coupon_snapshot']['code'])->toBe('KEEP');
});

// =========================================================================
//  Pricing → khuyến mãi trên thực đơn khách
// =========================================================================

it('P6: MenuDisplayPromotions dựng endsAt từ cửa sổ trong ngày, vắt qua nửa đêm vẫn đúng', function () {
    $organization = Organization::factory()->create();
    $brand = Brand::factory()->create(['console_organization_id' => $organization->console_organization_id]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $organization->console_organization_id,
        'console_brand_id' => $brand->console_brand_id,
        'timezone' => 'Asia/Tokyo',
    ]);
    $product = Product::factory()->active()->create(['organization_id' => $organization->id]);

    // 23:30 giờ Tokyo, trong cửa sổ 21:00 → 02:00 ⇒ mốc kết thúc là 02:00 NGÀY MAI.
    Carbon::setTestNow(Carbon::parse('2026-08-03 14:30:00', 'UTC'));

    MenuPromotion::factory()->create([
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'applies_to' => 'all_items',
        'discount_percent' => 20,
        'is_active' => true,
        'weekdays' => [],
        'daily_time_from' => '21:00:00',
        'daily_time_to' => '02:00:00',
        'valid_from' => now()->subDays(5),
        'valid_until' => now()->addDays(5),
    ]);

    $map = app(MenuDisplayPromotions::class)->forMenuItems(
        (string) $branch->id,
        [['product_id' => (string) $product->id, 'category_ids' => []]],
    );

    expect($map)->toHaveKey((string) $product->id);
    $promotion = $map[(string) $product->id];
    expect($promotion)->not->toBeNull()
        ->and($promotion->discountPercent)->toBe(20.0)
        ->and(Carbon::parse($promotion->endsAt)->setTimezone('Asia/Tokyo')->format('Y-m-d H:i'))
        ->toBe('2026-08-04 02:00');

    Carbon::setTestNow();
});

it('P6b: endsAt rơi về valid_until khi khuyến mãi không có cửa sổ theo giờ', function () {
    $organization = Organization::factory()->create();
    $brand = Brand::factory()->create(['console_organization_id' => $organization->console_organization_id]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $organization->console_organization_id,
        'console_brand_id' => $brand->console_brand_id,
        'timezone' => 'Asia/Tokyo',
    ]);
    $product = Product::factory()->active()->create(['organization_id' => $organization->id]);

    Carbon::setTestNow(Carbon::parse('2026-08-03 05:00:00', 'UTC'));
    $validUntil = Carbon::parse('2026-08-10 03:00:00', 'UTC');

    MenuPromotion::factory()->create([
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'applies_to' => 'all_items',
        'discount_percent' => 10,
        'is_active' => true,
        'weekdays' => [],
        'daily_time_from' => null,
        'daily_time_to' => null,
        'valid_from' => now()->subDay(),
        'valid_until' => $validUntil,
    ]);

    $map = app(MenuDisplayPromotions::class)->forMenuItems(
        (string) $branch->id,
        [['product_id' => (string) $product->id, 'category_ids' => []]],
    );

    expect(Carbon::parse($map[(string) $product->id]->endsAt)->utc()->format('Y-m-d H:i'))
        ->toBe($validUntil->format('Y-m-d H:i'));

    Carbon::setTestNow();
});
