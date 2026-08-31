<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Services\Customer\CustomerOrderService;
use Illuminate\Support\Str;

/*
 * #1420 — a line added WITHOUT `menu_product_sku_id` must still be taxed by the
 * tier-1 override of the menu line whose price it was charged.
 *
 * Customer-web never sends `menu_product_sku_id` (grep: zero hits), so every one
 * of its orders takes the #514 lowest-price fallback. That branch resolved a
 * `menu_product_id` and handed the resolver its menu + section (tiers 3 and 2)
 * while leaving the tier-1 type null — so the MENU's rate beat the menu LINE's
 * own override, inverting the documented priority. A line overridden to 10%
 * inside an 8% menu was displayed at 10% (MenuController stamps
 * `effective_tax_rate` from the same override) and billed at 8%: ORD-2026-4483,
 * ¥5,100 税込 shown as ¥464 tax, saved as ¥378.
 *
 * The existing tax suites all pass `menu_product_sku_id` explicitly, which is
 * why the whole fallback branch went uncovered.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->standard = TaxType::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'code' => 'STANDARD', 'rate' => 10, 'is_default' => true,
    ]);
    $this->reduced = TaxType::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'code' => 'REDUCED', 'rate' => 8,
    ]);

    // Branch default is the REDUCED type, exactly like the branch this was
    // reported on — so "8%" in a failure is ambiguous between tier 3 and tier 5
    // unless the assertions below pin the menu too.
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'default_tax_type_id' => $this->reduced->id,
        'prices_include_tax' => true,
        'currency_code' => 'JPY',
    ]);

    $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $this->product = Product::factory()->active()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'product_type_id' => $pt->id, 'tax_type_id' => $this->reduced->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $this->product->id, 'selling_price' => 1700, 'is_active' => true,
    ]);

    // The reported shape: an 8% menu (tier 3) carrying a line overridden to
    // 10% (tier 1). Every other tier resolves to 8%, so 10% can only come from
    // the override — the assertion cannot pass by accident.
    $this->menu = Menu::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id, 'status' => 'Active',
        'tax_type_id' => $this->reduced->id,
    ]);
    $this->line = MenuProduct::factory()->create([
        'menu_id' => $this->menu->id, 'product_id' => $this->product->id,
        'is_active' => true, 'tax_type_id' => $this->standard->id,
    ]);
    $this->menuSku = MenuProductSku::factory()->create([
        'menu_product_id' => $this->line->id, 'product_sku_id' => $this->sku->id,
        'selling_price' => 1700, 'is_price_overridden' => true, 'is_active' => true,
    ]);

    $this->makeOrder = fn (): CustomerOrder => app(CustomerOrderService::class)->create([
        'order_type' => 'takeaway',
        'status' => 'open',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
});

it('applies the menu line tier-1 override when the client sends no menu_product_sku_id', function () {
    $order = ($this->makeOrder)();

    app(CustomerOrderService::class)->addItems($order, ['items' => [[
        'product_sku_id' => $this->sku->id,
        'quantity' => 1,
    ]]]);

    $item = $order->fresh()->items->first();

    expect((float) $item->tax_rate)->toBe(10.0)
        ->and($item->tax_type_id)->toBe($this->standard->id);
});

it('charges the same rate whether or not the client names the menu line', function () {
    // The two transports must not disagree — that disagreement IS the bug.
    $implicit = ($this->makeOrder)();
    app(CustomerOrderService::class)->addItems($implicit, ['items' => [[
        'product_sku_id' => $this->sku->id, 'quantity' => 1,
    ]]]);

    $explicit = ($this->makeOrder)();
    app(CustomerOrderService::class)->addItems($explicit, ['items' => [[
        'product_sku_id' => $this->sku->id,
        'menu_product_sku_id' => $this->menuSku->id,
        'quantity' => 1,
    ]]]);

    expect((float) $implicit->fresh()->items->first()->tax_rate)
        ->toBe((float) $explicit->fresh()->items->first()->tax_rate)
        ->toBe(10.0);
});

it('still inherits the menu rate for a line the menu does not override', function () {
    // Guard against over-correcting: with tier 1 empty, tier 3 must still win
    // over the product — the #1218 ruling this fix must not disturb.
    $this->line->update(['tax_type_id' => null]);
    $this->product->update(['tax_type_id' => $this->standard->id]);
    $this->menu->update(['tax_type_id' => $this->reduced->id]);

    $order = ($this->makeOrder)();
    app(CustomerOrderService::class)->addItems($order, ['items' => [[
        'product_sku_id' => $this->sku->id, 'quantity' => 1,
    ]]]);

    expect((float) $order->fresh()->items->first()->tax_rate)->toBe(8.0);
});

it('bills 総額表示 ¥5,100 at the rate the menu line advertises', function () {
    // The reported order, to the yen: 3 × ¥1,700 tax-inclusive. At the override
    // (10%) the tax is ¥464; the bug billed the menu's 8% → ¥378.
    $order = ($this->makeOrder)();

    app(CustomerOrderService::class)->addItems($order, ['items' => [[
        'product_sku_id' => $this->sku->id, 'quantity' => 3,
    ]]]);

    $fresh = $order->fresh();

    expect((float) $fresh->total_amount)->toBe(5100.0)
        ->and((float) $fresh->tax_amount)->toBe(464.0);
});
