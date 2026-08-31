<?php

/**
 * Plan 015 — Shop addItems with toppings
 *
 * Covers (TESTS.md):
 *   Happy 4-8       — persistence, free_up_to_n math, merge rule extension
 *   Validation 9    — min/max/qty/group_attached/is_active/no_price/missing_sku
 *   Authorization 6 — pos_staff own/cross-branch, shop_manager, unauth, …
 *   Edge 5-7        — snapshot freshness, no-price reject, concurrent merge
 *   Error 4         — settled order, missing IDs, malformed payload
 *   Side effect 5   — order_item_toppings inserted, order total includes toppings
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderItemTopping;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
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
        'is_active' => true,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'oitw-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    // A product with a SKU that the order will reference.
    $this->product = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $this->product->id,
        'is_active' => true,
        'selling_price' => 1000,
    ]);

    // Open dine-in order on the shop.
    $this->order = CustomerOrder::factory()->create([
        'branch_id' => $this->shop->id,
        'organization_id' => $this->orgId,
        'status' => 'open',
    ]);

    $this->addItemUrl = "/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/items";
});

/**
 * Helper: build a topping group + 1 item + 1 item-SKU, attached to the
 * test fixture product. Returns [$group, $item, $itemSku, $toppingSku].
 *
 * @return array{ToppingGroup, ToppingGroupItem, ToppingGroupItemSku, ProductSku}
 */
function makeAttachedTopping(array $groupAttrs = [], float $extraPrice = 50.0, ?array $pivotAttrs = null): array
{
    /** @var TestCase $t */
    $t = test();

    $group = ToppingGroup::factory()->create(array_merge([
        'organization_id' => $t->orgId,
        'brand_id' => $t->brand->id,
        'is_active' => true,
        'min_select' => 0,
        'max_select' => null,
        'modifier_type' => 'add',
        'selection_type' => 'multiple',
        'price_strategy' => 'flat',
    ], $groupAttrs));

    $toppingProduct = Product::factory()->create([
        'organization_id' => $t->orgId,
        'brand_id' => $t->brand->id,
    ]);
    $toppingSku = ProductSku::factory()->create([
        'product_id' => $toppingProduct->id,
        'is_active' => true,
        'selling_price' => $extraPrice,
    ]);

    $item = ToppingGroupItem::factory()->create([
        'topping_group_id' => $group->id,
        'product_id' => $toppingProduct->id,
        'is_default' => false,
        'sort_order' => 0,
    ]);
    $itemSku = ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $item->id,
        'product_sku_id' => $toppingSku->id,
        'extra_price' => $extraPrice,
    ]);

    $t->product->toppingGroups()->attach($group->id, $pivotAttrs ?? ['sort_order' => 0]);

    return [$group, $item, $itemSku, $toppingSku];
}

// =============================================================================
//  Happy path
// =============================================================================

