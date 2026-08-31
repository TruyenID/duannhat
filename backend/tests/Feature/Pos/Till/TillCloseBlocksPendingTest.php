<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Denomination;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\TillSession;
use App\Models\TillTenderType;
use App\Models\User;
use App\Omnify\Enums\TillSessionStatusEnum;
use App\Services\Pos\TillSessionService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * #552 — a shift must not settle while a LIVE pending payment is still stamped
 * to it. A card payment created in the shift but confirmed AFTER the shift
 * settled retroactively mutates the settled Z-report. Blocking the settle while
 * a live pending exists forces resolution (confirm or the 15-min expiry) first.
 *
 * "Live" = pending AND expires_at in the future. A pending past its 15-min
 * deadline is already dead (the expire-stale sweeper fails it) and must NOT
 * block the close — otherwise an abandoned mid-shift tap would strand the
 * cashier.
 *
 * The guard is on close() + settleFromWorkstation() but NOT manualSettle()
 * (the manager recovery path for an expired shift must always be able to close).
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
        'slug' => 'block-pending-shop',
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate(['slug' => 'org-staff'], ['name' => 'Org Staff', 'level' => 10]);
    $this->cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->cashier->assignRole($role, $this->orgId);
    grantOrgAccess($this->cashier, $this->orgId);

    $this->jpy10000 = Denomination::factory()->jpy10000()->create();
    $this->jpy1000 = Denomination::factory()->jpy1000()->create();

    TillTenderType::factory()->cash()->create(['organization_id' => $this->orgId, 'branch_id' => null]);
    TillTenderType::factory()->credit()->create(['organization_id' => $this->orgId, 'branch_id' => null]);

    $this->cashMethod = PaymentMethod::factory()->cash()->create(['organization_id' => $this->orgId]);
    $this->cardMethod = PaymentMethod::factory()->card()->create(['organization_id' => $this->orgId]);
});

function actingAsPendCashier(): TestCase
{
    /** @var TestCase $t */
    $t = test();

    return $t->actingAs($t->cashier)->withHeader('X-Shop-Slug', $t->shop->slug);
}

/** Open a shift with a 105,000 float (10×10,000 + 5×1,000). */
function openPendShift(): array
{
    return actingAsPendCashier()
        ->postJson('/api/v1/pos/till/sessions', [
            'opening_counts' => [
                ['denomination_id' => test()->jpy10000->id, 'quantity' => 10],
                ['denomination_id' => test()->jpy1000->id, 'quantity' => 5],
            ],
            'opened_by_id' => (string) Str::uuid(),
        ])
        ->assertCreated()
        ->json('data');
}

/** Stamp a payment of a given status/expiry to the shift. */
function stampPend(object $ctx, string $sessionId, PaymentMethod $method, float $amount, string $status, ?DateTimeInterface $expiresAt): OrderPayment
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-'.random_int(100000, 999999),
        'order_type' => 'dine_in',
        'status' => 'paying',
        'subtotal' => $amount, 'discount_amount' => 0, 'service_charge' => 0,
        'tax_amount' => 0, 'total_amount' => $amount, 'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => $ctx->shop->id, 'brand_id' => $ctx->brand->id, 'organization_id' => $ctx->orgId,
    ]);

    return OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $method->id,
        'amount' => $amount,
        'status' => $status,
        'expires_at' => $expiresAt,
        'refund_of_id' => null,
        'till_session_id' => $sessionId,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $ctx->orgId,
        'branch_id' => $ctx->shop->id,
        'brand_id' => $ctx->brand->id,
    ]);
}

/**
 * Close via the real cashier route with float-matching counts (cash variance 0).
 * $creditGross declares the credit-tender total so a succeeded card sale
 * reconciles flat — otherwise VARIANCE_REASON_REQUIRED (422) masks the pending
 * guard we are actually testing.
 */
