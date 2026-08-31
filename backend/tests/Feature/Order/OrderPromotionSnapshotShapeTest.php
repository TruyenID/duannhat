<?php

/**
 * Regression cover for two order-detail bugs that crashed admin-web.
 *
 * 1. `applied_promotion_snapshot.name` was written with
 *    `getTranslationsArray('name')`. Astrotomic's method takes NO argument, so
 *    PHP dropped it and the snapshot stored the full nested
 *    `locale => ['name' => …, 'description' => …]` map. admin-web rendered that
 *    object into JSX and threw "Objects are not valid as a React child (found:
 *    object with keys {name, description})", blanking the order-detail page.
 *
 * 2. `scheduled_pickup_time` arrived from clients as a UTC instant ("…Z") and
 *    was persisted as a UTC wall-clock, while Eloquent reads the column back in
 *    `config('app.timezone')` — round-tripping the pickup 9h early, so it
 *    landed BEFORE the order was placed.
 */

use App\Http\Resources\CustomerOrderItemResource;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// =============================================================================
// 1. Promotion snapshot name shape
// =============================================================================

it('serializes a legacy nested promotion snapshot as a locale => string map', function () {
    $item = CustomerOrderItem::factory()->create([
        'applied_promotion_snapshot' => [
            // The shape every pre-fix order carries on disk.
            'name' => [
                'en' => ['name' => 'Demo Happy Hour 15%', 'description' => null],
                'ja' => ['name' => 'デモ・ハッピーアワー 15%', 'description' => null],
                'vi' => ['name' => 'Happy Hour demo 15%', 'description' => 'Giảm 15%'],
            ],
            'discount_percent' => '15.00',
            'stacking_mode' => 'stackable_with_coupons',
        ],
    ]);

    $payload = (new CustomerOrderItemResource($item))->toArray(Request::create('/', 'GET'));
    $name = $payload['applied_promotion_snapshot']['name'];

    expect($name)->toBe([
        'en' => 'Demo Happy Hour 15%',
        'ja' => 'デモ・ハッピーアワー 15%',
        'vi' => 'Happy Hour demo 15%',
    ]);

    // The contract that actually protects the client: never an object/array.
    foreach ($name as $value) {
        expect($value)->toBeString();
    }

    // Sibling keys must survive untouched.
    expect($payload['applied_promotion_snapshot']['discount_percent'])->toBe('15.00');
});

it('leaves an already-flat promotion snapshot name unchanged', function () {
    $item = CustomerOrderItem::factory()->create([
        'applied_promotion_snapshot' => [
            'name' => ['en' => 'Flat Name', 'vi' => 'Tên phẳng'],
            'discount_percent' => '10.00',
        ],
    ]);

    $payload = (new CustomerOrderItemResource($item))->toArray(Request::create('/', 'GET'));

    expect($payload['applied_promotion_snapshot']['name'])
        ->toBe(['en' => 'Flat Name', 'vi' => 'Tên phẳng']);
});

it('tolerates a promotion snapshot with no name at all', function () {
    $item = CustomerOrderItem::factory()->create([
        'applied_promotion_snapshot' => ['discount_percent' => '5.00'],
    ]);

    $payload = (new CustomerOrderItemResource($item))->toArray(Request::create('/', 'GET'));

    expect($payload['applied_promotion_snapshot'])->toBe(['discount_percent' => '5.00']);
});

// =============================================================================
// 2. scheduled_pickup_time timezone frame
// =============================================================================

/**
 * Run a closure with the whole app pinned to a timezone.
 *
 * Both frames have to move together: the mutator reads `config('app.timezone')`
 * live, while Eloquent deserializes the column using PHP's default timezone,
 * which Laravel fixes at boot. Setting only the config would leave the read
 * side in the bootstrapped zone and make the assertions meaningless.
 */
function withAppTimezone(string $timezone, callable $callback): void
{
    $originalConfig = config('app.timezone');
    $originalDefault = date_default_timezone_get();

    config(['app.timezone' => $timezone]);
    date_default_timezone_set($timezone);

    try {
        $callback();
    } finally {
        config(['app.timezone' => $originalConfig]);
        date_default_timezone_set($originalDefault);
    }
}

it('stores a UTC pickup instant in the app timezone so it round-trips', function () {
    withAppTimezone('Asia/Tokyo', function () {
        $order = CustomerOrder::factory()->create();
        // 05:19Z is 14:19 in Asia/Tokyo — what customer-web's toISOString() sends.
        $order->update(['scheduled_pickup_time' => '2026-07-23T05:19:00.000Z']);

        // Persisted as an app-tz wall-clock — the frame created_at is written in.
        expect(DB::table('customer_orders')->where('id', $order->id)->value('scheduled_pickup_time'))
            ->toStartWith('2026-07-23 14:19:00');

        // And it re-reads as the very instant the client sent.
        expect($order->fresh()->scheduled_pickup_time->equalTo(Carbon::parse('2026-07-23T05:19:00Z')))
            ->toBeTrue();
    });
});

it('keeps a scheduled pickup at or after the moment the order was placed', function () {
    withAppTimezone('Asia/Tokyo', function () {
        $order = CustomerOrder::factory()->create(['created_at' => Carbon::parse('2026-07-23 13:42:58')]);
        $order->update(['scheduled_pickup_time' => '2026-07-23T06:00:00.000Z']); // 15:00 JST

        // The original bug wrote 06:00 and re-read it as JST, landing the pickup
        // ~8h BEFORE the order existed.
        expect($order->fresh()->scheduled_pickup_time->gte($order->fresh()->created_at))->toBeTrue();
    });
});

it('leaves a null pickup time null', function () {
    $order = CustomerOrder::factory()->create();
    $order->update(['scheduled_pickup_time' => null]);

    expect($order->fresh()->scheduled_pickup_time)->toBeNull();
});
