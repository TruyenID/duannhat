<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\OrderItemTopping;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Omnify\Enums\OrderItemStatusEnum;
use Illuminate\Support\Str;

/**
 * #825 regression — the three LAN item-mutation routes (delete / void / update).
 *
 * `items/{item}/delete` was the one workstation item route with no test, and it
 * 500'd on every call: deleteItem called $item->trashed() on CustomerOrderItem,
 * which has no SoftDeletes trait (and the table has no deleted_at column). The
 * LAN dropped the line, Cloud kept it, and the sync outbox retried forever.
 *
 * Removal is a VOID here, matching CustomerOrderService::removeItem() and every
 * other namespace — the pricing engine excludes lines by status, not deleted_at.
 *
 * All three routes are idempotent replay sinks for the workstation outbox (see
 * the OrderLifecycleController docblock), so each one is asserted twice.
 *
 * Fixture: bentō ¥1000 (軽減 8% takeaway) + beer ¥500 (標準 10%, alcohol).
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
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'currency_code' => 'JPY',
        'service_charge_rate' => 0,
        'prices_include_tax' => false,
        'service_charge_tax_rate' => 0,
    ]);

    $this->std = TaxType::factory()->standard()->asDefault()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
    ]);
    $this->red = TaxType::factory()->reduced()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
    ]);

    $this->menu = Menu::factory()->create([
        'organization_id' => Organization::where('console_organization_id', $this->orgId)->value('id'),
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
    ]);

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation', 'status' => 'active', 'device_token' => $this->wsToken,
        'organization_id' => Organization::where('console_organization_id', $this->orgId)->value('id'),
        'branch_id' => $this->branch->id,
    ]);
});

function mutationHeaders(?string $token = null): array
{
    return ['Authorization' => 'Bearer '.($token ?? test()->wsToken)];
}

/** Build a menu SKU with a specific tax type + alcohol flag. */
function mutationSku(float $price, TaxType $type): ProductSku
{
    $sku = ProductSku::factory()->create(['selling_price' => $price]);
    $sku->product->update([
        'tax_type_id' => $type->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
    $mp = MenuProduct::factory()->create([
        'menu_id' => test()->menu->id, 'product_id' => $sku->product_id, 'is_active' => true,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $mp->id, 'product_sku_id' => $sku->id, 'is_active' => true, 'selling_price' => $price,
    ]);

    return $sku;
}

function mutationOrder(string $type = 'takeaway'): CustomerOrder
{
    return CustomerOrder::create([
        'order_code' => 'WS-'.Str::random(5), 'order_type' => $type, 'status' => 'open',
        'subtotal' => 0, 'discount_amount' => 0, 'service_charge' => 0, 'tax_amount' => 0,
        'total_amount' => 0, 'paid_amount' => 0, 'total_tip' => 0, 'opened_at' => now(),
        'branch_id' => test()->branch->id, 'brand_id' => test()->brand->id, 'organization_id' => test()->orgId,
    ]);
}

function mutationLine(CustomerOrder $o, ProductSku $sku, int $qty = 1): string
{
    $id = (string) Str::uuid();
    test()->withHeaders(mutationHeaders())
        ->postJson("/api/v1/workstation/orders/{$o->id}/items", [
            'items' => [['id' => $id, 'product_sku_id' => $sku->id, 'quantity' => $qty]],
        ])->assertOk();

    return $id;
}

/** A device paired to a DIFFERENT branch in the same org — assertSameBranch must 404 it. */
function foreignBranchToken(): string
{
    $other = Branch::factory()->create([
        'console_organization_id' => test()->orgId,
        'console_brand_id' => test()->brand->console_brand_id,
        'is_active' => true,
    ]);
    $token = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation', 'status' => 'active', 'device_token' => $token,
        'organization_id' => Organization::where('console_organization_id', test()->orgId)->value('id'),
        'branch_id' => $other->id,
    ]);

    return $token;
}

// ───────────────────────────── deleteItem ─────────────────────────────

