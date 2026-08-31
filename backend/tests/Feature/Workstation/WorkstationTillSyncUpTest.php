<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Denomination;
use App\Models\Device;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Till;
use App\Models\TillCashDenominationCount;
use App\Models\TillCashEvent;
use App\Models\TillSession;
use App\Models\TillSettlementTenderDetail;
use App\Models\TillTenderType;
use App\Models\User;
use App\Omnify\Enums\TillCountPhaseEnum;
use App\Omnify\Enums\TillSessionStatusEnum;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Issue #817 — workstation → Cloud push UP for cashier shifts is now
 * Cloud-authoritative. The workstation used to ship a pre-computed
 * counted_cash + cash_variance and Cloud fabricated expected = counted −
 * variance, so a declared "variance = 0" hid any missing cash and a stale
 * replay could overwrite a settled Z-report or orphan a live shift.
 *
 * Cloud now runs reconcile() (against payments synced with till_session_id),
 * computes variance itself, and applies the idempotent-replay + till-pointer
 * guards. Hard VARIANCE_REASON_REQUIRED enforcement is deferred to Phase B
 * (needs the workstation client to capture the reason pre-close) — Phase A
 * settles and *exposes* an unreasoned variance via an audit row.
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

    $this->till = Till::factory()->create([
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'variance_tolerance_amount' => 0,
    ]);

    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);

    // Denomination + tender types are seeded on Cloud first; the workstation
    // references their UUIDs after sync_pull DOWN.
    $this->denomination = Denomination::create([
        'id' => (string) Str::uuid(),
        'currency_code' => 'JPY',
        'value' => 10000,
        'kind' => 'note',
        'label' => '¥10,000',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    TillTenderType::factory()->cash()->create(['organization_id' => $this->orgId, 'branch_id' => null]);
    TillTenderType::factory()->credit()->create(['organization_id' => $this->orgId, 'branch_id' => null]);

    $this->cashMethod = PaymentMethod::factory()->cash()->create(['organization_id' => $this->orgId]);
    $this->cardMethod = PaymentMethod::factory()->card()->create(['organization_id' => $this->orgId]);
});

// ─── Helpers ────────────────────────────────────────────────────────────────

function tsuHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->wsToken];
}

/** Push an offline `open` UP. Float is DERIVED from opening_counts server-side. */
function tsuOpenShift(string $sessionId, int $qty10k = 3, array $opts = []): TestResponse
{
    return test()->withHeaders(tsuHeaders())->postJson('/api/v1/workstation/till/sessions', array_merge([
        'id' => $sessionId,
        'session_code' => 'WS-'.substr($sessionId, 0, 8),
        'till_id' => test()->till->id,
        'branch_id' => test()->branch->id,
        'currency_code' => 'JPY',
        'opening_float_amount' => $qty10k * 10000,
        'opened_at' => now()->subHours(8)->toIso8601String(),
        'opening_counts' => [
            ['denomination_id' => test()->denomination->id, 'quantity' => $qty10k],
        ],
    ], $opts));
}

/** Stamp a succeeded sale (order + payment) attributed to $sessionId — as if synced. */
function tsuStampSale(string $sessionId, PaymentMethod $method, float $amount, float $tip = 0): OrderPayment
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-'.random_int(100000, 999999),
        'order_type' => 'dine_in',
        'status' => 'closed',
        'subtotal' => $amount,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => $amount,
        'paid_amount' => $amount,
        'total_tip' => $tip,
        'opened_at' => now(),
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);

    return OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $method->id,
        'amount' => $amount,
        'tip_amount' => $tip,
        'status' => 'succeeded',
        'refund_of_id' => null,
        'till_session_id' => $sessionId,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
    ]);
}

/** Push an offline `close` UP. */
function tsuCloseShift(string $sessionId, array $overrides = []): TestResponse
{
    return test()->withHeaders(tsuHeaders())->postJson(
        "/api/v1/workstation/till/sessions/{$sessionId}/close",
        array_merge([
            'closed_at' => now()->toIso8601String(),
            'closing_counts' => [],
            'tender_details' => [],
            'counted_cash' => 0,
        ], $overrides)
    );
}

