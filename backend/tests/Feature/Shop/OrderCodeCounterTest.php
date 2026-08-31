<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Services\Customer\CustomerOrderService;
use App\Services\Order\Internal\Concerns\WritesCustomerOrders;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * plan-041 — the gapless, global-per-year ORD-#### counter. Locked-in business
 * rule: the sequence must be continuous (no gaps) and globally unique across
 * cloud-direct and workstation-synced orders.
 */
beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->org->console_organization_id]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->org->console_organization_id,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $this->service = app(CustomerOrderService::class);
});

function makeCounterOrder(object $ctx, array $overrides = []): CustomerOrder
{
    return $ctx->service->create(array_merge([
        'order_type' => 'spot',
        'organization_id' => $ctx->org->id,
        'branch_id' => $ctx->branch->id,
        'brand_id' => $ctx->brand->id,
    ], $overrides));
}

it('hands out gapless, sequential ORD codes', function () {
    $a = makeCounterOrder($this);
    $b = makeCounterOrder($this);
    $c = makeCounterOrder($this);

    $year = now()->year;
    expect($a->order_code)->toBe("ORD-{$year}-0001");
    expect($b->order_code)->toBe("ORD-{$year}-0002");
    expect($c->order_code)->toBe("ORD-{$year}-0003");
});

it('seeds the counter from the existing MAX so it never collides with imported codes', function () {
    $year = now()->year;

    // Simulate a pre-existing / imported order with a high sequence number,
    // inserted OUTSIDE the service (no counter row yet).
    CustomerOrder::factory()->create([
        'order_code' => "ORD-{$year}-0050",
        'organization_id' => $this->org->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
    ]);

    // First service-minted code must continue from MAX+1, not restart at 0001.
    expect(makeCounterOrder($this)->order_code)->toBe("ORD-{$year}-0051");
    expect(makeCounterOrder($this)->order_code)->toBe("ORD-{$year}-0052");
});

it('leaves client_order_id null for cloud-direct orders', function () {
    // Cloud-originated flows (customer-web, admin, QR) pass no client_order_id;
    // it's a workstation-only idempotency key. They still get a minted ORD code.
    $order = makeCounterOrder($this);
    expect($order->client_order_id)->toBeNull();
    expect($order->order_code)->toStartWith('ORD-'.now()->year.'-');
});

it('advances the counter by exactly one per order', function () {
    $year = now()->year;
    makeCounterOrder($this);

    $before = (int) DB::table('order_code_counters')->where('year', $year)->value('next_value');
    makeCounterOrder($this);
    $after = (int) DB::table('order_code_counters')->where('year', $year)->value('next_value');

    expect($after)->toBe($before + 1);
});

it('does NOT skip a number when the order transaction rolls back', function () {
    $year = now()->year;
    makeCounterOrder($this); // ORD-...-0001, counter now at 2

    // Force a failure AFTER allocation by passing a non-existent table — the
    // table validation throws inside the transaction, rolling it back. The
    // counter increment must roll back with it (no gap).
    try {
        makeCounterOrder($this, ['table_ids' => [(string) Str::uuid()]]);
    } catch (Throwable) {
        // expected
    }

    // Next successful order takes 0002 — the rolled-back attempt left no gap.
    expect(makeCounterOrder($this)->order_code)->toBe("ORD-{$year}-0002");
});

// ── Concurrency crux: gaplessness + uniqueness under lockForUpdate ──
//
// A true multi-process race can't run against the in-memory SQLite test DB
// (writes serialize; `lockForUpdate` is a runtime no-op there — prod is MySQL
// where the row lock is real). These tests instead pin the two invariants the
// concurrency design rests on: (1) the allocator MUST take a pessimistic row
// lock so parallel allocators serialize, and (2) even if two allocators ever
// hand out the same sequence, the DB `unique(order_code)` index makes a
// duplicate code impossible — the request fails loudly instead of double-minting.

it('allocates the counter under a pessimistic FOR UPDATE row lock', function () {
    // The whole no-gap / no-dup guarantee hinges on lockForUpdate() serializing
    // concurrent allocators on the single per-year counter row. A behavioural
    // assertion can't run here — the in-memory SQLite test driver strips the
    // FOR UPDATE clause (no-op) while prod MySQL enforces it — so guard the lock
    // at the source: nextOrderCode() must SELECT the counter row lockForUpdate()
    // so a refactor can't silently drop the serialization primitive.
    // nextOrderCode() lives on the Plan 047 order persistence boundary.
    $method = new ReflectionMethod(WritesCustomerOrders::class, 'nextOrderCode');
    $source = implode('', array_slice(
        file($method->getFileName()),
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1,
    ));

    expect($source)->toContain('order_code_counters');
    expect($source)->toContain('lockForUpdate()');
});

