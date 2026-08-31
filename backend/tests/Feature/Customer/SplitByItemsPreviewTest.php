<?php

/**
 * Plan-033 Phase 4 — GET /split-by-items/preview tests on 3 surfaces:
 *   1. POS: /api/v1/pos/orders/{customerOrder}/split-by-items/preview
 *   2. Kiosk: /api/v1/kiosk/orders/{customerOrder}/split-by-items/preview
 *   3. Customer-web QR: /api/v1/customer/orders/{id}/split-by-items/preview
 *
 * Each test checks both the base shape (no allocations query) and the
 * candidate-allocations branch (preview_bills populated).
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\ShopOrderSetting;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'sbip-shop',
        'is_active' => true,
    ]);
    Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'is_active' => true,
    ]);
    $role = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($role, $this->orgId);
    $this->customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
    ]);
    $pt = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $pt->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'selling_price' => 1000,
        'is_active' => true,
    ]);
    $this->cashMethod = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);
});

function sbipSeedOrder(int $itemCount = 2): CustomerOrder
{
    $total = $itemCount * 1000;
    $order = CustomerOrder::create([
        'order_code' => 'ORD-PREV-'.Str::random(4),
        'order_type' => 'takeaway',
        'status' => 'checkout',
        'subtotal' => $total,
        'discount_amount' => 0, 'service_charge' => 0, 'tax_amount' => 0,
        'total_amount' => $total,
        'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(), 'checkout_at' => now(),
        'customer_id' => test()->customer->id,
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
    for ($i = 0; $i < $itemCount; $i++) {
        $order->items()->create([
            'product_sku_id' => test()->sku->id,
            'quantity' => 1,
            'unit_price' => 1000,
            'original_unit_price' => 1000,
            'subtotal' => 1000,
            'status' => 'served',
            'served_at' => now(),
            'tax_rate' => 0,
        ]);
    }

    return $order->refresh();
}

/**
 * Seed the plan's concrete verify-order: Chicken 2×1600 + Dragon Fruit 3×1000,
 * subtotal 6200, on an OPEN (pre-checkout) order under a 10% tax / 5% service
 * branch config. Returns [order, chickenItem, dragonItem].
 *
 * @return array{0: CustomerOrder, 1: CustomerOrderItem, 2: CustomerOrderItem}
 */
function sbipSeedTaxedOpenOrder(): array
{
    // plan-043 T6.2 — the flat branch tax_rate was dropped; the 10% tax now
    // rides on the per-line snapshot rate stamped on each item below. Service
    // charge (5%) still comes from the setting.
    ShopOrderSetting::factory()->create([
        'branch_id' => test()->shop->id,
        'organization_id' => test()->orgId,
        'service_charge_rate' => 5,
        'currency_code' => 'JPY',
        'split_bill_rounding_mode' => 'auto',
    ]);

    $order = CustomerOrder::create([
        'order_code' => 'ORD-PREV-OPEN'.Str::random(3),
        'order_type' => 'dine_in',
        'status' => 'open',
        'subtotal' => 6200,
        'discount_amount' => 0, 'service_charge' => 0, 'tax_amount' => 0,
        'total_amount' => 6200,
        'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(),
        'customer_id' => test()->customer->id,
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
    $chicken = $order->items()->create([
        'product_sku_id' => test()->sku->id, 'quantity' => 2, 'unit_price' => 1600, 'original_unit_price' => 1600,
        'subtotal' => 3200, 'tax_rate' => 10, 'status' => 'served', 'served_at' => now(),
    ]);
    $dragon = $order->items()->create([
        'product_sku_id' => test()->sku->id, 'quantity' => 3, 'unit_price' => 1000, 'original_unit_price' => 1000,
        'subtotal' => 3000, 'tax_rate' => 10, 'status' => 'served', 'served_at' => now(),
    ]);

    return [$order->refresh(), $chicken, $dragon];
}

it('preview resolves item name by request locale', function () {
    $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $product = Product::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'product_type_id' => $pt->id,
    ]);
    $product->translateOrNew('ja')->name = 'コーヒー';
    $product->translateOrNew('en')->name = 'Coffee';
    $product->translateOrNew('vi')->name = 'Cà phê';
    $product->save();
    // SKU has no own name → resolver falls back to the localized product name.
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'name' => null, 'selling_price' => 1000, 'is_active' => true]);

    $order = CustomerOrder::create([
        'order_code' => 'ORD-LOC-'.Str::random(4),
        'order_type' => 'dine_in', 'status' => 'checkout',
        'subtotal' => 1000, 'discount_amount' => 0, 'service_charge' => 0, 'tax_amount' => 0,
        'total_amount' => 1000, 'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(), 'checkout_at' => now(),
        'customer_id' => $this->customer->id, 'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
    ]);
    $order->items()->create([
        'product_sku_id' => $sku->id, 'quantity' => 1, 'unit_price' => 1000, 'original_unit_price' => 1000,
        'subtotal' => 1000, 'status' => 'served', 'served_at' => now(),
        'tax_rate' => 0,
    ]);

    foreach (['vi' => 'Cà phê', 'ja' => 'コーヒー', 'en' => 'Coffee'] as $locale => $expected) {
        $this->actingAs($this->manager)
            ->withHeader('X-Shop-Slug', $this->shop->slug)
            ->getJson('/api/v1/pos/orders/'.$order->id.'/split-by-items/preview?locale='.$locale)
            ->assertOk()
            ->assertJsonPath('data.items.0.name', $expected)
            ->assertJsonPath('data.items.0.product_name', $expected)
            ->assertJsonPath('data.items.0.sku_code', $sku->sku);
    }
});

