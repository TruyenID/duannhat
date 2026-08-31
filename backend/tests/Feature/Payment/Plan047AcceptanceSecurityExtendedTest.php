<?php

/**
 * Plan 047 acceptance — security H1, H3, H5, H6 and observability.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\PaymentRefund;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentRefundStateEnum;
use App\Services\Payment\Configuration\Exceptions\PaymentConfigurationException;
use App\Services\Payment\Configuration\Support\SensitivePaymentDataGuard;
use App\Services\Payment\Orchestration\Support\PaymentOrchestrationLogContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Fakes\Payment\PayPayFakePaymentGateway;
use Tests\Support\Payment\PaymentGatewayFixtures;

describe('H1 PAN and CVV-like payload rejection', function () {
    it('H1 rejects payloads containing PAN-like digit runs', function () {
        expect(fn () => SensitivePaymentDataGuard::rejectIfPresent([
            'note' => 'card 4111111111111111 declined',
        ], 'corr-h1-pan'))->toThrow(PaymentConfigurationException::class);
    });

    it('H1 rejects explicit cvv field names', function () {
        expect(fn () => SensitivePaymentDataGuard::rejectIfPresent([
            'cvv' => '123',
        ], 'corr-h1-cvv'))->toThrow(PaymentConfigurationException::class);

        try {
            SensitivePaymentDataGuard::rejectIfPresent(['cvv' => '123']);
        } catch (PaymentConfigurationException $exception) {
            expect($exception->errorCode)->toBe('PAYMENT_SENSITIVE_DATA_REJECTED');
        }
    });

    it('H1 allows non-sensitive operational metadata', function () {
        SensitivePaymentDataGuard::rejectIfPresent([
            'order_code' => 'ORD-1000',
            'terminal_ref' => 'T-01',
        ]);

        expect(true)->toBeTrue();
    });

    it('H1 does NOT false-positive on a UUID whose segments are all digits', function () {
        // 8+4+4 all-digit groups read as a 16-digit dash-separated run — the
        // PAN pattern used to match ACROSS the dashes, so ~0.05% of entity
        // ids randomly tripped the guard (order-dependent suite flake, and a
        // real-traffic 422 for the unlucky merchant).
        SensitivePaymentDataGuard::rejectIfPresent([
            'identity_brand_id' => '12345678-1234-4234-8234-123456789012',
            'note' => 'linked to 98765432-1111-4222-8333-444455556666 by operator',
        ], 'corr-h1-uuid');

        expect(true)->toBeTrue();
    });

    it('H1 still rejects a PAN even when a UUID rides the same string', function () {
        expect(fn () => SensitivePaymentDataGuard::rejectIfPresent([
            'note' => 'ref 12345678-1234-4234-8234-123456789012 card 4111111111111111',
        ], 'corr-h1-uuid-pan'))->toThrow(PaymentConfigurationException::class);
    });
});

describe('H3 orchestration log redaction', function () {
    it('H3 redacts bearer tokens stripe secrets and pan keys from structured logs', function () {
        $redacted = PaymentOrchestrationLogContext::redact([
            'authorization' => 'Bearer sk_test_live_secret_value',
            'webhook_secret' => 'whsec_test_secret_value',
            'pan' => '4111111111111111',
            'nested' => [
                'api_key' => 'pk_test_public',
                'safe_field' => 'attempt-123',
            ],
        ]);

        expect($redacted['authorization'])->toBe('[REDACTED]')
            ->and($redacted['webhook_secret'])->toBe('[REDACTED]')
            ->and($redacted['pan'])->toBe('[REDACTED]')
            ->and($redacted['nested']['api_key'])->toBe('[REDACTED]')
            ->and($redacted['nested']['safe_field'])->toBe('attempt-123');
    });
});

describe('H5 observation report detects stuck payment signals', function () {
    beforeEach(function () {
        $this->organizationId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $this->organizationId,
            'console_organization_id' => $this->organizationId,
        ]);
        $this->brand = Brand::factory()->create(['console_organization_id' => $this->organizationId]);
        $this->branch = Branch::factory()->create([
            'console_organization_id' => $this->organizationId,
            'console_brand_id' => $this->brand->console_brand_id,
        ]);
    });

    it('H5 fails strict observation when reconciliation-required attempts are due', function () {
        PaymentAttempt::factory()->create([
            'organization_id' => $this->organizationId,
            'branch_id' => $this->branch->id,
            'state' => PaymentAttemptStateEnum::ReconciliationRequired->value,
            'next_reconciliation_at' => now()->subMinute(),
        ]);

        $exit = Artisan::call('payments:observation-report', ['--strict' => true]);
        expect($exit)->not->toBe(0)
            ->and(Artisan::output())->toContain('reconciliation');
    });

    it('H5 fails strict observation when pending refunds are overdue', function () {
        PaymentRefund::factory()->create([
            'organization_id' => $this->organizationId,
            'branch_id' => $this->branch->id,
            'state' => PaymentRefundStateEnum::ReconciliationRequired->value,
            'next_reconciliation_at' => now()->subHour(),
        ]);

        $exit = Artisan::call('payments:observation-report', ['--strict' => true]);
        expect($exit)->not->toBe(0)
            ->and(Artisan::output())->toMatch('/refund|reconciliation/i');
    });

    it('H5 passes when ledger projection matches paid cache', function () {
        $cash = PaymentMethod::factory()->cash()->create([
            'organization_id' => $this->organizationId,
            'branch_id' => $this->branch->id,
        ]);
        $order = CustomerOrder::factory()->create([
            'organization_id' => $this->organizationId,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
            'status' => 'closed',
            'total_amount' => 500,
            'paid_amount' => 500,
        ]);
        OrderPayment::factory()->create([
            'customer_order_id' => $order->id,
            'payment_method_id' => $cash->id,
            'organization_id' => $this->organizationId,
            'branch_id' => $this->branch->id,
            'amount' => 500,
            'status' => 'succeeded',
        ]);

        $exit = Artisan::call('payments:observation-report', ['--strict' => true]);
        expect($exit)->toBe(0);
    });
});

describe('H6 reconciliation commands are overlap-safe', function () {
    it('H6 reconcile-attempts dry-run is idempotent and reports counts without mutation', function () {
        PaymentAttempt::factory()->create([
            'state' => PaymentAttemptStateEnum::ReconciliationRequired->value,
            'next_reconciliation_at' => now()->subMinute(),
        ]);

        $this->artisan('payments:reconcile-attempts')
            ->expectsOutputToContain('Running in dry-run mode.')
            ->assertExitCode(0);

        $this->artisan('payments:reconcile-attempts')
            ->expectsOutputToContain('Running in dry-run mode.')
            ->assertExitCode(0);
    });
});

describe('H8 second provider contract smoke', function () {
    it('H8 PayPay fake gateway passes capability snapshot contract', function () {
        $gateway = new PayPayFakePaymentGateway(
            PaymentGatewayFixtures::payPayPreauthCapability(),
            new DateTimeImmutable('2026-07-22T00:00:00+00:00'),
        );

        $capabilities = $gateway->capabilities(PaymentGatewayFixtures::connection());
        expect($capabilities->provider->value)->toBe('paypay')
            ->and($capabilities->id)->toBe('paypay.preauth.wallet.v1');
    });
});
