<?php

/**
 * Plan 016 — Edit toppings on a pending cart line.
 *
 * Endpoint: PATCH /api/v1/shops/{shopSlug}/orders/{customerOrder}/items/{item}
 * with `toppings[]` field. Atomic-replace OrderItemTopping rows; gated on
 * status=pending.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CustomerOrder;
use App\Models\OrderItemTopping;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
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
        'slug' => 'oite-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->product = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $this->product->id,
        'is_active' => true,
        'selling_price' => 1000,
    ]);

    $this->order = CustomerOrder::factory()->create([
        'branch_id' => $this->shop->id,
        'organization_id' => $this->orgId,
        'status' => 'open',
    ]);

    $this->addItemUrl = "/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/items";
});

/**
 * Build a topping group + 1 item attached to $this->product.
 *
 * @return array{ToppingGroup, ToppingGroupItem, ProductSku}
 */
function makeTopping(string $name, float $extraPrice, array $groupAttrs = []): array
{
    /** @var TestCase $t */
    $t = test();

    $group = ToppingGroup::factory()->create(array_merge([
        'organization_id' => $t->orgId,
        'brand_id' => $t->brand->id,
        'is_active' => true,
        'min_select' => 0,
        'max_select' => 5,
        'modifier_type' => 'add',
        'selection_type' => 'multiple',
        'price_strategy' => 'flat',
        'name' => $name.' group',
    ], $groupAttrs));

    $tp = Product::factory()->create([
        'organization_id' => $t->orgId,
        'brand_id' => $t->brand->id,
    ]);
    $tsku = ProductSku::factory()->create([
        'product_id' => $tp->id,
        'is_active' => true,
        'selling_price' => $extraPrice,
    ]);

    $item = ToppingGroupItem::factory()->create([
        'topping_group_id' => $group->id,
        'product_id' => $tp->id,
    ]);
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $item->id,
        'product_sku_id' => $tsku->id,
        'extra_price' => $extraPrice,
    ]);

    $t->product->toppingGroups()->attach($group->id, ['sort_order' => 0]);

    return [$group, $item, $tsku];
}

/**
 * Same as makeTopping but the group is NEVER attached to $this->product, so
 * selecting it must raise topping_group_not_attached.
 *
 * @return array{ToppingGroup, ToppingGroupItem, ProductSku}
 */
function makeToppingUnattached(string $name, float $extraPrice): array
{
    /** @var TestCase $t */
    $t = test();

    $group = ToppingGroup::factory()->create([
        'organization_id' => $t->orgId,
        'brand_id' => $t->brand->id,
        'is_active' => true,
        'min_select' => 0,
        'max_select' => 5,
        'modifier_type' => 'add',
        'selection_type' => 'multiple',
        'price_strategy' => 'flat',
        'name' => $name.' group',
    ]);

    $tp = Product::factory()->create(['organization_id' => $t->orgId, 'brand_id' => $t->brand->id]);
    $tsku = ProductSku::factory()->create(['product_id' => $tp->id, 'is_active' => true, 'selling_price' => $extraPrice]);

    $item = ToppingGroupItem::factory()->create(['topping_group_id' => $group->id, 'product_id' => $tp->id]);
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $item->id,
        'product_sku_id' => $tsku->id,
        'extra_price' => $extraPrice,
    ]);

    // Deliberately no ->toppingGroups()->attach().

    return [$group, $item, $tsku];
}

/**
 * Add the parent product to the order with one initial topping. Returns the
 * created order item id and the initial topping's (id, sku_id) so the test
 * can later edit it.
 *
 * @return array{string, string, string}
 */
function addItemWithTopping(string $name = 'Initial', float $price = 50.0): array
{
    /** @var TestCase $t */
    $t = test();

    [, $item, $sku] = makeTopping($name, $price);

    $t->actingAs($t->user)->postJson($t->addItemUrl, [
        'items' => [[
            'product_sku_id' => $t->sku->id,
            'quantity' => 1,
            'toppings' => [
                ['topping_group_item_id' => $item->id, 'product_sku_id' => $sku->id, 'quantity' => 1],
            ],
        ]],
    ])->assertCreated();

    $orderItem = $t->order->items()->first();

    return [$orderItem->id, $item->id, $sku->id];
}

