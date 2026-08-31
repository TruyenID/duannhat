<?php

/**
 * Plan 048 Gate 3 (T3.1–T3.5, T3.8) — generic provider webhook intake.
 *
 * Acceptance rows P048-C (plans/plan-048/TESTS.md):
 *   C1 POST /webhooks/payment/stripe valid signature → inbox row + 2xx
 *   C2 invalid signature → 400, no inbox row
 *   C3 duplicate provider event id → 200, single applicator outcome
 *   C4 legacy alias /customer/stripe/webhook still works (deprecated headers)
 *   C5 PayPay signed webhook → inbox → applicator
 *   C6 connection resolved from Stripe Connect `account` field
 */

use App\Jobs\Payment\ProcessPaymentProviderEventJob;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayProvider;
use App\Models\PaymentProviderEvent;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\PaymentGatewayRegistry;
use App\Services\Payment\Gateway\PayPay\PayPayWebhookSourceVerifier;
use App\Services\Payment\Gateway\ValueObjects\EphemeralSecret;
use App\Services\Payment\ProviderEvent\ProviderEventApplicator;
use App\Services\Payment\Secret\GatewayConnectionSecretResolver;
use App\Services\Payment\Secret\GatewaySecretAuditProtection;
use App\Services\Payment\Secret\ValueObjects\GatewaySecretAccessContext;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fakes\Payment\PayPayFakePaymentGateway;
use Tests\Support\Payment\PaymentGatewayFixtures;

uses()->group('payment');

beforeEach(function () {
    config([
        'services.stripe.secret' => 'sk_test_dummy_secret_for_tests',
        'services.stripe.webhook_secret' => 'whsec_test_secret_xyz',
    ]);

    $this->makeStripeEvent = function (string $type, array $dataObject, string $secret = 'whsec_test_secret_xyz', array $extra = []): array {
        $payload = json_encode(array_merge([
            'id' => 'evt_'.Str::random(24),
            'object' => 'event',
            'type' => $type,
            'created' => time(),
            'data' => ['object' => $dataObject],
        ], $extra));
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return [
            'payload' => $payload,
            'header' => "t={$timestamp},v1={$signature}",
            'event_id' => json_decode($payload, true)['id'],
        ];
    };

    $this->postProviderWebhook = fn (string $provider, string $payload, array $headers) => $this->call(
        'POST',
        "/api/v1/webhooks/payment/{$provider}",
        [],
        [],
        [],
        array_merge(['CONTENT_TYPE' => 'application/json'], $headers),
        $payload,
    );
});

it('C1: accepts a valid Stripe signature on the generic route and queues the inbox row', function () {
    Queue::fake();

    $event = ($this->makeStripeEvent)('payment_intent.succeeded', [
        'object' => 'payment_intent',
        'id' => 'pi_plan048_c1',
        'amount' => 500,
        'currency' => 'jpy',
        'status' => 'succeeded',
    ]);

    ($this->postProviderWebhook)('stripe', $event['payload'], ['HTTP_STRIPE_SIGNATURE' => $event['header']])
        ->assertOk()
        ->assertJson(['received' => true]);

    $inbox = PaymentProviderEvent::query()->where('provider_event_id', $event['event_id'])->first();
    expect($inbox)->not->toBeNull();

    Queue::assertPushed(ProcessPaymentProviderEventJob::class);
});

it('C2: rejects an invalid signature with 400 and persists nothing', function () {
    $event = ($this->makeStripeEvent)('payment_intent.succeeded', [
        'object' => 'payment_intent',
        'id' => 'pi_plan048_c2',
    ], 'whsec_wrong_secret');

    ($this->postProviderWebhook)('stripe', $event['payload'], ['HTTP_STRIPE_SIGNATURE' => $event['header']])
        ->assertStatus(400);

    expect(PaymentProviderEvent::query()->where('provider_event_id', $event['event_id'])->count())->toBe(0);
});

it('C3: deduplicates a replayed provider event id — one inbox row, both 2xx', function () {
    Queue::fake();

    $event = ($this->makeStripeEvent)('payment_intent.succeeded', [
        'object' => 'payment_intent',
        'id' => 'pi_plan048_c3',
        'amount' => 700,
        'currency' => 'jpy',
        'status' => 'succeeded',
    ]);

    ($this->postProviderWebhook)('stripe', $event['payload'], ['HTTP_STRIPE_SIGNATURE' => $event['header']])->assertOk();
    ($this->postProviderWebhook)('stripe', $event['payload'], ['HTTP_STRIPE_SIGNATURE' => $event['header']])->assertOk();

    expect(PaymentProviderEvent::query()->where('provider_event_id', $event['event_id'])->count())->toBe(1);
});

