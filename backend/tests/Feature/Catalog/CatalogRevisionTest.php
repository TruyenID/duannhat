<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CatalogRevision;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductToppingGroupItemOverride;
use App\Models\ProductType;
use App\Models\TaxType;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Services\Catalog\CatalogRevisionService;
use App\Services\Topping\ProductToppingGroupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * #1095 — per-branch catalog revisions for offline-order evidence.
 *
 * The property under test is narrow but load-bearing: a revision must exist
 * for every catalog state an offline device could have sold from, must NOT be
 * minted for edits that cannot change a charge, and must carry the price map
 * the verifier (#1096) will re-price historical orders against.
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

    $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $this->product = Product::factory()->active()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'product_type_id' => $pt->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $this->product->id, 'selling_price' => 1000, 'is_active' => true,
    ]);

    $this->revisions = app(CatalogRevisionService::class);
});

/** Build a branch menu carrying the product; returns the menu sku row. */
function menuLineFor(int $price, bool $override = true): MenuProductSku
{
    $menu = Menu::factory()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'branch_id' => test()->branch->id,
        'status' => 'Active',
    ]);
    $line = MenuProduct::factory()->create([
        'menu_id' => $menu->id, 'product_id' => test()->product->id, 'is_active' => true,
    ]);

    return MenuProductSku::factory()->create([
        'menu_product_id' => $line->id,
        'product_sku_id' => test()->sku->id,
        'selling_price' => $price,
        'is_price_overridden' => $override,
        'is_active' => true,
    ]);
}

// =========================================================================
//  A price change mints a revision
// =========================================================================

it('mints revision 1 for the branch as soon as it has a priced menu line', function () {
    menuLineFor(1000);

    $current = $this->revisions->currentFor($this->branch->id);
    expect($current)->not->toBeNull()
        ->and($current->revision)->toBe(1)
        ->and($current->branch_id)->toBe($this->branch->id)
        ->and($current->organization_id)->toBe($this->orgId);
});

it('bumps to the next revision when a menu price changes, and the snapshot carries the NEW price', function () {
    $menuSku = menuLineFor(1000);
    $first = $this->revisions->currentFor($this->branch->id);

    $menuSku->update(['selling_price' => 1200]);

    $second = $this->revisions->currentFor($this->branch->id);
    expect($second->revision)->toBe($first->revision + 1)
        ->and($second->snapshot['lines'][$menuSku->id]['price'])->toBe('1200.00')
        // The OLD revision is immutable — that is what makes historical
        // re-pricing possible (BR-CR03).
        ->and($first->fresh()->snapshot['lines'][$menuSku->id]['price'])->toBe('1000.00');
});

it('bumps when a SKU price changes and the menu line INHERITS it (no override)', function () {
    $menuSku = menuLineFor(1000, override: false);
    $before = $this->revisions->currentFor($this->branch->id);
    expect($before->snapshot['lines'][$menuSku->id]['price'])->toBe('1000.00');

    // Product-level price edit — the menu row inherits, so the offline charge
    // changes even though no menu row was touched.
    $this->sku->update(['selling_price' => 1500]);

    $after = $this->revisions->currentFor($this->branch->id);
    expect($after->revision)->toBe($before->revision + 1)
        ->and($after->snapshot['lines'][$menuSku->id]['price'])->toBe('1500.00');
});

it('bumps when a menu line changes its tax type — a tax move is a money move', function () {
    $menuSku = menuLineFor(1000);

    // #1661 — loại thuế phải tạo TRƯỚC khi chụp `$before`. Từ #1661 snapshot mang
    // cả bảng thuế suất của thương hiệu (feed workstation chở danh sách
    // `tax_types`), nên chỉ riêng việc TẠO một loại thuế đã làm tiến một bản.
    // Tạo sau khi chụp thì phép đo này đo HAI thay đổi rồi khẳng định là một.
    $reduced = TaxType::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'code' => 'REDUCED', 'rate' => 8,
    ]);

    $before = $this->revisions->currentFor($this->branch->id);
    expect($before->snapshot['lines'][$menuSku->id]['tax'])->toBeNull();

    MenuProduct::findOrFail($menuSku->menu_product_id)->update(['tax_type_id' => $reduced->id]);

    $after = $this->revisions->currentFor($this->branch->id);
    expect($after->revision)->toBe($before->revision + 1)
        ->and($after->snapshot['lines'][$menuSku->id]['tax'])->toBe($reduced->id);
});

