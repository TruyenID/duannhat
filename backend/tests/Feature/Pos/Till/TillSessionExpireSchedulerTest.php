<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Till;
use App\Models\TillSession;
use App\Services\Pos\TillSessionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
 * Plan 032 T6.2 — `tills:expire-stale-shifts` scheduler command + the
 * service-layer expire() it calls. Time-based scenarios use
 * Carbon::setTestNow so the threshold logic is deterministic.
 */

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
        'slug' => 'expire-shop',
        'is_active' => true,
    ]);

    config()->set('pos.shift.stale_timeout_hours', 48);
    config()->set('pos.shift.stale_activity_window_hours', 6);
});

afterEach(function () {
    Carbon::setTestNow();
});

function seedSessionOpenedAt(Branch $shop, Brand $brand, string $orgId, Carbon $openedAt, string $status = 'open'): TillSession
{
    $till = Till::firstOrCreate(
        ['branch_id' => $shop->id, 'till_code' => 'MAIN'],
        ['default_currency_code' => 'JPY', 'variance_tolerance_amount' => 0, 'is_active' => true,
            'brand_id' => $brand->id, 'organization_id' => $orgId],
    );
    $session = TillSession::create([
        'session_code' => 'SHIFT-TEST-'.fake()->unique()->numberBetween(1000, 99999),
        'status' => $status,
        'business_date' => $openedAt->toDateString(),
        'default_currency_code' => 'JPY',
        'opening_float_amount' => 0,
        'opened_at' => $openedAt,
        'till_id' => $till->id,
        'branch_id' => $shop->id,
        'brand_id' => $brand->id,
        'organization_id' => $orgId,
    ]);
    $till->update(['current_session_id' => $session->id]);

    return $session;
}

function seedPayment(TillSession $session, Carbon $createdAt): OrderPayment
{
    $shop = Branch::find($session->branch_id);
    $brand = Brand::find($session->brand_id);
    $method = PaymentMethod::firstOrCreate(
        ['organization_id' => $session->organization_id, 'branch_id' => null, 'code' => 'cash'],
        ['name' => 'Cash', 'is_auto_confirm' => true, 'requires_tendered' => true, 'is_active' => true, 'sort_order' => 0],
    );
    $order = CustomerOrder::factory()->create([
        'organization_id' => $session->organization_id, 'brand_id' => $brand->id, 'branch_id' => $shop->id,
    ]);

    $payment = OrderPayment::create([
        'payment_code' => 'PAY-T-'.fake()->unique()->numberBetween(10000, 99999),
        'amount' => 1000, 'status' => 'succeeded', 'paid_at' => $createdAt,
        'customer_order_id' => $order->id, 'payment_method_id' => $method->id,
        'branch_id' => $shop->id, 'brand_id' => $brand->id, 'organization_id' => $session->organization_id,
        'till_session_id' => $session->id,
    ]);
    // Eloquent auto-stamps created_at with now() regardless of input. The
    // scheduler query filters on order_payments.created_at, so overwrite via
    // raw DB query (the model's $timestamps + observers can't be bypassed
    // through ->update()).
    DB::table('order_payments')
        ->where('id', $payment->id)
        ->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);

    return $payment->refresh();
}

// =========================================================================
//  Happy path + boundary
// =========================================================================

it('expires an open shift opened > 48h ago with no recent payments', function () {
    Carbon::setTestNow('2026-06-10 10:00:00');
    $session = seedSessionOpenedAt($this->shop, $this->brand, $this->orgId, now()->subHours(50));

    Artisan::call('tills:expire-stale-shifts');

    $session->refresh();
    expect($session->status->value)->toBe('expired');
    expect($session->expired_at)->not->toBeNull();
    expect($session->expire_reason->value)->toBe('no_activity');
    expect((int) $session->expire_threshold_hours)->toBe(48);

    $till = Till::where('branch_id', $this->shop->id)->firstOrFail();
    expect($till->current_session_id)->toBeNull();
});

it('does NOT expire a shift opened 47h ago (under threshold)', function () {
    Carbon::setTestNow('2026-06-10 10:00:00');
    $session = seedSessionOpenedAt($this->shop, $this->brand, $this->orgId, now()->subHours(47));

    Artisan::call('tills:expire-stale-shifts');

    expect($session->fresh()->status->value)->toBe('open');
});

it('does NOT expire a shift with payment activity inside the 6h window', function () {
    Carbon::setTestNow('2026-06-10 10:00:00');
    $session = seedSessionOpenedAt($this->shop, $this->brand, $this->orgId, now()->subHours(50));
    seedPayment($session, now()->subHours(3)); // within window

    Artisan::call('tills:expire-stale-shifts');

    expect($session->fresh()->status->value)->toBe('open');
});

it('expires a shift whose last payment is older than the activity window', function () {
    Carbon::setTestNow('2026-06-10 10:00:00');
    $session = seedSessionOpenedAt($this->shop, $this->brand, $this->orgId, now()->subHours(50));
    seedPayment($session, now()->subHours(10)); // outside window

    Artisan::call('tills:expire-stale-shifts');

    expect($session->fresh()->status->value)->toBe('expired');
});

it('also expires sessions in closing status (Decision 13)', function () {
    Carbon::setTestNow('2026-06-10 10:00:00');
    $session = seedSessionOpenedAt($this->shop, $this->brand, $this->orgId, now()->subHours(50), 'closing');

    Artisan::call('tills:expire-stale-shifts');

    expect($session->fresh()->status->value)->toBe('expired');
});