// ─── openSession ─────────────────────────────────────────────────────────────

it('lands a complete TillSession row and DERIVES the opening float from counts', function () {
    $sessionId = (string) Str::uuid();

    // Payload float is deliberately wrong — Cloud must ignore it and derive
    // 3 × ¥10,000 = 30,000 from opening_counts (#817).
    tsuOpenShift($sessionId, 3, ['opening_float_amount' => 999999])
        ->assertCreated()
        ->assertJsonPath('data.id', $sessionId);

    $session = TillSession::findOrFail($sessionId);
    expect($session->status->value)->toBe(TillSessionStatusEnum::Open->value);
    expect((float) $session->opening_float_amount)->toBe(30000.0); // derived, not 999999

    $count = TillCashDenominationCount::where('session_id', $sessionId)->firstOrFail();
    expect($count->count_phase->value)->toBe(TillCountPhaseEnum::Opening->value);
    expect((float) $count->subtotal_amount)->toBe(30000.0);
    expect($this->till->fresh()->current_session_id)->toBe($sessionId);
});

it('#1026: re-mints an offline WS- temp code as the canonical SHIFT- code (idempotent on retry)', function () {
    $sessionId = (string) Str::uuid();

    $first = tsuOpenShift($sessionId)->assertCreated()->json('data.session_code');

    expect($first)->toMatch('/^SHIFT-\d{8}-\d{3}$/')
        ->and(TillSession::findOrFail($sessionId)->session_code)->toBe($first);

    // Retry (same client uuid) returns the SAME re-minted code — no double mint.
    $retry = tsuOpenShift($sessionId)->assertOk()->json('data.session_code');
    expect($retry)->toBe($first);
});

it('#1026: a non-WS session_code is stored verbatim (POS/Cloud codes untouched)', function () {
    $sessionId = (string) Str::uuid();

    tsuOpenShift($sessionId, 3, ['session_code' => 'SHIFT-20260101-042'])
        ->assertCreated()
        ->assertJsonPath('data.session_code', 'SHIFT-20260101-042');
});

it('rejects a foreign-currency denomination on open (DENOMINATION_CURRENCY_MISMATCH)', function () {
    $usd = Denomination::create([
        'id' => (string) Str::uuid(),
        'currency_code' => 'USD',
        'value' => 100,
        'kind' => 'note',
        'label' => '$100',
        'sort_order' => 1,
        'is_active' => true,
    ]);
    $sessionId = (string) Str::uuid();

    tsuOpenShift($sessionId, 3, [
        'opening_counts' => [
            ['denomination_id' => $this->denomination->id, 'quantity' => 3],
            ['denomination_id' => $usd->id, 'quantity' => 1],
        ],
    ])->assertStatus(422)->assertJsonPath('code', 'DENOMINATION_CURRENCY_MISMATCH');

    // Transaction rolled back — no partial session.
    expect(TillSession::find($sessionId))->toBeNull();
});

it('refuses to land a duplicate session id (idempotent retry)', function () {
    $sessionId = (string) Str::uuid();
    tsuOpenShift($sessionId)->assertCreated();
    tsuOpenShift($sessionId)->assertOk(); // retry returns the existing row

    expect(TillSession::where('id', $sessionId)->count())->toBe(1);
    expect(TillCashDenominationCount::where('session_id', $sessionId)->count())->toBe(1);
});

// ─── close: Cloud-authoritative reconcile ────────────────────────────────────

it('computes expected via reconcile and variance = counted − expected on close', function () {
    $sessionId = (string) Str::uuid();
    tsuOpenShift($sessionId)->assertCreated();          // float 30,000
    tsuStampSale($sessionId, $this->cashMethod, 110000); // cash sales 110,000

    // expected = 30,000 float + 110,000 cash = 140,000; drawer counts 140,000.
    tsuCloseShift($sessionId, [
        'closing_counts' => [['denomination_id' => $this->denomination->id, 'quantity' => 14]],
        'counted_cash' => 140000,
        'cash_variance' => 0,
        'closing_note' => 'EOD',
    ])->assertOk();

    $session = TillSession::findOrFail($sessionId);
    expect($session->status->value)->toBe(TillSessionStatusEnum::Settled->value);
    expect((float) $session->expected_cash_amount)->toBe(140000.0); // reconciled, not counted − declared
    expect((float) $session->counted_cash_amount)->toBe(140000.0);
    expect((float) $session->cash_variance_amount)->toBe(0.0);
    expect($this->till->fresh()->current_session_id)->toBeNull();

    // Settlement details now carry per-tender expected (pre-#817 they were 0).
    $credit = TillSettlementTenderDetail::where('session_id', $sessionId)
        ->where('tender_key', 'credit')->firstOrFail();
    expect((float) $credit->expected_amount)->toBe(0.0); // no card sales
});