it('cannot mint a duplicate code even if the counter re-hands-out a used sequence', function () {
    $year = now()->year;

    // First order consumes 0001; counter now points at 0002.
    $first = makeCounterOrder($this);
    expect($first->order_code)->toBe("ORD-{$year}-0001");

    // Simulate the pathological outcome of a broken lock / concurrent double
    // allocation: force the counter back so the next allocation re-mints 0001.
    DB::table('order_code_counters')->where('year', $year)->update(['next_value' => 1]);

    // The DB unique(order_code) index is the backstop — the second insert of
    // ORD-year-0001 must fail loudly, never silently create a duplicate code.
    expect(fn () => makeCounterOrder($this))
        ->toThrow(UniqueConstraintViolationException::class);

    // Exactly one order carries the code; no phantom duplicate row landed.
    expect(CustomerOrder::where('order_code', "ORD-{$year}-0001")->count())->toBe(1);
});

it('produces a contiguous, fully-distinct block of codes across many allocations', function () {
    $year = now()->year;
    $n = 25;

    $codes = collect(range(1, $n))->map(fn () => makeCounterOrder($this)->order_code);

    // Every code distinct (uniqueness) …
    expect($codes->unique()->count())->toBe($n);
    // … and the block is exactly 0001..00N with no gap (gaplessness) …
    $expected = collect(range(1, $n))->map(fn ($i) => sprintf('ORD-%d-%04d', $year, $i));
    expect($codes->values()->all())->toBe($expected->all());
    // … and the counter lands at exactly N+1 (advanced once per order).
    expect((int) DB::table('order_code_counters')->where('year', $year)->value('next_value'))
        ->toBe($n + 1);
});

// ── Global-per-year: the counter is keyed by YEAR only, not per tenant ──
//
// DESIGN §2/§6: the ORD sequence must be "globally unique across cloud-direct
// and workstation-synced orders" — a single row per year, shared by every
// organization/brand/branch. A regression that scoped the counter per tenant
// would silently let two orgs both mint ORD-{year}-0001 and collide.
it('shares one global-per-year sequence across organizations (codes do not reset per tenant)', function () {
    $year = now()->year;

    // Org A's first order → 0001.
    expect(makeCounterOrder($this)->order_code)->toBe("ORD-{$year}-0001");

    // A completely separate tenant: different org, brand and branch.
    $org2 = Organization::factory()->create();
    $brand2 = Brand::factory()->create(['console_organization_id' => $org2->console_organization_id]);
    $branch2 = Branch::factory()->create([
        'console_organization_id' => $org2->console_organization_id,
        'console_brand_id' => $brand2->console_brand_id,
    ]);

    $b = $this->service->create([
        'order_type' => 'spot',
        'organization_id' => $org2->id,
        'branch_id' => $branch2->id,
        'brand_id' => $brand2->id,
    ]);

    // The second tenant CONTINUES the global sequence (0002) — it must not
    // restart at 0001, which would duplicate org A's code.
    expect($b->order_code)->toBe("ORD-{$year}-0002");

    // Back to org A → 0003: one shared counter row advanced by both tenants.
    expect(makeCounterOrder($this)->order_code)->toBe("ORD-{$year}-0003");

    // Exactly one counter row exists for the year (not one per org).
    expect(DB::table('order_code_counters')->where('year', $year)->count())->toBe(1);
});

// ── Provisional WS- codes never consume an ORD sequence ──
//
// DESIGN §6: the "no duplicate ORD" guarantee rests on the workstation NEVER
// claiming an ORD number — it holds a provisional `WS-...` value until Cloud
// mints the real code. A supplied WS- code is inserted verbatim; the counter
// must stay untouched so the next Cloud-minted order still starts at 0001.
it('does not consume an ORD sequence for a provisional WS- code', function () {
    $year = now()->year;

    $ws = makeCounterOrder($this, ['order_code' => 'WS-A1B2-20260625-014']);
    expect($ws->order_code)->toBe('WS-A1B2-20260625-014');

    // The provisional insert bypasses nextOrderCode() AND the ORD-only
    // reconcile regex, so no counter row is created or advanced.
    expect(DB::table('order_code_counters')->where('year', $year)->exists())->toBeFalse();

    // The first genuinely Cloud-minted order still starts at 0001 — the WS
    // insert consumed no sequence number.
    expect(makeCounterOrder($this)->order_code)->toBe("ORD-{$year}-0001");
});