// =============================================================================
//  Happy path
// =============================================================================

describe('happy path', function () {
    it('replaces OrderItemTopping rows + recomputes subtotal on a pending line', function () {
        // Add an item with one ¥50 topping.
        [$itemId] = addItemWithTopping('Initial', 50.0);

        // Make a NEW topping group + item that the customer will switch to.
        [, $newToppingItem, $newToppingSku] = makeTopping('Replacement', 80.0);

        $patchUrl = "/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/items/{$itemId}";

        $response = $this->actingAs($this->user)->patchJson($patchUrl, [
            'toppings' => [
                ['topping_group_item_id' => $newToppingItem->id, 'product_sku_id' => $newToppingSku->id, 'quantity' => 1],
            ],
        ]);

        $response->assertOk();

        // OrderItemTopping rows replaced atomically.
        $rows = OrderItemTopping::where('customer_order_item_id', $itemId)
            ->orderBy('unit_price')
            ->get(['topping_group_item_id', 'unit_price'])
            ->map(fn ($r) => ['id' => $r->topping_group_item_id, 'price' => (float) $r->unit_price])
            ->all();
        expect($rows)->toHaveCount(1);
        expect($rows[0]['id'])->toBe($newToppingItem->id);
        expect($rows[0]['price'])->toBe(80.0);

        // Line subtotal recomputed: 1 × (1000 + 80) = 1080.
        $orderItem = $this->order->items()->first();
        expect((float) $orderItem->topping_subtotal)->toBe(80.0);
        expect((float) $orderItem->subtotal)->toBe(1080.0);
    });

    it('clearing all toppings sets topping_subtotal to 0', function () {
        [$itemId] = addItemWithTopping('ToBeRemoved', 50.0);

        $patchUrl = "/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/items/{$itemId}";

        $this->actingAs($this->user)->patchJson($patchUrl, [
            'toppings' => [],
        ])->assertOk();

        expect(OrderItemTopping::where('customer_order_item_id', $itemId)->count())->toBe(0);

        $orderItem = $this->order->items()->first();
        expect((float) $orderItem->topping_subtotal)->toBe(0.0);
        expect((float) $orderItem->subtotal)->toBe(1000.0);
    });

    it('rescales prices using the live extra_price (fresh snapshot)', function () {
        // Add at ¥50.
        [$itemId, $toppingItemId, $toppingSkuId] = addItemWithTopping('PriceShift', 50.0);

        // Admin bumps the topping's extra_price to ¥120 between addItem and edit.
        ToppingGroupItemSku::where('topping_group_item_id', $toppingItemId)
            ->where('product_sku_id', $toppingSkuId)
            ->update(['extra_price' => 120]);

        $patchUrl = "/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/items/{$itemId}";

        $this->actingAs($this->user)->patchJson($patchUrl, [
            'toppings' => [
                ['topping_group_item_id' => $toppingItemId, 'product_sku_id' => $toppingSkuId, 'quantity' => 1],
            ],
        ])->assertOk();

        // The single OIT row now snapshots ¥120, not ¥50 — fresh price.
        $row = OrderItemTopping::where('customer_order_item_id', $itemId)->first();
        expect((float) $row->unit_price)->toBe(120.0);
        $orderItem = $this->order->items()->first();
        expect((float) $orderItem->topping_subtotal)->toBe(120.0);
    });
});

// =============================================================================
//  Status gating
// =============================================================================

describe('status gating', function () {
    it('rejects topping edit when item is preparing (409)', function () {
        [$itemId, $toppingItemId, $toppingSkuId] = addItemWithTopping('Initial', 50.0);

        // Move the line to preparing status — kitchen has it now.
        $this->order->items()->where('id', $itemId)->update(['status' => 'preparing']);

        $patchUrl = "/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/items/{$itemId}";

        $response = $this->actingAs($this->user)->patchJson($patchUrl, [
            'toppings' => [
                ['topping_group_item_id' => $toppingItemId, 'product_sku_id' => $toppingSkuId, 'quantity' => 2],
            ],
        ]);

        $response->assertStatus(409);

        // OIT rows untouched.
        expect(OrderItemTopping::where('customer_order_item_id', $itemId)->count())->toBe(1);
    });
});

