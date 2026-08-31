<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
 * Plan 032 — Observability watchdog commands (Phase 5.5 / Decision 12).
 *
 * Covers the two dedicated watchdog commands that ship no financial mutation
 * but guard the ones that do:
 *   - tills:check-scheduler-freshness  (heartbeat staleness alert)
 *   - tills:check-force-abandon-rate   (per-manager fraud-signal alert)
 * Plus the ExpireStaleShifts heartbeat contract those commands consume.
 *
 * Prior to this file both commands had ZERO test coverage (plan-audit high).
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
        'slug' => 'obs-shop',
        'is_active' => true,
    ]);
    $this->till = Till::firstOrCreate(
        ['branch_id' => $this->shop->id, 'till_code' => 'MAIN'],
        ['default_currency_code' => 'JPY', 'variance_tolerance_amount' => 0, 'is_active' => true,
            'brand_id' => $this->brand->id, 'organization_id' => $this->orgId],
    );
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);

    config()->set('pos.shift.stale_timeout_hours', 48);
    config()->set('pos.shift.stale_activity_window_hours', 6);
    config()->set('pos.shift.force_abandon_rate_alert_count', 5);
    config()->set('pos.shift.force_abandon_rate_window_days', 7);
});

afterEach(function () {
    Carbon::setTestNow();
});

function seedForceAbandoned(Till $till, Branch $shop, Brand $brand, string $orgId, string $managerId, Carbon $abandonedAt): TillSession
{
    return TillSession::create([
        'session_code' => 'SHIFT-FA-'.fake()->unique()->numberBetween(10000, 99999),
        'status' => 'abandoned',
        'business_date' => $abandonedAt->toDateString(),
        'default_currency_code' => 'JPY',
        'opening_float_amount' => 0,
        'opened_at' => $abandonedAt->copy()->subDay(),
        'abandoned_at' => $abandonedAt,
        'force_abandoned' => true,
        'force_abandoned_by_id' => $managerId,
        'force_abandon_reason_code' => 'cashier_forgot_to_close',
        'till_id' => $till->id,
        'branch_id' => $shop->id,
        'brand_id' => $brand->id,
        'organization_id' => $orgId,
    ]);
}

// =========================================================================
//  tills:check-scheduler-freshness
// =========================================================================

it('freshness check stays quiet when the heartbeat is fresh', function () {
    Cache::put('pos.tills.last_run_at', now()->toIso8601String(), now()->addDay());

    Log::spy();
    $exit = Artisan::call('tills:check-scheduler-freshness');

    expect($exit)->toBe(0);
    Log::shouldNotHaveReceived('error');
});

it('freshness check emits scheduler-stale ERROR when the heartbeat is missing', function () {
    Cache::forget('pos.tills.last_run_at');

    Log::spy();
    $exit = Artisan::call('tills:check-scheduler-freshness');

    expect($exit)->toBe(0);
    Log::shouldHaveReceived('error')
        ->withArgs(fn ($msg, $context = []) => $msg === '[pos.till] scheduler-stale'
            && array_key_exists('last_run_at', $context) && $context['last_run_at'] === null
            && array_key_exists('stale_for_hours', $context) && $context['stale_for_hours'] === null
            && ($context['reason'] ?? null) === 'heartbeat_missing')
        ->once();
});

it('freshness check emits scheduler-stale ERROR when the heartbeat is older than 6h', function () {
    Carbon::setTestNow('2026-06-10 12:00:00');
    Cache::put('pos.tills.last_run_at', now()->subHours(8)->toIso8601String(), now()->addDay());

    Log::spy();
    $exit = Artisan::call('tills:check-scheduler-freshness');

    expect($exit)->toBe(0);
    Log::shouldHaveReceived('error')
        ->withArgs(fn ($msg, $context = []) => $msg === '[pos.till] scheduler-stale'
            && ($context['stale_for_hours'] ?? 0) >= 6
            && ($context['reason'] ?? null) === 'heartbeat_too_old')
        ->once();
});

it('freshness check stays quiet at the 5h boundary (just under 6h threshold)', function () {
    Carbon::setTestNow('2026-06-10 12:00:00');
    Cache::put('pos.tills.last_run_at', now()->subHours(5)->toIso8601String(), now()->addDay());

    Log::spy();
    Artisan::call('tills:check-scheduler-freshness');

    Log::shouldNotHaveReceived('error');
});

// =========================================================================
//  ExpireStaleShifts heartbeat contract (feeds the freshness check)
// =========================================================================

it('expire-stale-shifts writes the heartbeat on a successful run', function () {
    Cache::forget('pos.tills.last_run_at');
    Carbon::setTestNow('2026-06-10 10:00:00');

    Artisan::call('tills:expire-stale-shifts');

    expect(Cache::get('pos.tills.last_run_at'))->not->toBeNull();
});

it('expire-stale-shifts does NOT write the heartbeat on a --dry-run', function () {
    Cache::forget('pos.tills.last_run_at');
    Carbon::setTestNow('2026-06-10 10:00:00');

    Artisan::call('tills:expire-stale-shifts', ['--dry-run' => true]);

    expect(Cache::get('pos.tills.last_run_at'))->toBeNull();
});

// =========================================================================
//  tills:check-force-abandon-rate
// =========================================================================

it('rate check emits a fraud-signal WARNING when a manager exceeds the threshold', function () {
    Carbon::setTestNow('2026-06-10 06:00:00');
    // 6 force-abandons in the trailing 7d for one manager (> threshold of 5).
    for ($i = 0; $i < 6; $i++) {
        seedForceAbandoned($this->till, $this->shop, $this->brand, $this->orgId, $this->manager->id, now()->subDays(1));
    }

    Log::spy();
    $exit = Artisan::call('tills:check-force-abandon-rate');

    expect($exit)->toBe(0);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($msg, $context = []) => $msg === '[pos.till] force-abandon-rate'
            && (string) ($context['manager_id'] ?? '') === (string) $this->manager->id
            && ($context['count'] ?? 0) === 6
            && ($context['window_days'] ?? null) === 7
            && ($context['kind'] ?? null) === 'fraud-signal')
        ->once();
});

it('rate check stays quiet when a manager is exactly AT the threshold (5, not > 5)', function () {
    Carbon::setTestNow('2026-06-10 06:00:00');
    for ($i = 0; $i < 5; $i++) {
        seedForceAbandoned($this->till, $this->shop, $this->brand, $this->orgId, $this->manager->id, now()->subDays(1));
    }

    Log::spy();
    Artisan::call('tills:check-force-abandon-rate');

    Log::shouldNotHaveReceived('warning');
});

it('rate check ignores force-abandons older than the trailing window', function () {
    Carbon::setTestNow('2026-06-10 06:00:00');
    // 6 abandons but all 10 days ago — outside the 7d window.
    for ($i = 0; $i < 6; $i++) {
        seedForceAbandoned($this->till, $this->shop, $this->brand, $this->orgId, $this->manager->id, now()->subDays(10));
    }

    Log::spy();
    Artisan::call('tills:check-force-abandon-rate');

    Log::shouldNotHaveReceived('warning');
});