it('#825 — deleteItem returns 200 and voids the line instead of 500ing', function () {
    $bento = mutationSku(1000, $this->red);
    $o = mutationOrder('takeaway');
    $ib = mutationLine($o, $bento);

    $this->withHeaders(mutationHeaders())
        ->postJson("/api/v1/workstation/orders/{$o->id}/items/{$ib}/delete", [])
        ->assertOk();

    // The row survives — removal is a void, so the line stays on the bill with a
    // marker and keeps its audit trail. The reason distinguishes it from a void.
    $item = CustomerOrderItem::find($ib);
    expect($item)->not->toBeNull()
        ->and($item->status->value)->toBe(OrderItemStatusEnum::Voided->value)
        ->and($item->voided_at)->not->toBeNull()
        ->and($item->void_reason)->toBe('deleted_by_workstation');
});

it('#825 — deleteItem drops the line out of the order money fields', function () {
    $bento = mutationSku(1000, $this->red);            // 軽減 8%
    $beer = mutationSku(500, $this->std); // 標準 10%
    $o = mutationOrder('takeaway');
    mutationLine($o, $bento);
    $ir = mutationLine($o, $beer);
    // Both lines: tax (1000×8%) + (500×10%) = 80 + 50 = 130; total 1630.
    expect((float) $o->fresh()->total_amount)->toBe(1630.0);

    $this->withHeaders(mutationHeaders())
        ->postJson("/api/v1/workstation/orders/{$o->id}/items/{$ir}/delete", [])
        ->assertOk();

    // Beer's rate group vanishes: tax 80, total 1080 — matching the void path.
    $o->refresh();
    expect((float) $o->subtotal)->toBe(1000.0)
        ->and((float) $o->tax_amount)->toBe(80.0)
        ->and((float) $o->total_amount)->toBe(1080.0);
});

it('#825 — a replayed deleteItem is a no-op, not a second void', function () {
    $bento = mutationSku(1000, $this->red);
    $beer = mutationSku(500, $this->std);
    $o = mutationOrder('takeaway');
    mutationLine($o, $bento);
    $ir = mutationLine($o, $beer);

    $this->withHeaders(mutationHeaders())
        ->postJson("/api/v1/workstation/orders/{$o->id}/items/{$ir}/delete", [])
        ->assertOk();
    $firstVoidedAt = CustomerOrderItem::find($ir)->voided_at;

    // The outbox retries; the second call must not shrink the total again.
    $this->withHeaders(mutationHeaders())
        ->postJson("/api/v1/workstation/orders/{$o->id}/items/{$ir}/delete", [])
        ->assertOk();

    $o->refresh();
    expect((float) $o->total_amount)->toBe(1080.0)
        ->and(CustomerOrderItem::find($ir)->voided_at->eq($firstVoidedAt))->toBeTrue();
});

it('#825 — deleteItem 404s when the item belongs to another order', function () {
    $bento = mutationSku(1000, $this->red);
    $mine = mutationOrder('takeaway');
    $other = mutationOrder('takeaway');
    $ib = mutationLine($mine, $bento);

    $this->withHeaders(mutationHeaders())
        ->postJson("/api/v1/workstation/orders/{$other->id}/items/{$ib}/delete", [])
        ->assertNotFound();

    expect(CustomerOrderItem::find($ib)->status->value)->toBe(OrderItemStatusEnum::Pending->value);
});

it('#825 — deleteItem 404s for a device paired to another branch', function () {
    $bento = mutationSku(1000, $this->red);
    $o = mutationOrder('takeaway');
    $ib = mutationLine($o, $bento);

    $this->withHeaders(mutationHeaders(foreignBranchToken()))
        ->postJson("/api/v1/workstation/orders/{$o->id}/items/{$ib}/delete", [])
        ->assertNotFound();

    expect(CustomerOrderItem::find($ib)->status->value)->toBe(OrderItemStatusEnum::Pending->value);
});

// ───────────────────────────── voidItem ─────────────────────────────