it('bumps when a line is deactivated — a pulled item must stop being sellable offline', function () {
    $menuSku = menuLineFor(1000);
    $before = $this->revisions->currentFor($this->branch->id);
    expect($before->snapshot['lines'])->toHaveKey($menuSku->id);

    $menuSku->update(['is_active' => false]);

    $after = $this->revisions->currentFor($this->branch->id);
    expect($after->revision)->toBe($before->revision + 1)
        ->and($after->snapshot['lines'])->not->toHaveKey($menuSku->id);
});

// =========================================================================
//  Cosmetic edits must NOT mint a revision (BR-CR02)
// =========================================================================

it('does NOT bump for a cosmetic edit — renaming a menu cannot change a charge', function () {
    $menuSku = menuLineFor(1000);
    $before = $this->revisions->currentFor($this->branch->id);

    Menu::findOrFail(MenuProduct::findOrFail($menuSku->menu_product_id)->menu_id)
        ->update(['name' => 'Lunch (renamed)']);

    $after = $this->revisions->currentFor($this->branch->id);
    expect($after->id)->toBe($before->id)
        ->and($after->revision)->toBe($before->revision)
        ->and(CatalogRevision::where('branch_id', $this->branch->id)->count())->toBe($before->revision);
});

it('does NOT bump when a price is re-saved with the same value (idempotent writes)', function () {
    $menuSku = menuLineFor(1000);
    $before = $this->revisions->currentFor($this->branch->id);

    $menuSku->update(['selling_price' => 1000]);
    $menuSku->update(['selling_price' => 1000.00]);

    expect($this->revisions->currentFor($this->branch->id)->revision)->toBe($before->revision);
});

// =========================================================================
//  One revision per logical mutation, and only after COMMIT
// =========================================================================

it('collapses a multi-row menu build into ONE revision — an offline device never pulls a half-written catalog', function () {
    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id, 'status' => 'Active',
    ]);

    DB::transaction(function () use ($menu) {
        $line = MenuProduct::factory()->create([
            'menu_id' => $menu->id, 'product_id' => $this->product->id, 'is_active' => true,
        ]);
        foreach ([1000, 1100, 1200] as $price) {
            // Distinct products: three option-less SKUs of ONE product would
            // collide on the (product_id, option_signature) unique index.
            $extraProduct = Product::factory()->active()->create([
                'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
                'product_type_id' => $this->product->product_type_id,
            ]);
            $sku = ProductSku::factory()->create([
                'product_id' => $extraProduct->id, 'selling_price' => $price, 'is_active' => true,
            ]);
            MenuProductSku::factory()->create([
                'menu_product_id' => $line->id, 'product_sku_id' => $sku->id,
                'selling_price' => $price, 'is_price_overridden' => true, 'is_active' => true,
            ]);
        }
    });

    // 1 menu + 1 line + 3 skus + 3 menu skus written; exactly one revision.
    expect(CatalogRevision::where('branch_id', $this->branch->id)->count())->toBe(1)
        ->and($this->revisions->currentFor($this->branch->id)->snapshot['lines'])->toHaveCount(3);
});

it('a ROLLED BACK menu edit mints no revision — Cloud never records a catalog that never existed', function () {
    menuLineFor(1000);
    $before = $this->revisions->currentFor($this->branch->id);

    try {
        DB::transaction(function () {
            menuLineFor(9999);
            throw new RuntimeException('operator cancelled the menu edit');
        });
    } catch (RuntimeException) {
        // expected
    }

    $after = $this->revisions->currentFor($this->branch->id);
    expect($after->revision)->toBe($before->revision)
        ->and($after->snapshot['lines'])->toHaveCount(1);
});

// =========================================================================
//  Isolation between branches
// =========================================================================

it('keeps revision sequences independent per branch — one shop editing its menu does not invalidate another', function () {
    menuLineFor(1000);
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $otherMenu = Menu::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'branch_id' => $otherBranch->id, 'status' => 'Active',
    ]);
    $otherLine = MenuProduct::factory()->create([
        'menu_id' => $otherMenu->id, 'product_id' => $this->product->id, 'is_active' => true,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $otherLine->id, 'product_sku_id' => $this->sku->id,
        'selling_price' => 777, 'is_price_overridden' => true, 'is_active' => true,
    ]);

    expect($this->revisions->currentFor($this->branch->id)->revision)->toBe(1)
        ->and($this->revisions->currentFor($otherBranch->id)->revision)->toBe(1)
        // …and each snapshot holds only its own branch's prices.
        ->and($this->revisions->currentFor($this->branch->id)->snapshot['lines'])->toHaveCount(1)
        ->and(collect($this->revisions->currentFor($otherBranch->id)->snapshot['lines'])->pluck('price')->all())
        ->toBe(['777.00']);
});