it('ignores the client cash_variance so a variance=0 claim cannot hide missing cash', function () {
    $sessionId = (string) Str::uuid();
    tsuOpenShift($sessionId)->assertCreated();          // float 30,000
    tsuStampSale($sessionId, $this->cashMethod, 110000); // expected 140,000

    // Drawer is ¥10,000 SHORT but the device declares variance = 0.
    tsuCloseShift($sessionId, [
        'closing_counts' => [['denomination_id' => $this->denomination->id, 'quantity' => 13]],
        'counted_cash' => 130000,
        'cash_variance' => 0, // the fabrication — must be ignored
    ])->assertOk(); // settles (Phase A does not hard-block)

    $session = TillSession::findOrFail($sessionId);
    expect((float) $session->expected_cash_amount)->toBe(140000.0);
    expect((float) $session->counted_cash_amount)->toBe(130000.0);
    expect((float) $session->cash_variance_amount)->toBe(-10000.0); // the TRUE shortage

    // Exposed for manager triage rather than silently accepted.
    expect(AuditLog::where('auditable_id', $sessionId)
        ->where('action', 'till_session_settled_variance_unreviewed')->exists())->toBeTrue();
});

it('keeps counted_cash device-owned via a SIGNED adjustment (uncountable shortage surfaces)', function () {
    $sessionId = (string) Str::uuid();
    tsuOpenShift($sessionId)->assertCreated();          // float 30,000
    tsuStampSale($sessionId, $this->cashMethod, 110000); // expected 140,000

    // Denominations sum to 140,000 but the physical count is 300 short.
    tsuCloseShift($sessionId, [
        'closing_counts' => [['denomination_id' => $this->denomination->id, 'quantity' => 14]],
        'counted_cash' => 139700,
    ])->assertOk();

    $session = TillSession::findOrFail($sessionId);
    expect((float) $session->counted_cash_amount)->toBe(139700.0);
    expect((float) $session->closing_cash_adjustment_amount)->toBe(-300.0); // NOT clamped to 0
    expect((float) $session->cash_variance_amount)->toBe(-300.0);
});

it('is idempotent on replay — a re-close never rewrites a settled Z-report', function () {
    $sessionId = (string) Str::uuid();
    tsuOpenShift($sessionId)->assertCreated();
    tsuStampSale($sessionId, $this->cashMethod, 110000);
    tsuCloseShift($sessionId, [
        'closing_counts' => [['denomination_id' => $this->denomination->id, 'quantity' => 14]],
        'counted_cash' => 140000,
    ])->assertOk();

    // A payment syncs AFTER settle, and a bogus replay arrives.
    tsuStampSale($sessionId, $this->cashMethod, 50000);
    tsuCloseShift($sessionId, [
        'closing_counts' => [['denomination_id' => $this->denomination->id, 'quantity' => 1]],
        'counted_cash' => 999999,
    ])->assertOk();

    $session = TillSession::findOrFail($sessionId);
    // Frozen — NOT re-reconciled to 190,000, NOT overwritten by 999,999.
    expect((float) $session->expected_cash_amount)->toBe(140000.0);
    expect((float) $session->counted_cash_amount)->toBe(140000.0);
});

