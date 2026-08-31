<?php

use App\Models\CustomerOrder;
use App\Models\Table;
use Illuminate\Support\Facades\Cache;

// =========================================================================
//  Token minting
// =========================================================================

it('mints a 32-char qr_token automatically on order create', function () {
    $order = CustomerOrder::factory()->create();

    expect($order->qr_token)->not->toBeNull()->toHaveLength(32);
});

// =========================================================================
//  Table tokens
// =========================================================================

it('resolves a table token to type:table with id/code/name', function () {
    $table = Table::factory()->create([
        'qr_token' => 'tbl-token-aaa111',
        'is_active' => true,
        'code' => 'T-01',
        'name' => 'A-1',
    ]);

    $this->getJson('/api/v1/customer/qr/tbl-token-aaa111')
        ->assertOk()
        ->assertJsonPath('data.type', 'table')
        ->assertJsonPath('data.table.id', $table->id)
        ->assertJsonPath('data.table.code', 'T-01')
        ->assertJsonPath('data.table.name', 'A-1')
        ->assertJsonMissingPath('data.table.qr_token');
});

it('returns 404 for an inactive table token', function () {
    Table::factory()->create(['qr_token' => 'inactive-tok-123', 'is_active' => false]);

    $this->getJson('/api/v1/customer/qr/inactive-tok-123')->assertNotFound();
});

// =========================================================================
//  Order tokens
// =========================================================================

it('resolves an open order token to type:order with id/code', function () {
    $order = CustomerOrder::factory()->open()->create();

    $this->getJson('/api/v1/customer/qr/'.$order->qr_token)
        ->assertOk()
        ->assertJsonPath('data.type', 'order')
        ->assertJsonPath('data.order.id', $order->id)
        ->assertJsonPath('data.order.code', $order->order_code);
});

it('still resolves a closed (paid) order token', function () {
    $order = CustomerOrder::factory()->closed()->create();

    $this->getJson('/api/v1/customer/qr/'.$order->qr_token)
        ->assertOk()
        ->assertJsonPath('data.type', 'order')
        ->assertJsonPath('data.order.code', $order->order_code);
});

it('returns 404 for a voided order token', function () {
    $order = CustomerOrder::factory()->voided()->create();

    $this->getJson('/api/v1/customer/qr/'.$order->qr_token)->assertNotFound();
});

it('exposes only id and code on an order — no qr_token or totals leak', function () {
    $order = CustomerOrder::factory()->open()->create();

    $payload = $this->getJson('/api/v1/customer/qr/'.$order->qr_token)
        ->assertOk()
        ->json('data.order');

    expect(array_keys($payload))->toEqual(['id', 'code']);
});

// =========================================================================
//  Misses & throttling
// =========================================================================

it('returns 404 for an unknown token', function () {
    $this->getJson('/api/v1/customer/qr/does-not-exist')->assertNotFound();
});

it('throttles qr-resolve at 30 requests per minute per IP', function () {
    // Other tests in this process share the array cache the limiter uses;
    // flush so this test owns a fresh 30-request budget.
    Cache::flush();

    foreach (range(1, 30) as $i) {
        $this->getJson('/api/v1/customer/qr/nope-'.$i)->assertNotFound();
    }

    $this->getJson('/api/v1/customer/qr/nope-final')->assertStatus(429);
});