it('voidItem persists an explicit reason and re-derives the total', function () {
    $bento = mutationSku(1000, $this->red);
    $beer = mutationSku(500, $this->std);
    $o = mutationOrder('takeaway');
    mutationLine($o, $bento);
    $ir = mutationLine($o, $beer);

    $this->withHeaders(mutationHeaders())
        ->postJson("/api/v1/workstation/orders/{$o->id}/items/{$ir}/void", [
            'void_reason' => 'Customer changed their mind',
        ])->assertOk();

    $item = CustomerOrderItem::find($ir);
    expect($item->status->value)->toBe(OrderItemStatusEnum::Voided->value)
        ->and($item->void_reason)->toBe('Customer changed their mind')
        ->and((float) $o->fresh()->total_amount)->toBe(1080.0);
});

it('voidItem falls back to the workstation reason when none is sent', function () {
    $bento = mutationSku(1000, $this->red);
    $o = mutationOrder('takeaway');
    $ib = mutationLine($o, $bento);

    $this->withHeaders(mutationHeaders())
        ->postJson("/api/v1/workstation/orders/{$o->id}/items/{$ib}/void", [])
        ->assertOk();

    expect(CustomerOrderItem::find($ib)->void_reason)->toBe('voided_by_workstation');
});

it('a replayed voidItem is a no-op and keeps the first reason', function () {
    $bento = mutationSku(1000, $this->red);
    $beer = mutationSku(500, $this->std);
    $o = mutationOrder('takeaway');
    mutationLine($o, $bento);
    $ir = mutationLine($o, $beer);

    $this->withHeaders(mutationHeaders())
        ->postJson("/api/v1/workstation/orders/{$o->id}/items/{$ir}/void", ['void_reason' => 'first'])
        ->assertOk();
    $this->withHeaders(mutationHeaders())
        ->postJson("/api/v1/workstation/orders/{$o->id}/items/{$ir}/void", ['void_reason' => 'second'])
        ->assertOk();

    $o->refresh();
    expect(CustomerOrderItem::find($ir)->void_reason)->toBe('first')
        ->and((float) $o->total_amount)->toBe(1080.0);
});

it('voidItem 404s for a device paired to another branch', function () {
    $bento = mutationSku(1000, $this->red);
    $o = mutationOrder('takeaway');
    $ib = mutationLine($o, $bento);

    $this->withHeaders(mutationHeaders(foreignBranchToken()))
        ->postJson("/api/v1/workstation/orders/{$o->id}/items/{$ib}/void", [])
        ->assertNotFound();

    expect(CustomerOrderItem::find($ib)->status->value)->toBe(OrderItemStatusEnum::Pending->value);
});

// ───────────────────────────── updateItem ─────────────────────────────

it('updateItem recomputes the line subtotal and the order money fields', function () {
    $bento = mutationSku(1000, $this->red);
    $o = mutationOrder('takeaway');
    $ib = mutationLine($o, $bento);

    $this->withHeaders(mutationHeaders())
        ->postJson("/api/v1/workstation/orders/{$o->id}/items/{$ib}", ['quantity' => 3])
        ->assertOk();

    $item = CustomerOrderItem::find($ib);
    expect((int) $item->quantity)->toBe(3)
        ->and((float) $item->subtotal)->toBe(3000.0)
        ->and((float) $o->fresh()->subtotal)->toBe(3000.0)
        ->and((float) $o->fresh()->total_amount)->toBe(3240.0); // +8%
});