it('does not orphan a newer shift when a stale close(W) replays after A opened', function () {
    $wId = (string) Str::uuid();
    tsuOpenShift($wId)->assertCreated();
    tsuStampSale($wId, $this->cashMethod, 110000);
    tsuCloseShift($wId, [
        'closing_counts' => [['denomination_id' => $this->denomination->id, 'quantity' => 14]],
        'counted_cash' => 140000,
    ])->assertOk();

    // Next shift A opens on the same till.
    $aId = (string) Str::uuid();
    tsuOpenShift($aId)->assertCreated();
    expect($this->till->fresh()->current_session_id)->toBe($aId);

    // Stale replay of W's close must be a no-op and leave A's pointer intact.
    tsuCloseShift($wId, [
        'closing_counts' => [['denomination_id' => $this->denomination->id, 'quantity' => 14]],
        'counted_cash' => 140000,
    ])->assertOk();

    expect($this->till->fresh()->current_session_id)->toBe($aId);
});

it('folds safe-flow cash events into the reconciled expected on the workstation close', function () {
    $sessionId = (string) Str::uuid();
    tsuOpenShift($sessionId)->assertCreated(); // float 30,000

    // +20,000 loan from safe, −5,000 pickup to safe → expected 45,000.
    $this->withHeaders(tsuHeaders())->postJson("/api/v1/workstation/till/sessions/{$sessionId}/cash-events", [
        'id' => (string) Str::uuid(), 'event_type' => 'loan_from_safe', 'amount' => 20000,
        'currency_code' => 'JPY', 'occurred_at' => now()->toIso8601String(),
    ])->assertCreated();
    $this->withHeaders(tsuHeaders())->postJson("/api/v1/workstation/till/sessions/{$sessionId}/cash-events", [
        'id' => (string) Str::uuid(), 'event_type' => 'pickup_to_safe', 'amount' => 5000,
        'currency_code' => 'JPY', 'occurred_at' => now()->toIso8601String(),
    ])->assertCreated();

    tsuCloseShift($sessionId, [
        'closing_counts' => [['denomination_id' => $this->denomination->id, 'quantity' => 4]],
        'counted_cash' => 45000,
    ])->assertOk();

    $session = TillSession::findOrFail($sessionId);
    expect((float) $session->expected_cash_amount)->toBe(45000.0);
    expect((float) $session->cash_variance_amount)->toBe(0.0);
});

it('merges card_terminal into the card anchor when reconciling a workstation close', function () {
    $cardTerminalMethod = PaymentMethod::factory()->cardTerminal()->create(['organization_id' => $this->orgId]);

    $sessionId = (string) Str::uuid();
    tsuOpenShift($sessionId)->assertCreated();
    tsuStampSale($sessionId, $this->cardMethod, 8000);
    tsuStampSale($sessionId, $cardTerminalMethod, 4000); // same physical terminal → merges into card

    // credit anchor expected = 8,000 + 4,000 = 12,000; declaring 12,000 → flat.
    tsuCloseShift($sessionId, [
        'closing_counts' => [['denomination_id' => $this->denomination->id, 'quantity' => 3]],
        'counted_cash' => 30000,
        'tender_details' => [
            ['tender_key' => 'credit', 'gross_amount' => 12000, 'cancel_amount' => 0, 'terminal_batch_total' => 12000],
        ],
    ])->assertOk();

    $credit = TillSettlementTenderDetail::where('session_id', $sessionId)
        ->where('tender_key', 'credit')->firstOrFail();
    expect((float) $credit->expected_amount)->toBe(12000.0);
    expect((float) $credit->variance_amount)->toBe(0.0);
});

it('drops an unknown tender_key instead of dead-lettering the offline close', function () {
    $sessionId = (string) Str::uuid();
    tsuOpenShift($sessionId)->assertCreated();
    tsuStampSale($sessionId, $this->cashMethod, 110000);

    // Payload carries a tender_key that isn't seeded on Cloud — must be skipped,
    // NOT a 422 (that would dead-letter the sync item and orphan the till).
    tsuCloseShift($sessionId, [
        'closing_counts' => [['denomination_id' => $this->denomination->id, 'quantity' => 14]],
        'counted_cash' => 140000,
        'tender_details' => [
            ['tender_key' => 'invented_wallet', 'gross_amount' => 5000],
        ],
    ])->assertOk();

    $session = TillSession::findOrFail($sessionId);
    expect($session->status->value)->toBe(TillSessionStatusEnum::Settled->value);
    expect(TillSettlementTenderDetail::where('session_id', $sessionId)
        ->where('tender_key', 'invented_wallet')->exists())->toBeFalse();
});