it('subtracts already-paid by-items units from the next preview (A2 reconciliation)', function () {
    [$order, $chicken] = sbipSeedTaxedOpenOrder(); // chicken qty 2, dragon qty 3

    // A prior succeeded by-items payment claimed 1 unit of chicken.
    OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $order->id,
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'payment_method_id' => $this->cashMethod->id,
        'amount' => 1840,
        'metadata' => [
            'split_mode' => 'by_items',
            'bill_index' => 0,
            'item_allocations' => [['item_id' => $chicken->id, 'units' => 1]],
        ],
    ]);

    $items = collect($this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/orders/'.$order->id.'/split-by-items/preview')
        ->assertOk()
        ->json('data.items'))->keyBy('item_id');

    // Returned 1 chicken unit → its remaining drops from 2 to 1; dragon untouched.
    expect($items[$chicken->id]['units_claimed'])->toBe(1);
    expect($items[$chicken->id]['units_remaining'])->toBe(1);
});

it('open order: base preview reports live total + units_remaining', function () {
    [$order] = sbipSeedTaxedOpenOrder();

    $response = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/orders/'.$order->id.'/split-by-items/preview')
        ->assertOk();

    // Live-priced header: 6200 + service 310 + tax 620.
    $response->assertJsonPath('data.total_amount', '7130.00')
        ->assertJsonPath('data.remaining_amount', '7130.00')
        ->assertJsonPath('data.items.0.units_remaining', 2)
        ->assertJsonPath('data.items.1.units_remaining', 3);
});

it('open order: a single-item sub-bill carries its pro-rata tax + service', function () {
    [$order, $chicken] = sbipSeedTaxedOpenOrder();

    $alloc = [['item_id' => $chicken->id, 'units' => 1, 'bill_index' => 0]];

    $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/orders/'.$order->id.'/split-by-items/preview?allocations='.urlencode(json_encode($alloc)))
        ->assertOk()
        // 1600 + service 80 + tax 160.
        ->assertJsonPath('data.preview_bills.0.total', '1840.00');
});

it('open order: a full split sums exactly to the live order total (invariant)', function () {
    [$order, $chicken, $dragon] = sbipSeedTaxedOpenOrder();

    // Every one of the 5 units assigned to its own sub-bill.
    $alloc = [
        ['item_id' => $chicken->id, 'units' => 1, 'bill_index' => 0],
        ['item_id' => $chicken->id, 'units' => 1, 'bill_index' => 1],
        ['item_id' => $dragon->id, 'units' => 1, 'bill_index' => 2],
        ['item_id' => $dragon->id, 'units' => 1, 'bill_index' => 3],
        ['item_id' => $dragon->id, 'units' => 1, 'bill_index' => 4],
    ];

    $response = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/orders/'.$order->id.'/split-by-items/preview?allocations='.urlencode(json_encode($alloc)))
        ->assertOk();

    $response->assertJsonPath('data.total_amount', '7130.00');

    $bills = $response->json('data.preview_bills');
    $sum = array_sum(array_map(fn ($b) => (float) $b['total'], $bills));
    expect($sum)->toBe(7130.0); // == order.total, no ±1 drift
});

