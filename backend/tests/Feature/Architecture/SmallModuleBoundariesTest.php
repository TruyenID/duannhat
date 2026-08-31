<?php

declare(strict_types=1);

/**
 * #962 — sáu cạnh nhỏ đã trả, ghim lại để không mọc về.
 *
 * Deptrac ĐÃ cưỡng chế phần "không có cạnh mới": mỗi cạnh trả xong biến thành
 * một dòng `Skipped violation ... was not matched`, và baseline chỉ được co.
 * Bài test này bù đúng phần Deptrac KHÔNG nói:
 *
 *   1. **Cạnh biến mất bằng cách nào.** Một cạnh có thể "hết" vì đã đi qua cổng
 *      (đúng), hoặc vì ai đó bỏ type-hint / đổi sang `app('Tên\Class')` dạng
 *      chuỗi (giặt số liệu — Deptrac không đọc được, và cạnh thật vẫn còn).
 *      Ba cạnh dưới đây từng có sẵn cám dỗ đó: `MenuPromotionService` chỉ dính
 *      `CustomerOrderItem` qua MỘT type-hint trong closure, xoá đi là "xanh"
 *      ngay mà không sửa gì.
 *
 *   2. **Cổng có resolve được không.** Bài học của `CanonicalPortsAreBindableTest`
 *      (#1544): một interface không ai bind được là trang trí, và người sau sẽ
 *      quay lại import model vì "đường đúng không chạy".
 *
 *   3. **Bất biến nghiệp vụ đi kèm.** Hai chỗ dễ hỏng âm thầm nhất:
 *      hook `Brand::created` chuyển từ Observer sang provider (đăng ký hụt thì
 *      brand mới không có Reverb app, phát hiện ra ở production), và
 *      `assertBranchOwnership` chuyển từ model `Device` sang value object
 *      (mất nửa điều kiện tổ chức thì thiết bị chéo-tenant sửa được đơn — #845).
 */

use App\Exceptions\KdsRuleViolation;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\MenuPromotion;
use App\Models\Organization;
use App\Models\ProductType;
use App\Services\Kds\KdsBusinessRules;
use App\Services\Notification\Contracts\BrandEventBroadcaster;
use App\Services\Order\Contracts\PromotionRedemptionReads;
use App\Services\Order\ValueObjects\ActingDeviceTenancy;
use App\Services\Promotion\MenuPromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Cạnh đã trả: file nguồn KHÔNG được import lại class của module kia.
 *
 * @var array<string, list<string>>
 */
const REPAID_EDGES = [
    'app/Services/Kds/KdsBusinessRules.php' => ['App\Models\Device'],
    'app/Services/Promotion/MenuPromotionService.php' => ['App\Models\CustomerOrderItem'],
    'app/Services/Notification/Channels/RealtimeChannel.php' => ['App\Broadcasting\BrandAwareBroadcastManager'],
];

it('S1: cạnh đã trả không được import lại', function (string $file, array $forbidden) {
    $source = file_get_contents(base_path($file));

    foreach ($forbidden as $class) {
        expect($source)->not->toContain('use '.$class.';', sprintf(
            "%s import lại %s.\nCạnh này đã đi qua cổng ở #962 — sửa lại chỗ gọi, đừng nối thẳng.",
            $file,
            $class,
        ));
    }
})->with(array_map(
    static fn (string $file, array $forbidden): array => [$file, $forbidden],
    array_keys(REPAID_EDGES),
    array_values(REPAID_EDGES),
));

it('S2: cạnh đã trả không được lách bằng tên class dạng chuỗi', function (string $file, array $forbidden) {
    /*
     * Deptrac chỉ đọc `use` tĩnh. `app('App\\Models\\Device')` hoặc
     * `'App\Models\Device'::query()` giữ nguyên cạnh THẬT mà con số vẫn xanh —
     * đúng lớp nợ mà `RawTableReadsTest` (#1597) dựng lên để bắt.
     */
    $source = file_get_contents(base_path($file));

    foreach ($forbidden as $class) {
        expect($source)->not->toContain(str_replace('\\', '\\\\', $class), sprintf(
            '%s nhắc tên %s dưới dạng chuỗi — Deptrac không thấy, nhưng cạnh vẫn còn.',
            $file,
            $class,
        ));
    }
})->with(array_map(
    static fn (string $file, array $forbidden): array => [$file, $forbidden],
    array_keys(REPAID_EDGES),
    array_values(REPAID_EDGES),
));

