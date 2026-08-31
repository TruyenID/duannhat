<?php

/**
 * Plan-023 M8 T8.5 — CouponExpirationScannerJob tests.
 */

use App\Jobs\Notification\CouponExpirationScannerJob;
use App\Models\Coupon;
use App\Models\NotificationRule;
use App\Models\NotificationRuleFiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake();
});

it('M8-12: coupon expiring within 72h fires custom.coupon.expiring', function () {
    Coupon::factory()->create([
        'valid_until' => Carbon::now()->addHours(24),
        'organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    (new CouponExpirationScannerJob)->handle();

    Event::assertDispatched('custom.coupon.expiring', 1);
});

it('M8-13: coupon expired since last sweep fires custom.coupon.expired', function () {
    Coupon::factory()->create([
        'valid_until' => Carbon::now()->subHours(2),
        'organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    (new CouponExpirationScannerJob)->handle();

    Event::assertDispatched('custom.coupon.expired', 1);
});

it('M8-14: pass-1 cooldown 24h prevents repeat firing', function () {
    $coupon = Coupon::factory()->create([
        'valid_until' => Carbon::now()->addHours(24),
        'organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    // Create a matched firing within the 24h cooldown window
    $rule = NotificationRule::factory()->create([
        'trigger_event' => 'custom.coupon.expiring',
        'is_active' => true,
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'fire_count' => 0,
        'cooldown_minutes' => 0,
    ]);

    NotificationRuleFiring::factory()->create([
        'rule_id' => $rule->id,
        'model_type' => $coupon::class,
        'model_id' => (string) $coupon->id,
        'outcome' => 'matched',
        'fired_at' => Carbon::now()->subHours(12),
        'evaluation_trace' => null,
    ]);

    (new CouponExpirationScannerJob)->handle();

    Event::assertNotDispatched('custom.coupon.expiring');
});

it('M8-15: pass-2 idempotent with 7-day cooldown window', function () {
    $coupon = Coupon::factory()->create([
        'valid_until' => Carbon::now()->subHours(2),
        'organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    // Create a matched expired firing within the 168h (7d) cooldown window
    $rule = NotificationRule::factory()->create([
        'trigger_event' => 'custom.coupon.expired',
        'is_active' => true,
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'fire_count' => 0,
        'cooldown_minutes' => 0,
    ]);

    NotificationRuleFiring::factory()->create([
        'rule_id' => $rule->id,
        'model_type' => $coupon::class,
        'model_id' => (string) $coupon->id,
        'outcome' => 'matched',
        'fired_at' => Carbon::now()->subDays(3),
        'evaluation_trace' => null,
    ]);

    (new CouponExpirationScannerJob)->handle();

    // Should not re-fire because matched firing is within 168h cooldown
    Event::assertNotDispatched('custom.coupon.expired');
});
