<?php

/**
 * Plan-019 — feature tests for the Coupon × MenuPromotion stacking
 * guard (Decision B5). Covers both directions of the conflict:
 *
 *   1. Apply coupon when cart has an exclusive promotion item →
 *      422 coupon_excluded_by_active_promotion + meta.
 *   2. Add a promotion item when order already has a coupon AND
 *      promotion is exclusive → 422 cannot_add_promotion_item_with_coupon.
 *
 * Stackable promotions allow the coupon side-by-side; the test
 * verifies that path passes too.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\MenuPromotion;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'stacking-shop',
        'is_active' => true,
    ]);
    $managerRole = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    // Open order via API.
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders")
        ->assertCreated();
    $this->order = CustomerOrder::first();
    $this->order->update(['subtotal' => 200000, 'total_amount' => 200000]);
});

it('rejects coupon apply when cart has an exclusive-promotion item', function () {
    // Build the exclusive-promotion + a fake order item that references it.
    $promotion = makeStackingPromotion($this->shop, $this->brand, 'exclusive_with_coupons');
    seedItemWithPromotion($this->order, $promotion);

    Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'STKCOUPON',
        'discount_type' => 'percent',
        'discount_value' => 10,
        'min_order_subtotal' => 0,
        'usage_limit_total' => null,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", [
            'code' => 'STKCOUPON',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'coupon_excluded_by_active_promotion');

    expect($response->json('meta.exclusive_promotion_ids'))->toContain($promotion->id);
});

it('downgrades exclusive promotion items when apply opts in (User picks coupon over HH)', function () {
    // Plan-019 — "Use coupon instead of promotion" CTA. Order has 1 line
    // with an exclusive HH discount (unit 80k, original 100k). Customer
    // wants coupon instead → backend reverts line to 100k + applies coupon.
    $promotion = makeStackingPromotion($this->shop, $this->brand, 'exclusive_with_coupons');
    $item = seedItemWithPromotion($this->order, $promotion);

    // Re-sync the cart subtotal so the apply path computes off the
    // discounted value pre-downgrade (the service will then revert).
    $this->order->forceFill(['subtotal' => 80000])->save();

    Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'PREFCOUPON',
        'discount_type' => 'fixed',
        'discount_value' => 5000,
        'min_order_subtotal' => 0,
        'usage_limit_total' => null,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", [
            'code' => 'PREFCOUPON',
            'downgrade_exclusive_promotions' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.coupon_code_snapshot', 'PREFCOUPON');

    // Line price reverted to original; promotion snapshot cleared. #2617 —
    // original_unit_price không bị null nữa: dấu vết định hình giá là bắt
    // buộc trên mọi dòng, sau downgrade original == unit (độ sâu giảm = 0).
    $item->refresh();
    expect((float) $item->unit_price)->toBe(100000.0);
    expect((float) $item->original_unit_price)->toBe(100000.0);
    expect($item->applied_promotion_id)->toBeNull();
    expect($item->applied_promotion_snapshot)->toBeNull();
});

it('still rejects when downgrade_exclusive_promotions is missing or false', function () {
    // Symmetric regression — make sure the default (no flag) behaviour
    // didn't change; FE must explicitly opt in.
    $promotion = makeStackingPromotion($this->shop, $this->brand, 'exclusive_with_coupons');
    seedItemWithPromotion($this->order, $promotion);

    Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'DEFAULTREJ',
        'discount_type' => 'fixed',
        'discount_value' => 5000,
        'min_order_subtotal' => 0,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    // No flag → reject as before.
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", [
            'code' => 'DEFAULTREJ',
        ])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'coupon_excluded_by_active_promotion');

    // Explicit false also rejects.
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", [
            'code' => 'DEFAULTREJ',
            'downgrade_exclusive_promotions' => false,
        ])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'coupon_excluded_by_active_promotion');
});

it('allows coupon apply when cart has only stackable-promotion items', function () {
    $promotion = makeStackingPromotion($this->shop, $this->brand, 'stackable_with_coupons');
    seedItemWithPromotion($this->order, $promotion);

    Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'STACKOK',
        'discount_type' => 'fixed',
        'discount_value' => 5000,
        'min_order_subtotal' => 0,
        'usage_limit_total' => null,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", [
            'code' => 'STACKOK',
        ])
        ->assertOk()
        ->assertJsonPath('data.discount_amount', '5000.00');
});

it('prices a stacked coupon off the ALREADY-DISCOUNTED subtotal (BR-COUP06)', function () {
    // #2125 §A — ca "stackable cho qua" ngay trên chỉ đo được CỬA (200 OK) chứ
    // không đo SỐ TIỀN: coupon của nó là `fixed` 5000, mà 5000 thì bằng nhau dù
    // cộng dồn tính trên giá đã giảm hay trên giá menu gốc. Tức nửa quan trọng
    // của BR-COUP06 — cộng dồn thì coupon áp lên phần CÒN LẠI — chưa từng bị đo.
    //
    // Ở đây dùng coupon PHẦN TRĂM để hai cách hiểu tách ra thành hai con số:
    //   giá menu gốc 100.000 → 25% = 25.000   (SAI — bỏ qua khuyến mãi)
    //   giá sau KM   80.000 → 25% = 20.000   (ĐÚNG — cộng dồn theo thứ tự)
    $promotion = makeStackingPromotion($this->shop, $this->brand, 'stackable_with_coupons');
    seedItemWithPromotion($this->order, $promotion);

    // Giỏ chỉ có đúng dòng vừa seed (đơn giá KM 80k, gốc 100k), nên subtotal của
    // đơn phải là 80k — beforeEach đặt 200k cho các ca không có dòng hàng thật.
    $this->order->forceFill(['subtotal' => 80000, 'total_amount' => 80000])->save();

    Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'STACKAMOUNT',
        'discount_type' => 'percent',
        'discount_value' => 25,
        'max_discount_cap' => null,
        'min_order_subtotal' => 0,
        'usage_limit_total' => null,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", [
            'code' => 'STACKAMOUNT',
        ])
        ->assertOk()
        ->assertJsonPath('data.discount_amount', '20000.00')
        ->assertJsonPath('data.total_amount', '60000.00');

    // Cộng dồn KHÔNG được hoàn nguyên dòng hàng: khác hẳn nhánh `downgrade`,
    // giá KM phải còn nguyên trên dòng sau khi coupon áp. Thiếu vế này thì một
    // bản cài đặt "gỡ KM rồi mới áp coupon" vẫn ra đúng 20.000 ở tổng khi giá
    // gốc tình cờ khớp, và mất thông tin khuyến mãi mà không ai thấy.
    $item = CustomerOrderItem::where('customer_order_id', $this->order->id)->first();
    expect((float) $item->unit_price)->toBe(80000.0);
    expect((float) $item->original_unit_price)->toBe(100000.0);
    expect($item->applied_promotion_id)->toBe($promotion->id);
});

it('skips voided items when checking exclusive guard', function () {
    $promotion = makeStackingPromotion($this->shop, $this->brand, 'exclusive_with_coupons');
    seedItemWithPromotion($this->order, $promotion, status: 'voided');

    Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'NOTBLOCKED',
        'discount_type' => 'fixed',
        'discount_value' => 5000,
        'min_order_subtotal' => 0,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    // Voided item should not block the coupon (BR-OI07 + B5 guard).
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/apply-coupon", [
            'code' => 'NOTBLOCKED',
        ])
        ->assertOk();
});

// ─── helpers ────────────────────────────────────────────────────────────

function makeStackingPromotion(Branch $branch, Brand $brand, string $stackingMode): MenuPromotion
{
    return MenuPromotion::factory()->create([
        'branch_id' => $branch->id,
        'brand_id' => $brand->id,
        'organization_id' => $branch->console_organization_id ?? Organization::first()->id,
        'name' => 'Test '.$stackingMode,
        'discount_percent' => 20,
        'applies_to' => 'all_items',
        'stacking_mode' => $stackingMode,
        'is_active' => true,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
    ]);
}

function seedItemWithPromotion(CustomerOrder $order, MenuPromotion $promotion, string $status = 'pending'): CustomerOrderItem
{
    // CustomerOrderItem requires productSku; use raw DB to dodge the FK.
    // Stacking guard reads applied_promotion_id + items.status only.
    $itemId = (string) Str::uuid();
    DB::table('customer_order_items')->insert([
        'id' => $itemId,
        'customer_order_id' => $order->id,
        'product_sku_id' => (string) Str::uuid(),
        'quantity' => 1,
        'unit_price' => 80000,
        'subtotal' => 80000,
        // #2188 — mọi dòng đều mang snapshot thuế; dòng NULL bị engine LOẠI
        // khỏi giỏ sống (bỏ dòng + WARN), làm coupon tính trên nền 0.
        'tax_rate' => 0,
        'topping_subtotal' => 0,
        'original_unit_price' => 100000,
        'applied_promotion_id' => $promotion->id,
        'applied_promotion_snapshot' => json_encode([
            'name' => 'Test',
            'discount_percent' => '20.00',
            'stacking_mode' => $promotion->stacking_mode,
        ]),
        'status' => $status,
        'voided_at' => $status === 'voided' ? now() : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return CustomerOrderItem::find($itemId);
}
