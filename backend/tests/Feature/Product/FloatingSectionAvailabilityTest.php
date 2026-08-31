<?php

declare(strict_types=1);

/**
 * #1622 — cổng Catalog trả lời "floating section nào đang phát sóng".
 *
 * Trước đây vị ngữ cửa sổ hiệu lực được viết ba lần ở ba module, và hai trong ba
 * bản sao tự khai điều đó trong comment. Bài test này ghim hai thứ mà một bản
 * sao im lặng sẽ phá:
 *
 *  1. **Ba nhánh cửa sổ đều đúng**, đặc biệt nhánh qua-nửa-đêm-sau-00:00 — nhánh
 *     dễ mất nhất, và mất nó thì quán mở xuyên đêm im lặng mất khuyến mãi mỗi
 *     ngày từ 00:00 tới giờ đóng.
 *  2. **Hai method trả lời NHẤT QUÁN**: section không phát sóng thì không có
 *     dòng giá nào, và section phát sóng thì có. Đó chính là chỗ hai bản sao cũ
 *     có thể lệch — thực đơn khách hiện một giá, động cơ đơn hàng tính giá khác.
 *
 * Cửa sổ khuyến mãi là THỜI GIAN NGHIỆP VỤ (#1091), nên mọi khẳng định về thời
 * gian ở đây chạy trên ≥3 múi giờ.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\FloatingSection;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Services\Product\Contracts\FloatingSectionAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * A branch in `$timezone` with one overnight floating section (22:00 → 02:00,
 * Monday only) offering `$price` for one SKU.
 *
 * @return array{branch: Branch, sku: ProductSku, section: FloatingSection}
 */
function overnightFloatingSection(string $timezone, float $price = 800.0, int $priority = 0): array
{
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'timezone' => $timezone,
    ]);
    $type = ProductType::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);
    $product = Product::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'product_type_id' => $type->id,
    ]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'selling_price' => 1000]);

    $section = FloatingSection::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'is_active' => true,
        'priority' => $priority,
        'start_date' => null,
        'end_date' => null,
    ]);
    $section->schedules()->create([
        'start_time' => '22:00:00',
        'end_time' => '02:00:00',
        'days_of_week' => 1 << 1, // Monday only
        'is_active' => true,
        'priority' => 0,
    ]);
    $floatingProduct = $section->products()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);
    $floatingProduct->skus()->create([
        'product_sku_id' => $sku->id,
        'is_active' => true,
        'selling_price' => $price,
        'is_price_overridden' => true,
    ]);

    return ['branch' => $branch, 'sku' => $sku, 'section' => $section];
}

dataset('branch timezones', ['Asia/Tokyo', 'Asia/Ho_Chi_Minh', 'UTC']);

it('keeps an overnight window live BEFORE midnight, on its own weekday', function (string $timezone) {
    ['branch' => $branch, 'sku' => $sku] = overnightFloatingSection($timezone);
    $availability = app(FloatingSectionAvailability::class);

    // 2026-08-03 is a Monday. 23:00 is inside 22:00→02:00.
    $at = CarbonImmutable::parse('2026-08-03 23:00:00', $timezone);

    expect($availability->liveSectionIds($branch->id, $at))->toHaveCount(1);
    expect($availability->livePricesForSkus($branch->id, [$sku->id], $at))->toHaveCount(1);
})->with('branch timezones');

it('keeps an overnight window live AFTER midnight, matching the PREVIOUS day mask', function (string $timezone) {
    ['branch' => $branch, 'sku' => $sku] = overnightFloatingSection($timezone);
    $availability = app(FloatingSectionAvailability::class);

    // Tuesday 01:00 — the run that opened MONDAY 22:00 is still on air. The
    // schedule's mask holds Monday only, so a same-day-mask check alone says
    // "closed" and the shop silently loses the promotion every single night.
    $at = CarbonImmutable::parse('2026-08-04 01:00:00', $timezone);

    expect($availability->liveSectionIds($branch->id, $at))->toHaveCount(1);
    expect($availability->livePricesForSkus($branch->id, [$sku->id], $at))->toHaveCount(1);
})->with('branch timezones');

it('closes the overnight window once the end time has passed', function (string $timezone) {
    ['branch' => $branch, 'sku' => $sku] = overnightFloatingSection($timezone);
    $availability = app(FloatingSectionAvailability::class);

    $at = CarbonImmutable::parse('2026-08-04 03:00:00', $timezone); // Tuesday, after 02:00

    expect($availability->liveSectionIds($branch->id, $at))->toBe([]);
    expect($availability->livePricesForSkus($branch->id, [$sku->id], $at))->toBe([]);
})->with('branch timezones');

