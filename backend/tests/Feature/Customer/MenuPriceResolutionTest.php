<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\FloatingSection;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Services\Customer\CustomerOrderService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * #514 — the same product_sku can appear on several active menus of one
 * branch at different selling_prices. addItems used to resolve the fallback
 * (no explicit menu_product_sku_id) via an unordered `->value()`, so cloud
 * and customer-web could disagree on which menu-price applied — the guest
 * agreed to one total but the saved order charged another.
 *
 * The fix pins the fallback to a deterministic authoritative rule: among THIS
 * branch's active menus carrying the SKU, take the LOWEST selling_price
 * (tie-broken by id).
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
});

function mprOrder(): CustomerOrder
{
    return CustomerOrder::create([
        'order_code' => 'ORD-'.Str::random(6),
        'order_type' => 'dine_in',
        'status' => 'open',
        'subtotal' => 0, 'discount_amount' => 0, 'service_charge' => 0,
        'tax_amount' => 0, 'total_amount' => 0, 'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
}

/** Attach $sku to a fresh active branch menu at $price and return the row. */
function mprMenuPrice(ProductSku $sku, float $price): MenuProductSku
{
    $menu = Menu::factory()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'branch_id' => test()->branch->id,
        'status' => 'Active',
    ]);
    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'is_active' => true,
    ]);

    return MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $sku->id,
        'is_active' => true,
        'selling_price' => $price,
    ]);
}

it('resolves the LOWEST active menu-price when a SKU sits on multiple branch menus', function () {
    $sku = ProductSku::factory()->create(['selling_price' => 9999]);

    // Same SKU, three active branch menus, different prices — inserted out of
    // order so an unordered ->value() would surface a non-minimum row.
    mprMenuPrice($sku, 2350);
    mprMenuPrice($sku, 2250); // the authoritative lowest
    mprMenuPrice($sku, 2450);

    $items = app(CustomerOrderService::class)->addItems(mprOrder(), [
        'items' => [[
            'product_sku_id' => $sku->id,
            'quantity' => 1,
        ]],
    ]);

    expect((float) $items[0]->unit_price)->toBe(2250.0);
});

it('is deterministic regardless of menu insertion order', function () {
    $sku = ProductSku::factory()->create(['selling_price' => 9999]);
    mprMenuPrice($sku, 1500);
    mprMenuPrice($sku, 1300); // lowest
    mprMenuPrice($sku, 1400);

    $items = app(CustomerOrderService::class)->addItems(mprOrder(), [
        'items' => [['product_sku_id' => $sku->id, 'quantity' => 2]],
    ]);

    expect((float) $items[0]->unit_price)->toBe(1300.0)
        ->and((float) $items[0]->subtotal)->toBe(2600.0);
});

it('ignores menu-prices from OTHER branches', function () {
    $sku = ProductSku::factory()->create(['selling_price' => 5000]);

    // A cheaper price on a DIFFERENT branch's menu must not leak in.
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $otherMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $otherBranch->id,
        'status' => 'Active',
    ]);
    $otherMp = MenuProduct::factory()->create(['menu_id' => $otherMenu->id, 'is_active' => true]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $otherMp->id,
        'product_sku_id' => $sku->id,
        'is_active' => true,
        'selling_price' => 100, // cheap, but wrong branch
    ]);

    // This branch only advertises it at 2000.
    mprMenuPrice($sku, 2000);

    $items = app(CustomerOrderService::class)->addItems(mprOrder(), [
        'items' => [['product_sku_id' => $sku->id, 'quantity' => 1]],
    ]);

    expect((float) $items[0]->unit_price)->toBe(2000.0);
});

it('falls back to ProductSku.selling_price when the SKU is off-menu for the branch', function () {
    $sku = ProductSku::factory()->create(['selling_price' => 777]);

    $items = app(CustomerOrderService::class)->addItems(mprOrder(), [
        'items' => [['product_sku_id' => $sku->id, 'quantity' => 1]],
    ]);

    expect((float) $items[0]->unit_price)->toBe(777.0);
});

