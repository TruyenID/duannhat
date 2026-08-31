<?php

/**
 * Plan-033 — server-side validation of OrderPayment.metadata.item_allocations.
 *
 * Covers the 4 structured 422 codes (split_by_items_unknown_item,
 * split_by_items_voided_item, split_by_items_double_claim,
 * split_by_items_total_mismatch) plus the 3 defensive bypass scenarios that
 * keep the customer-web Stripe path + refund flow + non-by-items payments
 * working without 422s.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
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
        'slug' => 'sbi-shop',
        'is_active' => true,
    ]);
    Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'is_active' => true,
        'auto_approve_stock_out' => true,
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

/**
 * Seed a checkout-status order with N items of qty=1 each priced at 1000.
 * total_amount = N * 1000 (no tax / discount / service to keep math trivial).
 */
function sbiSeedOrder(int $itemCount = 2): CustomerOrder
{
    $total = $itemCount * 1000;
    $order = CustomerOrder::create([
        'order_code' => 'ORD-SBI-'.Str::random(4),
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

it('returns 422 split_by_items_unknown_item when item_id is not in the order', function () {
    $order = sbiSeedOrder(1);
    $badId = (string) Str::uuid();

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'metadata' => [
                'split_mode' => 'by_items',
                'bill_index' => 0,
                'item_allocations' => [
                    ['item_id' => $badId, 'units' => 1],
                ],
            ],
        ]);

    // FormRequest catches the unknown UUID with `exists:` first → 422 with
    // Laravel envelope. Either path is a successful guard.
    $response->assertStatus(422);
});

it('returns 422 split_by_items_voided_item when allocation targets a voided item', function () {
    $order = sbiSeedOrder(2);
    $voided = $order->items->last();
    $voided->update(['status' => 'voided', 'voided_at' => now(), 'void_reason' => 'test']);
    $live = $order->items->first();

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'metadata' => [
                'split_mode' => 'by_items',
                'bill_index' => 0,
                'item_allocations' => [
                    ['item_id' => $live->id, 'units' => 1],
                    ['item_id' => $voided->id, 'units' => 1],
                ],
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'split_by_items_voided_item')
        ->assertJsonPath('item_id', $voided->id);
});

it('returns 422 split_by_items_double_claim when cumulative units exceed quantity', function () {
    $order = CustomerOrder::create([
        'order_code' => 'ORD-DC-'.Str::random(4),
        'order_type' => 'takeaway',
        'status' => 'checkout',
        'subtotal' => 2000, 'discount_amount' => 0, 'service_charge' => 0, 'tax_amount' => 0,
        'total_amount' => 2000, 'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(), 'checkout_at' => now(),
        'customer_id' => $this->customer->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    // qty 2, 1 unit @ 1000.
    $item = $order->items()->create([
        'product_sku_id' => $this->sku->id,
        'quantity' => 2, 'unit_price' => 1000, 'original_unit_price' => 1000, 'subtotal' => 2000,
        'status' => 'served', 'served_at' => now(),
        'tax_rate' => 0,
    ]);

    // Bill 0 claims 1 unit (success → paid).
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'metadata' => [
                'split_mode' => 'by_items',
                'bill_index' => 0,
                'item_allocations' => [['item_id' => $item->id, 'units' => 1]],
            ],
        ])
        ->assertCreated();

    // Bill 1 tries to claim 2 more units (total claimed would be 3 > qty 2).
    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 2000,
            'tendered_amount' => 2000,
            'metadata' => [
                'split_mode' => 'by_items',
                'bill_index' => 1,
                'item_allocations' => [['item_id' => $item->id, 'units' => 2]],
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'split_by_items_double_claim')
        ->assertJsonPath('item_id', $item->id)
        ->assertJsonPath('item_quantity', 2)
        ->assertJsonPath('claimed_units', 3);
});

it('returns 422 split_by_items_double_claim when allocation exceeds unrefunded units', function () {
    // #2180 — "một suất" của cổng thu tiền phải trừ refunded_quantity, cùng
    // nghĩa với SplitByItemsCalculator (#2159). Client cũ (tab chưa reload,
    // customer-web, gọi thẳng API) vẫn gửi đủ số suất GỐC — trước bản sửa,
    // tầng này nhận im lặng và chỉ còn cổng overpayment đứng chắn.
    $order = CustomerOrder::create([
        'order_code' => 'ORD-RF-'.Str::random(4),
        'order_type' => 'takeaway',
        'status' => 'checkout',
        'subtotal' => 2000, 'discount_amount' => 0, 'service_charge' => 0, 'tax_amount' => 0,
        'total_amount' => 1000, 'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(), 'checkout_at' => now(),
        'customer_id' => $this->customer->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    // qty 2 @1000, đã hoàn 1 ⇒ chỉ còn 1 suất chia được.
    $item = $order->items()->create([
        'product_sku_id' => $this->sku->id,
        'quantity' => 2, 'unit_price' => 1000, 'original_unit_price' => 1000, 'subtotal' => 2000,
        'refunded_quantity' => 1,
        'status' => 'served', 'served_at' => now(),
        'tax_rate' => 0,
    ]);

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 2000,
            'tendered_amount' => 2000,
            'metadata' => [
                'split_mode' => 'by_items',
                'bill_index' => 0,
                'item_allocations' => [['item_id' => $item->id, 'units' => 2]],
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'split_by_items_double_claim')
        ->assertJsonPath('item_id', $item->id)
        ->assertJsonPath('item_quantity', 1)
        ->assertJsonPath('claimed_units', 2);
});

it('returns 422 split_by_items_total_mismatch when amount disagrees with recomputed total', function () {
    $order = sbiSeedOrder(1);
    $item = $order->items->first();

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 500, // recomputed total is 1000 → 500 drift > tolerance.
            'tendered_amount' => 500,
            'metadata' => [
                'split_mode' => 'by_items',
                'bill_index' => 0,
                'item_allocations' => [['item_id' => $item->id, 'units' => 1]],
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'split_by_items_total_mismatch')
        ->assertJsonPath('expected_amount', '1000.00')
        ->assertJsonPath('given_amount', '500.00');
});

it('accepts a by-items payment whose amount matches recomputed total', function () {
    $order = sbiSeedOrder(2);
    [$itemA, $itemB] = [$order->items[0], $order->items[1]];

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

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'metadata' => [
                'split_mode' => 'by_items',
                'bill_index' => 1,
                'item_allocations' => [['item_id' => $itemB->id, 'units' => 1]],
            ],
        ])
        ->assertCreated();

    $order->refresh();
    expect((float) $order->paid_amount)->toBe(2000.0);
    expect($order->status->value ?? $order->status)->toBe('closed');
});

it('accepts the reconciled closing sub-check amount (last-bill drift parity with pos-web)', function () {
    // pos-web forwards the Σ-vs-order rounding drift onto the LAST non-empty
    // bill and tenders that reconciled figure as `amount`. For negative drift
    // the closing bill is smaller than its natural total, so the natural-only
    // total_mismatch check used to reject a perfectly legitimate payment.
    //
    //   order.total_amount = 4154, tax 10% + service 5%.
    //     bill 1 (natural) = 1658 + 166 + 83 = 1907
    //     bill 2 (natural) = 1955 + 196 + 98 = 2249
    //     Σ = 4156 → drift -2 → pos-web reconciles bill 2 down to 2247.
    $order = CustomerOrder::create([
        'order_code' => 'ORD-RECON-'.Str::random(4),
        'order_type' => 'takeaway',
        'status' => 'checkout',
        'subtotal' => 3613,
        'discount_amount' => 0,
        'service_charge' => 181,
        'tax_amount' => 360,
        'total_amount' => 4154,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'checkout_at' => now(),
        'customer_id' => $this->customer->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $itemA = $order->items()->create([
        'product_sku_id' => $this->sku->id,
        'quantity' => 1, 'unit_price' => 1658, 'original_unit_price' => 1658, 'subtotal' => 1658,
        // plan-043 — per-line snapshot rate; the split engine now taxes each line
        // from this (the flat branch tax_rate was dropped) so the recomputed bill
        // matches the 10% the order was checked out at.
        'tax_rate' => 10,
        'status' => 'served', 'served_at' => now(),
    ]);
    $itemB = $order->items()->create([
        'product_sku_id' => $this->sku->id,
        'quantity' => 1, 'unit_price' => 1955, 'original_unit_price' => 1955, 'subtotal' => 1955,
        'tax_rate' => 10,
        'status' => 'served', 'served_at' => now(),
    ]);
    $order->refresh();

    ShopOrderSetting::create([
        'branch_id' => $this->shop->id,
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'service_charge_rate' => 5,
        'currency_code' => 'JPY',
    ]);

    // Bill 1 — natural total 1907 (not the closing bill).
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1907,
            'tendered_amount' => 1907,
            'metadata' => [
                'split_mode' => 'by_items',
                'bill_index' => 0,
                'total_bills' => 2,
                'item_allocations' => [['item_id' => $itemA->id, 'units' => 1]],
            ],
        ])
        ->assertCreated();

    // Bill 2 — pos-web sends the RECONCILED 2247 (natural is 2249). This is the
    // closing sub-check: expected = order.total (4154) − prior paid (1907) = 2247.
    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 2247,
            'tendered_amount' => 2247,
            'metadata' => [
                'split_mode' => 'by_items',
                'bill_index' => 1,
                'total_bills' => 2,
                'item_allocations' => [['item_id' => $itemB->id, 'units' => 1]],
            ],
        ]);

    $response->assertCreated();
    expect((float) $response->json('data.amount'))->toBe(2247.0);

    $order->refresh();
    expect($order->status->value)->toBe('closed');
    expect((float) $order->paid_amount)->toBe(4154.0);
});

