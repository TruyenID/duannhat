<?php

/**
 * ToppingShowcaseSeeder smoke + integration test
 * ============================================================================
 *
 * Runs `database/seeders/ToppingShowcaseSeeder.php` end-to-end and asserts
 * every P-tier feature it demonstrates landed in the DB correctly. If the
 * seeder ever drifts from the schema (or vice versa), this test fails fast.
 *
 * Doubles as documentation: a future engineer can read these `it(...)` blocks
 * and see what the seeder produces without running it.
 *
 * Doesn't test API behaviour — that's covered by ToppingGroupTest /
 * ToppingGroupP2FieldsTest / ToppingGroupP3FieldsTest / ProductToppingGroup*.
 * This file ONLY verifies the seeder + persistence layer.
 *
 * Why query by predicate (modifier_type, price_strategy, ...) instead of
 * group name: `name` is translatable (Astrotomic stores it in
 * topping_group_translations). Querying with `where('name', '...')` would hit
 * the base JSON-cast column and fail in MySQL/SQLite. Using the P-tier fields
 * directly is more precise anyway — each scenario IS its feature, not its name.
 *
 * ============================================================================
 */

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductToppingGroup;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use Database\Seeders\ToppingShowcaseSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    // Seeder requires Brand + Organization to exist. Pest's RefreshDatabase
    // wipes everything between tests so we set up minimal context here.
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->seed(ToppingShowcaseSeeder::class);
});

// =============================================================================
// 1. PHO — REMOVE modifier
// =============================================================================

it('seeds a REMOVE modifier group with extra_price=0 on every item', function () {
    $removeGroups = ToppingGroup::where('modifier_type', 'remove')->get();
    expect($removeGroups)->toHaveCount(1);

    $group = $removeGroups->first();
    expect($group->selection_type->value)->toBe('multiple')
        ->and($group->min_select)->toBe(0)
        ->and($group->max_select)->toBe(3);

    // REMOVE invariant: every item priced at 0 (you don't charge for absence).
    $extraPrices = $group->items()->with('skus')->get()
        ->flatMap(fn ($item) => $item->skus)
        ->pluck('extra_price')
        ->map(fn ($p) => (int) $p);
    expect($extraPrices)->each->toEqual(0);
});

// =============================================================================
// 2. BURGER + COMBO — per-product min/max overrides
// =============================================================================

it('seeds a Sauce-style group attached to two products — one inherits, one overrides to 1/1', function () {
    // Locate the group with exactly 2 attachments (sauce shared between burger
    // and combo). Other groups in this seeder attach to a single product.
    // Counted via direct query on the pivot table — ToppingGroup currently
    // exposes a ManyToMany `products` relation, not a direct hasMany to the
    // pivot.
    $groupCounts = ProductToppingGroup::query()
        ->selectRaw('topping_group_id, COUNT(*) as cnt')
        ->groupBy('topping_group_id')
        ->get()
        ->keyBy('topping_group_id');

    $sauceGroupId = $groupCounts->filter(fn ($r) => (int) $r->cnt === 2)->keys()->first();
    expect($sauceGroupId)->not->toBeNull('expected exactly one group with 2 attachments');

    $sauceGroup = ToppingGroup::findOrFail($sauceGroupId);
    expect($sauceGroup->min_select)->toBe(0)
        ->and($sauceGroup->max_select)->toBe(3);

    $attachments = ProductToppingGroup::where('topping_group_id', $sauceGroup->id)->get();

    // One attachment NULL/NULL (inherits) — the other 1/1 (overrides).
    $inheriting = $attachments->first(
        fn ($a) => $a->min_select_override === null && $a->max_select_override === null
    );
    $overriding = $attachments->first(
        fn ($a) => $a->min_select_override === 1 && $a->max_select_override === 1
    );

    expect($inheriting)->not->toBeNull('expected an inheriting attachment')
        ->and($overriding)->not->toBeNull('expected a 1/1 overriding attachment');
});

