<?php

/**
 * plan-037 high plan-audit — takeaway confirm-step POST must be idempotent.
 *
 * customer-web's /order-confirm screen fires the create POST with an
 * `Idempotency-Key` header (the localStorage checkout-draft id). A double
 * tap or a network retry must resolve to the SAME order — never a duplicate
 * takeaway row (double kitchen prep + double counter charge).
 *
 * Also guards that the two dead item-edit routes (which referenced
 * non-existent controller methods → guaranteed 500) are gone.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\ProductSku;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();

    $this->organization = Organization::factory()->create([
        'console_organization_id' => '00000000-aaaa-4aaa-aaaa-000000000037',
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
        'locale' => 'vi-VN',
    ]);
    $this->sku = ProductSku::factory()->create();

    $this->place = function (array $overrides = [], array $headers = []) {
        return $this->postJson(
            "/api/v1/customer/branches/{$this->branch->slug}/orders",
            array_merge([
                'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
                'customer_takeaway_phone' => '+84336909454',
                'payment_method' => 'counter',
            ], $overrides),
            $headers,
        );
    };
});

it('dedupes on a repeated Idempotency-Key — one order, same id', function () {
    $key = 'draft-01960000-0000-7000-8000-000000000001';

    $first = ($this->place)([], ['Idempotency-Key' => $key])->assertCreated();
    $second = ($this->place)([], ['Idempotency-Key' => $key])->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect(CustomerOrder::where('branch_id', $this->branch->id)->count())->toBe(1);
});

it('dedupes across a cache flush between submits — durable, not cache-only', function () {
    // The core plan-audit finding: idempotency backed only by a Cache map +
    // lock is not durable. If the cache is flushed/evicted between a submit
    // and its retry (Redis restart, memory pressure, TTL), the retry misses
    // the map and mints a SECOND takeaway row → double kitchen prep + double
    // counter charge. The durable unique(client_order_id) constraint must
    // still collapse the retry to the original order.
    $key = 'draft-cache-flush-race';

    $first = ($this->place)([], ['Idempotency-Key' => $key])->assertCreated();

    // Simulate the cache disappearing between the double-submit.
    Cache::flush();

    $second = ($this->place)([], ['Idempotency-Key' => $key])->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect(CustomerOrder::where('branch_id', $this->branch->id)->count())->toBe(1);
    // The retry must not double the items on the resolved order either.
    expect($second->json('data.items'))->toHaveCount(1);
});

it('creates distinct orders for distinct Idempotency-Keys', function () {
    ($this->place)([], ['Idempotency-Key' => 'key-a'])->assertCreated();
    ($this->place)([], ['Idempotency-Key' => 'key-b'])->assertCreated();

    expect(CustomerOrder::where('branch_id', $this->branch->id)->count())->toBe(2);
});

it('still creates a fresh order per request when no Idempotency-Key is sent', function () {
    ($this->place)()->assertCreated();
    ($this->place)()->assertCreated();

    expect(CustomerOrder::where('branch_id', $this->branch->id)->count())->toBe(2);
});

it('replays the identical response payload on a deduped retry', function () {
    $key = 'draft-replay';

    $first = ($this->place)(
        ['customer_takeaway_email' => 'p037@example.com'],
        ['Idempotency-Key' => $key],
    )->assertCreated();

    $second = ($this->place)(
        ['customer_takeaway_email' => 'p037@example.com'],
        ['Idempotency-Key' => $key],
    )->assertCreated();

    expect($second->json('data.code'))->toBe($first->json('data.code'));
    expect($second->json('data.total'))->toBe($first->json('data.total'));
    expect($second->json('data.items'))->toEqual($first->json('data.items'));
});

it('does not expose the removed awaiting_confirmation item-edit routes', function () {
    $order = ($this->place)([], ['Idempotency-Key' => 'route-check'])->assertCreated();
    $orderId = $order->json('data.id');
    $itemId = $order->json('data.items.0.id');

    // These routes pointed at controller methods that never existed — a hit
    // used to 500. They must be gone now (404 route-not-found, never 500).
    $this->patchJson("/api/v1/customer/orders/{$orderId}/items/{$itemId}", ['quantity' => 2])
        ->assertNotFound();
    $this->deleteJson("/api/v1/customer/orders/{$orderId}/items/{$itemId}")
        ->assertNotFound();
});