it('does not open the window on a weekday outside the mask', function (string $timezone) {
    ['branch' => $branch, 'sku' => $sku] = overnightFloatingSection($timezone);
    $availability = app(FloatingSectionAvailability::class);

    $at = CarbonImmutable::parse('2026-08-04 23:00:00', $timezone); // Tuesday 23:00

    expect($availability->liveSectionIds($branch->id, $at))->toBe([]);
    expect($availability->livePricesForSkus($branch->id, [$sku->id], $at))->toBe([]);
})->with('branch timezones');

it('answers BOTH questions from one predicate — ids and prices never disagree', function (string $timezone) {
    ['branch' => $branch, 'sku' => $sku, 'section' => $section] = overnightFloatingSection($timezone);
    $availability = app(FloatingSectionAvailability::class);

    // Walk one full overnight run hour by hour and require the two methods to
    // agree at EVERY step. Disagreement is the exact failure the duplicated
    // predicate could produce: the customer menu shows a section the order
    // engine will not price.
    foreach (['2026-08-03 21:00', '2026-08-03 22:30', '2026-08-04 00:30', '2026-08-04 01:59', '2026-08-04 02:30'] as $stamp) {
        $at = CarbonImmutable::parse($stamp.':00', $timezone);

        $sectionIsLive = in_array($section->id, $availability->liveSectionIds($branch->id, $at), true);
        $hasPrice = $availability->livePricesForSkus($branch->id, [$sku->id], $at) !== [];

        expect($hasPrice)->toBe($sectionIsLive, sprintf(
            'At %s (%s) liveSectionIds says %s but livePricesForSkus says %s.',
            $stamp,
            $timezone,
            $sectionIsLive ? 'LIVE' : 'closed',
            $hasPrice ? 'LIVE' : 'closed',
        ));
    }
})->with('branch timezones');

it('reads the branch clock, not the app clock', function () {
    // Same instant, two branches, two timezones: 2026-08-04 00:30 Tokyo is
    // 2026-08-03 22:30 in Ho Chi Minh. Both are inside the Monday 22:00→02:00
    // run, but via DIFFERENT branches of the predicate — so a resolver that
    // silently used one global clock would answer the same for both and this
    // test would not distinguish them. What it does pin is that the branch's own
    // timezone column is what selects the branch of the rule.
    ['branch' => $tokyo, 'sku' => $tokyoSku] = overnightFloatingSection('Asia/Tokyo');
    ['branch' => $hanoi, 'sku' => $hanoiSku] = overnightFloatingSection('Asia/Ho_Chi_Minh');

    $availability = app(FloatingSectionAvailability::class);
    $instant = CarbonImmutable::parse('2026-08-03 15:30:00', 'UTC');

    expect($availability->livePricesForSkus($tokyo->id, [$tokyoSku->id], $instant))->toHaveCount(1);
    expect($availability->livePricesForSkus($hanoi->id, [$hanoiSku->id], $instant))->toHaveCount(1);

    // Two hours later Ho Chi Minh is at 00:30 (still on air) while Tokyo has
    // reached 02:30 and closed. One instant, two answers — that is the branch
    // clock doing its job.
    $later = CarbonImmutable::parse('2026-08-03 17:30:00', 'UTC');

    expect($availability->livePricesForSkus($tokyo->id, [$tokyoSku->id], $later))->toBe([]);
    expect($availability->livePricesForSkus($hanoi->id, [$hanoiSku->id], $later))->toHaveCount(1);
});

it('returns candidates UNRANKED — picking a winner belongs to the pricing side', function () {
    // Catalog must not decide which price wins. Two sections offering the same
    // SKU both come back; ordering them here would move a pricing rule into the
    // catalog and hide it inside an ORDER BY.
    ['branch' => $branch, 'sku' => $sku, 'section' => $cheap] = overnightFloatingSection('Asia/Tokyo', price: 500.0, priority: 5);

    $expensive = FloatingSection::factory()->create([
        'organization_id' => $cheap->organization_id,
        'brand_id' => $cheap->brand_id,
        'branch_id' => $branch->id,
        'is_active' => true,
        'priority' => 1,
        'start_date' => null,
        'end_date' => null,
    ]);
    $expensive->schedules()->create([
        'start_time' => '22:00:00',
        'end_time' => '02:00:00',
        'days_of_week' => 1 << 1,
        'is_active' => true,
        'priority' => 0,
    ]);
    $floatingProduct = $expensive->products()->create([
        'product_id' => $sku->product_id,
        'is_active' => true,
        'display_order' => 1,
    ]);
    $floatingProduct->skus()->create([
        'product_sku_id' => $sku->id,
        'is_active' => true,
        'selling_price' => 900.0,
        'is_price_overridden' => true,
    ]);

    $at = CarbonImmutable::parse('2026-08-03 23:00:00', 'Asia/Tokyo');
    $candidates = app(FloatingSectionAvailability::class)->livePricesForSkus($branch->id, [$sku->id], $at);

    expect($candidates)->toHaveCount(2);
    expect(array_map(fn ($c) => $c->price, $candidates))->toEqualCanonicalizing([500.0, 900.0]);
});