it('returns 422 split_by_items_mode_locked when order already paid with another split mode', function () {
    $order = sbiSeedOrder(2);
    $item = $order->items->first();

    // First payment uses the equal-split (non by-items) mode.
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'metadata' => [
                'split_mode' => 'equal',
                'bill_index' => 0,
                'total_bills' => 2,
            ],
        ])
        ->assertCreated();

    // A subsequent by-items payment must be locked.
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'metadata' => [
                'split_mode' => 'by_items',
                'bill_index' => 1,
                'item_allocations' => [['item_id' => $item->id, 'units' => 1]],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'split_by_items_mode_locked');
});

it('returns 422 split_by_items_mode_locked when order already paid in full (no split metadata)', function () {
    $order = sbiSeedOrder(2);
    $item = $order->items->first();

    // Pay-in-full path carries no split metadata at all.
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
        ])
        ->assertCreated();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'metadata' => [
                'split_mode' => 'by_items',
                'bill_index' => 1,
                'item_allocations' => [['item_id' => $item->id, 'units' => 1]],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'split_by_items_mode_locked');
});

it('allows a second by-items payment when the first was also by-items', function () {
    $order = sbiSeedOrder(2);
    [$itemA, $itemB] = [$order->items[0], $order->items[1]];

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

    // Same mode → not locked.
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'metadata' => [
                'split_mode' => 'by_items',
                'bill_index' => 1,
                'item_allocations' => [['item_id' => $itemB->id, 'units' => 1]],
            ],
        ])
        ->assertCreated();
});