it('honours the variance tolerance when flagging an unreviewed variance', function () {
    $this->till->update(['variance_tolerance_amount' => 500]);

    $sessionId = (string) Str::uuid();
    tsuOpenShift($sessionId)->assertCreated();
    tsuStampSale($sessionId, $this->cashMethod, 110000); // expected 140,000

    // −400 variance is within the 500 tolerance → settle, no unreviewed flag.
    tsuCloseShift($sessionId, [
        'closing_counts' => [['denomination_id' => $this->denomination->id, 'quantity' => 13]],
        'counted_cash' => 139600, // adjustment +9,600, variance −400
    ])->assertOk();

    expect((float) TillSession::findOrFail($sessionId)->cash_variance_amount)->toBe(-400.0);
    expect(AuditLog::where('auditable_id', $sessionId)
        ->where('action', 'till_session_settled_variance_unreviewed')->exists())->toBeFalse();
});

// ─── cash events ─────────────────────────────────────────────────────────────

it('lands a TillCashEvent with the workstation-supplied id (HasUuids workaround)', function () {
    $sessionId = (string) Str::uuid();
    tsuOpenShift($sessionId)->assertCreated();

    $eventId = (string) Str::uuid();
    $payload = [
        'id' => $eventId, 'event_type' => 'paid_out', 'amount' => 1500,
        'currency_code' => 'JPY', 'reason' => 'tip out', 'occurred_at' => now()->toIso8601String(),
    ];
    $this->withHeaders(tsuHeaders())
        ->postJson("/api/v1/workstation/till/sessions/{$sessionId}/cash-events", $payload)
        ->assertCreated();

    $event = TillCashEvent::find($eventId);
    expect($event)->not->toBeNull();
    expect($event->session_id)->toBe($sessionId);
    expect((float) $event->amount)->toBe(1500.0);

    // Idempotent retry.
    $this->withHeaders(tsuHeaders())
        ->postJson("/api/v1/workstation/till/sessions/{$sessionId}/cash-events", $payload)
        ->assertOk();
    expect(TillCashEvent::where('id', $eventId)->count())->toBe(1);
});

it('never mints a second cash row when the workstation replays the same id', function () {
    // The device id IS the idempotency key of the sync queue. Counting rows by
    // that id alone is not enough: if the id were dropped (HasUuids mints a
    // fresh UUIDv7 per insert) the by-id count would read 0 and 1 while TWO
    // rows sat on the session — a doubled 入金/出金 that moves the 過不足 on
    // close. So count the SESSION's rows, which is where the money is read.
    $sessionId = (string) Str::uuid();
    tsuOpenShift($sessionId)->assertCreated();

    $payload = [
        'id' => (string) Str::uuid(), 'event_type' => 'loan_from_safe', 'amount' => 20000,
        'currency_code' => 'JPY', 'occurred_at' => now()->toIso8601String(),
    ];

    $this->withHeaders(tsuHeaders())
        ->postJson("/api/v1/workstation/till/sessions/{$sessionId}/cash-events", $payload)
        ->assertCreated();
    // A retry is a 200 (not a 201) and must not write anything.
    $this->withHeaders(tsuHeaders())
        ->postJson("/api/v1/workstation/till/sessions/{$sessionId}/cash-events", $payload)
        ->assertOk();
    $this->withHeaders(tsuHeaders())
        ->postJson("/api/v1/workstation/till/sessions/{$sessionId}/cash-events", $payload)
        ->assertOk();

    expect(TillCashEvent::where('session_id', $sessionId)->count())->toBe(1);
});

