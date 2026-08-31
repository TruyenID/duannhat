<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Denomination;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\TillTenderType;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * RE-VERIFY on `dev` — #817: the WORKSTATION till-close path
 * (Api/V1/Workstation/TillController::close / abandon).
 *
 * Claims:
 *   (a) close() accepts CLIENT-declared counted_cash + cash_variance
 *       (`required|numeric`, no sign/cap) and derives expected = counted − variance.
 *   (b) no reconcile() — Cloud's own expected-cash math is never consulted.
 *   (c) no VARIANCE_REASON_REQUIRED — any variance settles silently.
 *   (d) no assertStatus() on close/abandon — an already-terminal session can be
 *       re-closed / re-abandoned.
 *   (e) current_session_id is nulled UNCONDITIONALLY, even when it points at a
 *       DIFFERENT open session.
 *
 * Driven through the REAL workstation routes with a real device token.
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
        'till_code' => 'MAIN',
        'default_currency_code' => 'JPY',
        'variance_tolerance_amount' => 0,
        'current_session_id' => null,
    ]);

    $this->jpy10000 = Denomination::factory()->jpy10000()->create();

    TillTenderType::factory()->cash()->create(['organization_id' => $this->orgId, 'branch_id' => null]);
    TillTenderType::factory()->credit()->create(['organization_id' => $this->orgId, 'branch_id' => null]);
});

function vtlWs(): TestCase
{
    /** @var TestCase $t */
    $t = test();

    return $t->withHeaders(['Authorization' => 'Bearer '.$t->wsToken]);
}

/** Open a shift through the REAL workstation sync-UP route. Float = 100,000. */
function vtlWsOpen(?string $id = null): string
{
    $id ??= (string) Str::uuid();

    vtlWs()->postJson('/api/v1/workstation/till/sessions', [
        'id' => $id,
        'session_code' => 'SHIFT-WS-'.Str::upper(Str::random(6)),
        'currency_code' => 'JPY',
        'opening_float_amount' => 100000,
        'opened_at' => now()->toIso8601String(),
        'opening_counts' => [
            ['denomination_id' => test()->jpy10000->id, 'quantity' => 10],
        ],
    ])->assertCreated();

    return $id;
}

function vtlWsClose(string $sessionId, array $payload): TestResponse
{
    return vtlWs()->postJson(
        "/api/v1/workstation/till/sessions/{$sessionId}/close",
        array_merge(['closed_at' => now()->toIso8601String()], $payload),
    );
}

// =========================================================================
//  (a) + (b) + (c) — client-declared numbers, no reconcile, no reason guard
// =========================================================================

it('#817(a,b,c): workstation close takes the CLIENT numbers verbatim, derives expected = counted − variance, and never asks for a variance reason', function () {
    $sessionId = vtlWsOpen(); // real float 100,000, zero payments → Cloud expected = 100,000

    // The device declares an arbitrary counted + variance. Nothing in the
    // payload is checked against Cloud's own reconcile().
    $resp = vtlWsClose($sessionId, [
        'counted_cash' => 40000,
        'cash_variance' => -60000, // "we are 60,000 short" — no reason attached
        'closing_counts' => [
            ['denomination_id' => $this->jpy10000->id, 'quantity' => 4],
        ],
        'tender_details' => [
            ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
        ],
    ]);

    $session = TillSession::find($sessionId);

    dump([
        'http_status' => $resp->status(),
        'status' => $session->status->value,
        'cloud_true_expected_cash' => 100000.0, // float, no payments
        'stored_expected_cash_amount' => (float) $session->expected_cash_amount,
        'stored_counted_cash_amount' => (float) $session->counted_cash_amount,
        'stored_cash_variance_amount' => (float) $session->cash_variance_amount,
        'closing_note' => $session->closing_note,
    ]);

    $resp->assertOk();
    expect($session->status->value)->toBe('settled');

    // expected = counted − variance = 40,000 − (−60,000) = 100,000 … by
    // arithmetic coincidence here, but it is DERIVED, never verified.
    expect((float) $session->expected_cash_amount)->toBe(100000.0);
    expect((float) $session->counted_cash_amount)->toBe(40000.0);
    expect((float) $session->cash_variance_amount)->toBe(-60000.0);

    // A 60,000 shortfall settled with no reason. The POS route would have
    // returned 422 VARIANCE_REASON_REQUIRED.
    expect($session->closing_note)->toBeNull();
});

it('#817(a): a fabricated counted_cash is exposed as variance, never as clean expected_cash', function () {
    $sessionId = vtlWsOpen(); // Cloud truth: expected = 100,000

    // Device claims it counted 999,999 with zero variance → Cloud stores
    // expected = 999,999 and variance = 0. Perfectly clean Z-report, invented.
    vtlWsClose($sessionId, [
        'counted_cash' => 999999,
        'cash_variance' => 0,
        'closing_counts' => [],
        'tender_details' => [],
    ])->assertOk();

    $session = TillSession::find($sessionId);

    dump([
        'cloud_true_expected_cash' => 100000.0,
        'stored_expected_cash_amount' => (float) $session->expected_cash_amount,
        'stored_counted_cash_amount' => (float) $session->counted_cash_amount,
        'stored_cash_variance_amount' => (float) $session->cash_variance_amount,
    ]);

    // RESOLVED (#821 #817): Cloud computes expected_cash from reconcile() (its
    // own truth = 100,000) and ignores the client's numbers as authority — the
    // device's 999,999 count lands as a glaring +899,999 variance, not a clean
    // zero. A fabricated Z-report is impossible.
    expect((float) $session->expected_cash_amount)->toBe(100000.0);
    expect((float) $session->counted_cash_amount)->toBe(999999.0);
    expect((float) $session->cash_variance_amount)->toBe(899999.0);
});

