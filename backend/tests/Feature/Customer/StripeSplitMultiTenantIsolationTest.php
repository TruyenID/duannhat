<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Customer\StripePaymentService;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * plan-020 — MONEY / multi-tenant isolation of the Stripe split-bill ledger.
 *
 * Customer payment endpoints are PUBLIC and resolve the order by an opaque id
 * (split webhook: metadata.order_id). There is no bearer-scoped tenant, so the
 * isolation invariant lives in the ledger write: recordStripePayment() stamps
 * organization_id / branch_id / brand_id from the RESOLVED ORDER, never from the
 * (forgeable, string-only) Stripe intent metadata, and provisions the canonical
 * Stripe PaymentMethod org-scoped to the order's organization. These tests pin
 * that boundary so a split payment can never be attributed to — or leak a
 * payment method into — the wrong tenant.
 */
beforeEach(function () {
    config()->set('services.stripe.key', 'pk_test_dummy');
    config()->set('services.stripe.secret', 'sk_test_dummy');
    config()->set('services.stripe.webhook_secret', 'whsec_test_dummy');
    config()->set('services.stripe.currency', 'jpy');
});

/**
 * Build a succeeded `flow=split` PaymentIntent. `metaTenant` lets a test force
 * the intent metadata's org/branch to a DIFFERENT tenant than the order, to
 * prove the ledger ignores metadata for authoritative tenant columns. Uniquely
 * named to avoid colliding with SplitOverpaymentRaceTest's global splitIntent().
 */
function mtiSplitIntent(string $id, int $amount, CustomerOrder $order, ?array $metaTenant = null): PaymentIntent
{
    return PaymentIntent::constructFrom([
        'id' => $id,
        'object' => 'payment_intent',
        'amount' => $amount,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'metadata' => [
            'flow' => 'split',
            'order_id' => $order->id,
            'order_code' => $order->order_code,
            'branch_id' => $metaTenant['branch_id'] ?? (string) $order->branch_id,
            'organization_id' => $metaTenant['organization_id'] ?? (string) $order->organization_id,
        ],
    ]);
}

function mtiMakeOrder(Organization $org, Brand $brand, Branch $branch, float $total = 5000): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => $org->id,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'total_amount' => $total,
        'paid_amount' => 0,
        'status' => CustomerOrderStatusEnum::Open->value,
        'stripe_payment_intent_id' => null,
    ]);
}

it('stamps each org\'s split payment with its own tenant and never crosses over', function () {
    // Two fully independent tenants, each with their own dine-in order.
    $orgA = Organization::factory()->create();
    $brandA = Brand::factory()->create();
    $branchA = Branch::factory()->create();
    $orderA = mtiMakeOrder($orgA, $brandA, $branchA, 5000);

    $orgB = Organization::factory()->create();
    $brandB = Brand::factory()->create();
    $branchB = Branch::factory()->create();
    $orderB = mtiMakeOrder($orgB, $brandB, $branchB, 3000);

    $service = new StripePaymentService(Mockery::mock(StripeClient::class));

    // A guest pays a ¥2,000 split slice on org A's order; a guest pays ¥1,000 on
    // org B's order. Interleaved deliveries — neither must touch the other's row.
    $service->markOrderPaidFromIntent(mtiSplitIntent('pi_orgA_1', 2000, $orderA));
    $service->markOrderPaidFromIntent(mtiSplitIntent('pi_orgB_1', 1000, $orderB));

    $rowA = OrderPayment::where('reference_no', 'pi_orgA_1')->firstOrFail();
    $rowB = OrderPayment::where('reference_no', 'pi_orgB_1')->firstOrFail();

    // Each ledger row carries its OWN order's tenant triplet — exact, no bleed.
    expect($rowA->organization_id)->toBe($orgA->id)
        ->and($rowA->brand_id)->toBe($brandA->id)
        ->and($rowA->branch_id)->toBe($branchA->id)
        ->and((float) $rowA->amount)->toBe(2000.0);

    expect($rowB->organization_id)->toBe($orgB->id)
        ->and($rowB->brand_id)->toBe($brandB->id)
        ->and($rowB->branch_id)->toBe($branchB->id)
        ->and((float) $rowB->amount)->toBe(1000.0);

    // paid_amount is updated per-order, in isolation.
    $orderA->refresh();
    $orderB->refresh();
    expect((float) $orderA->paid_amount)->toBe(2000.0)
        ->and((float) $orderB->paid_amount)->toBe(1000.0)
        // Neither order over-collected or closed on a partial slice.
        ->and($orderA->status->value)->toBe(CustomerOrderStatusEnum::Open->value)
        ->and($orderB->status->value)->toBe(CustomerOrderStatusEnum::Open->value);

    // Cross-tenant ledger leak check: org A's org owns exactly its own row.
    expect(OrderPayment::where('organization_id', $orgA->id)->count())->toBe(1)
        ->and(OrderPayment::where('organization_id', $orgB->id)->count())->toBe(1)
        ->and(OrderPayment::where('customer_order_id', $orderA->id)
            ->where('organization_id', $orgB->id)->count())->toBe(0)
        ->and(OrderPayment::where('customer_order_id', $orderB->id)
            ->where('organization_id', $orgA->id)->count())->toBe(0);
});

