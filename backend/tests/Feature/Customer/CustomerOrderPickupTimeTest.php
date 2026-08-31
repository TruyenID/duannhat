<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\ProductSku;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->brand = Brand::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->sku = ProductSku::factory()->create();
    $this->endpoint = "/api/v1/customer/branches/{$this->branch->slug}/orders";
});

// =============================================================================
// Validation: pickup_type
// =============================================================================

it('rejects pickup_type values outside immediate/scheduled', function () {
    $this->postJson($this->endpoint, [
        'customer_takeaway_phone' => '090-0000-0000',
        'pickup_type' => 'asap-please',
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['pickup_type']);
});

it('accepts pickup_type=immediate', function () {
    $this->postJson($this->endpoint, [
        'customer_takeaway_phone' => '090-0000-0000',
        'pickup_type' => 'immediate',
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
    ])->assertCreated();
});

it('accepts pickup_type=scheduled when scheduled_pickup_time is in future', function () {
    $this->postJson($this->endpoint, [
        'customer_takeaway_phone' => '090-0000-0000',
        'pickup_type' => 'scheduled',
        'scheduled_pickup_time' => now()->addHours(2)->toIso8601String(),
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
    ])->assertCreated();
});

// =============================================================================
// Validation: scheduled_pickup_time
// =============================================================================

it('rejects pickup_type=scheduled without scheduled_pickup_time', function () {
    $this->postJson($this->endpoint, [
        'customer_takeaway_phone' => '090-0000-0000',
        'pickup_type' => 'scheduled',
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['scheduled_pickup_time']);
});

it('rejects scheduled_pickup_time in the past', function () {
    $this->postJson($this->endpoint, [
        'customer_takeaway_phone' => '090-0000-0000',
        'pickup_type' => 'scheduled',
        'scheduled_pickup_time' => now()->subHour()->toIso8601String(),
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['scheduled_pickup_time']);
});

it('rejects scheduled_pickup_time that is not a valid date', function () {
    $this->postJson($this->endpoint, [
        'customer_takeaway_phone' => '090-0000-0000',
        'pickup_type' => 'scheduled',
        'scheduled_pickup_time' => 'tomorrow at noon',
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['scheduled_pickup_time']);
});

// =============================================================================
// Persistence — pickup time fields written to DB on takeaway order
// =============================================================================

it('persists pickup_type=immediate and defaults scheduled_pickup_time to the estimated ready time', function () {
    Carbon::setTestNow('2026-04-28 10:00:00');

    $response = $this->postJson($this->endpoint, [
        'customer_takeaway_phone' => '090-0000-0000',
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
            ['product_sku_id' => $this->sku->id, 'quantity' => 2],
        ],
    ])->assertCreated();

    // #1160 — 1 + 2 = 3 portions x the default 5'/item = 15 minutes from now.
    // (Quantity, not line count: the old formula read this basket as "2 items"
    // and charged 15 + 2 = 17 regardless of how many portions each line held.)
    $response->assertJsonPath('data.preparation_minutes', 15);

    $order = CustomerOrder::latest()->first();
    expect($order->pickup_type->value ?? $order->pickup_type)->toBe('immediate')
        ->and($order->preparation_minutes)->toBe(15)
        ->and($order->estimated_ready_time->toDateTimeString())->toBe('2026-04-28 10:15:00')
        // Immediate pickup has no customer-chosen time, so scheduled_pickup_time
        // is defaulted to the estimated ready time (never left null).
        ->and($order->scheduled_pickup_time)->not->toBeNull()
        ->and($order->scheduled_pickup_time->toDateTimeString())->toBe('2026-04-28 10:15:00');

    Carbon::setTestNow();
});

it('persists scheduled_pickup_time when client requests scheduled pickup', function () {
    $pickupAt = now()->addHours(3);

    $this->postJson($this->endpoint, [
        'customer_takeaway_phone' => '090-0000-0000',
        'pickup_type' => 'scheduled',
        'scheduled_pickup_time' => $pickupAt->toIso8601String(),
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
    ])->assertCreated();

    $order = CustomerOrder::latest()->first();
    expect($order->pickup_type->value ?? $order->pickup_type)->toBe('scheduled')
        ->and($order->scheduled_pickup_time)->not->toBeNull()
        ->and($order->scheduled_pickup_time->toDateTimeString())->toBe($pickupAt->toDateTimeString());
});

it('normalizes a UTC-supplied scheduled_pickup_time to the app timezone before storing', function () {
    // Regression #1026-followup — the client sends the pickup instant as UTC
    // ("…Z"). Under a non-UTC APP_TIMEZONE (production = Asia/Tokyo) Eloquent
    // reads a stored datetime back in the app tz, so the RAW wall-clock must be
    // written in that tz too. Before the fix the raw UTC wall-clock (05:22) was
    // persisted verbatim, so read-back in Asia/Tokyo produced 05:22 JST = the
    // previous day 20:22Z — landing BEFORE placed_at. The controller now casts
    // to the app tz first.
    //
    // The test env pins APP_TIMEZONE=UTC (phpunit.xml) where the fix is a no-op,
    // and the harness re-hydrates model dates as UTC regardless of the PHP
    // default tz — so force config('app.timezone')=Asia/Tokyo to engage the fix
    // and assert against the RAW stored string (production-faithful, independent
    // of how the harness re-hydrates the cast).
    $prevAppTz = config('app.timezone');
    config(['app.timezone' => 'Asia/Tokyo']);

    try {
        Carbon::setTestNow(Carbon::parse('2026-07-23T02:00:00Z')); // 11:00 JST
        $pickupUtc = Carbon::parse('2026-07-23T05:22:00Z');        // 14:22 JST, ~3h ahead

        $this->postJson($this->endpoint, [
            'customer_takeaway_phone' => '090-0000-0000',
            'pickup_type' => 'scheduled',
            'scheduled_pickup_time' => $pickupUtc->toIso8601String(),
            'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
        ])->assertCreated();

        $raw = substr((string) DB::table('customer_orders')
            ->latest('created_at')->value('scheduled_pickup_time'), 0, 19);

        // Stored as the app-tz wall-clock (14:22), NOT the raw UTC wall-clock (05:22).
        expect($raw)->toBe('2026-07-23 14:22:00')
            // Re-hydrated in the app tz (as production does) it is the SAME instant…
            ->and(Carbon::createFromFormat('Y-m-d H:i:s', $raw, 'Asia/Tokyo')->equalTo($pickupUtc))->toBeTrue()
            // …and it stays AFTER placed_at (11:00 JST), never a day before it.
            ->and(Carbon::createFromFormat('Y-m-d H:i:s', $raw, 'Asia/Tokyo')->greaterThan(now()))->toBeTrue();
    } finally {
        Carbon::setTestNow();
        config(['app.timezone' => $prevAppTz]);
    }
});

it('rejects missing items array (required by Customer\\CustomerOrderStoreRequest)', function () {
    $this->postJson($this->endpoint, [
        'customer_takeaway_phone' => '090-0000-0000',
        'customer_takeaway_name' => 'Test',
        'customer_takeaway_phone' => '090-0000-0000',
    ])->assertUnprocessable()->assertJsonValidationErrors(['items']);
});

it('returns pickup fields in the API response shape', function () {
    $this->postJson($this->endpoint, [
        'customer_takeaway_phone' => '090-0000-0000',
        'items' => [['product_sku_id' => $this->sku->id, 'quantity' => 1]],
    ])->assertCreated()
        ->assertJsonStructure(['data' => [
            'id', 'pickup_type', 'scheduled_pickup_time',
            'estimated_ready_time', 'preparation_minutes',
        ]]);
});