it('stores the workstation occurred_at in the app timezone so the instant round-trips', function () {
    // #1091 — the workstation ships `occurred_at` as UTC ("…Z"). Under a non-UTC
    // APP_TIMEZONE (a JP deployment) Eloquent WRITES the Carbon in the Carbon's
    // own tz but READS it back in the app tz, so a raw UTC wall-clock lands
    // shifted by the whole offset: an 出金 recorded at 00:30 JST would be stored
    // as 00:30 and re-read as the previous day 15:30Z — landing OUTSIDE the
    // shift it belongs to. Same normalisation openFromWorkstation applies to
    // `opened_at`.
    //
    // phpunit.xml pins APP_TIMEZONE=UTC (where the cast is a no-op) and the test
    // harness re-hydrates model dates as UTC regardless of the PHP default tz —
    // so force config('app.timezone') and assert on the RAW stored string, which
    // is what production actually re-reads.
    $prevAppTz = config('app.timezone');
    config(['app.timezone' => 'Asia/Tokyo']);

    try {
        $sessionId = (string) Str::uuid();
        tsuOpenShift($sessionId)->assertCreated();

        $eventId = (string) Str::uuid();
        $occurredUtc = Carbon::parse('2026-07-23T15:30:00Z'); // 2026-07-24 00:30 JST

        $this->withHeaders(tsuHeaders())
            ->postJson("/api/v1/workstation/till/sessions/{$sessionId}/cash-events", [
                'id' => $eventId, 'event_type' => 'pickup_to_safe', 'amount' => 5000,
                'currency_code' => 'JPY', 'occurred_at' => $occurredUtc->toIso8601String(),
            ])->assertCreated();

        $raw = substr((string) DB::table('till_cash_events')->where('id', $eventId)->value('occurred_at'), 0, 19);

        // Stored as the app-tz wall-clock (00:30 on the 24th), NOT the raw UTC
        // wall-clock (15:30 on the 23rd)…
        expect($raw)->toBe('2026-07-24 00:30:00')
            // …so re-hydrating it in the app tz (as production does) yields the
            // SAME instant the workstation sent.
            ->and(Carbon::createFromFormat('Y-m-d H:i:s', $raw, 'Asia/Tokyo')->equalTo($occurredUtc))->toBeTrue();
    } finally {
        config(['app.timezone' => $prevAppTz]);
    }
});

// ─── abandon ─────────────────────────────────────────────────────────────────

it('stamps abandoned_at (NOT closed_at) and releases the till on abandon', function () {
    $sessionId = (string) Str::uuid();
    tsuOpenShift($sessionId)->assertCreated();

    $this->withHeaders(tsuHeaders())->postJson("/api/v1/workstation/till/sessions/{$sessionId}/abandon", [
        'abandon_reason' => 'Opened by mistake.',
        'closed_at' => now()->toIso8601String(),
    ])->assertOk();

    $session = TillSession::findOrFail($sessionId);
    expect($session->status->value)->toBe(TillSessionStatusEnum::Abandoned->value);
    expect($session->abandoned_at)->not->toBeNull();
    expect($session->closed_at)->toBeNull();
    expect($this->till->fresh()->current_session_id)->toBeNull();

    // Idempotent re-abandon.
    $this->withHeaders(tsuHeaders())->postJson("/api/v1/workstation/till/sessions/{$sessionId}/abandon", [
        'abandon_reason' => 'Opened by mistake.',
        'closed_at' => now()->toIso8601String(),
    ])->assertOk();
});

it('refuses to abandon a shift that already settled (SHIFT_TERMINAL_STATE)', function () {
    $sessionId = (string) Str::uuid();
    tsuOpenShift($sessionId)->assertCreated();
    tsuStampSale($sessionId, $this->cashMethod, 110000);
    tsuCloseShift($sessionId, [
        'closing_counts' => [['denomination_id' => $this->denomination->id, 'quantity' => 14]],
        'counted_cash' => 140000,
    ])->assertOk();

    $this->withHeaders(tsuHeaders())->postJson("/api/v1/workstation/till/sessions/{$sessionId}/abandon", [
        'closed_at' => now()->toIso8601String(),
    ])->assertStatus(409)->assertJsonPath('code', 'SHIFT_TERMINAL_STATE');

    expect(TillSession::findOrFail($sessionId)->status->value)->toBe(TillSessionStatusEnum::Settled->value);
});

// ─── plan-046 — chain sync UP ────────────────────────────────────────────────