it('provisions a separate org-scoped Stripe PaymentMethod per tenant — no shared method leak', function () {
    $orgA = Organization::factory()->create();
    $orderA = mtiMakeOrder($orgA, Brand::factory()->create(), Branch::factory()->create());

    $orgB = Organization::factory()->create();
    $orderB = mtiMakeOrder($orgB, Brand::factory()->create(), Branch::factory()->create());

    $service = new StripePaymentService(Mockery::mock(StripeClient::class));

    // Two intents for org A (should REUSE org A's method), one for org B.
    $service->markOrderPaidFromIntent(mtiSplitIntent('pi_a_first', 1000, $orderA));
    $service->markOrderPaidFromIntent(mtiSplitIntent('pi_a_second', 1000, $orderA));
    $service->markOrderPaidFromIntent(mtiSplitIntent('pi_b_first', 1000, $orderB));

    // Exactly one stripe method per organization — org A's two payments reuse
    // one row, and org B gets its OWN distinct method (never org A's).
    $methodsA = PaymentMethod::where('code', 'stripe')->where('organization_id', $orgA->id)->get();
    $methodsB = PaymentMethod::where('code', 'stripe')->where('organization_id', $orgB->id)->get();

    expect($methodsA)->toHaveCount(1)
        ->and($methodsB)->toHaveCount(1)
        ->and($methodsA->first()->id)->not->toBe($methodsB->first()->id)
        // Canonical stripe method is org-scoped, branch-agnostic.
        ->and($methodsA->first()->branch_id)->toBeNull()
        ->and($methodsB->first()->branch_id)->toBeNull();

    // Total stripe methods across the whole system == number of orgs, no leak.
    expect(PaymentMethod::where('code', 'stripe')->count())->toBe(2);

    // Each org's payment rows reference only that org's stripe method.
    $orgAPayments = OrderPayment::where('organization_id', $orgA->id)->get();
    expect($orgAPayments)->toHaveCount(2);
    foreach ($orgAPayments as $p) {
        expect($p->payment_method_id)->toBe($methodsA->first()->id);
    }
});

it('ledgers a split payment under the order\'s real tenant even when intent metadata claims another org', function () {
    // The real order belongs to org A. An attacker-forged (or simply stale)
    // intent carries org B / branch B in its string metadata. The authoritative
    // tenant columns must follow the RESOLVED ORDER, not the metadata.
    $orgA = Organization::factory()->create();
    $brandA = Brand::factory()->create();
    $branchA = Branch::factory()->create();
    $orderA = mtiMakeOrder($orgA, $brandA, $branchA, 5000);

    $orgB = Organization::factory()->create();
    $branchB = Branch::factory()->create();

    $service = new StripePaymentService(Mockery::mock(StripeClient::class));

    $service->markOrderPaidFromIntent(mtiSplitIntent('pi_spoofed_meta', 2000, $orderA, [
        'organization_id' => (string) $orgB->id,
        'branch_id' => (string) $branchB->id,
    ]));

    $row = OrderPayment::where('reference_no', 'pi_spoofed_meta')->firstOrFail();

    // Authoritative columns = order A's tenant, NOT the spoofed org B metadata.
    expect($row->organization_id)->toBe($orgA->id)
        ->and($row->brand_id)->toBe($brandA->id)
        ->and($row->branch_id)->toBe($branchA->id)
        ->and($row->organization_id)->not->toBe($orgB->id);

    // No stripe method was ever provisioned into the spoofed org B.
    expect(PaymentMethod::where('code', 'stripe')->where('organization_id', $orgB->id)->count())->toBe(0)
        ->and(PaymentMethod::where('code', 'stripe')->where('organization_id', $orgA->id)->count())->toBe(1);

    // And org B's ledger stays empty — nothing leaked in.
    expect(OrderPayment::where('organization_id', $orgB->id)->count())->toBe(0);
});

it('closes only the settled tenant\'s order and leaves the other tenant open', function () {
    $orgA = Organization::factory()->create();
    $orderA = mtiMakeOrder($orgA, Brand::factory()->create(), Branch::factory()->create(), 5000);

    $orgB = Organization::factory()->create();
    $orderB = mtiMakeOrder($orgB, Brand::factory()->create(), Branch::factory()->create(), 5000);

    $service = new StripePaymentService(Mockery::mock(StripeClient::class));

    // Org A settles in full (single ¥5,000 slice); org B only pays ¥2,000.
    $service->markOrderPaidFromIntent(mtiSplitIntent('pi_close_a', 5000, $orderA));
    $service->markOrderPaidFromIntent(mtiSplitIntent('pi_partial_b', 2000, $orderB));

    $orderA->refresh();
    $orderB->refresh();

    // Closing org A must not close org B; ledgers stay exact per tenant.
    expect($orderA->status->value)->toBe(CustomerOrderStatusEnum::Closed->value)
        ->and((float) $orderA->paid_amount)->toBe(5000.0)
        ->and($orderA->closed_at)->not->toBeNull();

    expect($orderB->status->value)->toBe(CustomerOrderStatusEnum::Open->value)
        ->and((float) $orderB->paid_amount)->toBe(2000.0)
        ->and($orderB->closed_at)->toBeNull();

    // Ledger integrity per org: neither collected past its own total.
    $ledgerA = (float) OrderPayment::where('customer_order_id', $orderA->id)
        ->whereIn('status', [PaymentStatusEnum::Succeeded->value, PaymentStatusEnum::Refunded->value])
        ->sum('amount');
    $ledgerB = (float) OrderPayment::where('customer_order_id', $orderB->id)
        ->whereIn('status', [PaymentStatusEnum::Succeeded->value, PaymentStatusEnum::Refunded->value])
        ->sum('amount');
    expect($ledgerA)->toBe(5000.0)
        ->and($ledgerB)->toBe(2000.0);
});
