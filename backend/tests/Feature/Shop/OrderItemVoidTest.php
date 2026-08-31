<?php

use App\Events\OrderVoided;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\ShopOrderSetting;
use App\Models\Table;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Zone;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'item-void-shop',
        'is_active' => true,
    ]);

    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($this->managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    $this->customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'first_name' => 'Item',
        'last_name' => 'Void',
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
        'is_active' => true,
    ]);

    $zone = Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
    ]);

    $this->table = Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create([
        'organization_id' => $this->orgId,
    ]);

    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);

    $this->cashMethod = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);
});

// =========================================================================
//  Void pending item — success
// =========================================================================

it('voiding a pending item returns 200 and sets item status to voided', function () {
    $order = createItemVoidOrder('pending');
    $item = $order->items->first();

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}/void", [
            'void_reason' => 'Customer changed mind',
        ])
        ->assertOk();

    $voidedItem = collect($response->json('data.items'))->firstWhere('id', $item->id);
    expect($voidedItem['status'])->toBe('voided');
    expect($item->fresh()->status->value)->toBe('voided');
});

it('voiding is soft — row persists with voided_at + void_reason, row count unchanged', function () {
    $order = createItemVoidOrder('pending');
    $item = $order->items->first();
    $beforeCount = CustomerOrderItem::where('customer_order_id', $order->id)->count();

    $response = $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}/void", [
            'void_reason' => 'Khách đổi món',
        ])
        ->assertOk();

    // Row count unchanged — this is a soft-void, not a DELETE.
    $afterCount = CustomerOrderItem::where('customer_order_id', $order->id)->count();
    expect($afterCount)->toBe($beforeCount);

    // Row persists with the full void payload for audit.
    $fresh = $item->fresh();
    expect($fresh->status->value)->toBe('voided');
    expect($fresh->voided_at)->not->toBeNull();
    expect($fresh->void_reason)->toBe('Khách đổi món');

    // Voided item still appears in the response data.items (FE needs it
    // for the soft-void audit toggle).
    $idsInResponse = collect($response->json('data.items'))->pluck('id')->all();
    expect($idsInResponse)->toContain($item->id);

    // Order subtotal excludes the voided item (recalculateTotals filter).
    expect((float) $response->json('data.subtotal'))->toBe(0.0);
});

// =========================================================================
//  #559 — voiding the LAST active item empties the order → free the table
// =========================================================================

it('voiding the last active item voids the order and frees the table', function () {
    $order = createItemVoidOrder('pending'); // single item + table occupied
    $item = $order->items->first();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}/void", [
            'void_reason' => 'Khách huỷ hết món',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'voided');

    // Order is now voided; the table it held is free again.
    expect($order->fresh()->status->value)->toBe('voided');
    $table = $this->table->fresh();
    expect($table->status->value)->toBe('free');
    expect($table->current_order_id)->toBeNull();
});

it('voiding the last active item broadcasts OrderVoided with the freed table', function () {
    Event::fake([OrderVoided::class]);

    $order = createItemVoidOrder('pending');
    $item = $order->items->first();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}/void", [
            'void_reason' => 'Broadcast test',
        ])
        ->assertOk();

    Event::assertDispatched(
        OrderVoided::class,
        fn (OrderVoided $e) => $e->order->id === $order->id
            && in_array((string) $this->table->id, $e->releasedTableIds, true),
    );
});

it('voiding one of several active items keeps the order open and table occupied', function () {
    $order = createItemVoidOrder('pending');
    // Add a second pending item so the order is NOT emptied by one void.
    $second = $order->items()->create([
        'product_sku_id' => $this->sku->id,
        'quantity' => 1,
        'unit_price' => 500,
        'original_unit_price' => 500,
        'subtotal' => 500,
        'status' => 'pending',
        'tax_rate' => 0,
    ]);
    $first = $order->items()->where('id', '!=', $second->id)->first();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$first->id}/void", [
            'void_reason' => 'Chỉ huỷ 1 món',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'open');

    expect($order->fresh()->status->value)->toBe('open');
    $table = $this->table->fresh();
    expect($table->status->value)->toBe('occupied');
    expect($table->current_order_id)->toBe($order->id);
});

