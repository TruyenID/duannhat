<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Table;
use App\Models\Zone;
use Illuminate\Support\Str;

/**
 * #2531 — `CustomerOrder::tables()` mirrors `tables.current_order_id`, the
 * table CURRENTLY seated at an order. Once a dine-in order closes, checkout
 * releases (or reassigns) the table, so that relation goes empty/stale and
 * `GET /workstation/orders` — the feed the workstation mirrors into
 * `order_tables`, which pos-web history reads — stopped reporting where a
 * closed order actually happened. `orderTable()` (the `table_id` snapshot
 * stamped at open) is the fallback these tests pin.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->zone = Zone::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);
});

function tableHistory_mkTable(string $code): Table
{
    return Table::factory()->create([
        'branch_id' => test()->branch->id,
        'zone_id' => test()->zone->id,
        'organization_id' => test()->orgId,
        'code' => $code,
        'status' => 'free',
    ]);
}

function tableHistory_mkOrder(?string $tableId, string $status): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'TH-'.Str::random(4),
        'order_type' => 'dine_in',
        'status' => $status,
        'subtotal' => 1000,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 1000,
        'paid_amount' => 1000,
        'total_tip' => 0,
        'opened_at' => now(),
        'closed_at' => $status === 'closed' ? now() : null,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);

    // table_id is guarded on the model (App\Models\CustomerOrder overrides
    // $fillable and deliberately excludes it) — forceFill mirrors how
    // WritesCustomerOrders stamps the primary-table snapshot.
    $order->forceFill(['table_id' => $tableId])->save();

    return $order;
}

it('reports the snapshot table for a closed order whose table was released', function () {
    $table = tableHistory_mkTable('A-3');
    $order = tableHistory_mkOrder($table->id, 'closed');
    // Checkout released the table — current_order_id back to null, unlike the
    // still-populated table_id snapshot on the order itself.
    Table::where('id', $table->id)->update(['current_order_id' => null]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/orders?id='.$order->id)
        ->assertOk();

    expect($response->json('data.0.tables'))->toHaveCount(1);
    expect($response->json('data.0.tables.0.id'))->toBe($table->id);
    expect($response->json('data.0.tables.0.code'))->toBe('A-3');
});

it('reports the snapshot table for a closed order whose table now serves a new order', function () {
    $table = tableHistory_mkTable('A-8');
    $order = tableHistory_mkOrder($table->id, 'closed');
    $newOrder = tableHistory_mkOrder($table->id, 'open');
    Table::where('id', $table->id)->update(['current_order_id' => $newOrder->id]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/orders?id='.$order->id)
        ->assertOk();

    expect($response->json('data.0.tables'))->toHaveCount(1);
    expect($response->json('data.0.tables.0.id'))->toBe($table->id);
});

it('still reports the live occupant table for an open order', function () {
    $table = tableHistory_mkTable('B-1');
    $order = tableHistory_mkOrder($table->id, 'open');
    Table::where('id', $table->id)->update(['current_order_id' => $order->id]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/orders?id='.$order->id)
        ->assertOk();

    expect($response->json('data.0.tables.0.id'))->toBe($table->id);
});

it('#2531 review: an OPEN order whose table was taken away reports NOTHING', function () {
    // The `OPEN_STATUSES` guard, pinned for the first time. Every case above
    // decides on the LEFT side of the `||` — pivot non-empty, or empty on a
    // closed order — so deleting `|| in_array($status, OPEN_STATUSES, true)`
    // leaves all of them green. Measured, not assumed.
    //
    // The case that separates the two branches: an order still OPEN whose pivot
    // is empty while `table_id` still holds the old snapshot. That is what staff
    // releasing or reassigning a table mid-meal leaves behind. The live truth is
    // "this order holds no table"; the snapshot would name one it no longer
    // occupies — and the workstation mirrors `tables[]` straight into its own
    // pivot, so the wrong answer travels.
    $table = tableHistory_mkTable('C-9');
    $order = tableHistory_mkOrder($table->id, 'open');
    Table::where('id', $table->id)->update(['current_order_id' => null]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/orders?id='.$order->id)
        ->assertOk();

    expect($response->json('data.0.tables'))->toBe([]);
});

it('#2531 review: a CLOSED order that never had a table reports NOTHING', function () {
    // Takeaway and spot orders. The fallback must not invent a table, and
    // `$this->table` resolving to null is the only thing stopping it — worth a
    // case of its own, because "returns empty" here and "returns empty" for a
    // released table come from two different branches.
    $order = tableHistory_mkOrder(null, 'closed');

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/orders?id='.$order->id)
        ->assertOk();

    expect($response->json('data.0.tables'))->toBe([]);
});
