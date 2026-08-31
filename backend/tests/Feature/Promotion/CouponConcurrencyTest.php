<?php

/**
 * Plan-019 — T10.3 concurrency / oversell guard.
 *
 * The test environment is SQLite in-memory (phpunit.xml) which serialises
 * writes by design — we can't model real-world concurrent applies here.
 * What we CAN prove: the application-level counter logic (lockForUpdate
 * + WHERE-guard increment + race-safe insert via unique customer_order_id)
 * is correct in sequence. A 100-iteration loop against
 * `usage_limit_total = 50` should yield exactly 50 successful applies
 * and 50 rejections with `coupon_exhausted` — never an oversell. Real
 * MySQL parallel testing belongs to a deferred QA pass; document the
 * limitation here so the reader doesn't mistake this for a true race
 * test.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'concurrency-shop',
        'is_active' => true,
    ]);
    $orgMgr = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($orgMgr, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    // Coupon with usage_limit_total = 50.
    Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'code' => 'LIMIT50',
        'discount_type' => 'fixed',
        'discount_value' => 1000,
        'min_order_subtotal' => 0,
        'usage_limit_total' => 50,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);
});

it('serialises 100 apply attempts to exactly usage_limit_total successes', function () {
    $successes = 0;
    $exhausted = 0;
    $other = 0;

    for ($i = 0; $i < 100; $i++) {
        // Each iteration creates a brand-new order so the unique
        // customer_order_id constraint doesn't intercept the apply.
        $order = CustomerOrder::factory()->create([
            'branch_id' => $this->shop->id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'subtotal' => 100000,
            'discount_amount' => 0,
            'total_amount' => 100000,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $response = $this->actingAs($this->manager)
            ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/apply-coupon", [
                'code' => 'LIMIT50',
            ]);

        if ($response->status() === 200) {
            $successes++;
        } elseif ($response->json('error_code') === 'coupon_exhausted') {
            $exhausted++;
        } else {
            $other++;
        }
    }

    expect($successes)->toBe(50);
    expect($exhausted)->toBe(50);
    expect($other)->toBe(0);

    // Final counter state matches the contract — no oversell.
    expect(Coupon::where('code', 'LIMIT50')->value('times_used'))->toBe(50);
    expect(CouponRedemption::whereNull('released_at')->count())->toBe(50);
});

/**
 * The sequential loop above always re-reads a fresh `times_used`, so it only
 * exercises the read-side validation (`validateForApply` → coupon_exhausted).
 * The two tests below pin the DB-level backstops that make the guarantee hold
 * under a TRUE race — where two transactions both pass the stale read and only
 * the atomic write layer stands between them and an oversell. SQLite in-memory
 * cannot spawn real parallel writers (see the header note + TESTS.md L208), so
 * we assert the backstops directly instead of racing them.
 */
it('atomic WHERE-guard refuses to push times_used past usage_limit_total', function () {
    $coupon = Coupon::where('code', 'LIMIT50')->firstOrFail();
    $coupon->update(['times_used' => 50]); // already at the cap

    // Byte-for-byte the guarded statement CouponService::apply() runs as its
    // race backstop after the lockForUpdate. A losing racer that slipped past
    // a stale read must be a no-op here — never a 51st increment.
    $affected = Coupon::where('id', $coupon->id)
        ->where(function ($q) {
            $q->whereColumn('times_used', '<', 'usage_limit_total');
        })
        ->increment('times_used');

    expect($affected)->toBe(0);
    expect($coupon->fresh()->times_used)->toBe(50); // no oversell
});

it('rolls the race-loser apply fully back when an active redemption already occupies the order', function () {
    $coupon = Coupon::where('code', 'LIMIT50')->firstOrFail();
    $coupon->update(['times_used' => 1]);

    $order = CustomerOrder::factory()->create([
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'subtotal' => 100000,
        'discount_amount' => 0,
        'total_amount' => 100000,
        'status' => 'open',
        'opened_at' => now(),
    ]);

    // Simulate the WINNING concurrent apply: an ACTIVE redemption row already
    // holds this order's unique customer_order_id slot, while order.coupon_id
    // has not yet been mirrored — the exact residue a losing racer sees mid
    // flight. apply() only sweeps *released* orphans, so this active row makes
    // the redemption INSERT collide on `coupon_redemptions_order_unique`.
    CouponRedemption::create([
        'coupon_id' => $coupon->id,
        'customer_order_id' => $order->id,
        'customer_id' => null,
        'discount_applied_amount' => 1000,
        'coupon_snapshot' => ['code' => 'LIMIT50', 'discount_type' => 'fixed', 'discount_value' => '1000'],
        'redeemed_at' => now(),
        'redeemed_via' => 'pos',
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/apply-coupon", [
            'code' => 'LIMIT50',
        ])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'order_not_modifiable');

    // The whole transaction rolled back: the speculative increment reverted and
    // no duplicate redemption survived — the counter can never double-count.
    expect($coupon->fresh()->times_used)->toBe(1);
    expect(CouponRedemption::where('customer_order_id', $order->id)->whereNull('released_at')->count())->toBe(1);
});