// =============================================================================
//  Validation reuse
// =============================================================================

describe('validation', function () {
    it('rejects when edit leaves a mandatory group with 0 selections', function () {
        // Force a non-combo product type so CustomerOrderService's combo-only
        // auto-fill fallback can't silently inject a default and mask the
        // validation failure (Brand factory side-effect auto-creates a 'combo'
        // ProductType, and ProductFactory picks inRandomOrder, so $this->product
        // would otherwise be implicitly a combo).
        $nonComboType = ProductType::firstOrCreate(
            ['brand_id' => $this->brand->id, 'code' => 'standard-non-combo'],
            ProductType::factory()->make([
                'organization_id' => $this->orgId,
                'brand_id' => $this->brand->id,
                'code' => 'standard-non-combo',
            ])->toArray()
        );
        $this->product->update(['product_type_id' => $nonComboType->id]);

        // Mandatory group with min_select=1.
        [$mandatoryGroup, $mandatoryItem, $mandatorySku] = makeTopping(
            'Mandatory',
            0,
            ['min_select' => 1, 'max_select' => 1],
        );

        // Add the item with the mandatory selection ticked.
        $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
                'toppings' => [
                    ['topping_group_item_id' => $mandatoryItem->id, 'product_sku_id' => $mandatorySku->id, 'quantity' => 1],
                ],
            ]],
        ])->assertCreated();

        $orderItem = $this->order->items()->first();
        $patchUrl = "/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/items/{$orderItem->id}";

        // Try to edit and leave the mandatory group empty.
        $response = $this->actingAs($this->user)->patchJson($patchUrl, [
            'toppings' => [],
        ]);

        $response->assertStatus(422);
    });
});

// =============================================================================
//  Order-level rollup (tax / service / coupon) — recalculateTotals()
//
//  The pre-existing happy-path suite only asserts the *line* subtotal +
//  topping_subtotal; it never proves the order-level total/tax/service/coupon
//  are re-derived after a topping edit. These lock BR-SOS05 + coupon
//  live-recompute (#550) on the edit path.
// =============================================================================

describe('order-level rollup', function () {
    it('re-derives order tax + service charge from ShopOrderSetting after a topping edit', function () {
        // 10% tax + 5% service, JPY (integer step → exact whole-yen math).
        ShopOrderSetting::factory()->create([
            'branch_id' => $this->shop->id,
            'service_charge_rate' => 5,
            'currency_code' => 'JPY',
        ]);
        // plan-043 — the flat branch tax_rate was dropped; a STANDARD default tax
        // type (10% dine-in) lets the re-resolve stamp each line at 10% so the
        // order rolls up to the 108 tax the test expects.
        TaxType::factory()->standard()->asDefault()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);

        // No coupon / no manual discount — the CustomerOrder factory seeds a
        // random discount_amount, so zero it to keep tax deterministic.
        $this->order->update(['discount_amount' => 0]);

        [$itemId] = addItemWithTopping('Initial', 50.0);
        [, $newToppingItem, $newToppingSku] = makeTopping('Replacement', 80.0);

        $patchUrl = "/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/items/{$itemId}";

        $this->actingAs($this->user)->patchJson($patchUrl, [
            'toppings' => [
                ['topping_group_item_id' => $newToppingItem->id, 'product_sku_id' => $newToppingSku->id, 'quantity' => 1],
            ],
        ])->assertOk();

        // Line subtotal 1 × (1000 + 80) = 1080.
        //   tax     = round(1080 × 10%) = 108
        //   service = round(1080 ×  5%) =  54
        //   total   = 1080 + 108 + 54   = 1242
        $order = $this->order->fresh();
        expect((float) $order->subtotal)->toBe(1080.0);
        expect((float) $order->tax_amount)->toBe(108.0);
        expect((float) $order->service_charge)->toBe(54.0);
        expect((float) $order->total_amount)->toBe(1242.0);
    });

    it('recomputes a percent coupon discount against the fresh subtotal after a topping edit', function () {
        // 0% tax so the total isolates the coupon math.
        ShopOrderSetting::factory()->create([
            'branch_id' => $this->shop->id,
            'service_charge_rate' => 0,
            'currency_code' => 'JPY',
        ]);

        $coupon = Coupon::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'discount_type' => 'percent',
            'discount_value' => 10,
            'max_discount_cap' => null,
            'min_order_subtotal' => 0,
        ]);

        // Start with a ¥50 topping → subtotal 1050, coupon 10% = 105.
        [$itemId, $toppingItemId, $toppingSkuId] = addItemWithTopping('CouponStart', 50.0);
        $this->order->update(['coupon_id' => $coupon->id]);

        $patchUrl = "/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/items/{$itemId}";

        // Bump the topping to ¥200 → subtotal 1200, coupon must track LIVE
        // cart: 10% of 1200 = 120 (not the frozen 105).
        ToppingGroupItemSku::where('topping_group_item_id', $toppingItemId)
            ->where('product_sku_id', $toppingSkuId)
            ->update(['extra_price' => 200]);

        $this->actingAs($this->user)->patchJson($patchUrl, [
            'toppings' => [
                ['topping_group_item_id' => $toppingItemId, 'product_sku_id' => $toppingSkuId, 'quantity' => 1],
            ],
        ])->assertOk();

        $order = $this->order->fresh();
        expect((float) $order->subtotal)->toBe(1200.0);
        expect((float) $order->discount_amount)->toBe(120.0);
        // total = subtotal − discount + 0 tax = 1080.
        expect((float) $order->total_amount)->toBe(1080.0);
    });
});