it('applies the active Floating Section SKU price to the persisted order line', function () {
    $sku = ProductSku::factory()->create(['selling_price' => 2000, 'is_active' => true]);
    mprMenuPrice($sku, 1500);
    $section = FloatingSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'start_date' => null,
        'end_date' => null,
    ]);
    $section->schedules()->create([
        'start_time' => '00:00:00',
        'end_time' => '23:59:59',
        'days_of_week' => 127,
        'is_active' => true,
        'priority' => 0,
    ]);
    $floatingProduct = $section->products()->create([
        'product_id' => $sku->product_id,
        'is_active' => true,
        'display_order' => 1,
    ]);
    $floatingProduct->skus()->create([
        'product_sku_id' => $sku->id,
        'is_active' => true,
        'selling_price' => 900,
        'is_price_overridden' => true,
    ]);

    $items = app(CustomerOrderService::class)->addItems(mprOrder(), [
        'items' => [['product_sku_id' => $sku->id, 'quantity' => 1]],
    ]);

    expect((float) $items[0]->unit_price)->toBe(900.0);
});

it('rejects an explicit menu line from another branch as an authoritative price', function () {
    $sku = ProductSku::factory()->create(['selling_price' => 3000]);
    mprMenuPrice($sku, 2000);
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $otherMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $otherBranch->id,
        'status' => 'Active',
    ]);
    $otherProduct = MenuProduct::factory()->create(['menu_id' => $otherMenu->id, 'is_active' => true]);
    $foreignLine = MenuProductSku::factory()->create([
        'menu_product_id' => $otherProduct->id,
        'product_sku_id' => $sku->id,
        'is_active' => true,
        'selling_price' => 1,
    ]);

    expect(fn () => app(CustomerOrderService::class)->addItems(mprOrder(), [
        'items' => [[
            'product_sku_id' => $sku->id,
            'menu_product_sku_id' => $foreignLine->id,
            'quantity' => 1,
        ]],
    ]))->toThrow(ValidationException::class);
});

/**
 * #1185 — the customer menu now MERGES every active menu into one view, so a
 * guest can see the same SKU twice, under two different sections, at two
 * different prices. What the section showed is what the guest agreed to pay,
 * and the tap sends that section's menu_product_sku_id.
 *
 * The lowest-price rule above is the fallback for a product-anchored line; it
 * must NOT reach over an explicit menu line and quietly re-price it. This is
 * the merged view's highest-risk path, so it is pinned in both directions.
 */
it('#1185 charges the menu line the guest tapped, even when a cheaper menu line exists', function () {
    $sku = ProductSku::factory()->create(['selling_price' => 9999]);

    $cheap = mprMenuPrice($sku, 1000);
    $tapped = mprMenuPrice($sku, 1800);

    $items = app(CustomerOrderService::class)->addItems(mprOrder(), [
        'items' => [[
            'product_sku_id' => $sku->id,
            'menu_product_sku_id' => $tapped->id,
            'quantity' => 1,
        ]],
    ]);

    // Price IS the proof of which line was used: 1800 is reachable only from
    // the tapped line, 1000 only from the cheaper one.
    expect((float) $items[0]->unit_price)->toBe(1800.0)
        ->and((float) $items[0]->unit_price)->not->toBe((float) $cheap->selling_price);
});

it('#1185 still falls back to the lowest price when the line names no menu', function () {
    $sku = ProductSku::factory()->create(['selling_price' => 9999]);

    mprMenuPrice($sku, 1000);
    mprMenuPrice($sku, 1800);

    $items = app(CustomerOrderService::class)->addItems(mprOrder(), [
        'items' => [['product_sku_id' => $sku->id, 'quantity' => 1]],
    ]);

    expect((float) $items[0]->unit_price)->toBe(1000.0);
});