function closePendShift(string $sessionId, float $creditGross = 0): TestResponse
{
    return actingAsPendCashier()->postJson("/api/v1/pos/till/sessions/{$sessionId}/close", [
        'closing_counts' => [
            ['denomination_id' => test()->jpy10000->id, 'quantity' => 10],
            ['denomination_id' => test()->jpy1000->id, 'quantity' => 5],
        ],
        'tender_details' => [
            ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
            ['tender_key' => 'credit', 'gross_amount' => $creditGross, 'cancel_amount' => 0],
        ],
    ]);
}

it('blocks close while a LIVE pending payment is stamped to the shift', function () {
    $session = openPendShift();
    stampPend($this, $session['id'], $this->cardMethod, 8000, 'pending', now()->addMinutes(10));

    $res = closePendShift($session['id']);

    $res->assertStatus(409)->assertJsonPath('code', 'PENDING_PAYMENTS_BLOCK_CLOSE');
    expect(TillSession::find($session['id'])->status->value)->not->toBe('settled');
});

it('allows close when the only pending is already past its expiry (dead)', function () {
    $session = openPendShift();
    stampPend($this, $session['id'], $this->cardMethod, 8000, 'pending', now()->subMinute());

    closePendShift($session['id'])->assertOk()->assertJsonPath('data.status', 'settled');
});

it('allows close when payments are succeeded or failed (nothing pending)', function () {
    $session = openPendShift();
    stampPend($this, $session['id'], $this->cardMethod, 8000, 'succeeded', null);
    stampPend($this, $session['id'], $this->cardMethod, 5000, 'failed', now()->subMinutes(30));

    // Declare the 8,000 succeeded card sale so the credit tender reconciles flat.
    closePendShift($session['id'], 8000)->assertOk()->assertJsonPath('data.status', 'settled');
});

it('allows close when a pending has a NULL expiry — treated as dead, never a permanent lock', function () {
    // The expire-stale sweeper only fails rows with a non-null expires_at, so a
    // NULL-expiry pending would otherwise block the close forever. It must NOT.
    $session = openPendShift();
    stampPend($this, $session['id'], $this->cardMethod, 8000, 'pending', null);

    closePendShift($session['id'])->assertOk()->assertJsonPath('data.status', 'settled');
});

it('blocks settleFromWorkstation while a live pending exists', function () {
    $session = openPendShift();
    stampPend($this, $session['id'], $this->cardMethod, 8000, 'pending', now()->addMinutes(10));

    $model = TillSession::findOrFail($session['id']);

    try {
        app(TillSessionService::class)->settleFromWorkstation($model, [
            'counted_cash' => 105000,
            'closing_counts' => [
                ['denomination_id' => $this->jpy10000->id, 'quantity' => 10],
                ['denomination_id' => $this->jpy1000->id, 'quantity' => 5],
            ],
            'tender_details' => [],
        ]);
        $this->fail('Expected settleFromWorkstation to be blocked by the live pending.');
    } catch (HttpResponseException $e) {
        expect($e->getResponse()->getStatusCode())->toBe(409);
    }

    expect(TillSession::find($session['id'])->status->value)->not->toBe('settled');
});

it('does NOT block manualSettle — the manager recovery path always closes an expired shift', function () {
    $session = openPendShift();
    stampPend($this, $session['id'], $this->cardMethod, 8000, 'pending', now()->addMinutes(10));

    // Manager recovers a shift the reaper marked expired, even with a lingering pending.
    $model = TillSession::findOrFail($session['id']);
    $model->update(['status' => TillSessionStatusEnum::Expired->value]);

    $manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $managerRole = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $manager->assignRole($managerRole, $this->orgId);
    grantOrgAccess($manager, $this->orgId);

    $settled = app(TillSessionService::class)->manualSettle($model->fresh(), [
        'closing_counts' => [
            ['denomination_id' => $this->jpy10000->id, 'quantity' => 10],
            ['denomination_id' => $this->jpy1000->id, 'quantity' => 5],
        ],
        'tender_details' => [],
        'manual_settle_reason' => 'cashier left mid-shift',
    ], $manager);

    expect($settled->status->value)->toBe('settled');
});
