<?php

/**
 * plan-051 T3.1 (#1149) — per-status void matrix + VoidReason validation on
 * the POS item-void surface. The legacy-flag fallback paths (null matrix +
 * allow_item_edit_any_status true/false) stay pinned by OrderItemVoidTest;
 * this file covers the NEW matrix + structured-reason gates:
 *
 *   - matrix ["pending","preparing"]: preparing voids (with a real reason),
 *     ready → 409 ITEM_STATUS_NOT_VOIDABLE.
 *   - pending is a HARD union — voidable even with an empty matrix.
 *   - void_reason_id of another brand / inactive → 422 VOID_REASON_INVALID.
 *   - requires_note reason without a note → 422 VOID_NOTE_REQUIRED.
 *   - junk text without an id on a non-pending line → 422 (#1148 pin).
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\ShopOrderSetting;
use App\Models\User;
use App\Models\VoidReason;
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
        'slug' => 'void-matrix-shop',
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($role, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

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
});

function vmxMatrix(?array $statuses): void
{
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => test()->shop->id],
        ['organization_id' => test()->orgId, 'item_voidable_statuses' => $statuses],
    );
}

function vmxOrder(string $itemStatus): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-VMX-'.Str::random(4),
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
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);

    $order->items()->create([
        'product_sku_id' => test()->sku->id,
        'quantity' => 2,
        'unit_price' => 500,
        'original_unit_price' => 500,
        'subtotal' => 1000,
        'status' => $itemStatus,
        'served_at' => $itemStatus === 'served' ? now() : null,
        'tax_rate' => 0,
    ]);

    return $order->load('items');
}

function vmxReason(array $overrides = [], string $label = 'Bấm nhầm'): VoidReason
{
    return VoidReason::create(array_merge([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'stock_effect' => 'restock',
        'requires_note' => false,
        'is_active' => true,
        'sort_order' => 0,
    ], $overrides, [
        'en' => ['label' => $label],
    ]));
}

function vmxVoid(CustomerOrder $order, array $payload)
{
    $item = $order->items->first();

    return test()->actingAs(test()->manager)
        ->postJson('/api/v1/shops/'.test()->shop->slug."/orders/{$order->id}/items/{$item->id}/void", $payload);
}

// =========================================================================
//  Matrix
// =========================================================================

it('matrix ["pending","preparing"]: voids preparing with a real reason, 409s ready with ITEM_STATUS_NOT_VOIDABLE', function () {
    vmxMatrix(['pending', 'preparing']);

    vmxVoid(vmxOrder('preparing'), ['void_reason' => 'Khách đổi ý trước khi nấu'])
        ->assertOk();

    vmxVoid(vmxOrder('ready'), ['void_reason' => 'Real reason'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'ITEM_STATUS_NOT_VOIDABLE')
        ->assertJsonPath('item_status', 'ready');
});

it('pending stays voidable even when the stored matrix is empty (hard union)', function () {
    vmxMatrix([]);

    vmxVoid(vmxOrder('pending'), ['void_reason' => 'Trim'])
        ->assertOk();
});

it('matrix garbage entries are ignored: ["voided", 42] resolves to pending-only', function () {
    vmxMatrix(['voided', 42]);

    vmxVoid(vmxOrder('served'), ['void_reason' => 'Real reason'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'ITEM_STATUS_NOT_VOIDABLE');
});

it('matrix with served: a served line voids with a picked reason', function () {
    vmxMatrix(['pending', 'served']);
    $reason = vmxReason();

    $order = vmxOrder('served');
    vmxVoid($order, ['void_reason_id' => $reason->id])->assertOk();

    $item = $order->items->first()->fresh();
    expect($item->status->value)->toBe('voided');
    expect($item->void_reason_id)->toBe($reason->id);
    expect($item->void_reason)->toBe('Bấm nhầm'); // label snapshot
});

// =========================================================================
//  Reason validation
// =========================================================================

it('void_reason_id of another brand → 422 VOID_REASON_INVALID', function () {
    vmxMatrix(['pending', 'preparing']);
    $foreign = VoidReason::create([
        'organization_id' => (string) Str::uuid(),
        'brand_id' => (string) Str::uuid(),
        'stock_effect' => 'restock',
        'requires_note' => false,
        'is_active' => true,
        'sort_order' => 0,
        'en' => ['label' => 'Foreign'],
    ]);

    vmxVoid(vmxOrder('preparing'), ['void_reason_id' => $foreign->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VOID_REASON_INVALID');
});

it('deactivated void_reason_id → 422 VOID_REASON_INVALID', function () {
    vmxMatrix(['pending', 'preparing']);
    $inactive = vmxReason(['is_active' => false]);

    vmxVoid(vmxOrder('preparing'), ['void_reason_id' => $inactive->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VOID_REASON_INVALID');
});

it('requires_note reason without a note → 422 VOID_NOTE_REQUIRED; with a note → 200 and "label: note" snapshot', function () {
    vmxMatrix(['pending', 'preparing']);
    $reason = vmxReason(['requires_note' => true], 'Khách đổi món');

    vmxVoid(vmxOrder('preparing'), ['void_reason_id' => $reason->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VOID_NOTE_REQUIRED');

    $order = vmxOrder('preparing');
    vmxVoid($order, ['void_reason_id' => $reason->id, 'void_reason' => 'sang phở bò'])
        ->assertOk();
    expect($order->items->first()->fresh()->void_reason)->toBe('Khách đổi món: sang phở bò');
});

it('#1148 pin: junk text without an id on a non-pending line → 422', function () {
    vmxMatrix(['pending', 'preparing', 'ready', 'served']);

    vmxVoid(vmxOrder('preparing'), ['void_reason' => 'Removed by staff'])
        ->assertStatus(422);
});

it('pending void still works with plain free text (no reason row required)', function () {
    vmxMatrix(['pending']);

    $order = vmxOrder('pending');
    vmxVoid($order, ['void_reason' => 'Khách đổi ý'])->assertOk();
    expect($order->items->first()->fresh()->void_reason)->toBe('Khách đổi ý');
});