// =========================================================================
//  Idempotency + dry-run
// =========================================================================

it('is idempotent — re-tick on an already-expired session is a no-op', function () {
    Carbon::setTestNow('2026-06-10 10:00:00');
    $session = seedSessionOpenedAt($this->shop, $this->brand, $this->orgId, now()->subHours(50));

    Artisan::call('tills:expire-stale-shifts');
    $firstExpiredAt = $session->fresh()->expired_at;

    Carbon::setTestNow('2026-06-10 11:00:00');
    Artisan::call('tills:expire-stale-shifts');
    expect($session->fresh()->expired_at?->toIso8601String())->toBe($firstExpiredAt?->toIso8601String());
});

it('--dry-run lists candidates without flipping any session', function () {
    Carbon::setTestNow('2026-06-10 10:00:00');
    $session = seedSessionOpenedAt($this->shop, $this->brand, $this->orgId, now()->subHours(50));

    $exit = Artisan::call('tills:expire-stale-shifts', ['--dry-run' => true]);
    expect($exit)->toBe(0);
    expect($session->fresh()->status->value)->toBe('open');
});

// =========================================================================
//  Izakaya weekend (DESIGN §13)
// =========================================================================

it('izakaya weekend: Fri 22:00 -> Sun 04:30 last payment SKIPS at Sun 05:00 (still inside activity window)', function () {
    $opened = Carbon::parse('2026-06-05 22:00:00'); // Friday
    Carbon::setTestNow('2026-06-07 05:00:00');     // Sunday 5am (55h after open)
    $session = seedSessionOpenedAt($this->shop, $this->brand, $this->orgId, $opened);
    seedPayment($session, Carbon::parse('2026-06-07 04:30:00'));

    Artisan::call('tills:expire-stale-shifts');
    expect($session->fresh()->status->value)->toBe('open');
});

it('izakaya weekend: same shift EXPIRES at Mon 00:00 (opened 50h ago, last payment 19.5h ago — outside both windows)', function () {
    $opened = Carbon::parse('2026-06-05 22:00:00'); // Friday 22:00
    Carbon::setTestNow('2026-06-08 00:00:00');     // Monday 00:00 (50h after open)
    $session = seedSessionOpenedAt($this->shop, $this->brand, $this->orgId, $opened);
    seedPayment($session, Carbon::parse('2026-06-07 04:30:00')); // 19.5h ago, outside 6h window

    Artisan::call('tills:expire-stale-shifts');
    expect($session->fresh()->status->value)->toBe('expired');
});

// =========================================================================
//  In-transaction race (Decision 10)
// =========================================================================

it('skips no-op (returns null) when a payment lands between SELECT and lock — service-level direct call', function () {
    Carbon::setTestNow('2026-06-10 10:00:00');
    $session = seedSessionOpenedAt($this->shop, $this->brand, $this->orgId, now()->subHours(50));

    // Simulate the race by inserting a recent payment AFTER the outer SELECT
    // would have picked this candidate. Then call expire() directly so the
    // in-tx re-check is exercised cleanly without scheduler timing.
    seedPayment($session, now()->subHours(2));

    Log::spy();
    /** @var TillSessionService $service */
    $service = app(TillSessionService::class);
    $result = $service->expire($session, 'no_activity', 48, 6);

    expect($result)->toBeNull();
    expect($session->fresh()->status->value)->toBe('open');
    Log::shouldHaveReceived('info')->withArgs(fn ($msg) => str_contains((string) $msg, 'expire-skipped'))->atLeast()->once();
});

it('expire_threshold_hours stores the snapshot for historical accuracy (Decision 9)', function () {
    Carbon::setTestNow('2026-06-10 10:00:00');
    $session1 = seedSessionOpenedAt($this->shop, $this->brand, $this->orgId, now()->subHours(50));
    Artisan::call('tills:expire-stale-shifts');
    expect((int) $session1->fresh()->expire_threshold_hours)->toBe(48);

    // Change threshold to 24h, expire a second session.
    config()->set('pos.shift.stale_timeout_hours', 24);
    Carbon::setTestNow('2026-06-11 10:00:00');
    $session2 = seedSessionOpenedAt($this->shop, $this->brand, $this->orgId, now()->subHours(30));
    Artisan::call('tills:expire-stale-shifts');
    expect((int) $session2->fresh()->expire_threshold_hours)->toBe(24);

    // GROUP BY expire_reason returns 1 bucket; GROUP BY expire_threshold_hours returns 2.
    $reasonBuckets = TillSession::whereNotNull('expire_reason')->distinct('expire_reason')->pluck('expire_reason')->count();
    $thresholdBuckets = TillSession::whereNotNull('expire_threshold_hours')->distinct('expire_threshold_hours')->pluck('expire_threshold_hours')->count();
    expect($reasonBuckets)->toBe(1);
    expect($thresholdBuckets)->toBe(2);
});

// =========================================================================
//  Observability — per-run structured log
// =========================================================================

it('emits [pos.till] expire-run structured log with counters at end of run', function () {
    Carbon::setTestNow('2026-06-10 10:00:00');
    seedSessionOpenedAt($this->shop, $this->brand, $this->orgId, now()->subHours(50));

    Log::spy();
    Artisan::call('tills:expire-stale-shifts');

    Log::shouldHaveReceived('info')
        ->withArgs(fn ($msg, $context = []) => $msg === '[pos.till] expire-run'
            && isset($context['candidates'], $context['expired'], $context['duration_ms']))
        ->atLeast()->once();
});