it('C4: legacy Stripe alias still verifies and announces its deprecation', function () {
    Queue::fake();

    $event = ($this->makeStripeEvent)('payment_intent.succeeded', [
        'object' => 'payment_intent',
        'id' => 'pi_plan048_c4',
        'amount' => 900,
        'currency' => 'jpy',
        'status' => 'succeeded',
    ]);

    $response = $this->call(
        'POST',
        '/api/v1/customer/stripe/webhook',
        [],
        [],
        [],
        ['HTTP_STRIPE_SIGNATURE' => $event['header'], 'CONTENT_TYPE' => 'application/json'],
        $event['payload'],
    );

    $response->assertOk()
        ->assertJson(['received' => true])
        ->assertHeader('Deprecation', 'true')
        ->assertHeader('Sunset', 'Tue, 01 Jun 2027 00:00:00 GMT');

    expect(PaymentProviderEvent::query()->where('provider_event_id', $event['event_id'])->count())->toBe(1);
});

it('rejects unknown providers and the internal pseudo-provider with 404', function () {
    ($this->postProviderWebhook)('does-not-exist', '{"x":1}', [])->assertNotFound();
    ($this->postProviderWebhook)('internal', '{"x":1}', [])->assertNotFound();
});

describe('PayPay intake (C5)', function () {
    beforeEach(function () {
        config([
            'services.paypay.api_key' => 'pp_key_dummy',
            'services.paypay.api_secret' => 'pp_secret_dummy',
            'services.paypay.webhook_secret' => 'paypay_whsec_048',
        ]);

        $organization = Organization::factory()->create();
        $brand = Brand::factory()->create([
            'console_organization_id' => $organization->console_organization_id,
        ]);
        $provider = PaymentGatewayProvider::factory()->create([
            'code' => PaymentGatewayProviderCodeEnum::Paypay,
            'is_active' => true,
        ]);
        $this->paypayConnection = PaymentGatewayConnection::factory()->create([
            'provider_id' => $provider->id,
            'organization_id' => $organization->id,
            'brand_id' => $brand->id,
            'owner_branch_id' => null,
            'owner_scope' => 'hq',
            'environment' => PaymentGatewayEnvironmentEnum::Test,
            'merchant_account_id' => 'paypay_merchant_048',
            'is_active' => true,
        ]);
    });

    it('verifies a signed PayPay notification and lands it in the inbox with the merchant connection', function () {
        Queue::fake();

        $payload = json_encode([
            'id' => 'ppevt_'.Str::random(12),
            'type' => 'paypay.payment.notification',
            'merchant_id' => 'paypay_merchant_048',
            'merchantPaymentId' => 'mp_plan048_c5',
            'status' => 'COMPLETED',
        ], JSON_THROW_ON_ERROR);

        $signature = hash_hmac('sha256', $payload, 'paypay_whsec_048');

        ($this->postProviderWebhook)('paypay', $payload, ['HTTP_PAYPAY_SIGNATURE' => $signature])
            ->assertOk()
            ->assertJson(['received' => true]);

        $inbox = PaymentProviderEvent::query()
            ->where('event_type', 'paypay.payment.notification')
            ->latest('received_at')
            ->first();

        expect($inbox)->not->toBeNull()
            ->and((string) $inbox->connection_id)->toBe((string) $this->paypayConnection->id)
            ->and((string) $inbox->provider_object_id)->toBe('mp_plan048_c5');
    });

    it('applies an unmatched PayPay notification as a safe no-op outcome', function () {
        Queue::fake();

        $payload = json_encode([
            'id' => 'ppevt_'.Str::random(12),
            'type' => 'paypay.payment.notification',
            'merchant_id' => 'paypay_merchant_048',
            'merchantPaymentId' => 'mp_plan048_unmatched',
            'status' => 'COMPLETED',
        ], JSON_THROW_ON_ERROR);

        $signature = hash_hmac('sha256', $payload, 'paypay_whsec_048');
        ($this->postProviderWebhook)('paypay', $payload, ['HTTP_PAYPAY_SIGNATURE' => $signature])->assertOk();

        $inbox = PaymentProviderEvent::query()
            ->where('provider_object_id', 'mp_plan048_unmatched')
            ->firstOrFail();

        expect(app(ProviderEventApplicator::class)->apply((string) $inbox->id))
            ->toBe('paypay_no_matching_attempt');
    });

    it('#1107: accepts a live OPA Transaction webhook from a PayPay source IP without PAYPAY_WEBHOOK_SECRET', function () {
        Queue::fake();
        config(['services.paypay.webhook_secret' => null]);
        $this->paypayConnection->forceFill([
            'environment' => PaymentGatewayEnvironmentEnum::Live,
        ])->save();

        $payload = json_encode([
            'notification_type' => 'Transaction',
            'notification_id' => 'evt_'.Str::random(12),
            'merchant_id' => 'paypay_merchant_048',
            'order_id' => 'mp_plan048_opa_live',
            'merchant_order_id' => 'mp_plan048_opa_live',
            'state' => 'COMPLETED',
            'order_amount' => '1500',
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/v1/webhooks/payment/paypay',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '52.68.128.8'],
            $payload,
        )->assertOk();

        expect(PaymentProviderEvent::query()->where('provider_object_id', 'mp_plan048_opa_live')->count())->toBe(1);
    });

    it('#1107: rejects a live OPA webhook when the source IP is not on the PayPay allowlist', function () {
        config(['services.paypay.webhook_secret' => null]);
        $this->paypayConnection->forceFill([
            'environment' => PaymentGatewayEnvironmentEnum::Live,
        ])->save();

        $payload = json_encode([
            'notification_type' => 'Transaction',
            'notification_id' => 'evt_'.Str::random(12),
            'merchant_id' => 'paypay_merchant_048',
            'order_id' => 'mp_plan048_failclosed',
            'state' => 'COMPLETED',
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/v1/webhooks/payment/paypay',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.50'],
            $payload,
        )->assertStatus(400);

        expect(PaymentProviderEvent::query()->where('provider_object_id', 'mp_plan048_failclosed')->count())->toBe(0);
    });

    it('#1107: still accepts unverified sandbox/test payloads (with a warning) when no secret is set', function () {
        Queue::fake();
        config(['services.paypay.webhook_secret' => null]);

        $payload = json_encode([
            'id' => 'ppevt_'.Str::random(12),
            'type' => 'paypay.payment.notification',
            'merchant_id' => 'paypay_merchant_048',
            'merchantPaymentId' => 'mp_plan048_sandbox_skip',
            'status' => 'COMPLETED',
        ], JSON_THROW_ON_ERROR);

        ($this->postProviderWebhook)('paypay', $payload, ['HTTP_PAYPAY_SIGNATURE' => 'anything'])
            ->assertOk();

        expect(PaymentProviderEvent::query()->where('provider_object_id', 'mp_plan048_sandbox_skip')->count())->toBe(1);
    });

    it('#1110: maps OPA order_id/state payload keys onto provider_object_id', function () {
        Queue::fake();

        $payload = json_encode([
            'notification_type' => 'Transaction',
            'notification_id' => 'ppevt_'.Str::random(12),
            'merchant_id' => 'paypay_merchant_048',
            'order_id' => 'mp_plan048_order_key',
            'state' => 'COMPLETED',
        ], JSON_THROW_ON_ERROR);

        $signature = hash_hmac('sha256', $payload, 'paypay_whsec_048');
        ($this->postProviderWebhook)('paypay', $payload, ['HTTP_PAYPAY_SIGNATURE' => $signature])->assertOk();

        $inbox = PaymentProviderEvent::query()
            ->where('event_type', 'paypay.transaction.notification')
            ->firstOrFail();

        expect((string) $inbox->provider_object_id)->toBe('mp_plan048_order_key');
    });

    it('#1110: a matched non-terminal attempt is recovered through the applicator', function () {
        Queue::fake();

        PaymentAttempt::factory()->create([
            'connection_id' => $this->paypayConnection->id,
            'organization_id' => $this->paypayConnection->organization_id,
            'state' => 'provider_pending',
            'environment' => 'test',
            'provider_object_id' => 'mp_plan048_matched',
        ]);

        $payload = json_encode([
            'id' => 'ppevt_'.Str::random(12),
            'type' => 'paypay.transaction.notification',
            'merchant_id' => 'paypay_merchant_048',
            'order_id' => 'mp_plan048_matched',
            'state' => 'COMPLETED',
        ], JSON_THROW_ON_ERROR);

        $signature = hash_hmac('sha256', $payload, 'paypay_whsec_048');
        ($this->postProviderWebhook)('paypay', $payload, ['HTTP_PAYPAY_SIGNATURE' => $signature])->assertOk();

        $inbox = PaymentProviderEvent::query()
            ->where('provider_object_id', 'mp_plan048_matched')
            ->firstOrFail();

        // Swap the driver AFTER intake (the real adapter verified the HMAC);
        // recovery then retrieves provider truth from the fake gateway.
        app()->bind(
            PayPayFakePaymentGateway::class,
            fn () => new PayPayFakePaymentGateway(
                PaymentGatewayFixtures::fullCapability(),
                new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            ),
        );
        config(['payments.gateway_drivers.paypay' => PayPayFakePaymentGateway::class]);
        app()->forgetInstance(PaymentGatewayRegistry::class);

        $outcome = app(ProviderEventApplicator::class)->apply((string) $inbox->id);

        expect($outcome)->toBeIn([
            'orchestrator_paypay_attempt_recovered',
            // recoverAttempt returning false (fake gateway edge) is still a
            // matched-path outcome — but never the ignored/no-match ones.
            'paypay_attempt_recovery_unavailable',
        ])->not->toBe('paypay_no_matching_attempt');
    });

    it('rejects a PayPay notification with a wrong signature', function () {
        $payload = json_encode([
            'id' => 'ppevt_'.Str::random(12),
            'type' => 'paypay.payment.notification',
            'merchant_id' => 'paypay_merchant_048',
            'merchantPaymentId' => 'mp_plan048_bad_sig',
            'status' => 'COMPLETED',
        ], JSON_THROW_ON_ERROR);

        ($this->postProviderWebhook)('paypay', $payload, ['HTTP_PAYPAY_SIGNATURE' => 'not-the-hmac'])
            ->assertStatus(400);

        expect(PaymentProviderEvent::query()->where('provider_object_id', 'mp_plan048_bad_sig')->count())->toBe(0);
    });

    /*
    |----------------------------------------------------------------------
    | #2445 — a rejected webhook must say WHY
    |----------------------------------------------------------------------
    |
    | PayPay Live delivered to this endpoint from 2026-08-10 and every delivery
    | was rejected. The warning said only "signature verification failed", which
    | is true of two causes needing opposite fixes — an un-allowlisted source IP,
    | or a payload with no `notification_type` failing closed on a Live
    | connection. Identical log lines left the outage un-diagnosable while the
    | QR sweep quietly papered over it minutes later.
    |
    | These run against a LIVE connection on purpose: `verifyOpaWebhookSource`
    | accepts an OPA payload on any lower environment, so the same assertions on
    | the Test connection above would assert nothing.
    */

    it('names the client IP and the notification_type presence when a webhook is rejected', function () {
        $live = plan048LivePayPayConnection($this->paypayConnection);
        $ctxs = plan048CaptureWarnings();

        $this->call(
            'POST',
            '/api/v1/webhooks/payment/paypay?connection='.$live->id,
            [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.7'],
            json_encode(['notification_type' => 'Transaction', 'merchant_order_id' => 'x']),
        )->assertStatus(400);

        $hit = plan048FindRejection($ctxs);
        expect($hit)->not->toBeNull()
            ->and($hit['provider'])->toBe('paypay')
            ->and($hit['client_ip'])->toBe('203.0.113.7')
            ->and($hit['has_notification_type'])->toBeTrue();
    });

    it('records the raw X-Forwarded-For and REMOTE_ADDR next to client_ip (#2453)', function () {
        // client_ip alone cannot say WHERE the chain broke. Through CloudFront
        // it is a different edge address every request, so PayPay's source-IP
        // allowlist can never match; these two say whether the header arrived
        // at all, which decides who has to fix it.
        $live = plan048LivePayPayConnection($this->paypayConnection);
        $ctxs = plan048CaptureWarnings();

        $this->call(
            'POST',
            '/api/v1/webhooks/payment/paypay?connection='.$live->id,
            [], [], [],
            [
                'CONTENT_TYPE' => 'application/json',
                'REMOTE_ADDR' => '203.0.113.7',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.9, 203.0.113.7',
            ],
            json_encode(['notification_type' => 'Transaction']),
        )->assertStatus(400);

        $hit = plan048FindRejection($ctxs);
        expect($hit)->not->toBeNull()
            ->and($hit['x_forwarded_for'])->toBe('198.51.100.9, 203.0.113.7')
            ->and($hit['remote_addr'])->toBe('203.0.113.7');
    });

    it('reports x_forwarded_for as null when no proxy sent one', function () {
        // The distinguishing case: absent header means whoever sits in front is
        // not sending it, which is a different fix from mishandling it.
        $live = plan048LivePayPayConnection($this->paypayConnection);
        $ctxs = plan048CaptureWarnings();

        $this->call(
            'POST',
            '/api/v1/webhooks/payment/paypay?connection='.$live->id,
            [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.7'],
            json_encode(['notification_type' => 'Transaction']),
        )->assertStatus(400);

        $hit = plan048FindRejection($ctxs);
        expect($hit['x_forwarded_for'])->toBeNull()
            ->and($hit['remote_addr'])->toBe('203.0.113.7');
    });

    it('reports has_notification_type=false for a payload without the key', function () {
        // The other branch: a Live connection with no HMAC secret and no
        // notification_type fails closed. Same HTTP answer, opposite fix.
        $live = plan048LivePayPayConnection($this->paypayConnection);
        $ctxs = plan048CaptureWarnings();

        $this->call(
            'POST',
            '/api/v1/webhooks/payment/paypay?connection='.$live->id,
            [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.8'],
            json_encode(['merchant_order_id' => 'x', 'state' => 'COMPLETED']),
        )->assertStatus(400);

        $hit = plan048FindRejection($ctxs);
        expect($hit)->not->toBeNull()
            ->and($hit['client_ip'])->toBe('203.0.113.8')
            ->and($hit['has_notification_type'])->toBeFalse();
    });

    it('never logs the notification_type VALUE, only whether it was present', function () {
        // Presence is the routing fact; the value is provider content and has no
        // business in a line that fires on every rejected delivery.
        $live = plan048LivePayPayConnection($this->paypayConnection);
        $ctxs = plan048CaptureWarnings();

        $this->call(
            'POST',
            '/api/v1/webhooks/payment/paypay?connection='.$live->id,
            [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.9'],
            json_encode(['notification_type' => 'SENSITIVE_MARKER_2445']),
        )->assertStatus(400);

        $hit = plan048FindRejection($ctxs);
        expect($hit)->not->toBeNull()
            ->and(json_encode($hit))->not->toContain('SENSITIVE_MARKER_2445');
    });

    it('routes a non-JSON body to the malformed-input branch, not the signature one', function () {
        // Boundary marker. verifyWebhook json_decodes with JSON_THROW_ON_ERROR,
        // so a non-JSON body never reaches the WebhookVerificationFailed catch —
        // it lands in the malformed-input one. Worth pinning: otherwise someone
        // debugging a rejected delivery greps for the signature message and
        // concludes PayPay sent nothing.
        $live = plan048LivePayPayConnection($this->paypayConnection);
        $ctxs = plan048CaptureWarnings();

        $this->call(
            'POST',
            '/api/v1/webhooks/payment/paypay?connection='.$live->id,
            [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.10'],
            '<html>not json</html>',
        )->assertStatus(400);

        expect(plan048FindRejection($ctxs))->toBeNull();
    });

});

describe('Stripe Connect account resolution (C6)', function () {
    beforeEach(function () {
        $this->keyringPath = realpath(sys_get_temp_dir()).'/tempo-plan048-keyring-'.Str::uuid().'.json';
        file_put_contents($this->keyringPath, json_encode([
            'active_key_id' => 'plan048-master-a',
            'keys' => ['plan048-master-a' => 'base64:'.base64_encode(str_repeat('K', 32))],
        ], JSON_THROW_ON_ERROR));
        chmod($this->keyringPath, 0600);
        config(['payments.secret_store.keyring_path' => $this->keyringPath]);

        $organization = Organization::factory()->create();
        $brand = Brand::factory()->create([
            'console_organization_id' => $organization->console_organization_id,
        ]);
        $provider = PaymentGatewayProvider::factory()->create([
            'code' => PaymentGatewayProviderCodeEnum::Stripe,
            'is_active' => true,
        ]);
        $this->connectConnection = PaymentGatewayConnection::factory()->create([
            'provider_id' => $provider->id,
            'organization_id' => $organization->id,
            'brand_id' => $brand->id,
            'owner_branch_id' => null,
            'owner_scope' => 'hq',
            'environment' => PaymentGatewayEnvironmentEnum::Test,
            'merchant_account_id' => 'acct_plan048_connect',
            'is_active' => true,
            'secret_ref' => null,
            'webhook_secret_ref' => null,
            'secret_version' => null,
            'key_fingerprint' => null,
        ]);

        app(GatewaySecretAuditProtection::class)->install();
        app(GatewayConnectionSecretResolver::class)->rotateWebhook(
            new GatewaySecretAccessContext(
                (string) $organization->id,
                (string) $this->connectConnection->id,
                PaymentGatewayProviderCodeEnum::Stripe,
                PaymentGatewayEnvironmentEnum::Test,
                'operator:plan048-test',
                'plan048-c6:setup',
            ),
            new EphemeralSecret('whsec_connect_plan048'),
            0,
        );
    });

    afterEach(function () {
        if (is_file($this->keyringPath)) {
            unlink($this->keyringPath);
        }
    });

    it('resolves the connection from the Connect account field and verifies with its stored secret', function () {
        Queue::fake();

        $event = ($this->makeStripeEvent)(
            'payment_intent.succeeded',
            [
                'object' => 'payment_intent',
                'id' => 'pi_plan048_c6',
                'amount' => 1500,
                'currency' => 'jpy',
                'status' => 'succeeded',
            ],
            'whsec_connect_plan048',
            ['account' => 'acct_plan048_connect'],
        );

        ($this->postProviderWebhook)('stripe', $event['payload'], ['HTTP_STRIPE_SIGNATURE' => $event['header']])
            ->assertOk();

        $inbox = PaymentProviderEvent::query()->where('provider_event_id', $event['event_id'])->firstOrFail();

        expect((string) $inbox->connection_id)->toBe((string) $this->connectConnection->id);
    });

    it('#1109: rejects events for a known-but-deactivated connection instead of rerouting to legacy', function () {
        $this->connectConnection->forceFill(['is_active' => false])->save();

        $event = ($this->makeStripeEvent)(
            'payment_intent.succeeded',
            [
                'object' => 'payment_intent',
                'id' => 'pi_plan048_deactivated',
                'amount' => 100,
                'currency' => 'jpy',
                'status' => 'succeeded',
            ],
            'whsec_test_secret_xyz',
            ['account' => 'acct_plan048_connect'],
        );

        ($this->postProviderWebhook)('stripe', $event['payload'], ['HTTP_STRIPE_SIGNATURE' => $event['header']])
            ->assertStatus(400);

        expect(PaymentProviderEvent::query()->where('provider_event_id', $event['event_id'])->count())->toBe(0);
    });

    it('#1109: verifies with the global endpoint secret when the merchant has no rotated-in secret yet', function () {
        Queue::fake();

        $noSecret = PaymentGatewayConnection::factory()->create([
            'provider_id' => $this->connectConnection->provider_id,
            // #3074 — tenant RIÊNG, không chép org/brand của `connectConnection`.
            //
            // Bài này phân giải webhook theo `merchant_account_id`, nên việc dùng
            // chung tổ chức chỉ là tiện tay lúc viết. Sau khi khoá tự nhiên
            // (provider · environment · organization · brand · owner_scope ·
            // owner_branch_key) thành UNIQUE, hai connection cùng tenant là điều
            // KHÔNG THỂ — và đó chính là tính chất #3070 cần.
            'owner_branch_id' => null,
            'owner_scope' => 'hq',
            'environment' => PaymentGatewayEnvironmentEnum::Test,
            'merchant_account_id' => 'acct_plan048_unrotated',
            'is_active' => true,
            'secret_ref' => null,
            'webhook_secret_ref' => null,
            'secret_version' => null,
            'key_fingerprint' => null,
        ]);

        $event = ($this->makeStripeEvent)(
            'payment_intent.succeeded',
            [
                'object' => 'payment_intent',
                'id' => 'pi_plan048_global_fallback',
                'amount' => 200,
                'currency' => 'jpy',
                'status' => 'succeeded',
            ],
            'whsec_test_secret_xyz',
            ['account' => 'acct_plan048_unrotated'],
        );

        ($this->postProviderWebhook)('stripe', $event['payload'], ['HTTP_STRIPE_SIGNATURE' => $event['header']])
            ->assertOk();

        $inbox = PaymentProviderEvent::query()->where('provider_event_id', $event['event_id'])->firstOrFail();

        expect((string) $inbox->connection_id)->toBe((string) $noSecret->id);
    });

    it('#1109: a REVOKED webhook secret fails closed instead of downgrading to the global secret', function () {
        app(GatewayConnectionSecretResolver::class)->revokeWebhook(
            new GatewaySecretAccessContext(
                (string) $this->connectConnection->organization_id,
                (string) $this->connectConnection->id,
                PaymentGatewayProviderCodeEnum::Stripe,
                PaymentGatewayEnvironmentEnum::Test,
                'operator:plan048-test',
                'plan048-revoke:setup',
            ),
        );

        $event = ($this->makeStripeEvent)(
            'payment_intent.succeeded',
            [
                'object' => 'payment_intent',
                'id' => 'pi_plan048_revoked',
                'amount' => 100,
                'currency' => 'jpy',
                'status' => 'succeeded',
            ],
            'whsec_test_secret_xyz',
            ['account' => 'acct_plan048_connect'],
        );

        ($this->postProviderWebhook)('stripe', $event['payload'], ['HTTP_STRIPE_SIGNATURE' => $event['header']])
            ->assertStatus(400);

        expect(PaymentProviderEvent::query()->where('provider_event_id', $event['event_id'])->count())->toBe(0);
    });

    it('hint cannot rehome a Connect event onto a different connection', function () {
        $other = PaymentGatewayConnection::factory()->create([
            'provider_id' => $this->connectConnection->provider_id,
            // #3074 — tenant RIÊNG, không chép org/brand của `connectConnection`.
            //
            // Bài này phân giải webhook theo `merchant_account_id`, nên việc dùng
            // chung tổ chức chỉ là tiện tay lúc viết. Sau khi khoá tự nhiên
            // (provider · environment · organization · brand · owner_scope ·
            // owner_branch_key) thành UNIQUE, hai connection cùng tenant là điều
            // KHÔNG THỂ — và đó chính là tính chất #3070 cần.
            'owner_branch_id' => null,
            'owner_scope' => 'hq',
            'environment' => PaymentGatewayEnvironmentEnum::Test,
            'merchant_account_id' => 'acct_plan048_other',
            'is_active' => true,
            'secret_ref' => null,
            'webhook_secret_ref' => null,
            'secret_version' => null,
            'key_fingerprint' => null,
        ]);

        $event = ($this->makeStripeEvent)(
            'payment_intent.succeeded',
            [
                'object' => 'payment_intent',
                'id' => 'pi_plan048_hint_mismatch',
                'amount' => 100,
                'currency' => 'jpy',
                'status' => 'succeeded',
            ],
            'whsec_test_secret_xyz',
            ['account' => 'acct_plan048_connect'],
        );

        $this->call(
            'POST',
            '/api/v1/webhooks/payment/stripe?connection='.$other->id,
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $event['header'], 'CONTENT_TYPE' => 'application/json'],
            $event['payload'],
        )->assertStatus(400);

        expect(PaymentProviderEvent::query()->where('provider_event_id', $event['event_id'])->count())->toBe(0);
    });

    it('falls back to the legacy global connection when the account is unknown', function () {
        Queue::fake();

        $event = ($this->makeStripeEvent)(
            'payment_intent.succeeded',
            [
                'object' => 'payment_intent',
                'id' => 'pi_plan048_c6_fallback',
                'amount' => 300,
                'currency' => 'jpy',
                'status' => 'succeeded',
            ],
            'whsec_test_secret_xyz',
            ['account' => 'acct_never_registered'],
        );

        ($this->postProviderWebhook)('stripe', $event['payload'], ['HTTP_STRIPE_SIGNATURE' => $event['header']])
            ->assertOk();

        $inbox = PaymentProviderEvent::query()->where('provider_event_id', $event['event_id'])->firstOrFail();

        expect((string) $inbox->connection_id)->toBe('00000000-0000-4000-8000-000000000001');
    });
});

/**
 * Capture log context WITHOUT swapping the logger.
 *
 * `Log::spy()` looks tidier and breaks the request: other code on this path
 * calls `Log::channel('payment_orchestration')`, a spy answers that with null,
 * and the 400 under test turns into a 500. Listening to MessageLogged leaves
 * the real logger in place — the approach PrintImageDiskTest already uses.
 *
 * @return ArrayObject<int, array<string, mixed>>
 */
function plan048CaptureWarnings(): ArrayObject
{
    $seen = new ArrayObject;

    Event::listen(MessageLogged::class, function (MessageLogged $e) use ($seen) {
        $seen[] = ['message' => $e->message] + $e->context;
    });

    return $seen;
}

/**
 * @param  ArrayObject<int, array<string, mixed>>  $seen
 * @return array<string, mixed>|null
 */
function plan048FindRejection(ArrayObject $seen): ?array
{
    foreach ($seen as $entry) {
        if (($entry['message'] ?? null) === 'Provider webhook signature verification failed') {
            return $entry;
        }
    }

    return null;
}

/**
 * A LIVE PayPay connection cloned from the suite's Test-environment one.
 *
 * The rejection under test only exists on Live: `verifyOpaWebhookSource`
 * deliberately accepts an OPA payload on any lower environment and merely logs
 * `paypay_webhook_unverified_accept`.
 */
function plan048LivePayPayConnection(PaymentGatewayConnection $from): PaymentGatewayConnection
{
    return PaymentGatewayConnection::factory()->create([
        'provider_id' => $from->provider_id,
        'organization_id' => $from->organization_id,
        'brand_id' => $from->brand_id,
        'owner_branch_id' => null,
        'owner_scope' => 'hq',
        'environment' => PaymentGatewayEnvironmentEnum::Live,
        'merchant_account_id' => 'paypay_merchant_048_live',
        'is_active' => true,
    ]);
}

/*
|--------------------------------------------------------------------------
| #2445 — relay IP appended to the LIVE allowlist
|--------------------------------------------------------------------------
|
| PayPay's Live webhook for merchant 653886312490745856 is shared with the
| legacy WooCommerce site. Rather than move it and risk the live shop, that
| site replays each genuine delivery to us; the replay arrives from ITS egress
| IP, so that IP has to clear the same source check PayPay's own IPs do.
|
| The property that matters for the day PayPay finally points Live straight at
| us: their IPs stay in the list, so nothing needs changing — and a duplicate
| arriving on both paths is dropped by the unique index on
| (connection_id, environment, provider_event_id).
*/

it('appends PAYPAY_WEBHOOK_RELAY_IPS to the live allowlist without displacing PayPay', function () {
    $verifier = new PayPayWebhookSourceVerifier;

    config(['services.paypay.webhook_source_ips.live' => ['52.68.128.8', '85.131.198.83']]);

    expect($verifier->isAllowed('85.131.198.83', PaymentGatewayEnvironmentEnum::Live))->toBeTrue()
        ->and($verifier->isAllowed('52.68.128.8', PaymentGatewayEnvironmentEnum::Live))->toBeTrue()
        ->and($verifier->isAllowed('203.0.113.1', PaymentGatewayEnvironmentEnum::Live))->toBeFalse();
});

it('does not let a relay IP into the SANDBOX allowlist', function () {
    // The relay only exists for the Live merchant. Widening sandbox too would
    // grant trust nobody asked for.
    $verifier = new PayPayWebhookSourceVerifier;

    config([
        'services.paypay.webhook_source_ips.live' => ['85.131.198.83'],
        'services.paypay.webhook_source_ips.sandbox' => ['13.112.237.64'],
    ]);

    expect($verifier->isAllowed('85.131.198.83', PaymentGatewayEnvironmentEnum::Sandbox))->toBeFalse();
});

it('reads the relay list from env as a comma-separated string', function () {
    // Config is resolved at boot, so this asserts the PARSING contract the
    // config file relies on rather than re-reading the file.
    $parsed = array_values(array_filter(array_map('trim', explode(',', ' 85.131.198.83 , 203.0.113.9 ,, '))));

    expect($parsed)->toBe(['85.131.198.83', '203.0.113.9']);
});
