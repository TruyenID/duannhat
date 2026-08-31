<?php

use App\Http\Resources\Kds\KdsItemResource;
use App\Http\Resources\Kds\KdsOrderResource;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Priority-enum boundary locks (plan-028 test-gap audit).
 *
 * The existing KdsOrderResourceTest pins priority only at interior aging
 * values (2, 7, 13 min). The `computePriority()` match uses `< 5` / `< 10`
 * cut-points, so the transition boundaries (exactly 4/5/9/10/11 min) are the
 * real drift risk. plan-028 DESIGN.md §1.2 AND the code agree the vocabulary
 * is {normal, warning, critical}; TESTS.md line 34/38 documents a stale
 * {urgent, warn, normal} trio that never shipped. These tests lock the actual
 * enum strings at every boundary so the drift can never resurface silently.
 *
 * Time is frozen so `diffInMinutes(now())` lands on the exact integer.
 */
afterEach(function () {
    Carbon::setTestNow();
});

function kdsOrderAged(int $agingMinutes): array
{
    Carbon::setTestNow(Carbon::parse('2026-07-11 12:00:00'));

    $order = CustomerOrder::factory()->create([
        'opened_at' => now()->subMinutes($agingMinutes),
    ]);
    $order->load(['items', 'tables']);

    return (new KdsOrderResource($order))->toArray(Request::create('/'));
}

// ---------------------------------------------------------------------------
// Lower boundary: normal → warning at exactly 5 minutes
// ---------------------------------------------------------------------------

it('priority stays normal at aging exactly 4 minutes', function () {
    $resource = kdsOrderAged(4);

    expect($resource['aging_minutes'])->toBe(4);
    expect($resource['priority'])->toBe('normal');
    expect($resource['is_late'])->toBeFalse();
});

it('priority flips to warning at aging exactly 5 minutes (< 5 boundary)', function () {
    $resource = kdsOrderAged(5);

    expect($resource['aging_minutes'])->toBe(5);
    expect($resource['priority'])->toBe('warning');
    expect($resource['is_late'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// Upper boundary: warning → critical at exactly 10 minutes
// ---------------------------------------------------------------------------

it('priority stays warning at aging exactly 9 minutes', function () {
    $resource = kdsOrderAged(9);

    expect($resource['aging_minutes'])->toBe(9);
    expect($resource['priority'])->toBe('warning');
    expect($resource['is_late'])->toBeFalse();
});

it('priority flips to critical at aging exactly 10 minutes (< 10 boundary)', function () {
    $resource = kdsOrderAged(10);

    expect($resource['aging_minutes'])->toBe(10);
    expect($resource['priority'])->toBe('critical');
    // is_late uses `aging > 10` (strict) — exactly 10 is critical but NOT late.
    // KdsAggregateMeta documents this deliberate boundary divergence.
    expect($resource['is_late'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// is_late strict-boundary: critical AND late only past 10 minutes
// ---------------------------------------------------------------------------

it('order becomes late only once aging exceeds 10 minutes', function () {
    $resource = kdsOrderAged(11);

    expect($resource['aging_minutes'])->toBe(11);
    expect($resource['priority'])->toBe('critical');
    expect($resource['is_late'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// Vocabulary lock: only the {normal, warning, critical} trio is ever emitted
// (guards against drift toward TESTS.md's stale {urgent, warn, normal}).
// ---------------------------------------------------------------------------

it('priority only ever emits the normal/warning/critical vocabulary', function () {
    foreach ([0, 4, 5, 9, 10, 11, 30] as $aging) {
        $resource = kdsOrderAged($aging);

        expect($resource['priority'])->toBeIn(['normal', 'warning', 'critical']);
        expect($resource['priority'])->not->toBeIn(['urgent', 'warn']);
    }
});

// ---------------------------------------------------------------------------
// Item resource carries NO priority field (contract lock).
//
// TESTS.md line 34 claims each KdsItemResource has a derived `priority`
// ("urgent"|"warn"|"normal"). The shipped KdsItemResource has no such field —
// priority is an order-level aggregate only. This pins the actual contract so
// the doc drift cannot leak into code.
// ---------------------------------------------------------------------------

it('KdsItemResource does not expose a priority field', function () {
    $order = CustomerOrder::factory()->create();
    $item = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'status' => 'pending',
    ]);

    $resource = (new KdsItemResource($item))->toArray(Request::create('/'));

    expect($resource)->not->toHaveKey('priority');
});
