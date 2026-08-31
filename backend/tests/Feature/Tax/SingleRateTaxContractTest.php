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
use App\Services\Customer\CustomerMenuService;
use App\Services\Customer\CustomerOrderService;
use App\Services\Customer\TaxResolver;
use Illuminate\Support\Str;

/*
 * #1099 — single-rate TaxType contract. A tax type is ONE number; consumption
 * context (店内 vs 持ち帰り) is a MENU concern. These are BUSINESS tests: they
 * assert the exact yen a customer pays under the new model and that the two
 * behaviours the old model had — a per-order-type rate pair and the silent
 * re-price on an order_type flip — are gone for good.
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

    // Explicit rates so every expected yen below is traceable to a master row.
    $this->standard = TaxType::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'code' => 'STANDARD', 'rate' => 10, 'is_default' => true,
    ]);
    $this->reduced = TaxType::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'code' => 'REDUCED', 'rate' => 8,
    ]);

    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'default_tax_type_id' => $this->standard->id,
        'prices_include_tax' => false,
        'currency_code' => 'JPY',
    ]);

    $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    // The bentō: base type STANDARD (dine-in nền 10%). NO flag, NO rate pair.
    $this->bento = Product::factory()->active()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'product_type_id' => $pt->id, 'tax_type_id' => $this->standard->id,
    ]);
    $this->bentoSku = ProductSku::factory()->create([
        'product_id' => $this->bento->id, 'selling_price' => 1000, 'is_active' => true,
    ]);

    // Two menus for the SAME product — context lives here, not on the type.
    $this->dineInMenu = Menu::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id, 'status' => 'Active',
    ]);
    $this->dineInLine = MenuProduct::factory()->create([
        'menu_id' => $this->dineInMenu->id, 'product_id' => $this->bento->id,
        'is_active' => true, 'tax_type_id' => null, // inherits product → STANDARD 10%
    ]);
    $this->dineInMenuSku = MenuProductSku::factory()->create([
        'menu_product_id' => $this->dineInLine->id, 'product_sku_id' => $this->bentoSku->id,
        'selling_price' => 1000, 'is_price_overridden' => true, 'is_active' => true,
    ]);

    $this->takeawayMenu = Menu::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id, 'status' => 'Active',
    ]);
    $this->takeawayLine = MenuProduct::factory()->create([
        'menu_id' => $this->takeawayMenu->id, 'product_id' => $this->bento->id,
        'is_active' => true, 'tax_type_id' => $this->reduced->id, // 持ち帰り → 8%
    ]);
    $this->takeawayMenuSku = MenuProductSku::factory()->create([
        'menu_product_id' => $this->takeawayLine->id, 'product_sku_id' => $this->bentoSku->id,
        'selling_price' => 1000, 'is_price_overridden' => true, 'is_active' => true,
    ]);
});

function singleRateOrder(string $orderType): CustomerOrder
{
    return app(CustomerOrderService::class)->create([
        'order_type' => $orderType,
        // Takeaway would default to `pending`; keep both transports on `open`
        // so the update() lifecycle gate lets the flip itself through — the
        // point under test is the TAX, not the status machine.
        'status' => 'open',
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
}

/** Add the bentō through a SPECIFIC menu line — the whole point of #1099. */
function addViaMenuLine(CustomerOrder $order, MenuProductSku $menuSku, int $qty = 1): void
{
    app(CustomerOrderService::class)->addItems($order, ['items' => [[
        'product_sku_id' => test()->bentoSku->id,
        'menu_product_sku_id' => $menuSku->id,
        'quantity' => $qty,
    ]]]);
}

// =========================================================================
//  The master is one number
// =========================================================================

it('a tax type is ONE rate — the master carries no per-context pair anywhere', function () {
    expect(Schema::hasColumn('tax_types', 'rate'))->toBeTrue()
        ->and(Schema::hasColumn('tax_types', 'rate_dine_in'))->toBeFalse()
        ->and(Schema::hasColumn('tax_types', 'rate_takeaway'))->toBeFalse()
        ->and((float) $this->standard->rate)->toBe(10.0)
        ->and((float) $this->reduced->rate)->toBe(8.0);
});

// =========================================================================
//  Context = which menu line, exact yen
// =========================================================================