// =========================================================================
//  Void non-pending items — 409
// =========================================================================

it('voiding a preparing item returns 409', function () {
    $order = createItemVoidOrder('preparing');
    $item = $order->items->first();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}/void", [
            'void_reason' => 'Test',
        ])
        ->assertStatus(409);
});

it('voiding a ready item returns 409', function () {
    $order = createItemVoidOrder('ready');
    $item = $order->items->first();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}/void", [
            'void_reason' => 'Test',
        ])
        ->assertStatus(409);
});

it('voiding a served item returns 409', function () {
    $order = createItemVoidOrder('served');
    $item = $order->items->first();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}/void", [
            'void_reason' => 'Test',
        ])
        ->assertStatus(409);
});

it('voiding an already voided item returns 409', function () {
    $order = createItemVoidOrder('voided');
    $item = $order->items->first();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}/void", [
            'void_reason' => 'Double void attempt',
        ])
        ->assertStatus(409);
});

// =========================================================================
//  allow_item_edit_any_status — void a non-pending item when the branch
//  opts in. Mirrors the workstation gate; the order must still be open.
// =========================================================================

function setAllowItemEditAnyStatus(bool $on): void
{
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => test()->shop->id],
        ['organization_id' => test()->orgId, 'allow_item_edit_any_status' => $on],
    );
}

it('voids a preparing item when any-status editing is ON', function () {
    setAllowItemEditAnyStatus(true);
    $order = createItemVoidOrder('preparing');
    $item = $order->items->first();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}/void", [
            'void_reason' => 'Any-status void (preparing)',
        ])
        ->assertOk();

    expect($item->fresh()->status->value)->toBe('voided');
});

it('voids a served item when any-status editing is ON', function () {
    setAllowItemEditAnyStatus(true);
    $order = createItemVoidOrder('served');
    $item = $order->items->first();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}/void", [
            'void_reason' => 'Any-status void (served)',
        ])
        ->assertOk();

    expect($item->fresh()->status->value)->toBe('voided');
});

it('#1148 — flag ON never unlocks EDITING a cooked line: qty patch on preparing still 409s', function () {
    setAllowItemEditAnyStatus(true);
    // The Revise path prices the line BEFORE the pending gate — the shared
    // fixture product defaults to draft, which would 500 on the resolver's
    // sellable check and mask the 409 under test.
    $this->sku->product()->update(['status' => 'active', 'is_hidden' => false]);
    $order = createItemVoidOrder('preparing');
    $item = $order->items->first();

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}", [
            'quantity' => 5,
        ])
        ->assertStatus(409);

    expect((int) $item->fresh()->quantity)->not->toBe(5);
});

it('#1148 — reasonless DELETE cannot remove a cooked line even with the flag ON (junk reason rejected)', function () {
    setAllowItemEditAnyStatus(true);
    $order = createItemVoidOrder('preparing');
    $item = $order->items->first();

    // removeItem delegates to voidItem with the junk 'Removed by staff'
    // reason — a prepared dish demands a real operator-entered reason.
    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}")
        ->assertStatus(422);

    expect($item->fresh()->status->value)->toBe('preparing');
});

it('still 409s voiding a preparing item when any-status editing is OFF (default)', function () {
    setAllowItemEditAnyStatus(false);
    $order = createItemVoidOrder('preparing');
    $item = $order->items->first();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}/void", [
            'void_reason' => 'Should be blocked',
        ])
        ->assertStatus(409);
});

it('still 409s voiding a served item on a non-open order even when ON (order gate stays)', function () {
    setAllowItemEditAnyStatus(true);
    $order = createItemVoidOrder('served');
    $order->update(['status' => 'checkout']); // order no longer open
    $item = $order->items->first();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}/void", [
            'void_reason' => 'Order locked',
        ])
        ->assertStatus(409);
});

// =========================================================================
//  `confirmed` orders (counter-pay takeaway from customer-web) — item
//  mutations are allowed at the counter before payment, mirroring the
//  addItems gate. Pinned so the POS "adjust the cart on a confirmed
//  order" flow can't regress to 409.
// =========================================================================