it('S3: hai Observer đã đảo về provider không được dựng lại', function (string $class) {
    /*
     * Cả hai là class thuộc kernel/module này gọi SERVICE của module kia. Dựng
     * lại file là dựng lại cạnh — hook tương ứng phải sống ở provider.
     */
    expect(class_exists($class))->toBeFalse(
        "{$class} đã bị xoá ở #962 (hook đăng ký trong AppServiceProvider::boot). ".
        'Cần thêm hành vi thì thêm vào provider, đừng dựng lại Observer.'
    );
})->with([
    'App\\Observers\\BrandObserver',
    'App\\Observers\\ProductReviewObserver',
]);

it('S4: cổng mới resolve được từ container', function (string $port) {
    expect(app()->make($port))->toBeInstanceOf($port);
})->with([
    PromotionRedemptionReads::class,
    BrandEventBroadcaster::class,
]);

it('S5: hook Brand::created vẫn provisioning cả hai thứ sau khi bỏ Observer', function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $orgId,
        'console_organization_id' => $orgId,
    ]);

    $brand = Brand::factory()->create([
        'console_organization_id' => $orgId,
        'slug' => 'bnd-'.Str::random(6),
    ]);
    $brand->refresh();

    // Notifications — plan-012 T4.3.
    expect($brand->reverb_app_id)->not->toBeNull();
    // Catalog — combo ProductType cho luồng combo của admin-web.
    expect(ProductType::query()->where('brand_id', $brand->id)->where('code', 'combo')->exists())->toBeTrue();
});

it('S6: assertBranchOwnership vẫn chặn thiết bị khác tổ chức dù CÙNG chi nhánh', function () {
    /*
     * #845 — điều kiện là VÀ của hai cột. Value object thay cho model `Device`
     * không được làm rơi nửa nào; chỉ so branch thì một thiết bị ghép vào chi
     * nhánh chéo-tenant sửa được đơn của tenant kia.
     */
    $order = CustomerOrder::factory()->create();

    $sameEverything = new ActingDeviceTenancy((string) $order->organization_id, (string) $order->branch_id);
    $otherOrg = new ActingDeviceTenancy((string) Str::uuid(), (string) $order->branch_id);

    $rules = new KdsBusinessRules;

    expect(fn () => $rules->assertBranchOwnership($order, $sameEverything))->not->toThrow(Throwable::class);
    expect(fn () => $rules->assertBranchOwnership($order, $otherOrg))->toThrow(KdsRuleViolation::class);
});

it('S7: báo cáo khuyến mãi đi qua cổng vẫn ra đúng con số cũ', function () {
    $order = CustomerOrder::factory()->create();

    $promotion = MenuPromotion::factory()->create([
        'branch_id' => $order->branch_id,
        'brand_id' => $order->brand_id,
        'organization_id' => $order->organization_id,
    ]);

    // Ghi thô để né FK product_sku — cổng chỉ đọc `applied_promotion_id`.
    DB::table('customer_order_items')->insert([
        'id' => (string) Str::uuid(),
        'customer_order_id' => $order->id,
        'product_sku_id' => (string) Str::uuid(),
        'quantity' => 2,
        'unit_price' => 80000,
        'subtotal' => 160000,
        'topping_subtotal' => 0,
        'original_unit_price' => 100000,
        // #2411 — `tax_rate` NOT NULL từ nay. Ghi thô thì không có factory đóng
        // dấu hộ, nên phải nêu ở đây; 0% là giá trị TÁC GIẢ chọn, không phải
        // "chưa biết".
        'tax_rate' => 0,
        'applied_promotion_id' => $promotion->id,
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $report = app(MenuPromotionService::class)->report($promotion);

    expect($report['items_with_promotion_count'])->toBe(1);
    // 2 × (100000 − 80000).
    expect($report['total_discount_applied'])->toBe(40000.0);
    expect($report['first_redeemed_at'])->not->toBeNull();

    $recent = app(MenuPromotionService::class)->recentItems($promotion, 10);
    expect($recent)->toHaveCount(1);
    expect($recent[0]['customer_order_id'])->toBe((string) $order->id);
    expect($recent[0]['unit_price'])->toBe(80000.0);
    expect($recent[0]['original_unit_price'])->toBe(100000.0);
});

it('S8: khuyến mãi chưa ai dùng trả về bộ số rỗng, không phải null', function () {
    $promotion = MenuPromotion::factory()->create();

    $report = app(MenuPromotionService::class)->report($promotion);

    expect($report)->toBe([
        'items_with_promotion_count' => 0,
        'total_discount_applied' => 0.0,
        'first_redeemed_at' => null,
        'last_redeemed_at' => null,
    ]);
});