describe('happy path', function () {
    it('#1148 — replacing the variant SKU in place is REJECTED (void + re-add only); toppings/note still edit', function () {
        [, $toppingItem, , $toppingSku] = makeAttachedTopping(['price_strategy' => 'flat'], 50.0);
        $largeSku = ProductSku::factory()->withSequencedOption()->create([
            'product_id' => $this->product->id,
            'is_active' => true,
            'selling_price' => 1600,
            'name' => 'Large',
        ]);

        $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 2,
                'note' => 'old note',
            ]],
        ])->assertCreated();

        $orderItem = $this->order->items()->firstOrFail();

        // SKU swap → 409, line untouched.
        $this->actingAs($this->user)->patchJson(
            "{$this->addItemUrl}/{$orderItem->id}",
            ['product_sku_id' => $largeSku->id],
        )->assertStatus(409);
        expect((string) $orderItem->fresh()->product_sku_id)->toBe((string) $this->sku->id);

        // Toppings + note edits keep working exactly as before.
        $this->actingAs($this->user)->patchJson(
            "{$this->addItemUrl}/{$orderItem->id}",
            [
                'toppings' => [[
                    'topping_group_item_id' => $toppingItem->id,
                    'product_sku_id' => $toppingSku->id,
                    'quantity' => 1,
                ]],
                'note' => 'new note',
            ],
        )->assertOk()
            ->assertJsonPath('data.items.0.note', 'new note');

        $orderItem->refresh();
        expect((float) $orderItem->topping_subtotal)->toBe(50.0)
            ->and((float) $orderItem->subtotal)->toBe(2100.0)
            ->and(OrderItemTopping::where('customer_order_item_id', $orderItem->id)->count())->toBe(1);
    });

    it('rejects replacing an order item with a SKU from another product', function () {
        $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
            ]],
        ])->assertCreated();

        $otherProduct = Product::factory()->active()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);
        $otherSku = ProductSku::factory()->create([
            'product_id' => $otherProduct->id,
            'is_active' => true,
        ]);
        $orderItem = $this->order->items()->firstOrFail();

        $this->actingAs($this->user)->patchJson(
            "{$this->addItemUrl}/{$orderItem->id}",
            ['product_sku_id' => $otherSku->id, 'toppings' => []],
        )->assertStatus(409); // #1148 — every in-place SKU swap is banned

        expect((string) $orderItem->fresh()->product_sku_id)->toBe((string) $this->sku->id);
    });

    it('persists OrderItemTopping rows + sets topping_subtotal on flat strategy', function () {
        [, $item, , $sku] = makeAttachedTopping(['price_strategy' => 'flat'], 50.0);
        [, $item2, , $sku2] = makeAttachedTopping(['price_strategy' => 'flat'], 80.0);

        $response = $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [
                [
                    'product_sku_id' => $this->sku->id,
                    'quantity' => 2,
                    'toppings' => [
                        ['topping_group_item_id' => $item->id, 'product_sku_id' => $sku->id, 'quantity' => 1],
                        ['topping_group_item_id' => $item2->id, 'product_sku_id' => $sku2->id, 'quantity' => 1],
                    ],
                ],
            ],
        ]);

        $response->assertCreated();

        $orderItem = $this->order->items()->first();
        expect($orderItem)->not->toBeNull();
        expect((float) $orderItem->topping_subtotal)->toBe(130.0); // 50 + 80
        expect((float) $orderItem->subtotal)->toBe(2.0 * (1000.0 + 130.0)); // 2260.0

        // 2 OrderItemTopping rows persisted
        expect(OrderItemTopping::where('customer_order_item_id', $orderItem->id)->count())->toBe(2);

        // unit_price snapshotted at full extra_price
        $stored = OrderItemTopping::where('customer_order_item_id', $orderItem->id)
            ->orderBy('unit_price')->pluck('unit_price')->map(fn ($v) => (float) $v)->all();
        expect($stored)->toBe([50.0, 80.0]);
    });

    it('free_up_to_n waives most expensive: 50/80/120 with free_quantity=1 → topping_subtotal=130', function () {
        [$group] = makeAttachedTopping(['price_strategy' => 'free_up_to_n', 'free_quantity' => 1, 'max_select' => 5], 50.0);
        // Need 3 items in the same group. Create 2 more attached to the SAME group.
        $p2 = Product::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
        $s2 = ProductSku::factory()->create(['product_id' => $p2->id, 'is_active' => true, 'selling_price' => 80]);
        $i2 = ToppingGroupItem::factory()->create(['topping_group_id' => $group->id, 'product_id' => $p2->id]);
        ToppingGroupItemSku::factory()->create(['topping_group_item_id' => $i2->id, 'product_sku_id' => $s2->id, 'extra_price' => 80]);

        $p3 = Product::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
        $s3 = ProductSku::factory()->create(['product_id' => $p3->id, 'is_active' => true, 'selling_price' => 120]);
        $i3 = ToppingGroupItem::factory()->create(['topping_group_id' => $group->id, 'product_id' => $p3->id]);
        ToppingGroupItemSku::factory()->create(['topping_group_item_id' => $i3->id, 'product_sku_id' => $s3->id, 'extra_price' => 120]);

        $i1 = ToppingGroupItem::where('topping_group_id', $group->id)->first();
        $s1 = ProductSku::where('selling_price', 50)->first();

        $response = $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
                'toppings' => [
                    ['topping_group_item_id' => $i1->id, 'product_sku_id' => $s1->id, 'quantity' => 1],
                    ['topping_group_item_id' => $i2->id, 'product_sku_id' => $s2->id, 'quantity' => 1],
                    ['topping_group_item_id' => $i3->id, 'product_sku_id' => $s3->id, 'quantity' => 1],
                ],
            ]],
        ]);

        $response->assertCreated();

        $orderItem = $this->order->items()->first();
        // Waive 120 (most expensive), charge 80 + 50 = 130
        expect((float) $orderItem->topping_subtotal)->toBe(130.0);

        // 3 OIT rows still persisted with full prices
        $rows = OrderItemTopping::where('customer_order_item_id', $orderItem->id)
            ->orderBy('unit_price')->pluck('unit_price')->map(fn ($v) => (float) $v)->all();
        expect($rows)->toBe([50.0, 80.0, 120.0]);
    });

    it('merges existing line when SKU + toppings match exactly', function () {
        [, $item, , $sku] = makeAttachedTopping();

        $payload = [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
                'toppings' => [
                    ['topping_group_item_id' => $item->id, 'product_sku_id' => $sku->id, 'quantity' => 1],
                ],
            ]],
        ];

        $this->actingAs($this->user)->postJson($this->addItemUrl, $payload)->assertCreated();
        $this->actingAs($this->user)->postJson($this->addItemUrl, $payload)->assertCreated();

        // One line with quantity 2 — merged
        expect($this->order->items()->count())->toBe(1);
        expect((float) $this->order->items()->first()->quantity)->toBe(2.0);
    });

    it('merges two autofill-default requests into one line (BR-OI06 post-autofill merge key)', function () {
        // Regression: a mandatory group (min_select=1) with an is_default item
        // that the client OMITS from the payload. addItems autofills the
        // default, persisting an OrderItemTopping row. The merge key MUST be
        // built from the post-autofill toppings — building it from the raw
        // (empty) payload made existingMergeKey() (which reads the persisted
        // default) never match, so the second identical request created a
        // duplicate line instead of merging.
        [$group, $item, , $sku] = makeAttachedTopping(['min_select' => 1, 'max_select' => 1]);
        $item->update(['is_default' => true]);
        // Point the group item's default SKU explicitly so autofill resolves it.
        $group->update(['min_select' => 1]);

        $payload = [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
                // toppings intentionally omitted → autofill injects the default
            ]],
        ];

        $this->actingAs($this->user)->postJson($this->addItemUrl, $payload)->assertCreated();
        $this->actingAs($this->user)->postJson($this->addItemUrl, $payload)->assertCreated();

        // Autofilled default persisted, and the two requests merged into ONE line.
        expect($this->order->items()->count())->toBe(1);
        $line = $this->order->items()->first();
        expect((float) $line->quantity)->toBe(2.0);
        expect(OrderItemTopping::where('customer_order_item_id', $line->id)->count())->toBe(1);
        expect(OrderItemTopping::where('customer_order_item_id', $line->id)->first()->product_sku_id)
            ->toBe($sku->id);
    });

    it('does NOT merge when toppings differ', function () {
        [, $itemA, , $skuA] = makeAttachedTopping(['name' => 'A']);
        [, $itemB, , $skuB] = makeAttachedTopping(['name' => 'B']);

        $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
                'toppings' => [
                    ['topping_group_item_id' => $itemA->id, 'product_sku_id' => $skuA->id, 'quantity' => 1],
                ],
            ]],
        ])->assertCreated();

        $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
                'toppings' => [
                    ['topping_group_item_id' => $itemB->id, 'product_sku_id' => $skuB->id, 'quantity' => 1],
                ],
            ]],
        ])->assertCreated();

        expect($this->order->items()->count())->toBe(2);
    });

    it('auto-fills first item for combo product when mandatory group has no defaults', function () {
        // Combo products are typically seeded with mandatory groups but no
        // is_default flag on items. The order endpoint should still succeed
        // by falling back to the first item (sort_order) of each unsatisfied
        // mandatory group, instead of returning toppings_below_min.
        $comboType = ProductType::firstOrCreate(
            ['brand_id' => $this->brand->id, 'code' => 'combo'],
            ProductType::factory()->make([
                'organization_id' => $this->orgId,
                'brand_id' => $this->brand->id,
                'code' => 'combo',
            ])->toArray()
        );
        $combo = Product::factory()->active()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'product_type_id' => $comboType->id,
        ]);
        $comboSku = ProductSku::factory()->create([
            'product_id' => $combo->id,
            'is_active' => true,
            'selling_price' => 1500,
        ]);

        // Mandatory group attached to the combo, with two items, NEITHER
        // flagged is_default. Frontend would normally prompt the user to
        // pick one — but the auto-fill should still hand the order through.
        $group = ToppingGroup::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'is_active' => true,
            'min_select' => 1,
            'max_select' => 1,
        ]);

        $p1 = Product::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
        $sku1 = ProductSku::factory()->create(['product_id' => $p1->id, 'is_active' => true]);
        $item1 = ToppingGroupItem::factory()->create([
            'topping_group_id' => $group->id,
            'product_id' => $p1->id,
            'is_default' => false,
            'sort_order' => 0,
        ]);
        ToppingGroupItemSku::factory()->create([
            'topping_group_item_id' => $item1->id,
            'product_sku_id' => $sku1->id,
            'extra_price' => 0,
        ]);

        $p2 = Product::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
        $sku2 = ProductSku::factory()->create(['product_id' => $p2->id, 'is_active' => true]);
        $item2 = ToppingGroupItem::factory()->create([
            'topping_group_id' => $group->id,
            'product_id' => $p2->id,
            'is_default' => false,
            'sort_order' => 1,
        ]);
        ToppingGroupItemSku::factory()->create([
            'topping_group_item_id' => $item2->id,
            'product_sku_id' => $sku2->id,
            'extra_price' => 0,
        ]);

        $combo->toppingGroups()->attach($group->id, ['sort_order' => 0]);

        $response = $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $comboSku->id,
                'quantity' => 1,
                'toppings' => [],
            ]],
        ]);

        $response->assertCreated();

        $orderItem = $this->order->items()->where('product_sku_id', $comboSku->id)->first();
        expect($orderItem)->not->toBeNull();
        $stored = OrderItemTopping::where('customer_order_item_id', $orderItem->id)->get();
        // Auto-fill should have picked item1 (sort_order=0).
        expect($stored)->toHaveCount(1);
        expect($stored->first()->topping_group_item_id)->toBe($item1->id);
    });

    it('auto-fills default topping when mandatory group is omitted by client', function () {
        // Mandatory group (min_select=1) whose item is flagged is_default=true.
        // Client sends NO toppings — backend should auto-fill the default
        // instead of raising toppings_below_min so the order can proceed.
        [$group, $item, , $toppingSku] = makeAttachedTopping(['min_select' => 1, 'max_select' => 1]);
        $item->update(['is_default' => true]);

        $response = $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
                'toppings' => [],
            ]],
        ]);

        $response->assertCreated();

        $orderItem = $this->order->items()->first();
        expect($orderItem)->not->toBeNull();
        $stored = OrderItemTopping::where('customer_order_item_id', $orderItem->id)->get();
        expect($stored)->toHaveCount(1);
        expect($stored->first()->topping_group_item_id)->toBe($item->id);
        expect($stored->first()->product_sku_id)->toBe($toppingSku->id);
        expect((int) $stored->first()->quantity)->toBe(1);
    });

    it('returns toppings array on each item via GET /orders/{id}', function () {
        [, $item, , $sku] = makeAttachedTopping();

        $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
                'toppings' => [
                    ['topping_group_item_id' => $item->id, 'product_sku_id' => $sku->id, 'quantity' => 1, 'note' => 'no spice'],
                ],
            ]],
        ])->assertCreated();

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}");

        $response->assertOk();
        $response->assertJsonPath('data.items.0.toppings.0.topping_group_item_id', $item->id);
        $response->assertJsonPath('data.items.0.toppings.0.note', 'no spice');
    });
});