it('demonstrates COALESCE for effective min/max', function () {
    $sauceGroupId = ProductToppingGroup::query()
        ->selectRaw('topping_group_id, COUNT(*) as cnt')
        ->groupBy('topping_group_id')
        ->get()
        ->firstWhere('cnt', 2)
        ->topping_group_id;
    $sauceGroup = ToppingGroup::findOrFail($sauceGroupId);

    $effectives = ProductToppingGroup::where('topping_group_id', $sauceGroup->id)
        ->get()
        ->map(fn ($a) => [
            'min' => $a->min_select_override ?? $sauceGroup->min_select,
            'max' => $a->max_select_override ?? $sauceGroup->max_select,
        ]);

    expect($effectives)->toContain(['min' => 0, 'max' => 3])    // inherited
        ->and($effectives)->toContain(['min' => 1, 'max' => 1]); // overridden
});

// =============================================================================
// 3. PIZZA — free_up_to_n
// =============================================================================

it('seeds a free_up_to_n group with free_quantity=3 + unlimited max + 5 items', function () {
    $combo = ToppingGroup::where('price_strategy', 'free_up_to_n')->firstOrFail();

    expect($combo->free_quantity)->toBe(3)
        ->and($combo->max_select)->toBeNull()    // unlimited
        ->and($combo->items()->count())->toBe(5);
});

// =============================================================================
// 5. SIZE — single-select radio
// =============================================================================

it('seeds at least one selection_type=single group with min/max=1 and 3 items (spice level)', function () {
    $singles = ToppingGroup::where('selection_type', 'single')->get();
    expect($singles->count())->toBeGreaterThanOrEqual(1);

    // The spice group has 3 items (cay nhẹ/vừa/nhiều); the ice group has 2.
    $spice = $singles->first(fn ($g) => $g->items()->count() === 3);
    expect($spice)->not->toBeNull('expected a single-select group with 3 items');
    expect($spice->min_select)->toBe(1)
        ->and($spice->max_select)->toBe(1);
});

// =============================================================================
// 6. ICE — is_default
// =============================================================================

it('seeds an is_default item — exactly one default-true per ice group', function () {
    $defaultItems = ToppingGroupItem::where('is_default', true)->get();
    expect($defaultItems->count())->toBeGreaterThanOrEqual(1);

    $iceGroupId = $defaultItems->first()->topping_group_id;
    $iceGroup = ToppingGroup::findOrFail($iceGroupId);

    // The ice group is single-select min=1 max=1 with exactly 2 items
    // (one default true, one default false).
    expect($iceGroup->selection_type->value)->toBe('single');
    expect($iceGroup->items()->count())->toBe(2);

    $iceItems = $iceGroup->items()->orderBy('sort_order')->get();
    expect((bool) $iceItems->first()->is_default)->toBeTrue()
        ->and((bool) $iceItems->last()->is_default)->toBeFalse();
});

// =============================================================================
// Cross-cutting invariants
// =============================================================================

it('respects the topping product-type invariant — every item points at a Product whose type.code = topping (P1.2)', function () {
    $allItems = ToppingGroupItem::with('product.productType')->get();
    expect($allItems->count())->toBeGreaterThan(0);

    foreach ($allItems as $item) {
        expect($item->product->productType?->code)->toBe('topping');
    }
});

it('every topping product has at least one default SKU (P1.1 ready)', function () {
    // OrderItemTopping.product_sku_id is NOT NULL — Phase 2 service must
    // resolve to a real SKU. Seeder pre-creates default SKUs for each
    // topping product so Phase 2 doesn't crash on its first insert.
    $toppingProducts = Product::whereHas('productType', fn ($q) => $q->where('code', 'topping'))
        ->with('skus')->get();

    expect($toppingProducts->count())->toBeGreaterThan(0);
    foreach ($toppingProducts as $product) {
        expect($product->skus->count())->toBeGreaterThanOrEqual(1);
    }
});

it('creates exactly 6 topping groups (one per scenario)', function () {
    expect(ToppingGroup::count())->toBe(6);
});

it('creates exactly 7 product↔group attachments (sauce reused on burger+combo)', function () {
    // pho×exclusions, burger×sauce, combo×sauce, pizza×toppings, latte×morning,
    // bunBoHue×spice, tea×ice  ⇒ 7 attachments total.
    expect(ProductToppingGroup::count())->toBe(7);
});
