<?php

/**
 * Plan 047 Gate 7 (T7.4) — observation window aggregation command.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\PaymentRefund;
use App\Models\Till;
use App\Models\TillSession;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentRefundStateEnum;
use App\Omnify\Enums\TillSessionStatusEnum;
use App\Services\Payment\Gateway\PayPay\PayPayQrCodeClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

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

it('exits clean when observation signals are within gate thresholds', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'closed',
        'total_amount' => 1000,
        'paid_amount' => 1000,
    ]);
    OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'amount' => 1000,
        'status' => 'succeeded',
    ]);

    $exit = Artisan::call('payments:observation-report', ['--strict' => true]);
    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('Observation gate PASS');
});

it('fails when ledger drift is detected', function () {
    CustomerOrder::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'closed',
        'total_amount' => 1000,
        'paid_amount' => 1000,
    ]);

    $exit = Artisan::call('payments:observation-report');
    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('ledger drift detected');
});

it('fails under --strict when shadow reconciliation backlog is non-zero', function () {
    PaymentAttempt::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'state' => PaymentAttemptStateEnum::ReconciliationRequired,
    ]);

    $exit = Artisan::call('payments:observation-report', ['--strict' => true]);
    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('shadow reconciliation backlog');
});

it('reports refund pending and open till session counts in json output', function () {
    PaymentRefund::factory()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'state' => PaymentRefundStateEnum::Pending,
    ]);
    PaymentRefund::factory()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'state' => PaymentRefundStateEnum::Prepared,
    ]);

    $till = Till::factory()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
    ]);
    TillSession::factory()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'till_id' => $till->id,
        'status' => TillSessionStatusEnum::Open,
    ]);
    TillSession::factory()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'till_id' => $till->id,
        'status' => TillSessionStatusEnum::Closing,
    ]);

    Artisan::call('payments:observation-report', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['refund_pending']['total'])->toBe(2)
        ->and($payload['till_sessions_open']['open'])->toBe(1)
        ->and($payload['till_sessions_open']['closing'])->toBe(1)
        ->and($payload['till_sessions_open']['total'])->toBe(2)
        ->and($payload['gate_pass'])->toBeTrue();
});

it('counts outstanding PayPay QR attempts and flags the ones the sweep should have cleared', function () {
    // plan-054 T7.4. `stale` is the operator's signal that
    // payments:sweep-paypay-qr is not running — and an unswept QR is money
    // PayPay may have taken that never reached the ledger.
    config(['payments.paypay_qr.stale_sweep_grace_minutes' => 15]);

    $attempt = fn (string $mpid, int $ageMinutes) => PaymentAttempt::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'provider' => PaymentGatewayProviderCodeEnum::Paypay->value,
        'state' => PaymentAttemptStateEnum::Prepared,
        'provider_object_id' => $mpid,
        'prepared_at' => now()->subMinutes($ageMinutes),
    ]);

    $attempt(PayPayQrCodeClient::MPID_PREFIX.'fresh', 1);
    $attempt(PayPayQrCodeClient::MPID_PREFIX.'stale-a', 60);
    $attempt(PayPayQrCodeClient::MPID_PREFIX.'stale-b', 90);

    // A PREAUTH attempt on the same provider. It has no `tempoqr-` prefix and
    // reads from a different PayPay endpoint, so counting it here would report
    // it as un-swept QR backlog the sweeper will never touch.
    $attempt('preauth-not-a-qr', 90);

    Artisan::call('payments:observation-report', ['--json' => true]);
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['paypay_qr']['live'])->toBe(3)
        ->and($payload['paypay_qr']['stale'])->toBe(2)
        ->and($payload['paypay_qr']['grace_minutes'])->toBe(15)
        // Deliberately NOT a gate signal: a live QR is a customer at their
        // phone, which is the normal state of a working shop.
        ->and($payload['gate_pass'])->toBeTrue();
});

it('allows non-zero shadow backlog without --strict', function () {
    PaymentAttempt::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'state' => PaymentAttemptStateEnum::ReconciliationRequired,
    ]);

    $exit = Artisan::call('payments:observation-report');
    expect($exit)->toBe(0);
});