// =============================================================================
//  Validation
// =============================================================================

describe('validation', function () {
    it('rejects when mandatory group has 0 selections (toppings_below_min)', function () {
        // min_select=1 → mandatory. Customer omits the topping group entirely.
        // Force the parent product to NOT be a combo so the order endpoint's
        // combo-only auto-fill fallback (CustomerOrderService::autoFillDefaultToppings)
        // doesn't silently inject a default — the validation must surface.
        $nonComboType = ProductType::firstOrCreate(
            ['brand_id' => $this->brand->id, 'code' => 'standard-non-combo'],
            ProductType::factory()->make([
                'organization_id' => $this->orgId,
                'brand_id' => $this->brand->id,
                'code' => 'standard-non-combo',
            ])->toArray()
        );
        $this->product->update(['product_type_id' => $nonComboType->id]);

        makeAttachedTopping(['min_select' => 1, 'max_select' => 3]);

        $response = $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
                'toppings' => [],
            ]],
        ]);

        $response->assertStatus(422);
    });

    it('rejects above max_select (toppings_above_max)', function () {
        [$group, $item1, , $sku1] = makeAttachedTopping(['max_select' => 1, 'price_strategy' => 'flat']);
        $p2 = Product::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
        $s2 = ProductSku::factory()->create(['product_id' => $p2->id, 'is_active' => true]);
        $i2 = ToppingGroupItem::factory()->create(['topping_group_id' => $group->id, 'product_id' => $p2->id]);
        ToppingGroupItemSku::factory()->create(['topping_group_item_id' => $i2->id, 'product_sku_id' => $s2->id, 'extra_price' => 30]);

        $response = $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
                'toppings' => [
                    ['topping_group_item_id' => $item1->id, 'product_sku_id' => $sku1->id, 'quantity' => 1],
                    ['topping_group_item_id' => $i2->id, 'product_sku_id' => $s2->id, 'quantity' => 1],
                ],
            ]],
        ]);

        $response->assertStatus(422);
    });

    it('rejects when topping qty exceeds max_qty_per_item (topping_qty_above_max)', function () {
        [, $item, , $sku] = makeAttachedTopping();

        $response = $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
                'toppings' => [
                    ['topping_group_item_id' => $item->id, 'product_sku_id' => $sku->id, 'quantity' => 2],
                ],
            ]],
        ]);

        $response->assertStatus(422);
    });

    it('rejects topping referencing a group not attached to the parent product', function () {
        // Create a topping group + item that is NOT attached to $this->product.
        $orphanGroup = ToppingGroup::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'is_active' => true,
        ]);
        $orphanProduct = Product::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
        $orphanSku = ProductSku::factory()->create(['product_id' => $orphanProduct->id, 'is_active' => true]);
        $orphanItem = ToppingGroupItem::factory()->create(['topping_group_id' => $orphanGroup->id, 'product_id' => $orphanProduct->id]);
        ToppingGroupItemSku::factory()->create(['topping_group_item_id' => $orphanItem->id, 'product_sku_id' => $orphanSku->id, 'extra_price' => 0]);

        $response = $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
                'toppings' => [
                    ['topping_group_item_id' => $orphanItem->id, 'product_sku_id' => $orphanSku->id, 'quantity' => 1],
                ],
            ]],
        ]);

        $response->assertStatus(422);
    });

    it('rejects when group is_active=false (topping_item_inactive)', function () {
        [, $item, , $sku] = makeAttachedTopping(['is_active' => false]);

        $response = $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
                'toppings' => [
                    ['topping_group_item_id' => $item->id, 'product_sku_id' => $sku->id, 'quantity' => 1],
                ],
            ]],
        ]);

        $response->assertStatus(422);
    });

    it('accepts request with no toppings on a product whose groups are all optional', function () {
        makeAttachedTopping(['min_select' => 0]); // optional

        $response = $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
            ]],
        ]);

        $response->assertCreated();
        expect((float) $this->order->items()->first()->topping_subtotal)->toBe(0.0);
    });

    it('rejects malformed payload missing topping product_sku_id (422)', function () {
        [, $item] = makeAttachedTopping();

        $response = $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
                'toppings' => [
                    ['topping_group_item_id' => $item->id, 'quantity' => 1],
                ],
            ]],
        ]);

        $response->assertStatus(422);
    });
});