it('the SAME ¥1,000 bentō pays ¥100 tax through the dine-in menu and ¥80 through the takeaway menu', function () {
    $dineIn = singleRateOrder('dine_in');
    addViaMenuLine($dineIn, $this->dineInMenuSku);
    $dineIn->refresh();

    $takeaway = singleRateOrder('takeaway');
    addViaMenuLine($takeaway, $this->takeawayMenuSku);
    $takeaway->refresh();

    // Dine-in line inherits the product's STANDARD: ¥1,000 @ 10% = ¥100.
    $dineInItem = $dineIn->items->first();
    expect((float) $dineInItem->tax_rate)->toBe(10.0)
        ->and($dineInItem->tax_type_id)->toBe($this->standard->id)
        ->and((float) $dineIn->tax_amount)->toBe(100.0)
        ->and((float) $dineIn->total_amount)->toBe(1100.0);

    // Takeaway line carries the menu override REDUCED: ¥1,000 @ 8% = ¥80.
    $takeawayItem = $takeaway->items->first();
    expect((float) $takeawayItem->tax_rate)->toBe(8.0)
        ->and($takeawayItem->tax_type_id)->toBe($this->reduced->id)
        ->and((float) $takeaway->tax_amount)->toBe(80.0)
        ->and((float) $takeaway->total_amount)->toBe(1080.0);
});

it('one order mixing both rates groups per rate: ¥100 (10% group) + ¥80 (8% group) = ¥180 exactly', function () {
    // A second product on the dine-in menu — same ¥1,000, base STANDARD.
    // (The same SKU added twice would merge into one line per BR-OI06, so the
    // mix needs two distinct products to hold two rates side by side.)
    $cola = Product::factory()->active()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'product_type_id' => $this->bento->product_type_id, 'tax_type_id' => $this->standard->id,
    ]);
    $colaSku = ProductSku::factory()->create([
        'product_id' => $cola->id, 'selling_price' => 1000, 'is_active' => true,
    ]);
    $colaLine = MenuProduct::factory()->create([
        'menu_id' => $this->dineInMenu->id, 'product_id' => $cola->id,
        'is_active' => true, 'tax_type_id' => null,
    ]);
    $colaMenuSku = MenuProductSku::factory()->create([
        'menu_product_id' => $colaLine->id, 'product_sku_id' => $colaSku->id,
        'selling_price' => 1000, 'is_price_overridden' => true, 'is_active' => true,
    ]);

    $order = singleRateOrder('dine_in');
    app(CustomerOrderService::class)->addItems($order, ['items' => [
        ['product_sku_id' => $colaSku->id, 'menu_product_sku_id' => $colaMenuSku->id, 'quantity' => 1], // ¥1,000 @ 10%
        ['product_sku_id' => $this->bentoSku->id, 'menu_product_sku_id' => $this->takeawayMenuSku->id, 'quantity' => 1], // ¥1,000 @ 8%
    ]]);
    $order->refresh();

    expect((float) $order->tax_amount)->toBe(180.0)
        ->and((float) $order->total_amount)->toBe(2180.0)
        // Per-line stamps survive independently — no averaging, no bleed.
        ->and($order->items->pluck('tax_rate')->map(fn ($r) => (float) $r)->sort()->values()->all())
        ->toBe([8.0, 10.0]);
});

// =========================================================================
//  The flip is dead
// =========================================================================

it('flipping dine_in → takeaway changes NOTHING about the money — no silent re-price of a bill the customer saw', function () {
    $order = singleRateOrder('dine_in');
    addViaMenuLine($order, $this->dineInMenuSku);
    $order->refresh();
    expect((float) $order->tax_amount)->toBe(100.0);

    app(CustomerOrderService::class)->update($order, ['order_type' => 'takeaway']);

    $order->refresh();
    expect($order->order_type->value)->toBe('takeaway')
        ->and((float) $order->items->first()->tax_rate)->toBe(10.0)
        ->and((float) $order->tax_amount)->toBe(100.0)
        ->and((float) $order->total_amount)->toBe(1100.0);
});

it('flipping takeaway → dine_in keeps the 8% the takeaway menu line promised', function () {
    $order = singleRateOrder('takeaway');
    addViaMenuLine($order, $this->takeawayMenuSku);
    $order->refresh();
    expect((float) $order->tax_amount)->toBe(80.0);

    app(CustomerOrderService::class)->update($order, ['order_type' => 'dine_in']);

    $order->refresh();
    expect((float) $order->items->first()->tax_rate)->toBe(8.0)
        ->and((float) $order->tax_amount)->toBe(80.0);
});

// =========================================================================
//  Resolver purity + editing the master
// =========================================================================

it('TaxResolver has no consumption-context parameter at all', function () {
    $method = new ReflectionMethod(TaxResolver::class, 'resolveForLine');
    $params = array_map(fn ($p) => $p->getName(), $method->getParameters());

    // #1218 added menuId + menuSectionId. They are MENU LINE IDENTITY, not
    // consumption context: they say which menu the guest ordered from, and the
    // rate rides that menu — which is exactly #1099's model. What must never
    // come back is a parameter describing HOW the food is consumed (order type,
    // dine-in/takeaway, eat-in flag), because that would let flipping an order's
    // type re-price it.
    expect($params)->toBe(['product', 'menuTaxType', 'branchId', 'brandId', 'menuId', 'menuSectionId']);

    foreach ($params as $name) {
        expect($name)->not->toMatch('/order_?type|dine|takeaway|takeout|eat_?in|consumption/i');
    }
});