// =============================================================================
//  Quantity multipliers
//
//  Every pre-existing edit test uses line quantity = 1 and topping quantity =
//  1, so neither multiplier in `quantity × (unit_price + topping_subtotal)`
//  nor the per-item topping-quantity expansion in ToppingPricingService is
//  exercised on the edit path.
// =============================================================================

describe('quantity multipliers', function () {
    it('applies the line quantity multiplier when editing toppings on a qty>1 line', function () {
        [, $toppingItem, $toppingSku] = makeTopping('LineMult', 50.0);

        // Add the parent product with quantity 2 (no topping yet).
        $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 2,
            ]],
        ])->assertCreated();

        $orderItem = $this->order->items()->first();
        $patchUrl = "/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/items/{$orderItem->id}";

        // Edit in a ¥80 topping (re-price the ¥50 snapshot to ¥80 first).
        ToppingGroupItemSku::where('topping_group_item_id', $toppingItem->id)
            ->where('product_sku_id', $toppingSku->id)
            ->update(['extra_price' => 80]);

        $this->actingAs($this->user)->patchJson($patchUrl, [
            'toppings' => [
                ['topping_group_item_id' => $toppingItem->id, 'product_sku_id' => $toppingSku->id, 'quantity' => 1],
            ],
        ])->assertOk();

        // topping_subtotal is per-unit (80); line subtotal multiplies by qty:
        //   2 × (1000 + 80) = 2160.
        $orderItem->refresh();
        expect((float) $orderItem->quantity)->toBe(2.0);
        expect((float) $orderItem->topping_subtotal)->toBe(80.0);
        expect((float) $orderItem->subtotal)->toBe(2160.0);
        expect((float) $this->order->fresh()->subtotal)->toBe(2160.0);
    });

    it('expands per-item topping quantity>1 into the topping_subtotal (flat strategy)', function () {
        // max_qty_per_item=3 lets one topping type be picked ×2.
        [, $toppingItem, $toppingSku] = makeTopping('QtyPerItem', 80.0, ['max_qty_per_item' => 3]);

        // Base line, no topping.
        $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
            ]],
        ])->assertCreated();

        $orderItem = $this->order->items()->first();
        $patchUrl = "/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/items/{$orderItem->id}";

        $this->actingAs($this->user)->patchJson($patchUrl, [
            'toppings' => [
                ['topping_group_item_id' => $toppingItem->id, 'product_sku_id' => $toppingSku->id, 'quantity' => 2],
            ],
        ])->assertOk();

        // One OIT row snapshots qty 2 @ ¥80; flat strategy expands to 2 units:
        //   topping_subtotal = 2 × 80 = 160
        //   line subtotal    = 1 × (1000 + 160) = 1160
        $row = OrderItemTopping::where('customer_order_item_id', $orderItem->id)->first();
        expect((int) $row->quantity)->toBe(2);
        $orderItem->refresh();
        expect((float) $orderItem->topping_subtotal)->toBe(160.0);
        expect((float) $orderItem->subtotal)->toBe(1160.0);
    });
});