// =========================================================================
//  Historical lookup — what the verifier will use
// =========================================================================

it('finds an old revision by number and hands back the price map AS SOLD', function () {
    $menuSku = menuLineFor(1000);
    $soldAt = $this->revisions->currentFor($this->branch->id)->revision;

    // Menu re-priced twice after the sale.
    $menuSku->update(['selling_price' => 1200]);
    $menuSku->update(['selling_price' => 1400]);

    $historical = $this->revisions->find($this->branch->id, $soldAt);
    expect($historical)->not->toBeNull()
        ->and($historical->snapshot['lines'][$menuSku->id]['price'])->toBe('1000.00')
        ->and($this->revisions->currentFor($this->branch->id)->snapshot['lines'][$menuSku->id]['price'])->toBe('1400.00');
});

it('returns null for a revision that never existed — the verifier must fail closed on a bogus claim', function () {
    menuLineFor(1000);

    expect($this->revisions->find($this->branch->id, 999))->toBeNull()
        ->and($this->revisions->find((string) Str::uuid(), 1))->toBeNull();
});

it('hashes the snapshot deterministically and independently of row insertion order', function () {
    $menuSku = menuLineFor(1000);
    $snapshot = $this->revisions->buildSnapshot($this->branch->id);

    expect($this->revisions->hashSnapshot($snapshot))
        ->toBe($this->revisions->hashSnapshot($this->revisions->buildSnapshot($this->branch->id)))
        ->and($this->revisions->currentFor($this->branch->id)->snapshot_hash)
        ->toBe($this->revisions->hashSnapshot($snapshot))
        // Prices are hashed as decimal STRINGS so float formatting can never
        // shift the hash between writers.
        ->and($snapshot['lines'][$menuSku->id]['price'])->toBeString();
});

// =========================================================================
//  #1114 — topping prices ride the snapshot (v2)
// =========================================================================

/**
 * Attach an active topping group (1 item backed by the seeded SKU's product,
 * base ¥100 wildcard price) to the test product. The v2 snapshot resolves
 * prices per parentProduct|item|toppingSku at build time.
 */
function toppingConfigFor(array $groupAttrs = [], array $pivotAttrs = []): array
{
    $group = ToppingGroup::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'is_active' => true,
        'price_strategy' => 'flat',
        'min_select' => 0,
        'max_select' => null,
    ], $groupAttrs));
    DB::table('product_topping_groups')->insert(array_merge([
        'product_id' => test()->product->id,
        'topping_group_id' => $group->id,
        'sort_order' => 0,
    ], $pivotAttrs));
    $item = ToppingGroupItem::factory()->create([
        'topping_group_id' => $group->id,
        'product_id' => test()->product->id,
        'is_default' => true,
        'sort_order' => 3,
    ]);
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $item->id,
        'product_sku_id' => null,
        'extra_price' => 100,
    ]);

    return [$group, $item];
}

/** The v2 price key for the seeded (parent product, item, topping sku) triple. */
function toppingPriceKey(ToppingGroupItem $item): string
{
    return test()->product->id.'|'.$item->id.'|'.test()->sku->id;
}

it('#1114: the snapshot carries topping config — group strategy and the resolved price', function () {
    [$group, $item] = toppingConfigFor(['price_strategy' => 'free_up_to_n', 'free_quantity' => 2]);
    menuLineFor(1000);

    $snapshot = $this->revisions->buildSnapshot($this->branch->id);

    expect($snapshot['topping_groups'][$group->id] ?? null)->toBe(['strategy' => 'free_up_to_n', 'free' => 2])
        ->and($snapshot['topping_items'][$item->id] ?? null)->toBe(['group' => (string) $group->id])
        ->and($snapshot['topping_prices'][toppingPriceKey($item)] ?? null)->toBe('100.00');
});

