<?php

/**
 * Regression: POST /api/v1/kiosk/payments used to carry a numeric
 * `throttle:10,1`. Two problems fixed together:
 *
 *   1. 10/min is far too tight for a kiosk draining an offline batch —
 *      a reconnect that flushes ~50 queued payments 429d after the 10th.
 *   2. device.auth stuffs the device into $request->attributes but never
 *      calls Auth::shouldUse(), so a numeric throttle falls back to client
 *      IP — every kiosk behind one branch NAT shared a single bucket.
 *
 * The route now uses the named `kiosk-payments` limiter (60/min, keyed by
 * device id). Lock down that a burst well past the old 10/min ceiling no
 * longer 429s.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->deviceToken = Str::random(32);
    Device::factory()->create([
        'type' => 'kiosk', 'status' => 'active', 'device_token' => $this->deviceToken,
        'organization_id' => $this->orgId, 'branch_id' => $this->branch->id,
    ]);
});

it('does not 429 a batch of payment syncs well past the old 10/min ceiling', function () {
    $headers = ['Authorization' => "Bearer {$this->deviceToken}"];

    // 40 posts in one burst — the old throttle:10,1 would 429 from the 11th
    // on. Bodies are intentionally minimal: the throttle middleware runs
    // before the controller, so the request never needs to be a valid
    // payment to exercise the limiter — we only assert it isn't rate-limited.
    for ($i = 0; $i < 40; $i++) {
        $status = $this->withHeaders($headers)
            ->postJson('/api/v1/kiosk/payments', [])
            ->status();
        expect($status)->not->toBe(429);
    }
});