// =============================================================================
//  Order + item state branches
//
//  Pre-existing gating test only covers item.status = preparing. These cover
//  the other post-fire item states, the AwaitingConfirmation positive branch
//  (plan-037 follow-up), and a non-editable *order* status.
// =============================================================================

describe('state branches', function () {
    it('rejects a topping edit once the item is ready or served (409)', function ($itemStatus) {
        [$itemId, $toppingItemId, $toppingSkuId] = addItemWithTopping('Initial', 50.0);

        $this->order->items()->where('id', $itemId)->update(['status' => $itemStatus]);

        $patchUrl = "/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/items/{$itemId}";

        $this->actingAs($this->user)->patchJson($patchUrl, [
            'toppings' => [
                ['topping_group_item_id' => $toppingItemId, 'product_sku_id' => $toppingSkuId, 'quantity' => 1],
            ],
        ])->assertStatus(409);

        // Original snapshot untouched.
        expect(OrderItemTopping::where('customer_order_item_id', $itemId)->count())->toBe(1);
    })->with(['ready', 'served']);

    it('allows a topping edit while the order is awaiting_confirmation (plan-037 branch)', function () {
        [$itemId] = addItemWithTopping('Initial', 50.0);
        [, $newToppingItem, $newToppingSku] = makeTopping('AwaitSwap', 90.0);

        // Order not yet confirmed — line still pending, so edit is safe.
        $this->order->update(['status' => 'awaiting_confirmation']);

        $patchUrl = "/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/items/{$itemId}";

        $this->actingAs($this->user)->patchJson($patchUrl, [
            'toppings' => [
                ['topping_group_item_id' => $newToppingItem->id, 'product_sku_id' => $newToppingSku->id, 'quantity' => 1],
            ],
        ])->assertOk();

        $orderItem = $this->order->items()->first();
        expect((float) $orderItem->topping_subtotal)->toBe(90.0);
    });

    it('rejects a topping edit when the order status is no longer editable (409)', function () {
        [$itemId, $toppingItemId, $toppingSkuId] = addItemWithTopping('Initial', 50.0);

        // Line is still pending, but the ORDER has moved to checkout — the
        // assertStatus([awaiting, open]) guard must still block the edit.
        $this->order->update(['status' => 'checkout']);

        $patchUrl = "/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/items/{$itemId}";

        $this->actingAs($this->user)->patchJson($patchUrl, [
            'toppings' => [
                ['topping_group_item_id' => $toppingItemId, 'product_sku_id' => $toppingSkuId, 'quantity' => 1],
            ],
        ])->assertStatus(409);

        expect(OrderItemTopping::where('customer_order_item_id', $itemId)->count())->toBe(1);
    });
});

// =============================================================================
//  Validation — above_max, unattached group, cross-tenant injection
//
//  Pre-existing validation test only covers below_min. These cover the
//  remaining reused-validation branches, incl. multi-tenant isolation: a
//  topping group from *another* organization must never attach on edit.
// =============================================================================