it('bypasses validator when metadata.split_mode is not by_items', function () {
    $order = sbiSeedOrder(1);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'metadata' => [
                'split_mode' => 'equal',
                'bill_index' => 0,
                'total_bills' => 1,
            ],
        ])
        ->assertCreated();
});

it('bypasses validator when item_allocations is an empty array', function () {
    $order = sbiSeedOrder(1);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'metadata' => [
                'split_mode' => 'by_items',
                'bill_index' => 0,
                'item_allocations' => [], // empty
            ],
        ])
        ->assertCreated();
});

it('bypasses validator when metadata is absent (single-payment path)', function () {
    $order = sbiSeedOrder(1);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
        ])
        ->assertCreated();
});

it('rejects units=0 via the FormRequest shape validator (before service)', function () {
    $order = sbiSeedOrder(1);
    $item = $order->items->first();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'metadata' => [
                'split_mode' => 'by_items',
                'bill_index' => 0,
                'item_allocations' => [['item_id' => $item->id, 'units' => 0]],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['metadata.item_allocations.0.units']);
});

it('by_items overpay guard widens tolerance for per-bill tax/service rounding drift', function () {
    // POS-web computes each sub-check independently: bill.total = subtotal +
    // roundHalfUp(taxable * taxRate%) + roundHalfUp(taxable * serviceRate%).
    // With 2 bills the sum can drift from order.total_amount by a few units
    // even when every allocation is legitimate. A rigid 1-unit tolerance
    // used to reject the closing payment as "exceeds outstanding balance".
    //
    // Repro: order subtotal 3613, no discount, tax 10% + service 5%.
    //   bill 1 = 1658 + 166 + 83 = 1907
    //   bill 2 = 1955 + 196 + 98 = 2249
    //   Σ = 4156, but order.total_amount = 4154 (order-level rounded once).
    //   Overpay on the second Thu = 2, exceeds the old 1-unit tolerance.
    $order = CustomerOrder::create([
        'order_code' => 'ORD-DRIFT-'.Str::random(4),
        'order_type' => 'takeaway',
        'status' => 'checkout',
        'subtotal' => 3613,
        'discount_amount' => 0,
        'service_charge' => 181,
        'tax_amount' => 360,
        'total_amount' => 4154,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'checkout_at' => now(),
        'customer_id' => $this->customer->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    // plan-043 T6.2 — the 10% tax now rides on the per-line snapshot rate
    // (the flat branch tax_rate was dropped); service charge stays on config.
    $itemA = $order->items()->create([
        'product_sku_id' => $this->sku->id,
        'quantity' => 1, 'unit_price' => 1658, 'original_unit_price' => 1658, 'subtotal' => 1658,
        'tax_rate' => 10, 'status' => 'served', 'served_at' => now(),
    ]);
    $itemB = $order->items()->create([
        'product_sku_id' => $this->sku->id,
        'quantity' => 1, 'unit_price' => 1955, 'original_unit_price' => 1955, 'subtotal' => 1955,
        'tax_rate' => 10, 'status' => 'served', 'served_at' => now(),
    ]);
    $order->refresh();

    // Set the shop's service rate so the calculator computes the drifted
    // per-bill totals the guard will validate against.
    ShopOrderSetting::create([
        'branch_id' => $this->shop->id,
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'service_charge_rate' => 5,
        'currency_code' => 'JPY',
    ]);

    // First cashier pays bill 1 — 1907, exact match against BE compute.
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 1907,
            'tendered_amount' => 1907,
            'metadata' => [
                'split_mode' => 'by_items',
                'bill_index' => 0,
                'total_bills' => 2,
                'label' => 'Người 1',
                'item_allocations' => [['item_id' => $itemA->id, 'units' => 1]],
            ],
        ])
        ->assertCreated();

    // Second cashier pays bill 2 — 2249 (matches BE compute, but Σ 4156
    // exceeds order.total 4154 by 2 units). With the widened tolerance the
    // guard clamps to outstanding (2247) instead of rejecting.
    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 2249,
            'tendered_amount' => 2249,
            'metadata' => [
                'split_mode' => 'by_items',
                'bill_index' => 1,
                'total_bills' => 2,
                'label' => 'Người 2',
                'item_allocations' => [['item_id' => $itemB->id, 'units' => 1]],
            ],
        ])
        ->assertCreated();

    // The persisted amount is clamped to the exact outstanding.
    expect((float) $response->json('data.amount'))->toBe(2247.0);

    $order->refresh();
    expect($order->status->value)->toBe('closed');
    expect((float) $order->paid_amount)->toBe(4154.0);
});
