<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Services\Workstation\MenuCatalogReplicaBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * plan-056 — the menu-catalog feed must carry TURNED-OFF rows, and must do it
 * without moving a single price.
 *
 * Before this change both `menu_products` and `menu_product_skus` were filtered
 * to `is_active = true` in the builder, so the flag it emitted was always `1`.
 * That made the POS structurally unable to show — let alone switch back on — a
 * dish the shop had turned off.
 *
 * The interesting half of this file is not "does the off row ship" (it does),
 * it is the price tests below. Lifting the filter puts turned-off rows in front
 * of `$overrideBySku`, a map that keys by product_sku_id and keeps the FIRST row
 * it meets with no ORDER BY — so a dead row can beat a live one and put a stale
 * number on a real receipt, non-deterministically. `$mpSkus` / `$mpSkusAll`
 * exist to stop exactly that, and these tests are why they cannot be merged.
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
    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->builder = app(MenuCatalogReplicaBuilder::class);

    $this->makeMenu = fn (string $status = 'Active'): Menu => Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => $status,
    ]);

    $this->makeProduct = fn (string $name = 'Phở bò'): Product => Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->productType->id,
        'status' => 'active',
        'is_hidden' => false,
        'name' => $name,
    ]);

    /** Attach a product to a menu; returns the menu_product id. */
    $this->attach = function (Menu $menu, Product $product, bool $isActive = true, array $extra = []): string {
        $id = (string) Str::uuid();
        DB::table('menu_products')->insert(array_merge([
            'id' => $id,
            'menu_id' => $menu->id,
            'product_id' => $product->id,
            'is_active' => $isActive,
            'display_order' => 1,
        ], $extra));

        return $id;
    };

    /** Attach a catalog SKU to a menu_product; returns the menu_product_sku id. */
    $this->attachSku = function (string $mpId, ProductSku $sku, bool $isActive = true, ?float $price = null, bool $overridden = false, array $extra = []): string {
        // upsert, not insert: `(menu_product_id, product_sku_id)` is UNIQUE and
        // the pivot row can already exist by the time this runs. The helper's
        // contract is "this SKU is on this menu_product in THIS state", so it
        // states the state either way rather than failing on a row that is
        // exactly what the caller asked for.
        $row = array_merge([
            'selling_price' => $price ?? $sku->selling_price,
            'is_price_overridden' => $overridden,
            'is_active' => $isActive,
        ], $extra);

        $existing = DB::table('menu_product_skus')
            ->where('menu_product_id', $mpId)
            ->where('product_sku_id', $sku->id)
            ->value('id');

        if ($existing !== null) {
            DB::table('menu_product_skus')->where('id', $existing)->update($row);

            return (string) $existing;
        }

        $id = (string) Str::uuid();
        DB::table('menu_product_skus')->insert(array_merge([
            'id' => $id,
            'menu_product_id' => $mpId,
            'product_sku_id' => $sku->id,
        ], $row));

        return $id;
    };
});

// =========================================================================
//  The feed carries turned-off rows
// =========================================================================

it('ships a turned-off dish with is_active false and its reason', function () {
    $menu = ($this->makeMenu)();
    $product = ($this->makeProduct)();
    $mpId = ($this->attach)($menu, $product, false, [
        'disabled_reason' => 'Hết nguyên liệu',
        'disabled_at' => '2026-08-12 03:00:00',
        'disabled_by_name' => 'Ann',
    ]);
    ($this->attachSku)($mpId, ProductSku::factory()->create([
        'product_id' => $product->id, 'is_active' => true, 'selling_price' => 1000,
    ]));

    $out = $this->builder->buildForBranch($this->branch->id);

    expect($out['menu_products'])->toHaveCount(1);
    $row = $out['menu_products'][0];
    expect($row['is_active'])->toBeFalse()
        ->and($row['disabled_reason'])->toBe('Hết nguyên liệu')
        ->and($row['disabled_by_name'])->toBe('Ann')
        // ISO-8601, not the driver's raw "2026-08-12 03:00:00": a bare string
        // makes the Go side guess a timezone and read the shop's "when did we
        // turn this off" 7 or 9 hours wrong.
        ->and($row['disabled_at'])->toContain('2026-08-12T03:00:00');
});

it('ships a turned-off VARIANT in menu_product_skus, keyed by the pivot uuid', function () {
    $menu = ($this->makeMenu)();
    $product = ($this->makeProduct)();
    $mpId = ($this->attach)($menu, $product);
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'is_active' => true, 'selling_price' => 1000]);
    $mpsId = ($this->attachSku)($mpId, $sku, isActive: false, extra: ['disabled_reason' => 'Hết size L']);

    $out = $this->builder->buildForBranch($this->branch->id);

    expect($out['menu_product_skus'])->toHaveCount(1);
    $row = $out['menu_product_skus'][0];
    // The PIVOT uuid, not the catalog sku uuid. This is the address a write
    // goes back to; using product_sku_id would flip the variant in every menu
    // that carries it at once.
    expect($row['id'])->toBe($mpsId)
        ->and($row['product_sku_id'])->toBe($sku->id)
        ->and($row['menu_product_id'])->toBe($mpId)
        ->and($row['is_active'])->toBeFalse()
        ->and($row['disabled_reason'])->toBe('Hết size L');
});

