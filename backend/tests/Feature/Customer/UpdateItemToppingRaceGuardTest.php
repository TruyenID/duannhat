<?php

/**
 * plan-016 · #615 — TOCTOU race between the pending-status gate and the
 * atomic topping replace in CustomerOrderService::updateItem.
 *
 * The pending-only gate runs on a non-locked read BEFORE the DB::transaction
 * opens. If a KDS/workstation fire flips the line (pending→preparing) between
 * the gate and the mutation, an unfixed service still deletes+re-inserts the
 * OrderItemTopping rows and recomputes the bill on a line the kitchen already
 * owns — kitchen ticket shows old toppings, invoice charges new toppings.
 *
 * This test simulates that exact interleave with a one-shot `retrieved`
 * listener that flips the row to `preparing` right after the non-locking gate
 * read but before the transaction's SELECT … FOR UPDATE re-read. The fix
 * re-locks + re-asserts Pending inside the transaction, so the edit must be
 * rejected (409) and the persisted toppings left untouched.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\OrderItemTopping;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Models\Zone;
use App\Services\Customer\CustomerOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        'qr_token' => 'topping-race-token',
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

    $toppingProduct = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->toppingSku = ProductSku::factory()->create([
        'product_id' => $toppingProduct->id,
        'is_active' => true,
        'selling_price' => 50,
    ]);
    $this->toppingItem = ToppingGroupItem::factory()->create([
        'topping_group_id' => $this->toppingGroup->id,
        'product_id' => $toppingProduct->id,
        'is_default' => false,
        'sort_order' => 0,
    ]);
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $this->toppingItem->id,
        'product_sku_id' => $this->toppingSku->id,
        'extra_price' => 50,
    ]);

    $this->product->toppingGroups()->attach($this->toppingGroup->id, ['sort_order' => 0]);
});

it('rejects a pending topping edit when the line is fired concurrently (TOCTOU)', function () {
    // Create an open order with one pending line that already carries a
    // priced topping (the row the kitchen ticket depends on).
    $this->postJson('/api/v1/customer/tables/topping-race-token/orders', [
        'items' => [[
            'product_sku_id' => $this->sku->id,
            'quantity' => 1,
            'toppings' => [[
                'topping_group_item_id' => $this->toppingItem->id,
                'product_sku_id' => $this->toppingSku->id,
                'quantity' => 1,
            ]],
        ]],
    ])->assertStatus(201);

    $order = CustomerOrder::where('branch_id', $this->branch->id)->latest()->first();
    $item = $order->items()->first();

    expect($item->status->value)->toBe('pending');
    expect(OrderItemTopping::where('customer_order_item_id', $item->id)->count())->toBe(1);

    // Simulate the concurrent KDS fire: the FIRST time updateItem retrieves
    // this line (the non-locking gate read) we flip the persisted row to
    // `preparing`, mimicking another connection committing pending→preparing
    // between the gate and the transaction's locked re-read.
    $flipped = false;
    $itemId = (string) $item->id;
    CustomerOrderItem::retrieved(function (CustomerOrderItem $m) use (&$flipped, $itemId) {
        if (! $flipped && (string) $m->id === $itemId) {
            $flipped = true;
            DB::table('customer_order_items')->where('id', $itemId)->update(['status' => 'preparing']);
        }
    });

    $service = app(CustomerOrderService::class);

    // The edit must be rejected — the kitchen owns the line now.
    expect(fn () => $service->updateItem($order->fresh(), $itemId, ['toppings' => []]))
        ->toThrow(HttpException::class);

    // And the persisted toppings must be untouched: no delete, no re-insert,
    // no bill recompute on a kitchen-owned line.
    expect(OrderItemTopping::where('customer_order_item_id', $itemId)->count())->toBe(1);
});