it('#1114: a per-product override resolves into the snapshot price; a hidden one does not', function () {
    [$group, $item] = toppingConfigFor();
    ProductToppingGroupItemOverride::factory()->create([
        'product_id' => $this->product->id,
        'topping_group_id' => $group->id,
        'topping_group_item_id' => $item->id,
        'product_sku_id' => null,
        'override_price' => 150,
        'is_hidden' => false,
    ]);
    menuLineFor(1000);

    expect($this->revisions->buildSnapshot($this->branch->id)['topping_prices'][toppingPriceKey($item)])
        ->toBe('150.00');

    // Hidden override → falls through to the HQ base price.
    ProductToppingGroupItemOverride::query()->update(['is_hidden' => true, 'override_price' => null]);
    expect($this->revisions->buildSnapshot($this->branch->id)['topping_prices'][toppingPriceKey($item)])
        ->toBe('100.00');
});

it('#1114: an inactive group is absent from the snapshot — an offline claim on it is refused, like live', function () {
    toppingConfigFor(['is_active' => false]);
    menuLineFor(1000);

    $snapshot = $this->revisions->buildSnapshot($this->branch->id);
    expect($snapshot['topping_groups'])->toBe([])
        ->and($snapshot['topping_prices'])->toBe([]);
});

it('#1114: the topping-bearing snapshot hashes deterministically across rebuilds', function () {
    toppingConfigFor();
    menuLineFor(1000);

    $first = $this->revisions->buildSnapshot($this->branch->id);
    expect($this->revisions->hashSnapshot($first))
        ->toBe($this->revisions->hashSnapshot($this->revisions->buildSnapshot($this->branch->id)));
});

// =========================================================================
//  #1114 — topping edits mint revisions (money moves)
// =========================================================================

it('#1114: a topping price edit bumps the revision and the new snapshot carries the new price', function () {
    [, $item] = toppingConfigFor();
    menuLineFor(1000);
    $before = $this->revisions->currentFor($this->branch->id);

    ToppingGroupItemSku::where('topping_group_item_id', $item->id)->first()
        ->update(['extra_price' => 250]);

    $after = $this->revisions->currentFor($this->branch->id);
    expect($after->revision)->toBe($before->revision + 1)
        ->and($after->snapshot['topping_prices'][toppingPriceKey($item)])->toBe('250.00')
        // The old revision keeps the old price — that is the whole point.
        ->and($before->fresh()->snapshot['topping_prices'][toppingPriceKey($item)])->toBe('100.00');
});

it('#1114: an override edit bumps the revision', function () {
    [$group, $item] = toppingConfigFor();
    menuLineFor(1000);
    $before = $this->revisions->currentFor($this->branch->id);

    ProductToppingGroupItemOverride::factory()->create([
        'product_id' => $this->product->id,
        'topping_group_id' => $group->id,
        'topping_group_item_id' => $item->id,
        'product_sku_id' => null,
        'override_price' => 300,
        'is_hidden' => false,
    ]);

    $after = $this->revisions->currentFor($this->branch->id);
    expect($after->revision)->toBe($before->revision + 1)
        ->and($after->snapshot['topping_prices'][toppingPriceKey($item)])->toBe('300.00');
});

it('#1114: a group strategy edit bumps; a cosmetic group edit does not', function () {
    [$group] = toppingConfigFor();
    menuLineFor(1000);
    $before = $this->revisions->currentFor($this->branch->id);

    // Cosmetic: group sort_order is not part of the snapshot.
    $group->update(['sort_order' => 9]);
    expect($this->revisions->currentFor($this->branch->id)->revision)->toBe($before->revision);

    // Money: free_up_to_n with 1 free unit changes what a basket costs.
    $group->update(['price_strategy' => 'free_up_to_n', 'free_quantity' => 1]);
    $after = $this->revisions->currentFor($this->branch->id);
    expect($after->revision)->toBe($before->revision + 1)
        ->and($after->snapshot['topping_groups'][$group->id]['strategy'])->toBe('free_up_to_n');
});

it('#1114: detaching every group via the sync flow (bulk delete) still mints a revision', function () {
    toppingConfigFor();
    menuLineFor(1000);
    $before = $this->revisions->currentFor($this->branch->id);
    expect($before->snapshot['topping_groups'])->not->toBe([]);

    app(ProductToppingGroupService::class)->syncForProduct($this->product->fresh(), []);

    $after = $this->revisions->currentFor($this->branch->id);
    expect($after->revision)->toBe($before->revision + 1)
        ->and($after->snapshot['topping_groups'])->toBe([]);
});