// =============================================================================
//  Authorization
// =============================================================================

describe('authorization', function () {
    it('rejects unauthenticated POST → 401', function () {
        $response = $this->postJson($this->addItemUrl, [
            'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(401);
    });

    // Multi-tenant isolation (topping WRITE path). TESTS.md Authorization:
    // "shop_manager of branch A attempt addItems on branch B's order → 403".
    // The topping payload must NOT be a side-channel that bypasses the
    // organization boundary enforced by CustomerOrderController::authorizeOrganization.
    it('denies a foreign-org user adding toppings to another org\'s order → 403', function () {
        // A valid topping attached to THIS org's product — the same payload
        // succeeds for the owning org, so a 403 proves the tenant guard (not
        // topping validation) is what blocks the foreign caller.
        [, $item, , $sku] = makeAttachedTopping([], 50.0);

        // Foreign org with its own brand + shop + user.
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $otherOrgId,
            'is_active' => true,
        ]);
        $otherShop = Branch::factory()->create([
            'console_organization_id' => $otherOrgId,
            'console_brand_id' => $otherBrand->console_brand_id,
            'slug' => 'oitw-other-'.Str::random(4),
            'is_active' => true,
        ]);
        $otherUser = User::factory()->create([
            'console_organization_id' => $otherOrgId,
        ]);
        grantOrgAccess($otherUser, $otherOrgId);

        // Reach org-A's order through org-B's shop URL (bindShopContext passes
        // for org-B, then authorizeOrganization rejects the org-A order).
        $this->actingAs($otherUser)->postJson(
            "/api/v1/shops/{$otherShop->slug}/orders/{$this->order->id}/items",
            [
                'items' => [[
                    'product_sku_id' => $this->sku->id,
                    'quantity' => 1,
                    'toppings' => [
                        ['topping_group_item_id' => $item->id, 'product_sku_id' => $sku->id, 'quantity' => 1],
                    ],
                ]],
            ],
        )->assertForbidden();

        // No OrderItemTopping leaked into the other org's attempt.
        expect(OrderItemTopping::query()->count())->toBe(0);
    });

    it('denies a foreign-org user posting toppings to a shop they lack access to → 403', function () {
        [, $item, , $sku] = makeAttachedTopping([], 50.0);

        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $otherUser = User::factory()->create([
            'console_organization_id' => $otherOrgId,
        ]);
        grantOrgAccess($otherUser, $otherOrgId);

        // Foreign user hits org-A's shop URL directly → blocked at shop-context
        // bind (no role pivot on org-A) before the payload is processed.
        $this->actingAs($otherUser)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
                'toppings' => [
                    ['topping_group_item_id' => $item->id, 'product_sku_id' => $sku->id, 'quantity' => 1],
                ],
            ]],
        ])->assertForbidden();

        expect(OrderItemTopping::query()->count())->toBe(0);
    });

    // Multi-tenant WRITE isolation via the topping payload as a side-channel.
    // Existing "topping_group_not_attached" coverage uses a SAME-org orphan
    // group; this asserts the boundary also holds when the injected
    // topping_group_item_id belongs to a DIFFERENT organization. A pos_staff
    // of org A, authorized on org A's own order, must NOT be able to reference
    // org B's topping item — it is rejected (422) and no OrderItemTopping
    // leaks across the tenant boundary.
    it('rejects an org-A order referencing a topping item owned by org B → 422, no leak', function () {
        // Foreign org B with its own active topping group + item + item-SKU.
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $otherOrgId,
            'is_active' => true,
        ]);
        $foreignGroup = ToppingGroup::factory()->create([
            'organization_id' => $otherOrgId,
            'brand_id' => $otherBrand->id,
            'is_active' => true,
            'min_select' => 0,
            'max_select' => null,
            'price_strategy' => 'flat',
        ]);
        $foreignProduct = Product::factory()->create([
            'organization_id' => $otherOrgId,
            'brand_id' => $otherBrand->id,
        ]);
        $foreignSku = ProductSku::factory()->create([
            'product_id' => $foreignProduct->id,
            'is_active' => true,
            'selling_price' => 50,
        ]);
        $foreignItem = ToppingGroupItem::factory()->create([
            'topping_group_id' => $foreignGroup->id,
            'product_id' => $foreignProduct->id,
        ]);
        ToppingGroupItemSku::factory()->create([
            'topping_group_item_id' => $foreignItem->id,
            'product_sku_id' => $foreignSku->id,
            'extra_price' => 50,
        ]);

        // Org-A user, on org-A's own order + shop, tries to attach org-B's
        // topping item. The group is never attached to org-A's product, so the
        // attachment guard rejects it — the tenant boundary is not a function
        // of who is calling but of what the product actually offers.
        $response = $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
                'toppings' => [
                    ['topping_group_item_id' => $foreignItem->id, 'product_sku_id' => $foreignSku->id, 'quantity' => 1],
                ],
            ]],
        ]);

        $response->assertStatus(422);
        expect(OrderItemTopping::query()->count())->toBe(0);
    });
});

