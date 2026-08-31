<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use Illuminate\Support\Str;

/**
 * Regression: with workstation pullIntervalSlow at 5 s and ~12 catalog
 * endpoints fanning out from pullSlowPos, the per-device throttle on
 * the /api/v1/workstation/* group must accommodate ~160 read req/min.
 * The previous `throttle:60,1` silently 429d the puller after ~25 s
 * and shop manager denominations / coupons / menu edits never
 * propagated to cashier devices.
 *
 * Lock down both:
 *   1. The configured limit is now ≥ 200/min (chosen so a small future
 *      bump in slow-loop fan-out — 1-2 more endpoints — stays under).
 *   2. A burst of 70 reads in one second succeeds (would 429 under the
 *      old 60/min limit).
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
});

it('allows the slow-loop fan-out without hitting the throttle', function () {
    $headers = ['Authorization' => "Bearer {$this->wsToken}"];

    // Simulate one slow-loop tick: 12 reads in quick succession against
    // the catalog endpoints the workstation puller hits every 5 s.
    $endpoints = [
        '/api/v1/workstation/payment-methods',
        '/api/v1/workstation/customers',
        '/api/v1/workstation/menu-schedules',
        '/api/v1/workstation/till',
        '/api/v1/workstation/till-denominations',
        '/api/v1/workstation/till-tender-categories',
        '/api/v1/workstation/till-tender-types',
        '/api/v1/workstation/coupons',
        '/api/v1/workstation/menu-promotions',
        '/api/v1/workstation/menu-catalog',
        '/api/v1/workstation/staff',
        '/api/v1/workstation/lots',
    ];

    foreach ($endpoints as $ep) {
        $this->withHeaders($headers)->getJson($ep)->assertStatus(200);
    }

    // Fire 60 more — the previous 60/min limit would 429 here, the new
    // 300/min limit stays comfortable.
    for ($i = 0; $i < 60; $i++) {
        $this->withHeaders($headers)
            ->getJson('/api/v1/workstation/till-denominations')
            ->assertStatus(200);
    }
});