it('still ships the catalog sku row for a turned-off variant', function () {
    // Without this the management screen renders a variant row with no name,
    // no code and no option labels — a blank line nobody can identify, let
    // alone switch back on. `$skuIds` has to come from `$mpSkusAll`.
    $menu = ($this->makeMenu)();
    $product = ($this->makeProduct)();
    $mpId = ($this->attach)($menu, $product);
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'is_active' => true, 'selling_price' => 1000, 'sku' => 'SKU-L']);
    ($this->attachSku)($mpId, $sku, isActive: false);

    $out = $this->builder->buildForBranch($this->branch->id);

    expect($out['skus'])->toHaveCount(1)
        ->and($out['skus'][0]['sku'])->toBe('SKU-L');
});

it('keeps menu_product_skus empty rather than absent when a branch has nothing', function () {
    // The Go puller indexes this key unconditionally; a missing key and an
    // empty list are the same thing to JSON but not to a decoder that expects
    // the field to exist.
    expect($this->builder->emptyShape())->toHaveKey('menu_product_skus')
        ->and($this->builder->emptyShape()['menu_product_skus'])->toBe([]);
});

// =========================================================================
//  MONEY — the price map must not notice any of the above
// =========================================================================

it('prices a variant from the LIVE menu row when a turned-off row exists for the same sku', function () {
    // THE regression this whole two-collection design exists for.
    //
    // One catalog SKU in two menus: on at 1500 in menu A, turned off at 900 in
    // menu B. `$overrideBySku` keeps the first row it meets and the query has
    // no ORDER BY, so if turned-off rows reach it the emitted price is decided
    // by whatever order MySQL felt like — 900 half the time, on a real receipt.
    $product = ($this->makeProduct)();
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'is_active' => true, 'selling_price' => 1000]);

    $menuOff = ($this->makeMenu)();
    $mpOff = ($this->attach)($menuOff, $product);
    ($this->attachSku)($mpOff, $sku, isActive: false, price: 900, overridden: true);

    $menuOn = ($this->makeMenu)();
    $mpOn = ($this->attach)($menuOn, $product);
    ($this->attachSku)($mpOn, $sku, isActive: true, price: 1500, overridden: true);

    $out = $this->builder->buildForBranch($this->branch->id);

    expect($out['skus'])->toHaveCount(1)
        ->and($out['skus'][0]['selling_price'])->toBe(1500)
        ->and($out['skus'][0]['is_price_overridden'])->toBeTrue();
});

it('falls back to the catalog price when every menu row for a sku is turned off', function () {
    // No live row means no override to inherit, so the canonical catalog price
    // is the only honest answer. The variant is not sellable in this state —
    // the workstation filters it out of the ordering read — but the management
    // screen still needs a number to show next to it.
    $product = ($this->makeProduct)();
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'is_active' => true, 'selling_price' => 1000]);

    $menu = ($this->makeMenu)();
    $mpId = ($this->attach)($menu, $product);
    ($this->attachSku)($mpId, $sku, isActive: false, price: 900, overridden: true);

    $out = $this->builder->buildForBranch($this->branch->id);

    expect($out['skus'][0]['selling_price'])->toBe(1000)
        ->and($out['skus'][0]['is_price_overridden'])->toBeFalse()
        // …and the shop price the operator set is still readable, from the
        // pivot array where it belongs.
        ->and($out['menu_product_skus'][0]['selling_price'])->toBe(900)
        ->and($out['menu_product_skus'][0]['is_price_overridden'])->toBeTrue();
});

it('leaves skus[] byte-identical when a dish is turned off', function () {
    // The ordering screen reads `skus[]`. Turning a DISH off changes what is
    // visible, never what anything costs — so this array must not move at all.
    $menu = ($this->makeMenu)();
    $product = ($this->makeProduct)();
    $mpId = ($this->attach)($menu, $product);
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'is_active' => true, 'selling_price' => 1000]);
    ($this->attachSku)($mpId, $sku, price: 1200, overridden: true);

    $before = $this->builder->buildForBranch($this->branch->id)['skus'];

    DB::table('menu_products')->where('id', $mpId)->update([
        'is_active' => false,
        'disabled_reason' => 'Hết hàng',
    ]);

    expect($this->builder->buildForBranch($this->branch->id)['skus'])->toBe($before);
});
