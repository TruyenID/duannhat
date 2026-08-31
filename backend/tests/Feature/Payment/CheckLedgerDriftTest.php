<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Services\Customer\StripePaymentService;
use Illuminate\Support\Str;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * Plan 047 Gate 4 (T4.9) — ledger-drift auditor.
 *
 * Proves `payments:check-ledger-drift` is a real gate: it stays silent (exit 0)
 * when every order's cached paid_amount ties out to the canonical projection,
 * and fails (exit 1) the moment a cache diverges — the signal a settlement-path
 * regression would trip during a pre-cutover shadow run or in CI.
 */
beforeEach(function () {
    $this->organizationId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->organizationId,
        'console_organization_id' => $this->organizationId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->organizationId,
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->organizationId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->cash = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);
});

function driftOrder(string $orgId, string $brandId, string $branchId, float $total, float $paid, string $status = 'paying'): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'branch_id' => $branchId,
        'order_type' => 'takeaway',
        'status' => $status,
        'total_amount' => $total,
        'paid_amount' => $paid,
    ]);
}

it('exits clean when every paid_amount ties out to the ledger projection', function () {
    $order = driftOrder($this->organizationId, $this->brand->id, $this->branch->id, 1000, 1000, 'closed');
    OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'amount' => 1000,
        'status' => 'succeeded',
    ]);

    $this->artisan('payments:check-ledger-drift')
        ->assertExitCode(0)
        ->expectsOutputToContain('No ledger drift found.');
});

it('detects a paid_amount cache that diverges from the ledger', function () {
    // Cache says 1000 collected, ledger only has an 800 succeeded row.
    $order = driftOrder($this->organizationId, $this->brand->id, $this->branch->id, 1000, 1000, 'closed');
    OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'amount' => 800,
        'status' => 'succeeded',
    ]);

    $this->artisan('payments:check-ledger-drift')
        ->assertExitCode(1)
        ->expectsOutputToContain('paid_amount_drift');
});

it('excludes debt-settlement rows so a rider does not read as drift', function () {
    // 1000 cache, 1000 real sale row, plus a 900 debt-settlement row that rides
    // this order but belongs to a different order's debt. netCollectedForOrder
    // excludes it, so the cache still ties out and the audit stays clean.
    $order = driftOrder($this->organizationId, $this->brand->id, $this->branch->id, 1000, 1000, 'closed');
    OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'amount' => 1000,
        'status' => 'succeeded',
    ]);
    OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'amount' => 900,
        'status' => 'succeeded',
        'metadata' => ['settles_payment_id' => (string) Str::uuid()],
    ]);

    $this->artisan('payments:check-ledger-drift')->assertExitCode(0);
});

it('scopes the scan to one organization', function () {
    // A drifted order in ANOTHER org must not fail a scan scoped to this org.
    $otherOrg = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrg, 'console_organization_id' => $otherOrg]);
    driftOrder($otherOrg, $this->brand->id, $this->branch->id, 1000, 1000, 'closed'); // no payments → drift

    $this->artisan('payments:check-ledger-drift', ['--organization' => $this->organizationId])
        ->assertExitCode(0);
});

it('stays clean after stripe webhook settlement updates the paid_amount cache', function () {
    config([
        'services.stripe.key' => 'pk_test_dummy',
        'services.stripe.secret' => 'sk_test_dummy',
        'services.stripe.currency' => 'jpy',
    ]);

    $order = driftOrder($this->organizationId, $this->brand->id, $this->branch->id, 1500, 0, 'open');
    $order->forceFill(['stripe_payment_intent_id' => 'pi_drift_clean'])->save();

    $service = new StripePaymentService(Mockery::mock(StripeClient::class));
    $service->markOrderPaidFromIntent(PaymentIntent::constructFrom([
        'id' => 'pi_drift_clean',
        'object' => 'payment_intent',
        'amount' => 1500,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'metadata' => ['flow' => 'full', 'order_currency' => 'JPY'],
    ]));

    expect((float) $order->fresh()->paid_amount)->toBe(1500.0)
        ->and(OrderPayment::netCollectedForOrder($order->id))->toBe(1500.0);

    $this->artisan('payments:check-ledger-drift')->assertExitCode(0);
});

/**
 * #1801 — a soft-deleted payment must not count toward the projection.
 *
 * The scanner reads through the raw query builder, which applies no model
 * scope; the projection it audits against (`cachedTotalsForOrder`, the writer
 * of `paid_amount`) reads through Eloquent and drops deleted rows. Without an
 * explicit `deleted_at` filter the two disagree and a healthy order is reported
 * as drifting by exactly the deleted total — a false alarm that reaches
 * `payments:observation-report --strict`, and therefore cron/CI.
 */
it('ignores soft-deleted payments, matching the projection that writes paid_amount', function () {
    $order = driftOrder($this->organizationId, $this->brand->id, $this->branch->id, 1000, 1000, 'closed');

    OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'amount' => 1000,
        'status' => 'succeeded',
    ]);

    // Deleted row carrying real money — the shape left behind after cleaning up
    // payments that were attached to the wrong order.
    $deleted = OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'amount' => 84_000,
        'status' => 'succeeded',
    ]);
    $deleted->delete();

    expect($deleted->fresh()->deleted_at)->not->toBeNull();

    $this->artisan('payments:check-ledger-drift')
        ->assertExitCode(0)
        ->expectsOutputToContain('No ledger drift found.');
});

it('does not count a soft-deleted row as an unattributed gap payment', function () {
    $order = driftOrder($this->organizationId, $this->brand->id, $this->branch->id, 1000, 1000, 'closed');

    OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'amount' => 1000,
        'status' => 'succeeded',
        'till_session_id' => null,
    ]);

    $deleted = OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'amount' => 500,
        'status' => 'succeeded',
        'till_session_id' => null,
    ]);
    $deleted->delete();

    // One live gap payment, not two.
    $this->artisan('payments:check-ledger-drift')
        ->assertExitCode(0)
        ->expectsOutputToContain('1 succeeded sale row(s) have no till attribution');
});
