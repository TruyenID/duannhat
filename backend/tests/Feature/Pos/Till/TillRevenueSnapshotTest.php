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
use App\Services\Pos\TillSessionService;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Issue #552 (defense-in-depth) — a settled shift's recognized revenue is
 * FROZEN at close. reconcile() computes revenue live for an open shift, but
 * once close()/settleFromWorkstation()/manualSettle() stamp the settled_*_amount
 * snapshot, reconcile() returns those frozen figures verbatim. This makes the
 * Z-report immutable against ANY later payment mutation stamped to the closed
 * shift (a stray confirm, a post-close order edit) — not just the confirm()
 * re-stamp/detach path.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'revenue-snapshot-shop',
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate(
        ['slug' => 'org-staff'],
        ['name' => 'Org Staff', 'level' => 10],
    );

    $this->cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->cashier->assignRole($role, $this->orgId);
    grantOrgAccess($this->cashier, $this->orgId);

    $this->jpy10000 = Denomination::factory()->jpy10000()->create();
    $this->jpy1000 = Denomination::factory()->jpy1000()->create();

    TillTenderType::factory()->cash()->create(['organization_id' => $this->orgId, 'branch_id' => null]);
    TillTenderType::factory()->credit()->create(['organization_id' => $this->orgId, 'branch_id' => null]);

    $this->cardMethod = PaymentMethod::factory()->card()->create(['organization_id' => $this->orgId]);
});

function actingAsSnapshotCashier(): TestCase
{
    /** @var TestCase $t */
    $t = test();

    return $t->actingAs($t->cashier)->withHeader('X-Shop-Slug', $t->shop->slug);
}

/** Open a shift with a 105,000 float. */
function openSnapshotShift(): array
{
    return actingAsSnapshotCashier()
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

/** Stamp a succeeded card sale (order + payment) of $amount to $sessionId. */
function stampSnapshotSale(object $ctx, string $sessionId, float $amount): OrderPayment
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
        'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => $ctx->shop->id,
        'brand_id' => $ctx->brand->id,
        'organization_id' => $ctx->orgId,
    ]);

    return OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $ctx->cardMethod->id,
        'amount' => $amount,
        'status' => 'succeeded',
        'refund_of_id' => null,
        'till_session_id' => $sessionId,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $ctx->orgId,
        'branch_id' => $ctx->shop->id,
        'brand_id' => $ctx->brand->id,
    ]);
}

it('close() freezes recognized revenue so a later payment cannot retro-grow a settled Z-report (#552)', function () {
    $session = openSnapshotShift(); // float 105,000

    // A 12,000 card sale recognized in the shift (order total 12,000).
    stampSnapshotSale($this, $session['id'], 12000);

    // Close flat: cash counted = float (no cash sale) → cash variance 0; the
    // credit slip declares exactly the 12,000 expected.
    actingAsSnapshotCashier()->postJson(
        "/api/v1/pos/till/sessions/{$session['id']}/close",
        [
            'closing_counts' => [
                ['denomination_id' => $this->jpy10000->id, 'quantity' => 10],
                ['denomination_id' => $this->jpy1000->id, 'quantity' => 5], // 105,000
            ],
            'tender_details' => [
                ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
                ['tender_key' => 'credit', 'gross_amount' => 12000, 'cancel_amount' => 0, 'terminal_batch_total' => 12000],
            ],
        ],
    )->assertOk();

    // The snapshot columns are stamped at close.
    $settled = TillSession::findOrFail($session['id']);
    expect((float) $settled->settled_gross_amount)->toBe(12000.0);
    expect((float) $settled->settled_net_amount)->toBe(12000.0);   // tax 0
    expect((float) $settled->settled_tax_amount)->toBe(0.0);
    expect((float) $settled->settled_discount_amount)->toBe(0.0);

    $svc = app(TillSessionService::class);
    expect((float) $svc->reconcile($settled)['revenue']['gross'])->toBe(12000.0);

    // Retro-mutation: a succeeded sale of 3,000 lands on the ALREADY-settled
    // shift (a stray confirm / post-close edit). Live math would make gross
    // 15,000.
    stampSnapshotSale($this, $session['id'], 3000);

    // …but the frozen snapshot keeps the settled Z-report at exactly 12,000.
    expect((float) $svc->reconcile($settled->fresh())['revenue']['gross'])->toBe(12000.0);
});

it('reconcile() reports LIVE revenue for an open shift (no snapshot yet)', function () {
    $session = openSnapshotShift();
    stampSnapshotSale($this, $session['id'], 12000);

    $open = TillSession::findOrFail($session['id']);
    expect($open->settled_gross_amount)->toBeNull();

    $svc = app(TillSessionService::class);
    expect((float) $svc->reconcile($open)['revenue']['gross'])->toBe(12000.0);

    // An open shift is still live — a second sale grows its gross.
    stampSnapshotSale($this, $session['id'], 3000);
    expect((float) $svc->reconcile($open->fresh())['revenue']['gross'])->toBe(15000.0);
});
