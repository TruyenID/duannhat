<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\User;
use App\Services\Customer\StripePaymentService;
use Illuminate\Support\Str;
use Stripe\Refund;

/**
 * BLOCKER 2 — money-safety gates on the in-app refund path.
 *
 * Three safe defaults, exercised end-to-end over the Shop refund route
 * (POST /api/v1/shops/{slug}/orders/{order}/payments/{payment}/refund):
 *
 *   (1) config kill-switch STRIPE_LIVE_REFUNDS_ENABLED — default OFF blocks a
 *       real card refund (403) BEFORE Stripe is called.
 *   (2) manager role gate — a cashier (shop-staff) is rejected (403); a manager
 *       (org-manager / org-admin / shop-manager) is allowed.
 *   (3) per-refund cap max_card_refund_amount — an over-cap card refund is
 *       rejected (422) BEFORE Stripe is called.
 */
beforeEach(function () {
    config([
        'services.stripe.secret' => 'sk_test_dummy_secret_for_tests',
        'services.stripe.currency' => 'jpy',
    ]);

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
        'slug' => 'gate-shop',
        'is_active' => true,
    ]);

    // Manager — org-manager role in the org. The role pivot row both satisfies
    // the shop-context IAM check (any org role) AND clears the manager gate.
    $managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 80],
    );
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($managerRole, $this->orgId);

    // Cashier — shop-staff role in the org. Passes the shop-context IAM check
    // (has an org role pivot) but is NOT manager-level, so the refund gate 403s.
    $cashierRole = Role::firstOrCreate(
        ['slug' => 'shop-staff'],
        ['name' => 'Shop Staff', 'level' => 10],
    );
    $this->cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->cashier->assignRole($cashierRole, $this->orgId);

    $this->customer = Customer::factory()->selfRegistered()->create();

    $this->stripeMethod = PaymentMethod::factory()->create([
        'code' => 'stripe',
        'organization_id' => $this->orgId,
        'branch_id' => null,
    ]);

    // Helper: a closed order carrying a succeeded Stripe card payment
    // (reference_no is the pi_ PaymentIntent id, as recordStripePayment stamps).
    $this->makeStripePayment = function (float $amount = 1500): OrderPayment {
        $order = CustomerOrder::factory()->create([
            'customer_id' => $this->customer->id,
            'branch_id' => $this->shop->id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'total_amount' => $amount,
            'paid_amount' => $amount,
            'status' => 'closed',
        ]);

        return OrderPayment::factory()->succeeded()->create([
            'customer_order_id' => $order->id,
            'payment_method_id' => $this->stripeMethod->id,
            'branch_id' => $this->shop->id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'amount' => $amount,
            'reference_no' => 'pi_gate_'.Str::random(8),
        ]);
    };

    $this->refundUrl = fn (OrderPayment $p): string => "/api/v1/shops/{$this->shop->slug}/orders/{$p->customer_order_id}/payments/{$p->id}/refund";

    // Any resolution of StripePaymentService blows up unless a test opts in —
    // proves the guarded branches never reach Stripe.
    $this->neverStripe = function (): void {
        $mock = Mockery::mock(StripePaymentService::class);
        $mock->shouldNotReceive('refundPayment');
        $this->app->instance(StripePaymentService::class, $mock);
    };
});

it('kill-switch OFF: a live card refund is rejected 403 and Stripe is NOT called', function () {
    config(['payments.stripe_live_refunds_enabled' => false]);
    ($this->neverStripe)();

    $payment = ($this->makeStripePayment)();

    $this->actingAs($this->manager)
        ->postJson(($this->refundUrl)($payment))
        ->assertStatus(403);

    // No ledger row written; original untouched.
    expect(OrderPayment::where('refund_of_id', $payment->id)->count())->toBe(0)
        ->and($payment->fresh()->status->value)->toBe('succeeded');
});

it('role gate: a cashier (non-manager) cannot refund — 403, Stripe NOT called', function () {
    config(['payments.stripe_live_refunds_enabled' => true]);
    ($this->neverStripe)();

    $payment = ($this->makeStripePayment)();

    $this->actingAs($this->cashier)
        ->postJson(($this->refundUrl)($payment))
        ->assertStatus(403);

    expect(OrderPayment::where('refund_of_id', $payment->id)->count())->toBe(0)
        ->and($payment->fresh()->status->value)->toBe('succeeded');
});

it('manager + kill-switch ON: a live card refund succeeds and calls Stripe once', function () {
    config(['payments.stripe_live_refunds_enabled' => true]);

    $payment = ($this->makeStripePayment)(1500);

    $mock = Mockery::mock(StripePaymentService::class);
    $mock->expects('refundPayment')
        ->once()
        ->withArgs(fn (string $pi, float $amount, string $key) => $amount === 1500.0 && str_starts_with($key, 'refund_'))
        ->andReturn(Refund::constructFrom(['id' => 're_gate_ok', 'object' => 'refund', 'amount' => 1500]));
    $this->app->instance(StripePaymentService::class, $mock);

    $response = $this->actingAs($this->manager)
        ->postJson(($this->refundUrl)($payment))
        ->assertCreated();

    expect((float) $response->json('data.amount'))->toBe(-1500.0)
        ->and($response->json('data.metadata.stripe_refund_id'))->toBe('re_gate_ok')
        ->and($payment->fresh()->status->value)->toBe('refunded');
});

it('amount cap: a card refund over the ceiling is rejected 422 and Stripe NOT called', function () {
    config([
        'payments.stripe_live_refunds_enabled' => true,
        'payments.max_card_refund_amount' => 1000,
    ]);
    ($this->neverStripe)();

    $payment = ($this->makeStripePayment)(1500);

    $this->actingAs($this->manager)
        ->postJson(($this->refundUrl)($payment), ['amount' => 1500])
        ->assertStatus(422);

    expect(OrderPayment::where('refund_of_id', $payment->id)->count())->toBe(0)
        ->and($payment->fresh()->status->value)->toBe('succeeded');
});
