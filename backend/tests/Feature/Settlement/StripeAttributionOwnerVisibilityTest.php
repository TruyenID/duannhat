<?php

/**
 * #2893 — phép đo nghiệm thu THẬT: người của tổ chức sở hữu có NHÌN THẤY tiền
 * của mình không.
 *
 * Trước bản vá, mọi webhook tài khoản nền rơi vào hàng connection tổng hợp
 * `LegacyGlobalStripeConnection`, thuộc một tổ chức không có thành viên nào.
 * `SettlementController::brandConnectionIds()` lọc theo org+brand của người
 * đăng nhập, nên chủ sở hữu mở màn hình đối soát ra thấy **rỗng** — không phải
 * thấy sai số, mà là không thấy gì. Đo trên production 2026-08-15: 747 hàng,
 * ¥939.235.
 *
 * Bộ test này đi ĐÚNG đường thật: POST webhook đã ký → hàng inbox → ghi sổ
 * settlement → GET endpoint HQ bằng tài khoản của tổ chức đó. Ba mắt xích, vì
 * đứt bất kỳ mắt nào cũng ra cùng một triệu chứng "màn hình trống".
 *
 * Test cuối CỐ Ý dựng lại trạng thái CŨ (`STRIPE_ACCOUNT_ID` rỗng) và ghim
 * rằng lúc đó tiền vô hình — rào phải biết KÊU và biết IM, nếu không nó chỉ
 * chứng minh được một chiều.
 */

use App\Models\Brand;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentProviderEvent;
use App\Models\PaymentSettlement;
use App\Models\User;
use App\Services\Payment\ProviderEvent\LegacyGlobalStripeConnection;
use App\Services\Payment\Settlement\Stripe\StripeSettlementClient;
use App\Services\Payment\Settlement\Stripe\StripeSettlementRecorder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fakes\Payment\FakeStripeSettlementClient;
use Tests\Support\Payment\SettlementTestFactory;

uses()->group('payment');

const PLATFORM_ACCOUNT_2893 = 'acct_2893PlatformAccount';

beforeEach(function () {
    config([
        'services.stripe.secret' => 'sk_test_dummy_secret_for_tests',
        'services.stripe.webhook_secret' => 'whsec_test_secret_2893',
        'services.stripe.account_id' => PLATFORM_ACCOUNT_2893,
    ]);

    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
        'name' => 'ベト屋フーズ株式会社',
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'betoya-'.Str::lower(Str::random(4)),
        'is_active' => true,
    ]);

    // Hàng connection THẬT của quán — mang định danh THẬT của tài khoản Stripe
    // (`acct_…`), đúng thứ mà `payments:migrate-stripe-attribution` đóng dấu.
    $this->connection = PaymentGatewayConnection::factory()->create([
        'provider_id' => SettlementTestFactory::provider('stripe')->id,
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'owner_branch_id' => null,
        'owner_scope' => 'hq',
        'environment' => 'test',
        'merchant_account_id' => PLATFORM_ACCOUNT_2893,
        'health' => 'ready',
        'is_active' => true,
        'secret_ref' => null,
        'webhook_secret_ref' => null,
        'secret_version' => null,
        'key_fingerprint' => null,
    ]);

    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->user, $this->orgId);
    $this->actingAs($this->user);

    $this->postPlatformWebhook = function (string $intentId, string $chargeId): PaymentProviderEvent {
        $payload = json_encode([
            'id' => 'evt_'.Str::random(24),
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'created' => time(),
            // KHÔNG có `account` — đây chính là hình dạng của sự kiện tài khoản
            // NỀN, tức 100% lưu lượng Stripe của quán hôm nay.
            'data' => ['object' => [
                'object' => 'payment_intent',
                'id' => $intentId,
                'amount' => 10_000,
                'currency' => 'jpy',
                'status' => 'succeeded',
                'latest_charge' => $chargeId,
                'metadata' => ['order_id' => (string) Str::uuid()],
            ]],
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test_secret_2893');

        test()->call(
            'POST',
            '/api/v1/webhooks/payment/stripe',
            [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}"],
            $payload,
        )->assertOk();

        return PaymentProviderEvent::query()
            ->where('provider_object_id', $intentId)
            ->orWhere('event_type', 'payment_intent.succeeded')
            ->latest('created_at')
            ->firstOrFail();
    };

    $this->recordSettlement = function (PaymentProviderEvent $event, string $chargeId, string $txnId): void {
        $fake = new FakeStripeSettlementClient;
        app()->instance(StripeSettlementClient::class, $fake);
        $fake->withCharge(['id' => $chargeId, 'balance_transaction' => $txnId])
            ->withBalanceTransaction([
                'id' => $txnId, 'type' => 'charge', 'amount' => 10_000, 'fee' => 360,
                'net' => 9_640, 'currency' => 'jpy', 'created' => 1785000000,
                'fee_details' => [['type' => 'stripe_fee', 'amount' => 360, 'currency' => 'jpy']],
            ]);

        expect(app(StripeSettlementRecorder::class)->applyProviderEvent($event))
            ->toBe('settlement_payment_recorded');
    };
});

it('attributes a platform-account webhook to the OWNER connection, not the synthetic one', function () {
    Queue::fake();

    $event = ($this->postPlatformWebhook)('pi_2893_owner', 'ch_2893_owner');

    expect((string) $event->connection_id)->toBe((string) $this->connection->id)
        ->and((string) $event->connection_id)->not->toBe(LegacyGlobalStripeConnection::CONNECTION_ID)
        ->and((string) $event->organization_id)->toBe($this->orgId);
});

it('ACCEPTANCE: a member of the owning organization SEES the settlement row through the HQ endpoint', function () {
    Queue::fake();

    $event = ($this->postPlatformWebhook)('pi_2893_visible', 'ch_2893_visible');
    ($this->recordSettlement)($event, 'ch_2893_visible', 'txn_2893_visible');

    $row = PaymentSettlement::query()->where('external_ref', 'txn_2893_visible')->firstOrFail();
    expect((string) $row->connection_id)->toBe((string) $this->connection->id);

    $response = $this->getJson("/api/v1/hq/{$this->brand->slug}/settlements")->assertOk();

    expect($response->json('meta.total'))->toBe(1)
        ->and($response->json('data.0.external_ref'))->toBe('txn_2893_visible')
        ->and($response->json('data.0.gross_minor'))->toBe(10_000)
        ->and($response->json('data.0.net_minor'))->toBe(9_640);
});

it('CHIỀU NGƯỢC LẠI: with STRIPE_ACCOUNT_ID unset the money lands on the retired connection and the owner sees NOTHING', function () {
    Queue::fake();
    config(['services.stripe.account_id' => null]);

    $event = ($this->postPlatformWebhook)('pi_2893_invisible', 'ch_2893_invisible');
    expect((string) $event->connection_id)->toBe(LegacyGlobalStripeConnection::CONNECTION_ID);

    ($this->recordSettlement)($event, 'ch_2893_invisible', 'txn_2893_invisible');

    // Tiền CÓ trong sổ…
    expect(PaymentSettlement::query()->where('external_ref', 'txn_2893_invisible')->count())->toBe(1);

    // …và chủ sở hữu không thấy một dòng nào. Đây là bản sao chính xác của lỗi
    // production mà #2893 tồn tại để sửa.
    $this->getJson("/api/v1/hq/{$this->brand->slug}/settlements")
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});