// =========================================================================
//  (d) — no assertStatus(): a settled session can be re-closed / re-abandoned
// =========================================================================

it('#817(d): re-closing a SETTLED session is idempotent and it cannot be abandoned', function () {
    $sessionId = vtlWsOpen();

    vtlWsClose($sessionId, [
        'counted_cash' => 100000,
        'cash_variance' => 0,
        'closing_counts' => [],
        'tender_details' => [],
    ])->assertOk();

    $first = TillSession::find($sessionId);
    expect($first->status->value)->toBe('settled');

    // Re-close with completely different figures. No SHIFT_NOT_OPEN guard.
    $second = vtlWsClose($sessionId, [
        'counted_cash' => 55555,
        'cash_variance' => 5555,
        'closing_counts' => [],
        'tender_details' => [],
    ]);

    $after = TillSession::find($sessionId)->refresh();

    // …and then flip the SETTLED session to ABANDONED.
    $abandon = vtlWs()->postJson("/api/v1/workstation/till/sessions/{$sessionId}/abandon", [
        'closed_at' => now()->toIso8601String(),
        'abandon_reason' => 'oops',
    ]);
    $final = TillSession::find($sessionId)->refresh();

    dump([
        'reclose_http_status' => $second->status(),
        'expected_after_reclose' => (float) $after->expected_cash_amount,
        'counted_after_reclose' => (float) $after->counted_cash_amount,
        'variance_after_reclose' => (float) $after->cash_variance_amount,
        'abandon_http_status' => $abandon->status(),
        'final_status' => $final->status->value,
    ]);

    // RESOLVED (#821 #817): the second close is an idempotent replay — it returns
    // OK but the settled figures are frozen at the first close (100,000 / 0), not
    // overwritten with the 55,555 fabrication. And a settled session cannot be
    // abandoned: the abandon is refused 409 and the status stays settled.
    $second->assertOk();
    expect((float) $after->counted_cash_amount)->toBe(100000.0);
    expect((float) $after->expected_cash_amount)->toBe(100000.0);

    $abandon->assertStatus(409);
    expect($final->status->value)->toBe('settled');
});

// =========================================================================
//  (e) — current_session_id nulled unconditionally
// =========================================================================

it('#817(e): closing session A leaves the till pointer alone when it points at a different session B', function () {
    $sessionA = vtlWsOpen();

    // A second session B exists and OWNS the till pointer. (This is exactly the
    // state the workstation's own SHIFT_ALREADY_OPEN branch warns about: an
    // offline device shift plus a Cloud shift.)
    $sessionB = TillSession::create([
        'session_code' => 'SHIFT-CLOUD-'.Str::upper(Str::random(6)),
        'status' => 'open',
        'business_date' => now()->toDateString(),
        'default_currency_code' => 'JPY',
        'opening_float_amount' => 50000,
        'opened_at' => now(),
        'till_id' => $this->till->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $this->till->update(['current_session_id' => $sessionB->id]);

    // The workstation closes its OWN session A.
    vtlWsClose($sessionA, [
        'counted_cash' => 100000,
        'cash_variance' => 0,
        'closing_counts' => [],
        'tender_details' => [],
    ])->assertOk();

    $till = Till::find($this->till->id)->refresh();
    $b = TillSession::find($sessionB->id)->refresh();

    dump([
        'closed_session' => $sessionA,
        'till_pointer_before' => $sessionB->id,
        'till_pointer_after' => $till->current_session_id,
        'session_B_status' => $b->status->value,
    ]);

    // RESOLVED (#821 #817): closing A only clears the pointer if it points at A.
    // It points at the still-open B, so the pointer is untouched and B keeps
    // receiving payment attribution.
    expect($till->current_session_id)->toBe($sessionB->id);
    expect($b->status->value)->toBe('open');
});

it('#817: compensating guard check — is there ANY variance/reason enforcement on the workstation close route?', function () {
    $sessionId = vtlWsOpen();

    // Absurd values: negative counted cash, a variance larger than any drawer.
    $resp = vtlWsClose($sessionId, [
        'counted_cash' => -100000,
        'cash_variance' => -999999999,
        'closing_counts' => [],
        'tender_details' => [],
    ]);

    $session = TillSession::find($sessionId);

    dump([
        'http_status' => $resp->status(),
        'stored_counted_cash' => (float) $session->counted_cash_amount,
        'stored_variance' => (float) $session->cash_variance_amount,
        'stored_expected' => (float) $session->expected_cash_amount,
        'status' => $session->status->value,
    ]);

    // No min:0, no cap, no reason. It settles.
    $resp->assertOk();
    expect($session->status->value)->toBe('settled');
    expect((float) $session->counted_cash_amount)->toBe(-100000.0);
});