describe('validation branches', function () {
    it('rejects exceeding a groups max_select on edit (422) and leaves rows intact', function () {
        // Single group, max_select=1, with TWO distinct selectable items.
        $group = ToppingGroup::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'is_active' => true,
            'min_select' => 0,
            'max_select' => 1,
            'modifier_type' => 'add',
            'selection_type' => 'multiple',
            'price_strategy' => 'flat',
        ]);
        $this->product->toppingGroups()->attach($group->id, ['sort_order' => 0]);

        $mkItem = function () use ($group) {
            $p = Product::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
            $sku = ProductSku::factory()->create(['product_id' => $p->id, 'is_active' => true, 'selling_price' => 40]);
            $item = ToppingGroupItem::factory()->create(['topping_group_id' => $group->id, 'product_id' => $p->id]);
            ToppingGroupItemSku::factory()->create([
                'topping_group_item_id' => $item->id,
                'product_sku_id' => $sku->id,
                'extra_price' => 40,
            ]);

            return [$item->id, $sku->id];
        };
        [$itemA, $skuA] = $mkItem();
        [$itemB, $skuB] = $mkItem();

        // Add the line with a single valid selection.
        $this->actingAs($this->user)->postJson($this->addItemUrl, [
            'items' => [[
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
                'toppings' => [
                    ['topping_group_item_id' => $itemA, 'product_sku_id' => $skuA, 'quantity' => 1],
                ],
            ]],
        ])->assertCreated();

        $orderItem = $this->order->items()->first();
        $patchUrl = "/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/items/{$orderItem->id}";

        // Edit selecting BOTH items → 2 > max_select 1.
        $this->actingAs($this->user)->patchJson($patchUrl, [
            'toppings' => [
                ['topping_group_item_id' => $itemA, 'product_sku_id' => $skuA, 'quantity' => 1],
                ['topping_group_item_id' => $itemB, 'product_sku_id' => $skuB, 'quantity' => 1],
            ],
        ])->assertStatus(422);

        // Transaction rolled back — the original single row survives.
        $rows = OrderItemTopping::where('customer_order_item_id', $orderItem->id)->get();
        expect($rows)->toHaveCount(1);
        expect($rows[0]->topping_group_item_id)->toBe($itemA);
    });

    it('rejects a topping whose group is not attached to the product (422)', function () {
        [$itemId, $origItem] = addItemWithTopping('Initial', 50.0);

        // A perfectly valid group in the SAME org — just never attached to
        // $this->product.
        [, $unattachedItem, $unattachedSku] = makeToppingUnattached('Detached', 60.0);

        $patchUrl = "/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/items/{$itemId}";

        $this->actingAs($this->user)->patchJson($patchUrl, [
            'toppings' => [
                ['topping_group_item_id' => $unattachedItem->id, 'product_sku_id' => $unattachedSku->id, 'quantity' => 1],
            ],
        ])->assertStatus(422);

        // Original topping snapshot preserved (rollback).
        $rows = OrderItemTopping::where('customer_order_item_id', $itemId)->get();
        expect($rows)->toHaveCount(1);
        expect($rows[0]->topping_group_item_id)->toBe($origItem);
    });

    it('rejects injecting a topping group owned by another organization (multi-tenant)', function () {
        [$itemId, $origItem] = addItemWithTopping('Initial', 50.0);

        // Build a fully-valid topping group under a DIFFERENT organization +
        // brand. It is active + priced — the only thing wrong is the tenant.
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
            'max_select' => 5,
            'modifier_type' => 'add',
            'selection_type' => 'multiple',
            'price_strategy' => 'flat',
        ]);
        $foreignProduct = Product::factory()->create(['organization_id' => $otherOrgId, 'brand_id' => $otherBrand->id]);
        $foreignSku = ProductSku::factory()->create(['product_id' => $foreignProduct->id, 'is_active' => true, 'selling_price' => 70]);
        $foreignItem = ToppingGroupItem::factory()->create(['topping_group_id' => $foreignGroup->id, 'product_id' => $foreignProduct->id]);
        ToppingGroupItemSku::factory()->create([
            'topping_group_item_id' => $foreignItem->id,
            'product_sku_id' => $foreignSku->id,
            'extra_price' => 70,
        ]);

        $patchUrl = "/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/items/{$itemId}";

        // The foreign group is not attached to $this->product, so the
        // attachment guard rejects it — cross-tenant toppings can't leak in.
        $this->actingAs($this->user)->patchJson($patchUrl, [
            'toppings' => [
                ['topping_group_item_id' => $foreignItem->id, 'product_sku_id' => $foreignSku->id, 'quantity' => 1],
            ],
        ])->assertStatus(422);

        // No foreign row was written; the original snapshot is intact.
        expect(OrderItemTopping::where('customer_order_item_id', $itemId)
            ->where('topping_group_item_id', $foreignItem->id)->count())->toBe(0);
        $rows = OrderItemTopping::where('customer_order_item_id', $itemId)->get();
        expect($rows)->toHaveCount(1);
        expect($rows[0]->topping_group_item_id)->toBe($origItem);
    });
});