it('syncs a workstation HANDOVER: settlement_kind lands on Cloud + snapshot returned', function () {
    $chainId = (string) Str::uuid();
    $sessionId = (string) Str::uuid();
    tsuOpenShift($sessionId, 3, [
        'chain_id' => $chainId,
        'chain_sequence' => 1,
    ])->assertCreated();

    // A workstation handover POSTs to THIS /close route with settlement_kind=handover
    // (P7-C — there is no separate Cloud handover route). WITHOUT the validation
    // rule the key is stripped and this would be stored as a final settle.
    $res = tsuCloseShift($sessionId, [
        'settlement_kind' => 'handover',
        'chain_id' => $chainId,
        'chain_sequence' => 1,
        'counted_cash' => 30000,
    ])->assertOk();

    $session = TillSession::findOrFail($sessionId);
    expect($session->settlement_kind->value)->toBe('handover');
    expect($session->chain_id)->toBe($chainId);
    expect((int) $session->chain_sequence)->toBe(1);
    expect($session->settlement_snapshot)->toBeArray();

    // shape() returns the authoritative snapshot INSIDE data (G3) so the workstation
    // adopts it via the sync-UP response write-back (R7 adopt-if-present).
    $res->assertJsonPath('data.settlement_kind', 'handover');
    expect($res->json('data.settlement_snapshot'))->not->toBeNull();
});

it('syncs a workstation FINAL close: settlement_kind=final (default when omitted)', function () {
    $sessionId = (string) Str::uuid();
    tsuOpenShift($sessionId, 3)->assertCreated();

    // No settlement_kind in the payload → defaults to final (backward-compat).
    tsuCloseShift($sessionId, ['counted_cash' => 30000])->assertOk();

    expect(TillSession::findOrFail($sessionId)->settlement_kind->value)->toBe('final');
});

/**
 * #1704 — đường POS ghi vết cho đúng thao tác này, đường máy trạm thì không.
 *
 * Máy trạm là nơi PHẦN LỚN tiền mặt đi qua ở cửa hàng chạy POS offline, nên
 * thiếu vết ở đây nghĩa là khi 過不足 lệch thì câu hỏi "ai bỏ tiền vào, lúc nào"
 * không trả lời được.
 *
 * HAI mốc, cố ý: `occurred_at` trong metadata là lúc tiền thật sự vào ngăn kéo
 * (mốc CŨ với sự kiện replay offline), còn `created_at` của dòng audit là lúc
 * Cloud nhận. Ghi một cái rồi bỏ cái kia là làm bản kiểm nói dối theo một trong
 * hai hướng.
 */
it('#1704: cash event từ máy trạm để lại vết audit, và replay KHÔNG sinh vết thứ hai', function () {
    $sessionId = (string) Str::uuid();
    tsuOpenShift($sessionId)->assertCreated();

    $eventId = (string) Str::uuid();
    $occurred = Carbon::parse('2026-07-23T15:30:00Z');
    $payload = [
        'id' => $eventId, 'event_type' => 'pickup_to_safe', 'amount' => 5000,
        'currency_code' => 'JPY', 'occurred_at' => $occurred->toIso8601String(),
    ];

    $this->withHeaders(tsuHeaders())
        ->postJson("/api/v1/workstation/till/sessions/{$sessionId}/cash-events", $payload)
        ->assertCreated();

    $rows = AuditLog::query()->where('action', 'till_cash_event_recorded')->get();

    expect($rows)->toHaveCount(1);

    $meta = $rows->first()->metadata;
    expect($meta['source'])->toBe('workstation')
        ->and($meta['cash_event_id'])->toBe($eventId)
        ->and((float) $meta['amount'])->toBe(5000.0)
        // Mốc SỰ KIỆN, không phải mốc nhận — chúng khác nhau ở replay offline.
        ->and(Carbon::parse($meta['occurred_at'])->equalTo($occurred))->toBeTrue();

    // Replay idempotent: một dòng tiền, và cũng chỉ MỘT dòng vết.
    $this->withHeaders(tsuHeaders())
        ->postJson("/api/v1/workstation/till/sessions/{$sessionId}/cash-events", $payload)
        ->assertOk();

    expect(TillCashEvent::where('session_id', $sessionId)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'till_cash_event_recorded')->count())->toBe(1);
});