// =============================================================================
//  Edge cases — snapshot freshness, no-price reject, in-request merge
// =============================================================================

describe('edge cases', function () {
    // TESTS.md Edge 5 — snapshot-at-submit price freshness. The unit_price
    // frozen onto each OrderItemTopping must reflect the extra_price AT SUBMIT
    // time, not the value the client saw at menu load. Here the price is bumped
    // 50 → 75 after the topping is built but before addItems runs.
    it('snapshots the extra_price at submit time, not at menu-load time', function () {
        [, $item, $itemSku, $sku] = makeAttachedTopping(['price_strategy' => 'flat'], 50.0);

        // Price changes between "menu load" and "addItems submit".
        $itemSku->update(['extra_price' => 75.0]);

        $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
                'toppings' => [
                    ['topping_group_item_id' => $item->id, 'product_sku_id' => $sku->id, 'quantity' => 1],
                ],
            ]],
        ])->assertCreated();

        $orderItem = $this->order->items()->first();
        // Snapshot reflects the FRESH price (75), not the stale 50.
        expect((float) $orderItem->topping_subtotal)->toBe(75.0);
        $stored = OrderItemTopping::where('customer_order_item_id', $orderItem->id)->first();
        expect((float) $stored->unit_price)->toBe(75.0);
        // Parent line subtotal folds in the fresh topping price.
        expect((float) $orderItem->subtotal)->toBe(1.0 * (1000.0 + 75.0));
    });

    // TESTS.md Edge 6 — topping_item_no_price. When the chosen
    // (topping_group_item, product_sku) pair has NO topping_group_item_skus row
    // and NO NULL-fallback row, pricing cannot resolve a snapshot → 422.
    it('rejects a topping whose (item, sku) pair has no extra_price row (topping_item_no_price)', function () {
        // Group + item attached to the product. makeAttachedTopping creates the
        // item-SKU for $pricedSku only (product_sku_id = $pricedSku->id, no NULL row).
        [, $item, , $pricedSku] = makeAttachedTopping(['price_strategy' => 'flat'], 50.0);

        // A different, valid ProductSku that has NO topping_group_item_skus row
        // for $item and no NULL fallback → resolveSnapshotPrice throws.
        $unpricedProduct = Product::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
        $unpricedSku = ProductSku::factory()->create(['product_id' => $unpricedProduct->id, 'is_active' => true]);

        $response = $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
                'toppings' => [
                    ['topping_group_item_id' => $item->id, 'product_sku_id' => $unpricedSku->id, 'quantity' => 1],
                ],
            ]],
        ]);

        $response->assertStatus(422);
        expect((string) $response->getContent())->toContain('topping_item_no_price');
        // Nothing persisted under a failed price resolution.
        expect($this->order->items()->count())->toBe(0);
        expect(OrderItemTopping::query()->count())->toBe(0);

        // Control: the SAME item priced via $pricedSku succeeds, proving the 422
        // was the missing-price row, not a broken fixture.
        $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
                'toppings' => [
                    ['topping_group_item_id' => $item->id, 'product_sku_id' => $pricedSku->id, 'quantity' => 1],
                ],
            ]],
        ])->assertCreated();
    });

    // TESTS.md Edge 7 — merge serialization. A genuine parallel race cannot be
    // reproduced under the sqlite :memory: test harness (each connection gets
    // its own DB; lockForUpdate is a no-op), so this exercises the strongest
    // deterministic proxy: two IDENTICAL item entries in ONE addItems request.
    // The in-transaction merge loop must fold the second entry into the line
    // the first entry just created — one line at quantity 2, not two lines and
    // not doubled topping rows. This is the same code path lockForUpdate
    // serializes across concurrent requests.
    it('merges identical items within a single addItems request into one line (no doubling)', function () {
        [, $item, , $sku] = makeAttachedTopping(['price_strategy' => 'flat'], 50.0);

        $entry = [
            'product_sku_id' => $this->sku->id,
            'quantity' => 1,
            'toppings' => [
                ['topping_group_item_id' => $item->id, 'product_sku_id' => $sku->id, 'quantity' => 1],
            ],
        ];

        $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [$entry, $entry],
        ])->assertCreated();

        // Exactly one merged line at quantity 2.
        expect($this->order->items()->count())->toBe(1);
        $orderItem = $this->order->items()->first();
        expect((float) $orderItem->quantity)->toBe(2.0);
        // Line subtotal = 2 × (1000 + 50) = 2100 — no lost or doubled qty.
        expect((float) $orderItem->subtotal)->toBe(2100.0);
        // Topping rows belong to the single surviving line and are NOT doubled.
        expect(OrderItemTopping::where('customer_order_item_id', $orderItem->id)->count())->toBe(1);
        expect(OrderItemTopping::query()->count())->toBe(1);
    });
});

// =============================================================================
//  Side effects
// =============================================================================

describe('side effects', function () {
    it('updates total_amount to include topping subtotal', function () {
        [, $item, , $sku] = makeAttachedTopping([], 50.0);

        $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 2,
                'toppings' => [
                    ['topping_group_item_id' => $item->id, 'product_sku_id' => $sku->id, 'quantity' => 1],
                ],
            ]],
        ])->assertCreated();

        $this->order->refresh();
        // 2 × (1000 + 50) = 2100
        expect((float) $this->order->subtotal)->toBe(2100.0);
    });
});

// =============================================================================
//  Error handling
// =============================================================================

describe('error handling', function () {
    it('returns 409 when adding items to a closed order', function () {
        $this->order->update(['status' => 'closed']);

        $response = $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
        ]);

        $response->assertStatus(409);
    });

    it('returns 422 when product_sku_id is missing entirely', function () {
        $response = $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [['quantity' => 1]],
        ]);

        $response->assertStatus(422);
    });
});
