<?php

// Test-gap coverage (plan-001): the shop-side SKU price-override endpoint's
// validation failure paths (negative / zero / missing / non-numeric) and the
// decimal(15,2) money-rounding behaviour were never asserted. These are the
// high-risk money paths — a validation hole here lets a branch set a 0 or
// negative retail price, and a rounding drift corrupts per-SKU reconciliation.

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
        'slug' => 'price-validation-shop',
        'is_active' => true,
    ]);

    $productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'product_type_id' => $productType->id,
        'brand_id' => $this->brand->id,
    ]);

    $this->productSku = ProductSku::factory()->create([
        'product_id' => $this->product->id,
        'selling_price' => 30000.00,
        'is_active' => true,
    ]);

    $this->masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'is_master' => true,
        'status' => 'Active',
        'master_menu_id' => null,
    ]);

    $this->masterMp = MenuProduct::factory()->create([
        'menu_id' => $this->masterMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $this->branchMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'is_master' => false,
        'status' => 'Active',
        'master_menu_id' => $this->masterMenu->id,
    ]);

    $this->branchMp = MenuProduct::factory()->create([
        'menu_id' => $this->branchMenu->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 1,
        'master_menu_product_id' => $this->masterMp->id,
    ]);

    $this->branchMpSku = MenuProductSku::factory()->create([
        'menu_product_id' => $this->branchMp->id,
        'product_sku_id' => $this->productSku->id,
        'selling_price' => 30000.00,
        'is_price_overridden' => false,
        'is_active' => true,
    ]);

    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );
    $this->manager = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->manager->assignRole($this->managerRole, $this->orgId);

    $this->priceUrl = "/api/v1/shops/{$this->shop->slug}/menus/{$this->branchMenu->id}"
        ."/products/{$this->branchMp->id}/skus/{$this->branchMpSku->id}/price";
});

// =============================================================================
// Validation — price override failure paths
// =============================================================================

it('rejects a negative selling_price with 422 and leaves the SKU price untouched', function () {
    $this->actingAs($this->manager)
        ->postJson($this->priceUrl, ['selling_price' => -1])
        ->assertStatus(422)
        ->assertJsonValidationErrors('selling_price');

    $fresh = $this->branchMpSku->fresh();
    expect((float) $fresh->selling_price)->toBe(30000.00);
    expect($fresh->is_price_overridden)->toBeFalse();
});

it('ACCEPTS a zero selling_price — 0 là một mức giá (#2052)', function () {
    // Đảo chiều ở #2052. Trước đó `min:0.01` chặn giá 0, và test này ghim
    // đúng luật ấy — nên nó là chỗ luật cũ sống lâu nhất sau khi code đã đổi.
    //
    // Giá 0 hợp lệ: hàng tặng, quà khuyến mãi, món kèm combo, đổi điểm, hàng
    // mẫu. Mọi mô hình POS chuẩn (ARTS/NRF) đều cho dòng giá 0. Thứ phải chặn
    // là giá ÂM — test ngay trên vẫn giữ nguyên và vẫn phải đỏ nếu ai nới nó.
    //
    // `min:0.01` cũng chưa bao giờ là một biện pháp kiểm soát: nó không chặn
    // được ai tặng hàng, hạ xuống 0,01 là lách xong.
    $this->actingAs($this->manager)
        ->postJson($this->priceUrl, ['selling_price' => 0])
        ->assertOk();

    $fresh = $this->branchMpSku->fresh();
    expect((float) $fresh->selling_price)->toBe(0.00);
    expect($fresh->is_price_overridden)->toBeTrue();
});

it('rejects a missing selling_price with 422 (required)', function () {
    $this->actingAs($this->manager)
        ->postJson($this->priceUrl, [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('selling_price');
});

it('rejects a non-numeric selling_price with 422', function () {
    $this->actingAs($this->manager)
        ->postJson($this->priceUrl, ['selling_price' => 'free'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('selling_price');
});

it('accepts 0.01 and overrides the price (không còn là BIÊN từ #2052 — biên giờ là 0)', function () {
    $this->actingAs($this->manager)
        ->postJson($this->priceUrl, ['selling_price' => 0.01])
        ->assertSuccessful();

    $fresh = $this->branchMpSku->fresh();
    expect((string) $fresh->selling_price)->toBe('0.01');
    expect($fresh->is_price_overridden)->toBeTrue();
});

// =============================================================================
// Money rounding / precision — decimal(15,2)
// =============================================================================

it('formats an integer-ish override to exactly two decimal places', function () {
    // A single-decimal input must serialise as two-decimal money, never "100.5".
    $response = $this->actingAs($this->manager)
        ->postJson($this->priceUrl, ['selling_price' => 100.5])
        ->assertSuccessful();

    expect($response->json('data.selling_price'))->toBe('100.50');
    expect((string) $this->branchMpSku->fresh()->selling_price)->toBe('100.50');
});

it('preserves an exact two-decimal override with no float drift', function () {
    $response = $this->actingAs($this->manager)
        ->postJson($this->priceUrl, ['selling_price' => 12345.67])
        ->assertSuccessful();

    expect($response->json('data.selling_price'))->toBe('12345.67');
    expect((string) $this->branchMpSku->fresh()->selling_price)->toBe('12345.67');
});

it('rounds a three-decimal override half-up to two decimals', function () {
    // decimal:2 cast rounds on read — 100.999 → 101.00, 100.994 → 100.99.
    $this->actingAs($this->manager)
        ->postJson($this->priceUrl, ['selling_price' => 100.999])
        ->assertSuccessful();
    expect((string) $this->branchMpSku->fresh()->selling_price)->toBe('101.00');

    $this->actingAs($this->manager)
        ->postJson($this->priceUrl, ['selling_price' => 100.994])
        ->assertSuccessful();
    expect((string) $this->branchMpSku->fresh()->selling_price)->toBe('100.99');
});

it('resets an overridden price back to the exact live product_skus.selling_price', function () {
    // Brand list price carries cents; reset must restore the live value exactly.
    $this->productSku->update(['selling_price' => 28999.99]);
    $this->branchMpSku->update(['selling_price' => 40000.00, 'is_price_overridden' => true]);

    $resetUrl = "/api/v1/shops/{$this->shop->slug}/menus/{$this->branchMenu->id}"
        ."/products/{$this->branchMp->id}/skus/{$this->branchMpSku->id}/reset-price";

    $response = $this->actingAs($this->manager)
        ->postJson($resetUrl)
        ->assertSuccessful();

    expect($response->json('data.selling_price'))->toBe('28999.99');
    $fresh = $this->branchMpSku->fresh();
    expect((string) $fresh->selling_price)->toBe('28999.99');
    expect($fresh->is_price_overridden)->toBeFalse();
});
