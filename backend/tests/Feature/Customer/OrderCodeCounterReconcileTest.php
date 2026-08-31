<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Services\Customer\CustomerOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * #423 — a caller-supplied `order_code` (workstation sync UP, seed import) is
 * inserted verbatim and BYPASSES nextOrderCode(), so the `order_code_counters`
 * row never advances past it. Left unreconciled, MAX(order_code) eventually
 * overtakes next_value and the counter re-mints an already-inserted ORD code →
 * duplicate-key 500 on every subsequent POS order. create() must fast-forward
 * the counter for the provided code's own year.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create(['console_organization_id' => $this->orgId, 'console_brand_id' => $this->brand->id]);

    $this->service = app(CustomerOrderService::class);
    $this->base = [
        'order_type' => 'dine_in',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'created_by_id' => (string) Str::uuid(),
    ];
});

it('#423: a provided ORD code fast-forwards the counter so the next auto code does not collide', function () {
    $year = (int) now()->year;

    // Counter currently sits at seq 5.
    DB::table('order_code_counters')->insert([
        'id' => (string) Str::uuid(),
        'year' => $year,
        'next_value' => 5,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A synced/imported order lands with an explicit code that CONSUMES seq 5.
    $provided = $this->service->create($this->base + [
        'order_code' => sprintf('ORD-%d-%04d', $year, 5),
    ]);
    expect($provided->order_code)->toBe(sprintf('ORD-%d-%04d', $year, 5));

    // The counter must have advanced past the consumed number.
    expect((int) DB::table('order_code_counters')->where('year', $year)->value('next_value'))->toBe(6);

    // The next Cloud-allocated order must NOT re-mint seq 5 — before the fix the
    // stale counter minted ORD-<year>-0005 again and threw a duplicate-key 500.
    $auto = $this->service->create($this->base);
    expect($auto->order_code)->toBe(sprintf('ORD-%d-%04d', $year, 6));
});

it('#423: a provided code only ever moves the counter forward, never rewinds it', function () {
    $year = (int) now()->year;

    DB::table('order_code_counters')->insert([
        'id' => (string) Str::uuid(),
        'year' => $year,
        'next_value' => 50,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A low provided seq (older backfill) must not drag the counter backwards.
    $this->service->create($this->base + ['order_code' => sprintf('ORD-%d-%04d', $year, 3)]);

    expect((int) DB::table('order_code_counters')->where('year', $year)->value('next_value'))->toBe(50);
});

it('#423: a provided code for a different year bumps that year row (late 31 Dec sync)', function () {
    // No counter row for 2099 yet; a workstation sync UP lands a 2099 code.
    $this->service->create($this->base + ['order_code' => 'ORD-2099-0042']);

    expect((int) DB::table('order_code_counters')->where('year', 2099)->value('next_value'))->toBe(43);
});
