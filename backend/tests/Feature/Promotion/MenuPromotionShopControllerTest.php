<?php

/**
 * Plan-019 — feature tests for Shop\MenuPromotionController.
 *
 * /api/v1/shops/{shopSlug}/promotions surface (T4.7 + T4.8).
 */

use App\Exceptions\MenuPromotionException;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CustomerOrder;
use App\Models\MenuPromotion;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\Promotion\MenuPromotionService;
use Illuminate\Support\Facades\DB;
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
        'slug' => 'promo-shop',
        'is_active' => true,
    ]);

    $shopMgr = Role::firstOrCreate(['slug' => 'shop-manager'], ['name' => 'Shop Manager', 'level' => 30]);
    $orgMgr = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($shopMgr, $this->orgId);
    $this->manager->assignRole($orgMgr, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    $this->base = "/api/v1/shops/{$this->shop->slug}/promotions";
});

it('lists empty promotions initially', function () {
    $this->actingAs($this->manager)
        ->getJson($this->base)
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('creates a promotion happy path', function () {
    $payload = [
        'name:en' => 'Late-night Happy Hour 20%',
        'name:ja' => '深夜ハッピーアワー',
        'name:vi' => 'Happy hour cuối ngày',
        'discount_percent' => 20,
        'applies_to' => 'all_items',
        'daily_time_from' => '21:00',
        'daily_time_to' => '23:00',
        'weekdays' => null,
        'valid_from' => now()->subDay()->toIso8601String(),
        'valid_until' => now()->addDays(30)->toIso8601String(),
        'stacking_mode' => 'stackable_with_coupons',
        'is_active' => true,
    ];

    $this->actingAs($this->manager)
        ->postJson($this->base, $payload)
        ->assertCreated()
        ->assertJsonPath('data.discount_percent', '20.00')
        ->assertJsonPath('data.applies_to', 'all_items')
        ->assertJsonPath('data.stacking_mode', 'stackable_with_coupons')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.applicable_category_ids', [])
        ->assertJsonPath('data.applicable_product_ids', []);
});

it('attaches category pivots when applies_to=categories', function () {
    // Regression: applies_to is cast to an enum, and syncPivots compared the
    // enum instance against string `.value`s with strict in_array — always
    // false — so category/product pivots silently stayed empty and the
    // customer menu never surfaced active_promotion.
    $category = Category::factory()->create(['brand_id' => $this->brand->id]);

    $payload = [
        'name:en' => 'Category promo',
        'discount_percent' => 15,
        'applies_to' => 'categories',
        'applicable_category_ids' => [$category->id],
        'valid_from' => now()->subDay()->toIso8601String(),
        'valid_until' => now()->addDays(7)->toIso8601String(),
        'stacking_mode' => 'stackable_with_coupons',
        'is_active' => true,
    ];

    $res = $this->actingAs($this->manager)
        ->postJson($this->base, $payload)
        ->assertCreated()
        ->assertJsonPath('data.applicable_category_ids', [$category->id]);

    $promotion = MenuPromotion::find($res->json('data.id'));
    expect($promotion->categories()->pluck('categories.id')->all())->toBe([$category->id]);
});

it('rejects discount_percent above 100', function () {
    $this->actingAs($this->manager)
        ->postJson($this->base, basePromotionPayload(discount: 200, appliesTo: 'all_items'))
        ->assertStatus(422);
});

it('rejects discount_percent at or below zero', function () {
    $this->actingAs($this->manager)
        ->postJson($this->base, basePromotionPayload(discount: 0, appliesTo: 'all_items'))
        ->assertStatus(422);
});

it('requires categories array when applies_to=categories', function () {
    $this->actingAs($this->manager)
        ->postJson($this->base, basePromotionPayload(discount: 20, appliesTo: 'categories'))
        ->assertStatus(422);
});

it('requires products array when applies_to=products', function () {
    $this->actingAs($this->manager)
        ->postJson($this->base, basePromotionPayload(discount: 20, appliesTo: 'products'))
        ->assertStatus(422);
});

it('rejects mismatched daily_time_from without daily_time_to', function () {
    $payload = basePromotionPayload(discount: 20, appliesTo: 'all_items');
    $payload['daily_time_from'] = '21:00';
    unset($payload['daily_time_to']);

    $this->actingAs($this->manager)
        ->postJson($this->base, $payload)
        ->assertStatus(422);
});

it('shows a promotion with report meta', function () {
    $promotion = makeShopPromotion($this->shop, $this->brand, $this->orgId);

    $this->actingAs($this->manager)
        ->getJson("{$this->base}/{$promotion->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $promotion->id)
        ->assertJsonPath('meta.report.items_with_promotion_count', 0)
        ->assertJsonPath('meta.report.total_discount_applied', 0);
});

it('updates a promotion happy path', function () {
    $promotion = makeShopPromotion($this->shop, $this->brand, $this->orgId);

    $this->actingAs($this->manager)
        ->putJson("{$this->base}/{$promotion->id}", [
            'name:en' => 'Updated name',
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);
});

it('soft-deletes a promotion with no items applied', function () {
    $promotion = makeShopPromotion($this->shop, $this->brand, $this->orgId);

    $this->actingAs($this->manager)
        ->deleteJson("{$this->base}/{$promotion->id}")
        ->assertNoContent();

    expect(MenuPromotion::withTrashed()->find($promotion->id)->deleted_at)->not->toBeNull();
});

// ─── delete-guard when a promotion has already been applied ──────────────
// Negative counterpart to "soft-deletes a promotion with no items applied":
// once a promotion is stamped onto ≥1 customer_order_item, DELETE must be
// refused and the row must stay live — deletion would orphan the
// applied_promotion_snapshot the receipt/audit trail depends on.
//
// NOTE ON THE DESIGN/IMPL DISCREPANCY: DESIGN B.Edge-cases specifies
// `409 promotion_already_used_use_deactivate_instead`. In practice the
// endpoint returns **403** because MenuPromotionPolicy::delete() short-
// circuits on `customerOrderItems()->count() === 0` and the AuthZ gate runs
// BEFORE MenuPromotionService::delete() (which is where the 409 lives). So
// the service-level 409 guard is currently unreachable over HTTP. These
// tests pin the ACTUAL behaviour (403 at the endpoint) plus the service-
// level 409 contract directly, so a future fix that surfaces 409 through the
// endpoint will flip the first test and flag the intended change. (Product
// fix out of scope for this test-only pass.)

it('rejects deleting a promotion that has been applied to an order item (403 policy gate)', function () {
    $promotion = makeShopPromotion($this->shop, $this->brand, $this->orgId);
    seedPromotionOrderItem($this->shop, $this->brand, $this->orgId, $promotion);

    $this->actingAs($this->manager)
        ->deleteJson("{$this->base}/{$promotion->id}")
        ->assertForbidden();

    // Guard is fail-safe: the promotion is NOT soft-deleted.
    expect(MenuPromotion::withTrashed()->find($promotion->id)->deleted_at)->toBeNull();
});

it('exposes the 409 already-used contract at the service layer with the exact item count', function () {
    // The documented `promotion_already_used_use_deactivate_instead` guard
    // lives in MenuPromotionService::delete(). Exercise it directly (bypassing
    // the policy gate) so the 409 error_code + meta count are pinned even while
    // the HTTP path returns 403 first.
    $promotion = makeShopPromotion($this->shop, $this->brand, $this->orgId);
    seedPromotionOrderItem($this->shop, $this->brand, $this->orgId, $promotion);
    seedPromotionOrderItem($this->shop, $this->brand, $this->orgId, $promotion);
    seedPromotionOrderItem($this->shop, $this->brand, $this->orgId, $promotion);

    $service = app(MenuPromotionService::class);

    try {
        $service->delete($promotion);
        $this->fail('Expected MenuPromotionException for an already-used promotion.');
    } catch (MenuPromotionException $e) {
        expect($e->errorCode)->toBe('promotion_already_used_use_deactivate_instead');
        expect($e->status)->toBe(409);
        expect($e->meta['items_with_promotion_count'])->toBe(3);
    }

    // Still live — the guard threw before touching the row.
    expect(MenuPromotion::withTrashed()->find($promotion->id)->deleted_at)->toBeNull();
});

it('lets the sanctioned remedy through: deactivate the applied promotion instead of deleting', function () {
    // The 409 error tells the operator to deactivate — prove that path works
    // even after the promotion has been applied (toggle is NOT delete-guarded).
    $promotion = makeShopPromotion($this->shop, $this->brand, $this->orgId);
    seedPromotionOrderItem($this->shop, $this->brand, $this->orgId, $promotion);

    $this->actingAs($this->manager)
        ->postJson("{$this->base}/{$promotion->id}/toggle")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect(MenuPromotion::find($promotion->id)->is_active)->toBeFalse();
});

it('deletes a promotion whose only applied item belongs to a DIFFERENT promotion', function () {
    // The guard counts items scoped to THIS promotion only — a sibling
    // promotion's redemptions must never block an unrelated delete.
    $target = makeShopPromotion($this->shop, $this->brand, $this->orgId);
    $other = makeShopPromotion($this->shop, $this->brand, $this->orgId);
    seedPromotionOrderItem($this->shop, $this->brand, $this->orgId, $other);

    $this->actingAs($this->manager)
        ->deleteJson("{$this->base}/{$target->id}")
        ->assertNoContent();

    expect(MenuPromotion::withTrashed()->find($target->id)->deleted_at)->not->toBeNull();
    // Sibling untouched.
    expect(MenuPromotion::withTrashed()->find($other->id)->deleted_at)->toBeNull();
});

it('toggles is_active', function () {
    $promotion = makeShopPromotion($this->shop, $this->brand, $this->orgId);

    expect((bool) $promotion->is_active)->toBeTrue();

    $this->actingAs($this->manager)
        ->postJson("{$this->base}/{$promotion->id}/toggle")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $this->actingAs($this->manager)
        ->postJson("{$this->base}/{$promotion->id}/toggle")
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
});

it('returns 401 without auth', function () {
    $this->getJson($this->base)->assertUnauthorized();
});

// ─── helpers ────────────────────────────────────────────────────────────

function basePromotionPayload(float $discount, string $appliesTo): array
{
    return [
        'name:en' => 'Test promo',
        'discount_percent' => $discount,
        'applies_to' => $appliesTo,
        'valid_from' => now()->subDay()->toIso8601String(),
        'valid_until' => now()->addDays(7)->toIso8601String(),
        'stacking_mode' => 'stackable_with_coupons',
    ];
}

/**
 * Stamp one customer_order_item with applied_promotion_id = $promotion so the
 * delete-guard (MenuPromotionService::delete → customerOrderItems()->count())
 * sees a live redemption. Raw insert dodges the product_sku FK — the guard
 * only reads applied_promotion_id.
 */
function seedPromotionOrderItem(Branch $branch, Brand $brand, string $orgId, MenuPromotion $promotion): void
{
    $order = CustomerOrder::factory()->create([
        'branch_id' => $branch->id,
        'brand_id' => $brand->id,
        'organization_id' => $orgId,
        'subtotal' => 100000,
        'total_amount' => 100000,
        'status' => 'open',
        'opened_at' => now(),
    ]);

    DB::table('customer_order_items')->insert([
        'id' => (string) Str::uuid(),
        'customer_order_id' => $order->id,
        'product_sku_id' => (string) Str::uuid(),
        'quantity' => 1,
        'unit_price' => 80000,
        'subtotal' => 80000,
        // #2411 — ghi thô để né FK `product_sku` nên không factory nào đóng dấu
        // hộ; 0% là giá trị tác giả chọn (ca này chỉ đọc promotion, không đọc
        // thuế), cùng lối `StackingTest` đã chọn ở #2188.
        'tax_rate' => 0,
        'topping_subtotal' => 0,
        'original_unit_price' => 100000,
        'applied_promotion_id' => $promotion->id,
        'applied_promotion_snapshot' => json_encode([
            'name' => 'Seed promo',
            'discount_percent' => '20.00',
            'stacking_mode' => $promotion->stacking_mode,
        ]),
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function makeShopPromotion(Branch $branch, Brand $brand, string $orgId): MenuPromotion
{
    return MenuPromotion::factory()->create([
        'branch_id' => $branch->id,
        'brand_id' => $brand->id,
        'organization_id' => $orgId,
        'name' => 'Seed promo',
        'discount_percent' => 20,
        'applies_to' => 'all_items',
        'daily_time_from' => null,
        'daily_time_to' => null,
        'weekdays' => null,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'stacking_mode' => 'stackable_with_coupons',
        'is_active' => true,
    ]);
}