it('voids a pending item on a confirmed order — and the order stays confirmed (no #559 cascade)', function () {
    $order = createItemVoidOrder('pending');
    $order->update(['status' => 'confirmed']);
    $item = $order->items->first();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}/void", [
            'void_reason' => 'Customer changed mind at the counter',
        ])
        ->assertOk();

    expect($item->fresh()->status->value)->toBe('voided');
    // The empty-order auto-void cascade is scoped to Open orders — a
    // confirmed order keeps its status even when the last line is voided.
    expect($order->fresh()->status->value)->toBe('confirmed');
});

it('edits quantity of a pending item on a confirmed order', function () {
    // The Revise path prices the line before the gates — the shared fixture
    // product defaults to draft, which would 500 on the sellable check.
    $this->sku->product()->update(['status' => 'active', 'is_hidden' => false]);
    $order = createItemVoidOrder('pending');
    $order->update(['status' => 'confirmed']);
    $item = $order->items->first();

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}", [
            'quantity' => 5,
        ])
        ->assertOk();

    expect((int) $item->fresh()->quantity)->toBe(5);
});

it('adds items to a confirmed order', function () {
    // addItems resolves the SKU through the sellable check — the shared
    // fixture product defaults to draft, which would 500 before the gate.
    $this->sku->product()->update(['status' => 'active', 'is_hidden' => false]);
    $order = createItemVoidOrder('pending');
    $order->update(['status' => 'confirmed']);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items", [
            'items' => [
                ['product_sku_id' => $this->sku->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated();

    expect($order->fresh()->items()->count())->toBeGreaterThan(1);
});

it('DELETE removes a pending line on a confirmed order (delete = void with default reason)', function () {
    $order = createItemVoidOrder('pending');
    $order->update(['status' => 'confirmed']);
    $item = $order->items->first();

    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}")
        ->assertOk();

    expect($item->fresh()->status->value)->toBe('voided');
});

it('still 409s editing a non-pending item on a confirmed order (#1148 pending gate holds)', function () {
    $this->sku->product()->update(['status' => 'active', 'is_hidden' => false]);
    $order = createItemVoidOrder('preparing');
    $order->update(['status' => 'confirmed']);
    $item = $order->items->first();

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}", [
            'quantity' => 5,
        ])
        ->assertStatus(409);
});

// =========================================================================
//  Void after checkout — 409 (order locked)
// =========================================================================

it('voiding an item after the order is in checkout status returns 409', function () {
    $order = createItemVoidOrder('pending');
    $item = $order->items->first();

    // Move the order to checkout status
    $order->update(['status' => 'checkout']);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items/{$item->id}/void", [
            'void_reason' => 'Too late',
        ])
        ->assertStatus(409);
});

// =========================================================================
//  Helper
// =========================================================================

/**
 * Create an open order whose single item has the given status.
 */
function createItemVoidOrder(string $itemStatus): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-IV-'.Str::random(4),
        'order_type' => 'dine_in',
        'status' => 'open',
        'opened_at' => now(),
        'subtotal' => 1000,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 1000,
        'paid_amount' => 0,
        'total_tip' => 0,
        'created_by_id' => test()->manager->id,
        'customer_id' => test()->customer->id,
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);

    Table::where('id', test()->table->id)->update([
        'current_order_id' => $order->id,
        'status' => 'occupied',
    ]);

    $itemData = [
        'product_sku_id' => test()->sku->id,
        'quantity' => 2,
        'unit_price' => 500,
        'original_unit_price' => 500,
        'subtotal' => 1000,
        'status' => $itemStatus,
        // #2411 — đơn dựng tay ở đây khai `tax_amount => 0`, nên 0% là tỉ lệ
        // duy nhất khớp với số tiền của chính nó. Helper này là hàm toàn cục
        // của Pest: nhiều file test khác cũng gọi nó.
        'tax_rate' => 0,
    ];

    if ($itemStatus === 'voided') {
        $itemData['voided_at'] = now();
        $itemData['void_reason'] = 'Pre-voided for test';
    }

    if ($itemStatus === 'served') {
        $itemData['served_at'] = now();
    }

    $order->items()->create($itemData);

    return $order->load('items');
}
