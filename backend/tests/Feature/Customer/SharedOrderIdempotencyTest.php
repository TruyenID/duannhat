<?php

/**
 * plan-034 audit HIGH (logic-risk) — shared-append POST /orders must be
 * idempotent when the client supplies an `Idempotency-Key`.
 *
 * The dine-in shared-session flow re-POSTs to
 * `POST /api/v1/customer/tables/{qrToken}/orders` to append a device's cart
 * onto the ONE open order for the table. A network timeout / double-tap
 * previously replayed the request and ran `addItems()` twice, silently
 * doubling the customer's bill. These tests pin the fix: the same key
 * short-circuits to the first response; a different key (or none) still
 * appends.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\TableSession;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->organization = Organization::factory()->create([
        'console_organization_id' => (string) Str::uuid(),
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
});

function idemTable(): Table
{
    return Table::factory()->create([
        'organization_id' => test()->organization->id,
        'branch_id' => test()->branch->id,
        'status' => 'free',
        'is_active' => true,
        'qr_token' => (string) Str::uuid(),
    ]);
}

/** Open the shared session + first order (device A) and return its id. */
function idemSeedOrder(Table $table, ProductSku $sku): string
{
    test()->postJson("/api/v1/customer/tables/{$table->qr_token}/join")->assertOk();

    return test()->postJson("/api/v1/customer/tables/{$table->qr_token}/orders", [
        'items' => [['product_sku_id' => $sku->id, 'quantity' => 1]],
    ])->assertCreated()->json('data.id');
}

it('does NOT double-append when the same Idempotency-Key is replayed', function () {
    $table = idemTable();
    $skuA = ProductSku::factory()->create();
    $skuB = ProductSku::factory()->create();
    $orderId = idemSeedOrder($table, $skuA);

    $key = (string) Str::uuid();
    $payload = ['items' => [['product_sku_id' => $skuB->id, 'quantity' => 2]]];

    $first = $this->withHeader('Idempotency-Key', $key)
        ->postJson("/api/v1/customer/tables/{$table->qr_token}/orders", $payload);
    $first->assertOk();

    // Replay — same key, same body (the retry a flaky network would send).
    $second = $this->withHeader('Idempotency-Key', $key)
        ->postJson("/api/v1/customer/tables/{$table->qr_token}/orders", $payload);
    $second->assertOk();

    // Byte-identical response, and skuB counted ONCE (qty 2, not 4).
    expect($second->json('data.id'))->toBe($orderId);
    // #1688 — the claim above is now ASSERTED, not just written down: the
    // replay returns the FIRST response's bytes, it does not re-render the
    // order. Re-rendering would look identical here and diverge the moment
    // another device appends between the two calls.
    expect($second->getStatusCode())->toBe($first->getStatusCode());
    expect($second->getContent())->toBe($first->getContent());

    $order = CustomerOrder::with('items')->find($orderId);
    $skuBLine = $order->items->firstWhere('product_sku_id', $skuB->id);
    expect($skuBLine)->not->toBeNull();
    expect((int) $skuBLine->quantity)->toBe(2);
    // One line per SKU: skuA + skuB only.
    expect($order->items->count())->toBe(2);
});

/**
 * #1688 — the CREATE half of the same contract. The append tests above all run
 * against a table that already has an order, so they only ever exercise the
 * 200 branch; nothing pinned the 201 one. It is the branch that matters most:
 * a replay that misses the cache does NOT fail loudly, it silently falls
 * through to the shared-append path and returns 200 with the customer's cart
 * added a second time.
 */
it('replays the CREATE response verbatim and creates no second order', function () {
    $table = idemTable();
    $sku = ProductSku::factory()->create();

    $key = (string) Str::uuid();
    $payload = ['items' => [['product_sku_id' => $sku->id, 'quantity' => 1]]];

    $first = $this->withHeader('Idempotency-Key', $key)
        ->postJson("/api/v1/customer/tables/{$table->qr_token}/orders", $payload);
    $first->assertCreated();

    // Replay — same key, same body.
    $second = $this->withHeader('Idempotency-Key', $key)
        ->postJson("/api/v1/customer/tables/{$table->qr_token}/orders", $payload);

    // 201 again — NOT the 200 the append path would answer with — and the same
    // bytes. Status is the tell that the replay short-circuited rather than
    // re-entering the funnel.
    expect($second->getStatusCode())->toBe(201);
    expect($second->getContent())->toBe($first->getContent());

    // One order, one line, quantity 1: nothing ran twice.
    expect(CustomerOrder::count())->toBe(1);
    $order = CustomerOrder::with('items')->firstOrFail();
    expect($order->items->count())->toBe(1);
    expect((int) $order->items->first()->quantity)->toBe(1);
    expect(TableSession::where('table_id', $table->id)->count())->toBe(1);
});

it('appends again when a DIFFERENT Idempotency-Key is used', function () {
    $table = idemTable();
    $skuA = ProductSku::factory()->create();
    $skuB = ProductSku::factory()->create();
    $orderId = idemSeedOrder($table, $skuA);

    $payload = ['items' => [['product_sku_id' => $skuB->id, 'quantity' => 2]]];

    $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/customer/tables/{$table->qr_token}/orders", $payload)
        ->assertOk();
    $this->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/customer/tables/{$table->qr_token}/orders", $payload)
        ->assertOk();

    $order = CustomerOrder::with('items')->find($orderId);
    $skuBLine = $order->items->firstWhere('product_sku_id', $skuB->id);
    // Two distinct keys → two real appends → qty 4.
    expect((int) $skuBLine->quantity)->toBe(4);
});

it('still double-appends without an Idempotency-Key (documents legacy behavior)', function () {
    $table = idemTable();
    $skuA = ProductSku::factory()->create();
    $skuB = ProductSku::factory()->create();
    $orderId = idemSeedOrder($table, $skuA);

    $payload = ['items' => [['product_sku_id' => $skuB->id, 'quantity' => 2]]];

    $this->postJson("/api/v1/customer/tables/{$table->qr_token}/orders", $payload)->assertOk();
    $this->postJson("/api/v1/customer/tables/{$table->qr_token}/orders", $payload)->assertOk();

    $order = CustomerOrder::with('items')->find($orderId);
    $skuBLine = $order->items->firstWhere('product_sku_id', $skuB->id);
    expect((int) $skuBLine->quantity)->toBe(4);
});

it('scopes the Idempotency-Key per table (same key on another table still appends)', function () {
    $tableA = idemTable();
    $tableB = idemTable();
    $sku = ProductSku::factory()->create();
    $extra = ProductSku::factory()->create();

    $orderA = idemSeedOrder($tableA, $sku);
    $orderB = idemSeedOrder($tableB, $sku);

    $key = 'shared-key';
    $payload = ['items' => [['product_sku_id' => $extra->id, 'quantity' => 1]]];

    $this->withHeader('Idempotency-Key', $key)
        ->postJson("/api/v1/customer/tables/{$tableA->qr_token}/orders", $payload)->assertOk();
    // Same key, DIFFERENT table → not a replay of tableA's request.
    $this->withHeader('Idempotency-Key', $key)
        ->postJson("/api/v1/customer/tables/{$tableB->qr_token}/orders", $payload)->assertOk();

    expect((int) CustomerOrder::with('items')->find($orderA)->items->firstWhere('product_sku_id', $extra->id)->quantity)->toBe(1);
    expect((int) CustomerOrder::with('items')->find($orderB)->items->firstWhere('product_sku_id', $extra->id)->quantity)->toBe(1);
});