it('POS preview returns base shape with no allocations', function () {
    $order = sbipSeedOrder(2);

    $response = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson("/api/v1/pos/orders/{$order->id}/split-by-items/preview");

    $response->assertOk()
        ->assertJsonPath('data.order_id', $order->id)
        ->assertJsonPath('data.total_amount', '2000.00')
        ->assertJsonPath('data.allocated_amount', '0.00')
        ->assertJsonPath('data.remaining_amount', '2000.00')
        ->assertJsonPath('data.rounding_mode', 'auto')
        // #815 — the null-ShopOrderSetting default is now JPY (was VND) so the
        // charge currency and the priced currency share one default.
        ->assertJsonPath('data.currency_code', 'JPY')
        ->assertJsonCount(2, 'data.items')
        ->assertJsonMissingPath('data.preview_bills');
});

it('POS preview returns preview_bills when allocations query is provided', function () {
    $order = sbipSeedOrder(2);
    [$itemA, $itemB] = [$order->items[0], $order->items[1]];

    $candidate = [
        ['item_id' => $itemA->id, 'units' => 1, 'bill_index' => 0],
        ['item_id' => $itemB->id, 'units' => 1, 'bill_index' => 1],
    ];

    $response = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/orders/'.$order->id.'/split-by-items/preview?allocations='.urlencode(json_encode($candidate)));

    $response->assertOk()
        ->assertJsonCount(2, 'data.preview_bills')
        ->assertJsonPath('data.preview_bills.0.total', '1000.00')
        ->assertJsonPath('data.preview_bills.1.total', '1000.00');
});

it('POS preview reflects existing claims from prior payments', function () {
    $order = sbipSeedOrder(2);
    [$itemA, $itemB] = [$order->items[0], $order->items[1]];

    // Pay bill 0 with item A (use shop slug URL to bypass POS till-session
    // middleware — preview test doesn't need cashier shift setup).
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'metadata' => [
                'split_mode' => 'by_items',
                'bill_index' => 0,
                'item_allocations' => [['item_id' => $itemA->id, 'units' => 1]],
            ],
        ])
        ->assertCreated();

    $response = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson("/api/v1/pos/orders/{$order->id}/split-by-items/preview");

    $response->assertOk()
        ->assertJsonPath('data.allocated_amount', '1000.00')
        ->assertJsonPath('data.remaining_amount', '1000.00');

    $itemAView = collect($response->json('data.items'))->firstWhere('item_id', $itemA->id);
    $itemBView = collect($response->json('data.items'))->firstWhere('item_id', $itemB->id);
    expect($itemAView['units_claimed'])->toBe(1);
    expect($itemAView['units_remaining'])->toBe(0);
    expect($itemBView['units_claimed'])->toBe(0);
    expect($itemBView['units_remaining'])->toBe(1);
});

it('Kiosk preview rejects orders from other branches with 403', function () {
    $otherShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'sbip-other-shop',
        'is_active' => true,
    ]);
    $order = sbipSeedOrder(1);

    $token = Str::random(64);
    Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $otherShop->id,
        'name' => 'Other Shop Kiosk',
        'type' => 'kiosk',
        'status' => 'active',
        'paired_at' => now(),
        'device_token' => $token,
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson("/api/v1/kiosk/orders/{$order->id}/split-by-items/preview");

    $response->assertStatus(403);
});

it('Kiosk preview returns base shape for an order in the device branch', function () {
    $order = sbipSeedOrder(1);

    $token = Str::random(64);
    Device::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'name' => 'Same Shop Kiosk',
        'type' => 'kiosk',
        'status' => 'active',
        'paired_at' => now(),
        'device_token' => $token,
    ]);

    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson("/api/v1/kiosk/orders/{$order->id}/split-by-items/preview");

    $response->assertOk()
        ->assertJsonPath('data.order_id', $order->id)
        ->assertJsonPath('data.total_amount', '1000.00');
});

it('Customer preview returns base shape for any valid order id', function () {
    $order = sbipSeedOrder(2);

    $response = $this->getJson("/api/v1/customer/orders/{$order->id}/split-by-items/preview");

    $response->assertOk()
        ->assertJsonPath('data.order_id', $order->id)
        ->assertJsonPath('data.total_amount', '2000.00')
        ->assertJsonCount(2, 'data.items');
});

it('Customer preview returns 404 for missing order', function () {
    $bogus = (string) Str::uuid();

    $response = $this->getJson("/api/v1/customer/orders/{$bogus}/split-by-items/preview");

    $response->assertStatus(404);
});

it('Customer preview drops preview_bills when allocations query exceeds 4 KB', function () {
    $order = sbipSeedOrder(1);
    $bigPayload = str_repeat('x', 5000);

    $response = $this->getJson("/api/v1/customer/orders/{$order->id}/split-by-items/preview?allocations={$bigPayload}");

    $response->assertOk()->assertJsonMissingPath('data.preview_bills');
});
