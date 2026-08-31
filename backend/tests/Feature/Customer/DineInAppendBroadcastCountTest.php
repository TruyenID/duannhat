<?php

/**
 * #2522 — how many `OrderItemAdded` a dine-in append broadcasts.
 *
 * The 人形町店 C-6 investigation found the event firing TWICE per add. It was
 * not a fixed doubling: `CustomerTableOrderService` called `changeItems()` once
 * per cart line — each firing its own event inside `WritesCustomerOrders` — and
 * then fired one more after the loop. A cart of N lines broadcast N+1 times.
 * The ×2 in the production logs was the N=1 case, the mildest one.
 *
 * A count is the only thing that catches a re-introduction, so it is asserted
 * directly rather than through "no duplicates" wording that a second emitter
 * would satisfy.
 */

use App\Events\OrderItemAdded;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\Zone;
use App\Services\Order\Commands\ChangeOrderItemsCommand;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Enums\OrderItemMutation;
use App\Services\Order\Internal\OrderMutationContextFactory;
use App\Services\Order\ValueObjects\OrderLineSelectionPayload;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses()->group('customer');

beforeEach(function () {
    $orgId = '00000000-0000-0000-0000-000000000001';

    $this->brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $this->zone = Zone::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $this->branch->id,
    ]);
    $this->table = Table::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $this->branch->id,
        'zone_id' => $this->zone->id,
        'qr_token' => 'append-count-token',
        'is_active' => true,
        'status' => 'free',
    ]);

    $this->skus = ProductSku::factory()->count(3)->create();
});

/** Open the table's order so the next POST takes the append (device B) path. */
function openTheTable(): void
{
    test()->postJson('/api/v1/customer/tables/append-count-token/orders', [
        'items' => [['product_sku_id' => test()->skus[0]->id, 'quantity' => 1]],
    ])->assertStatus(201);
}

it('broadcasts exactly once when a second device appends one line', function () {
    // The shape 人形町店 actually produced: one bowl per confirm. Before the fix
    // this was 2.
    openTheTable();

    Event::fake([OrderItemAdded::class]);

    $this->postJson('/api/v1/customer/tables/append-count-token/orders', [
        'items' => [['product_sku_id' => $this->skus[1]->id, 'quantity' => 1]],
    ])->assertStatus(200);

    Event::assertDispatchedTimes(OrderItemAdded::class, 1);
});

it('broadcasts once per line, never N+1, when a cart carries several lines', function () {
    // The case that showed the second emitter was not a constant. Three lines
    // used to broadcast four times; the extra one is what this pins out.
    openTheTable();

    Event::fake([OrderItemAdded::class]);

    $this->postJson('/api/v1/customer/tables/append-count-token/orders', [
        'items' => [
            ['product_sku_id' => $this->skus[0]->id, 'quantity' => 1],
            ['product_sku_id' => $this->skus[1]->id, 'quantity' => 1],
            ['product_sku_id' => $this->skus[2]->id, 'quantity' => 1],
        ],
    ])->assertStatus(200);

    Event::assertDispatchedTimes(OrderItemAdded::class, 3);
});

it('still broadcasts for a NON-customer caller of the shared mutation path', function () {
    // The load-bearing half of where the fix was applied. #2522 proposed
    // removing the event from EITHER emitter as if the two were equivalent.
    // They are not: `changeItems()` is also how the staff PDA
    // (`HandyController`) and the POS (`Shop\CustomerOrderController`) add
    // lines, and neither has a second emitter of its own. Taking the event out
    // of the shared path would have left both silent — the session fan-out
    // would simply stop for staff-entered items, with nothing failing.
    //
    // Driven through the facade rather than the PDA's HTTP route on purpose:
    // the property being pinned is "the shared mutation path is the emitter",
    // which is what makes it true for every caller, present and future. A
    // device-token route test would prove it for one of them.
    openTheTable();

    $order = CustomerOrder::query()->latest('created_at')->firstOrFail();
    expect($order->table_session_id)->not->toBeNull();

    Event::fake([OrderItemAdded::class]);

    $payload = new OrderLineSelectionPayload((string) Str::uuid(), null, 1, [], null, (string) $this->skus[2]->id);

    app(OrderMutationFacade::class)->changeItems(new ChangeOrderItemsCommand(
        OrderMutationContextFactory::fromOrder($order),
        (string) $order->id,
        OrderItemMutation::Add,
        $payload->fingerprint(),
        $payload,
    ));

    Event::assertDispatchedTimes(OrderItemAdded::class, 1);
});

it('leaves the LAST broadcast carrying the final basket, not a partial one', function () {
    // The regression this fix could have introduced, and the reason to assert
    // the payload rather than only the count.
    //
    // The emitter that was removed fired ONCE, after `refresh()` + `load()`, so
    // it was guaranteed to describe the finished basket. The survivors fire
    // per line from inside the loop. If they carried a snapshot frozen at
    // dispatch time, the last thing a customer's phone hears about a 3-line
    // cart would be the state after line 2 — a cart UI that settles on the
    // wrong total and stays there, which is worse than the duplicate
    // broadcasts this change set out to remove.
    //
    // It holds because `broadcastWith()` reads `items()->count()` as a live
    // query and the event is `ShouldDispatchAfterCommit`, so nothing ships
    // until every line is committed. That is a property of two collaborating
    // classes, not of this one — exactly the kind that breaks quietly when
    // somebody "optimises" the count into a cached relation.
    openTheTable();

    Event::fake([OrderItemAdded::class]);

    $this->postJson('/api/v1/customer/tables/append-count-token/orders', [
        'items' => [
            ['product_sku_id' => $this->skus[0]->id, 'quantity' => 1],
            ['product_sku_id' => $this->skus[1]->id, 'quantity' => 1],
            ['product_sku_id' => $this->skus[2]->id, 'quantity' => 1],
        ],
    ])->assertStatus(200);

    $order = CustomerOrder::query()->latest('created_at')->firstOrFail()->refresh();

    $payloads = [];
    Event::assertDispatched(OrderItemAdded::class, function (OrderItemAdded $event) use (&$payloads) {
        $payloads[] = $event->broadcastWith();

        return true;
    });

    $last = end($payloads);

    // THREE lines, not four, and the reason is worth writing down because it
    // bears on the open product question in #2522. `openTheTable()` already put
    // sku[0] on the order, and this cart re-adds it: same-SKU adds MERGE into
    // the existing line (qty 2) rather than creating a second one. So "should
    // we merge same-SKU adds?" is already answered WITHIN a cart — what did not
    // merge at 人形町店 C-6 was four SEPARATE requests, minutes apart in machine
    // terms. Any merging feature there is about crossing request boundaries,
    // which is a different and much less obvious thing to get right.
    //
    // I expected 4 here and was wrong; the assertion is what corrected me.
    expect($last['order_id'])->toBe($order->id)
        ->and($last['items_count'])->toBe(3)
        ->and($last['total'])->toBe((float) $order->total_amount)
        ->and($last['subtotal'])->toBe((float) $order->subtotal);

    $merged = $order->items()->where('product_sku_id', $this->skus[0]->id)->sole();
    expect((float) $merged->quantity)->toBe(2.0);
});

it('broadcasts nothing when the append carries no items', function () {
    // The `! empty($validated['items'])` guard. An empty append is a no-op, and
    // a device refreshing its cart on an empty broadcast is churn for nothing.
    openTheTable();

    Event::fake([OrderItemAdded::class]);

    $this->postJson('/api/v1/customer/tables/append-count-token/orders', [
        'items' => [],
    ]);

    Event::assertNotDispatched(OrderItemAdded::class);
});