it('updateItem still replays toppings and note (SKU edit is banned, #1148)', function () {
    $regular = mutationSku(1000, $this->red);
    $menuProduct = MenuProduct::where('menu_id', $this->menu->id)
        ->where('product_id', $regular->product_id)
        ->firstOrFail();
    $large = ProductSku::factory()->withSequencedOption()->create([
        'product_id' => $regular->product_id,
        'is_active' => true,
        'selling_price' => 1600,
    ]);
    // Creating the SKU already put the row on this menu (#2537), inactive and
    // unpriced-by-the-shop; enabling it is what the shop would do next.
    $largeMenuSku = MenuProductSku::where('menu_product_id', $menuProduct->id)
        ->where('product_sku_id', $large->id)
        ->firstOrFail();
    $largeMenuSku->update([
        'is_active' => true,
        'selling_price' => 1600,
    ]);

    $group = ToppingGroup::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'is_active' => true,
        'min_select' => 0,
        'modifier_type' => 'add',
        'selection_type' => 'multiple',
        'price_strategy' => 'flat',
    ]);
    Product::findOrFail($regular->product_id)->toppingGroups()->attach($group->id, ['sort_order' => 0]);
    $toppingProduct = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $toppingSku = ProductSku::factory()->create([
        'product_id' => $toppingProduct->id,
        'is_active' => true,
        'selling_price' => 50,
    ]);
    $toppingItem = ToppingGroupItem::factory()->create([
        'topping_group_id' => $group->id,
        'product_id' => $toppingProduct->id,
    ]);
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $toppingItem->id,
        'product_sku_id' => $toppingSku->id,
        'extra_price' => 50,
    ]);

    $o = mutationOrder('takeaway');
    $itemId = mutationLine($o, $regular, 2);

    $this->withHeaders(mutationHeaders())
        ->postJson("/api/v1/workstation/orders/{$o->id}/items/{$itemId}", [
            'note' => 'extra hot',
            'toppings' => [[
                'topping_group_item_id' => $toppingItem->id,
                'product_sku_id' => $toppingSku->id,
                'quantity' => 1,
            ]],
        ])->assertOk();

    $item = CustomerOrderItem::findOrFail($itemId);
    expect((string) $item->product_sku_id)->toBe((string) $regular->id)
        ->and((float) $item->unit_price)->toBe(1000.0)
        ->and((float) $item->topping_subtotal)->toBe(50.0)
        ->and((float) $item->subtotal)->toBe(2100.0)
        ->and($item->note)->toBe('extra hot')
        ->and(OrderItemTopping::where('customer_order_item_id', $itemId)->count())->toBe(1)
        ->and((float) $o->fresh()->subtotal)->toBe(2100.0);
});

it('#1148 — a SKU swap is REJECTED outright: void + re-add is the only path', function () {
    $hot = mutationSku(1000, $this->red);
    $iced = ProductSku::factory()->withSequencedOption()->create([
        'product_id' => $hot->product_id,
        'is_active' => true,
        'selling_price' => 1000,
    ]);

    $o = mutationOrder('takeaway');
    $itemId = mutationLine($o, $hot);

    $this->withHeaders(mutationHeaders())
        ->postJson("/api/v1/workstation/orders/{$o->id}/items/{$itemId}", [
            'product_sku_id' => $iced->id,
        ])->assertStatus(409);

    // The line is untouched — sku, price, tax snapshot all intact.
    $item = CustomerOrderItem::findOrFail($itemId);
    expect((string) $item->product_sku_id)->toBe((string) $hot->id)
        ->and((float) $item->unit_price)->toBe(1000.0);
});

it('updateItem 404s when the item belongs to another order', function () {
    $bento = mutationSku(1000, $this->red);
    $mine = mutationOrder('takeaway');
    $other = mutationOrder('takeaway');
    $ib = mutationLine($mine, $bento);

    $this->withHeaders(mutationHeaders())
        ->postJson("/api/v1/workstation/orders/{$other->id}/items/{$ib}", ['quantity' => 3])
        ->assertNotFound();

    expect((int) CustomerOrderItem::find($ib)->quantity)->toBe(1);
});

it('updateItem 404s for a device paired to another branch', function () {
    $bento = mutationSku(1000, $this->red);
    $o = mutationOrder('takeaway');
    $ib = mutationLine($o, $bento);

    $this->withHeaders(mutationHeaders(foreignBranchToken()))
        ->postJson("/api/v1/workstation/orders/{$o->id}/items/{$ib}", ['quantity' => 3])
        ->assertNotFound();

    expect((int) CustomerOrderItem::find($ib)->quantity)->toBe(1);
});
