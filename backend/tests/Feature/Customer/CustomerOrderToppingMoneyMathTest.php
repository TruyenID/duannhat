<?php

/**
 * plan-005 test-gap (money-math) — the customer QR order-create endpoint
 * must fold per-unit topping price deltas into the ORDER-level money, not
 * just persist the topping row.
 *
 * CustomerOrderToppingsPersistTest already pins that the topping row lands
 * with the right unit_price, but nothing asserts the aggregation the kitchen
 * bill + counter charge actually depend on:
 *
 *     order_item.subtotal   = quantity × (unit_price + topping_subtotal)
 *     order.subtotal/total  = Σ order_item.subtotal
 *
 * That multiplication (line 1013 of CustomerOrderService::addItems) is where
 * a rounding/`×` regression would silently under- or over-charge every
 * dine-in guest who adds a paid topping. These tests exercise it with
 * quantity > 1, the merge re-sum path, and a two-topping line so the delta
 * math can't be faked by a happen-to-pass single-unit case.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Models\Zone;
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

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->zone = Zone::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->table = Table::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'zone_id' => $this->zone->id,
        'qr_token' => 'topping-money-token',
        'is_active' => true,
        'status' => 'free',
    ]);

    $this->product = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $this->product->id,
        'is_active' => true,
        'selling_price' => 1000,
    ]);

    // A "modifier" topping group attached to the product, priced flat.
    $this->toppingGroup = ToppingGroup::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'is_active' => true,
        'min_select' => 0,
        'max_select' => null,
        'modifier_type' => 'add',
        'selection_type' => 'multiple',
        'price_strategy' => 'flat',
    ]);
    $this->product->toppingGroups()->attach($this->toppingGroup->id, ['sort_order' => 0]);

    // Helper: attach one priced topping item to the group. Returns [item, sku].
    $this->makeTopping = function (int $extraPrice): array {
        $tProduct = Product::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);
        $tSku = ProductSku::factory()->create([
            'product_id' => $tProduct->id,
            'is_active' => true,
            'selling_price' => $extraPrice,
        ]);
        $item = ToppingGroupItem::factory()->create([
            'topping_group_id' => $this->toppingGroup->id,
            'product_id' => $tProduct->id,
            'is_default' => false,
            'sort_order' => 0,
        ]);
        ToppingGroupItemSku::factory()->create([
            'topping_group_item_id' => $item->id,
            'product_sku_id' => $tSku->id,
            'extra_price' => $extraPrice,
        ]);

        return [$item, $tSku];
    };
});

// =========================================================================
//  Per-unit topping delta × quantity folds into every money column
// =========================================================================

it('multiplies the topping delta by quantity into the item + order subtotal', function () {
    [$topItem, $topSku] = ($this->makeTopping)(50);

    $response = $this->postJson('/api/v1/customer/tables/topping-money-token/orders', [
        'items' => [[
            'product_sku_id' => $this->sku->id,
            'quantity' => 2,
            'toppings' => [[
                'topping_group_item_id' => $topItem->id,
                'product_sku_id' => $topSku->id,
                'quantity' => 1,
            ]],
        ]],
    ])->assertStatus(201);

    // Order-level money: 2 × (base 1000 + topping 50) = 2100. No tax config → total == subtotal.
    $response->assertJsonPath('data.subtotal', 2100)
        ->assertJsonPath('data.total', 2100);

    // Item-level money: unit_price stays the base; topping folds into subtotal.
    $item = CustomerOrderItem::firstOrFail();
    expect((float) $item->unit_price)->toBe(1000.0)
        ->and((float) $item->topping_subtotal)->toBe(50.0)
        ->and((float) $item->subtotal)->toBe(2100.0);

    // The stored order row agrees with the response.
    $order = CustomerOrder::firstOrFail();
    expect((float) $order->subtotal)->toBe(2100.0)
        ->and((float) $order->total_amount)->toBe(2100.0);
});

it('applies a negative discount topping as a real discount (biz rule: negative allowed)', function () {
    // base 1000, discount topping −300 → line 700.
    [$discItem, $discSku] = ($this->makeTopping)(-300);

    $response = $this->postJson('/api/v1/customer/tables/topping-money-token/orders', [
        'items' => [[
            'product_sku_id' => $this->sku->id,
            'quantity' => 2,
            'toppings' => [[
                'topping_group_item_id' => $discItem->id,
                'product_sku_id' => $discSku->id,
                'quantity' => 1,
            ]],
        ]],
    ])->assertStatus(201);

    // 2 × (1000 − 300) = 1400.
    $response->assertJsonPath('data.subtotal', 1400)->assertJsonPath('data.total', 1400);
    $item = CustomerOrderItem::firstOrFail();
    expect((float) $item->topping_subtotal)->toBe(-300.0)
        ->and((float) $item->subtotal)->toBe(1400.0);
});

it('floors the line at zero — a discount larger than the base never pays the customer', function () {
    // base 1000, discount topping −1500 → line floored to 0, NOT −500.
    [$bigDisc, $bigDiscSku] = ($this->makeTopping)(-1500);

    $response = $this->postJson('/api/v1/customer/tables/topping-money-token/orders', [
        'items' => [[
            'product_sku_id' => $this->sku->id,
            'quantity' => 1,
            'toppings' => [[
                'topping_group_item_id' => $bigDisc->id,
                'product_sku_id' => $bigDiscSku->id,
                'quantity' => 1,
            ]],
        ]],
    ])->assertStatus(201);

    // unit_price 1000 + topping_subtotal clamped to −1000 → line 0. Never negative.
    $response->assertJsonPath('data.subtotal', 0)->assertJsonPath('data.total', 0);
    $item = CustomerOrderItem::firstOrFail();
    expect((float) $item->topping_subtotal)->toBe(-1000.0)
        ->and((float) $item->subtotal)->toBe(0.0);
});

it('sums two distinct toppings on one line into the per-unit delta', function () {
    [$cheese, $cheeseSku] = ($this->makeTopping)(50);
    [$bacon, $baconSku] = ($this->makeTopping)(70);

    $response = $this->postJson('/api/v1/customer/tables/topping-money-token/orders', [
        'items' => [[
            'product_sku_id' => $this->sku->id,
            'quantity' => 3,
            'toppings' => [
                ['topping_group_item_id' => $cheese->id, 'product_sku_id' => $cheeseSku->id, 'quantity' => 1],
                ['topping_group_item_id' => $bacon->id, 'product_sku_id' => $baconSku->id, 'quantity' => 1],
            ],
        ]],
    ])->assertStatus(201);

    // per-unit = 1000 + 50 + 70 = 1120 ; ×3 = 3360.
    $response->assertJsonPath('data.subtotal', 3360)
        ->assertJsonPath('data.total', 3360);

    $item = CustomerOrderItem::firstOrFail();
    expect((float) $item->topping_subtotal)->toBe(120.0)
        ->and((float) $item->subtotal)->toBe(3360.0);
});

// =========================================================================
//  Merge re-sum keeps the topping delta (BR-OI06) — money stays correct
// =========================================================================

it('re-sums the topping delta when the same SKU+topping line is appended', function () {
    [$topItem, $topSku] = ($this->makeTopping)(50);

    $payload = [
        'items' => [[
            'product_sku_id' => $this->sku->id,
            'quantity' => 1,
            'toppings' => [[
                'topping_group_item_id' => $topItem->id,
                'product_sku_id' => $topSku->id,
                'quantity' => 1,
            ]],
        ]],
    ];

    // First order (201) then an append of the identical line (200, merges).
    $this->postJson('/api/v1/customer/tables/topping-money-token/orders', $payload)->assertStatus(201);
    $this->postJson('/api/v1/customer/tables/topping-money-token/orders', $payload)->assertStatus(200);

    // One order, one merged line at quantity 2 → subtotal 2 × 1050 = 2100.
    expect(CustomerOrder::count())->toBe(1);
    $order = CustomerOrder::with('items')->firstOrFail();
    expect($order->items)->toHaveCount(1);

    $line = $order->items->first();
    expect((float) $line->quantity)->toBe(2.0)
        ->and((float) $line->topping_subtotal)->toBe(50.0)
        ->and((float) $line->subtotal)->toBe(2100.0);
    expect((float) $order->subtotal)->toBe(2100.0)
        ->and((float) $order->total_amount)->toBe(2100.0);
});

it('keeps a topped line and a plain line separate, each money-correct', function () {
    [$topItem, $topSku] = ($this->makeTopping)(50);

    // Line A — same SKU WITH a paid topping.
    $this->postJson('/api/v1/customer/tables/topping-money-token/orders', [
        'items' => [[
            'product_sku_id' => $this->sku->id,
            'quantity' => 1,
            'toppings' => [[
                'topping_group_item_id' => $topItem->id,
                'product_sku_id' => $topSku->id,
                'quantity' => 1,
            ]],
        ]],
    ])->assertStatus(201);

    // Line B — same SKU with NO topping. Different topping tuple → separate line.
    $this->postJson('/api/v1/customer/tables/topping-money-token/orders', [
        'items' => [[
            'product_sku_id' => $this->sku->id,
            'quantity' => 1,
        ]],
    ])->assertStatus(200);

    $order = CustomerOrder::with('items')->firstOrFail();
    expect($order->items)->toHaveCount(2);

    $topped = $order->items->firstWhere('topping_subtotal', 50.0);
    $plain = $order->items->firstWhere('topping_subtotal', 0.0);
    expect((float) $topped->subtotal)->toBe(1050.0)
        ->and((float) $plain->subtotal)->toBe(1000.0);

    // Order money is the sum of the two lines: 1050 + 1000 = 2050.
    expect((float) $order->subtotal)->toBe(2050.0)
        ->and((float) $order->total_amount)->toBe(2050.0);
});
