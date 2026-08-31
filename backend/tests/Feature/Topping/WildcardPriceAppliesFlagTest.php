<?php

declare(strict_types=1);

/**
 * #1316 — the admin topping table drew a dead wildcard price row as an ordinary
 * line under the "variants" column, labelled with the topping's own name, so an
 * operator read "two variants" off a topping that sells one. #1275 fixed only
 * the header badge; the table kept saying it.
 *
 * Two things are pinned here:
 *
 *   1. `ToppingGroupItem::wildcardPriceApplies()` — now the ONE predicate behind
 *      the create guard, the prune command and this read flag. It answers the
 *      same either way the relations happen to be loaded, because the read path
 *      needs the eager-loaded branch to avoid an N+1 while the write path forces
 *      the fresh query.
 *   2. The `applies` flag on the serialized row, including its safe default:
 *      when the parent could not compute it, a working row must never be
 *      labelled dead.
 *
 * Companions: DeadWildcardPriceRowTest (write guard),
 * PruneDeadWildcardPricesTest (cleanup).
 */

use App\Http\Resources\MenuToppingGroupItemResource;
use App\Http\Resources\MenuToppingGroupItemSkuResource;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->group = ToppingGroup::factory()->create(['modifier_type' => 'add']);
    $this->toppingProduct = Product::factory()->create();
    $this->item = ToppingGroupItem::factory()->create([
        'topping_group_id' => $this->group->id,
        'product_id' => $this->toppingProduct->id,
    ]);

    // Two active SKUs on one product need distinct option signatures —
    // `product_skus` is UNIQUE on (product_id, option_signature). That
    // constraint is why the reported topping could not have had a second
    // variant in the first place: with no option axis it gets exactly one SKU,
    // so the extra row could only ever be a price.
    $this->twoVariants = function (): array {
        $option = ProductOption::factory()->create([
            'product_id' => $this->toppingProduct->id,
            'is_active' => true,
            'position' => 0,
        ]);
        $first = ProductOptionValue::factory()->create(['option_id' => $option->id, 'position' => 0, 'is_active' => true]);
        $second = ProductOptionValue::factory()->create(['option_id' => $option->id, 'position' => 1, 'is_active' => true]);

        return [
            ProductSku::factory()->create([
                'product_id' => $this->toppingProduct->id,
                'option_value1_id' => $first->id,
                'is_active' => true,
            ]),
            ProductSku::factory()->create([
                'product_id' => $this->toppingProduct->id,
                'option_value1_id' => $second->id,
                'is_active' => true,
            ]),
        ];
    };
});

// ── the predicate ────────────────────────────────────────────────────────────

it('reports a wildcard as not applying once every active SKU is scoped', function () {
    // The reported shape: a topping with no option axis, so exactly one SKU,
    // and that SKU already has its own ¥120 row. The ¥150 wildcard beneath it
    // can never be reached — tier 3 sorts NULL last.
    $sku = ProductSku::factory()->create([
        'product_id' => $this->toppingProduct->id,
        'is_active' => true,
    ]);
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $sku->id,
        'extra_price' => 120,
    ]);

    expect($this->item->fresh()->wildcardPriceApplies())->toBeFalse();
});

it('reports a wildcard as applying while any active SKU is unscoped', function () {
    // The legitimate majority — 51 such rows in dev. The wildcard is the only
    // price the second SKU has.
    [$scoped] = ($this->twoVariants)();
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $scoped->id,
        'extra_price' => 120,
    ]);

    expect($this->item->fresh()->wildcardPriceApplies())->toBeTrue();
});

it('reports a wildcard as applying when the topping has no active SKU at all', function () {
    // The case a naive implementation gets wrong: "every active SKU is scoped"
    // is vacuously TRUE over an empty set, so a direct reading calls this dead
    // and strips the only price the topping carries.
    ProductSku::factory()->create([
        'product_id' => $this->toppingProduct->id,
        'is_active' => false,
    ]);

    expect($this->item->fresh()->wildcardPriceApplies())->toBeTrue();
});

it('gives the same answer whether or not the relations are eager-loaded', function () {
    // The predicate has two branches — loaded relations for the read path, a
    // query for the write path. They must not disagree, or the panel and the
    // guard would tell the operator different things about the same row.
    $sku = ProductSku::factory()->create([
        'product_id' => $this->toppingProduct->id,
        'is_active' => true,
    ]);
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $sku->id,
        'extra_price' => 120,
    ]);

    $queried = ToppingGroupItem::findOrFail($this->item->id);
    $loaded = ToppingGroupItem::with(['product.skus', 'skus'])->findOrFail($this->item->id);

    expect($queried->wildcardPriceApplies())->toBeFalse()
        ->and($loaded->wildcardPriceApplies())->toBeFalse();
});

it('ignores an inactive SKU when deciding, so deactivating a variant revives the wildcard', function () {
    // Deactivating the scoped SKU leaves the wildcard as the live price again.
    // Asking `product_skus` rather than the option axis is the whole point of
    // the #1277 correction — an is_active filter must not silently flip the
    // answer the other way.
    [$active, $inactive] = ($this->twoVariants)();
    $inactive->is_active = false;
    $inactive->saveQuietly();
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $inactive->id,
        'extra_price' => 150,
    ]);

    // Only the inactive SKU is scoped; the active one is not → still applies.
    expect($this->item->fresh()->wildcardPriceApplies())->toBeTrue();

    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $active->id,
        'extra_price' => 120,
    ]);

    expect($this->item->fresh()->wildcardPriceApplies())->toBeFalse();
});

// ── the serialized flag ──────────────────────────────────────────────────────

it('serializes applies=false on the dead wildcard row and true on the real one', function () {
    $sku = ProductSku::factory()->create([
        'product_id' => $this->toppingProduct->id,
        'is_active' => true,
    ]);
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $sku->id,
        'extra_price' => 120,
    ]);
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => null,
        'extra_price' => 150,
    ]);

    $item = ToppingGroupItem::with(['product.skus', 'skus.productSku'])
        ->findOrFail($this->item->id);

    $data = (new MenuToppingGroupItemResource($item))->toArray(Request::create('/'));

    $rows = collect($data['skus']->resolve(Request::create('/')));

    expect($rows->firstWhere('product_sku_id', $sku->id)['applies'])->toBeTrue()
        ->and($rows->first(fn (array $r): bool => $r['product_sku_id'] === null)['applies'])
        ->toBeFalse();
});

it('serializes applies=true on a wildcard that still prices an unscoped SKU', function () {
    [$scoped] = ($this->twoVariants)();
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $scoped->id,
        'extra_price' => 120,
    ]);
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => null,
        'extra_price' => 150,
    ]);

    $item = ToppingGroupItem::with(['product.skus', 'skus.productSku'])
        ->findOrFail($this->item->id);

    $data = (new MenuToppingGroupItemResource($item))->toArray(Request::create('/'));
    $rows = collect($data['skus']->resolve(Request::create('/')));

    expect($rows->first(fn (array $r): bool => $r['product_sku_id'] === null)['applies'])
        ->toBeTrue();
});

it('defaults applies to true when the parent never stamped it', function () {
    // A row rendered on its own — no parent item resource, so nothing computed
    // the flag. Under-reporting is the safe direction: never accuse a working
    // price row of doing nothing.
    $row = ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => null,
        'extra_price' => 150,
    ]);

    $data = (new MenuToppingGroupItemSkuResource($row))->toArray(Request::create('/'));

    expect($data['applies'])->toBeTrue();
});