it('editing the master rate NEVER rewrites a stamped line (immutable history survives the redesign)', function () {
    $order = singleRateOrder('takeaway');
    addViaMenuLine($order, $this->takeawayMenuSku);
    $order->refresh();
    expect((float) $order->tax_amount)->toBe(80.0);

    // The government raises the reduced rate to 9% next year…
    $this->reduced->update(['rate' => 9]);

    // …and yesterday's bill still says exactly ¥80.
    expect((float) $order->fresh()->items->first()->tax_rate)->toBe(8.0)
        ->and((float) $order->fresh()->tax_amount)->toBe(80.0);
});

// =========================================================================
//  The menu hint must agree with the invoice (audit follow-up)
// =========================================================================

/**
 * A branch carrying exactly ONE menu, so getMenuForBranch has nothing to choose
 * between (the shared fixture deliberately has two, for the context tests).
 *
 * @return array{0: Branch, 1: ProductSku, 2: MenuProductSku}
 */
function branchWithOneMenu(object $ctx, ?string $productTaxTypeId): array
{
    $branch = Branch::factory()->create([
        'console_organization_id' => $ctx->orgId,
        'console_brand_id' => $ctx->brand->console_brand_id,
        'is_active' => true,
    ]);
    ShopOrderSetting::factory()->create([
        'branch_id' => $branch->id,
        'default_tax_type_id' => $ctx->standard->id,
        'prices_include_tax' => false,
        'currency_code' => 'JPY',
    ]);

    $product = Product::factory()->active()->create([
        'organization_id' => $ctx->orgId, 'brand_id' => $ctx->brand->id,
        'product_type_id' => $ctx->bento->product_type_id,
        'tax_type_id' => $productTaxTypeId,
    ]);
    $sku = ProductSku::factory()->create([
        'product_id' => $product->id, 'selling_price' => 1000, 'is_active' => true,
    ]);

    $menu = Menu::factory()->create([
        'organization_id' => $ctx->orgId, 'brand_id' => $ctx->brand->id,
        'branch_id' => $branch->id, 'status' => 'Active',
    ]);
    $line = MenuProduct::factory()->create([
        'menu_id' => $menu->id, 'product_id' => $product->id,
        'is_active' => true, 'tax_type_id' => null,
    ]);
    $menuSku = MenuProductSku::factory()->create([
        'menu_product_id' => $line->id, 'product_sku_id' => $sku->id,
        'selling_price' => 1000, 'is_price_overridden' => true, 'is_active' => true,
    ]);

    return [$branch, $sku, $menuSku];
}

/** The tax_rate the menu endpoint hints for a SKU, or false when absent. */
function menuHintedRate(string $branchId, string $skuId): float|false
{
    $menu = app(CustomerMenuService::class)->getMenuForBranch($branchId);
    $item = collect($menu['categories'] ?? [])
        ->flatMap(fn (array $c): array => $c['items'] ?? [])
        ->firstWhere('sku_id', $skuId);

    return $item === null ? false : (float) $item['tax_rate'];
}

it('hints the rate an item inheriting from its PRODUCT will actually be billed at (tier 2)', function () {
    // Product carries STANDARD; the menu line overrides nothing.
    [$branch, $sku, $menuSku] = branchWithOneMenu($this, (string) $this->standard->id);

    $hinted = menuHintedRate((string) $branch->id, (string) $sku->id);

    // Bill the same item through the same menu line and compare.
    $order = app(CustomerOrderService::class)->create([
        'order_type' => 'dine_in', 'status' => 'open',
        'branch_id' => $branch->id, 'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
    ]);
    app(CustomerOrderService::class)->addItems($order, ['items' => [[
        'product_sku_id' => $sku->id, 'menu_product_sku_id' => $menuSku->id, 'quantity' => 1,
    ]]]);
    $billed = (float) $order->refresh()->items->first()->tax_rate;

    expect($hinted)->toBe($billed)->and($billed)->toBe(10.0);
});

it('hints through the BRANCH default when neither the menu line nor the product carries a type (tier 3)', function () {
    // Nothing on the line, nothing on the product — only the branch default is
    // left. The old two-tier hint returned null here and the client had to guess.
    [$branch, $sku, $menuSku] = branchWithOneMenu($this, null);

    $hinted = menuHintedRate((string) $branch->id, (string) $sku->id);

    $order = app(CustomerOrderService::class)->create([
        'order_type' => 'dine_in', 'status' => 'open',
        'branch_id' => $branch->id, 'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
    ]);
    app(CustomerOrderService::class)->addItems($order, ['items' => [[
        'product_sku_id' => $sku->id, 'menu_product_sku_id' => $menuSku->id, 'quantity' => 1,
    ]]]);
    $billed = (float) $order->refresh()->items->first()->tax_rate;

    expect($hinted)->toBe($billed)->and($billed)->toBe(10.0);
});
