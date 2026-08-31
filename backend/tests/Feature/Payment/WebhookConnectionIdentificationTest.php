<?php

/**
 * #2938 — kiến thức phân giải webhook sống TRONG adapter của từng nhà cung cấp.
 *
 * Trước #2938, `WebhookConnectionResolver` phân giải bằng `match ($provider)`
 * rồi gọi `resolveStripe()` / `resolvePayPay()` ngay trong chính nó. Hành vi
 * đúng, nhưng CHỖ Ở sai: thêm nhà cung cấp thứ tư phải sửa một file dùng chung
 * — đúng thứ `PaymentGatewayRegistry` sinh ra để tránh.
 *
 * Bộ test này ghim hai thứ khác nhau, và cần cả hai:
 *
 *  1. **hình dạng hợp đồng** — mỗi adapter tự nhận ra payload của mình, và
 *     adapter chưa đăng ký trả `null`;
 *  2. **hệ quả kiến trúc** — một nhà cung cấp THỨ TƯ phân giải được mà KHÔNG
 *     ai chạm vào `WebhookConnectionResolver`. Đây mới là rào thật: bỏ bản vá
 *     ra thì test này đỏ, vì `default => null` của cái `match` cũ nuốt trọn
 *     provider lạ.
 *
 * Hành vi phân giải cũ (#1109 tắt-là-từ-chối, #2893 tài khoản nền, lưới cuối
 * hàng ngưng dùng, chặn rehome bằng `?connection=`) KHÔNG được lặp lại ở đây —
 * `Plan048ProviderWebhookIntakeTest` đã ghim chúng qua HTTP thật và phải giữ
 * nguyên không sửa một assertion nào.
 */

use App\Models\Brand;
use App\Models\Organization;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayProvider;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\Commands\CancelPaymentCommand;
use App\Services\Payment\Gateway\Commands\CapturePaymentCommand;
use App\Services\Payment\Gateway\Commands\CreatePaymentCommand;
use App\Services\Payment\Gateway\Commands\RefundPaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrievePaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrieveRefundCommand;
use App\Services\Payment\Gateway\Commands\VerifyWebhookCommand;
use App\Services\Payment\Gateway\Contracts\PaymentGatewayContract;
use App\Services\Payment\Gateway\Enums\ConnectionLookupKind;
use App\Services\Payment\Gateway\PaymentGatewayRegistry;
use App\Services\Payment\Gateway\PayPay\PayPayPaymentGateway;
use App\Services\Payment\Gateway\Results\GatewayPaymentResult;
use App\Services\Payment\Gateway\Results\GatewayRefundResult;
use App\Services\Payment\Gateway\Results\VerifiedGatewayEvent;
use App\Services\Payment\Gateway\Sbps\SbpsPaymentGateway;
use App\Services\Payment\Gateway\Stripe\StripePaymentGateway;
use App\Services\Payment\Gateway\ValueObjects\CapabilitySet;
use App\Services\Payment\Gateway\ValueObjects\ConnectionLocator;
use App\Services\Payment\Gateway\ValueObjects\ConnectionLookup;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\ProviderEvent\WebhookConnectionResolver;

uses()->group('payment');

/** Provider row + một connection đang bật, đủ để resolver tra được. */
function issue2938Connection(
    PaymentGatewayProviderCodeEnum $code,
    string $merchantAccountId,
    bool $isActive = true,
): PaymentGatewayConnection {
    $organization = Organization::factory()->create();
    $brand = Brand::factory()->create([
        'console_organization_id' => $organization->console_organization_id,
    ]);
    $provider = PaymentGatewayProvider::query()->firstOrCreate(
        ['code' => $code->value],
        ['is_active' => true, 'name' => ucfirst($code->value), 'sort_order' => 10],
    );

    return PaymentGatewayConnection::factory()->create([
        'provider_id' => $provider->id,
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'owner_branch_id' => null,
        'owner_scope' => 'hq',
        'environment' => PaymentGatewayEnvironmentEnum::Test,
        'merchant_account_id' => $merchantAccountId,
        'is_active' => $isActive,
        'secret_ref' => null,
        'webhook_secret_ref' => null,
        'secret_version' => null,
        'key_fingerprint' => null,
    ]);
}

/**
 * Nhà cung cấp THỨ TƯ giả lập: một adapter chỉ biết ĐÚNG một việc — đọc khoá
 * riêng của mình rồi khai ra phép tra. Mọi thao tác tiền đều ném, để nếu có ai
 * lỡ đi đường khác thì nó chết to tiếng chứ không âm thầm.
 */
function issue2938FourthProviderGateway(): PaymentGatewayContract
{
    return new class implements PaymentGatewayContract
    {
        public function identifyConnection(array $payload): ?ConnectionLocator
        {
            $account = $payload['sbps_merchant'] ?? null;

            if (! is_string($account) || $account === '') {
                return null;
            }

            return new ConnectionLocator([ConnectionLookup::merchantAccount([$account])], [$account]);
        }

        public function capabilities(GatewayConnectionData $connection): CapabilitySet
        {
            throw new RuntimeException('unused');
        }

        public function preparePayment(CreatePaymentCommand $command): GatewayPaymentResult
        {
            throw new RuntimeException('unused');
        }

        public function retrievePayment(RetrievePaymentCommand $command): GatewayPaymentResult
        {
            throw new RuntimeException('unused');
        }

        public function capture(CapturePaymentCommand $command): GatewayPaymentResult
        {
            throw new RuntimeException('unused');
        }

        public function cancel(CancelPaymentCommand $command): GatewayPaymentResult
        {
            throw new RuntimeException('unused');
        }

        public function refund(RefundPaymentCommand $command): GatewayRefundResult
        {
            throw new RuntimeException('unused');
        }

        public function retrieveRefund(RetrieveRefundCommand $command): GatewayRefundResult
        {
            throw new RuntimeException('unused');
        }

        public function verifyWebhook(VerifyWebhookCommand $command): VerifiedGatewayEvent
        {
            throw new RuntimeException('unused');
        }
    };
}

/*
|--------------------------------------------------------------------------
| 1. Hình dạng hợp đồng — mỗi adapter nhận ra payload CỦA MÌNH
|--------------------------------------------------------------------------
*/

it('Stripe nhận ra `account` của Connect và ghim nó làm định danh RÀNG BUỘC', function () {
    $locator = (new StripePaymentGateway)->identifyConnection(['account' => 'acct_issue2938']);

    expect($locator)->not->toBeNull()
        ->and($locator->lookups[0]->kind)->toBe(ConnectionLookupKind::MerchantAccount)
        ->and($locator->lookups[0]->values)->toBe(['acct_issue2938'])
        // #1109 — merchant biết-nhưng-đã-tắt phải chặn đứng cả locator.
        ->and($locator->lookups[0]->haltWhenOnlyInactiveMatches)->toBeTrue()
        // Định danh sự kiện tự khai ⇒ dùng chặn rehome bằng `?connection=`.
        ->and($locator->bindingMerchantAccountIds)->toBe(['acct_issue2938']);
});

it('Stripe KHÔNG có `account`: không ràng buộc định danh, và lưới cuối luôn kèm cảnh báo (#2893)', function () {
    config(['services.stripe.account_id' => 'acct_issue2938Platform']);

    $locator = (new StripePaymentGateway)->identifyConnection(['type' => 'payment_intent.succeeded']);

    $kinds = array_map(fn (ConnectionLookup $l): ConnectionLookupKind => $l->kind, $locator->lookups);
    $last = $locator->lookups[array_key_last($locator->lookups)];

    expect($locator->bindingMerchantAccountIds)->toBe([])
        ->and($kinds)->toBe([ConnectionLookupKind::MerchantAccount, ConnectionLookupKind::Designated])
        ->and($locator->lookups[0]->values)->toBe(['acct_issue2938Platform'])
        ->and($last->warningEvent)->toBe('stripe_webhook_attributed_to_retired_connection');
});

it('PayPay nhận ra mã tham chiếu CỦA TA, và cố ý KHÔNG ràng buộc theo merchant id', function () {
    $locator = (new PayPayPaymentGateway)->identifyConnection([
        'merchantPaymentId' => 'mp_issue2938',
        'merchant_id' => 'paypay_merchant_shared',
    ]);

    expect($locator->lookups[0]->kind)->toBe(ConnectionLookupKind::ProviderObjectReference)
        ->and($locator->lookups[0]->values)->toBe(['mp_issue2938'])
        // Một merchant account PayPay phục vụ cả deployment, nên merchant id
        // KHÔNG chỉ được chủ sở hữu. Khai nó vào ràng buộc sẽ là rào giả.
        ->and($locator->bindingMerchantAccountIds)->toBe([]);
});

it('adapter CHƯA có hợp đồng nhà cung cấp trả null, KHÔNG ném (SBPS #1796)', function () {
    // Ném ở đây sẽ biến mọi rác gửi tới `POST /webhooks/payment/sbps` thành
    // 500 — tự khai "lỗi của ta" cho traffic giả mạo.
    expect((new SbpsPaymentGateway)->identifyConnection(['anything' => 'at all']))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| 2. Resolver — payload lạ ⇒ null, provider chưa đăng ký ⇒ null
|--------------------------------------------------------------------------
*/

it('payload không mang định danh nào ⇒ TỪ CHỐI, không đoán một connection', function () {
    // Hai connection PayPay đang bật: lưới "một connection duy nhất" không còn
    // áp dụng, nên không còn gì để đoán — và đoán chính là thứ phải cấm.
    issue2938Connection(PaymentGatewayProviderCodeEnum::Paypay, 'paypay_tenant_a');
    issue2938Connection(PaymentGatewayProviderCodeEnum::Paypay, 'paypay_tenant_b');

    $resolved = app(WebhookConnectionResolver::class)->resolve(
        PaymentGatewayProviderCodeEnum::Paypay,
        json_encode(['nothing' => 'recognisable'], JSON_THROW_ON_ERROR),
        null,
    );

    expect($resolved)->toBeNull();
});

it('PayPay phân giải qua `payment_attempts.provider_object_id` — mã do CHÍNH TA sinh', function () {
    $connection = issue2938Connection(PaymentGatewayProviderCodeEnum::Paypay, 'paypay_tenant_a');
    issue2938Connection(PaymentGatewayProviderCodeEnum::Paypay, 'paypay_tenant_b');

    PaymentAttempt::factory()->create([
        'connection_id' => $connection->id,
        'provider_object_id' => 'mp_issue2938_attempt',
    ]);

    $resolved = app(WebhookConnectionResolver::class)->resolve(
        PaymentGatewayProviderCodeEnum::Paypay,
        json_encode(['merchantPaymentId' => 'mp_issue2938_attempt'], JSON_THROW_ON_ERROR),
        null,
    );

    expect($resolved)->not->toBeNull()
        ->and((string) $resolved->id)->toBe((string) $connection->id);
});

it('provider CHƯA đăng ký driver ⇒ TỪ CHỐI, không 5xx (SBPS vắng khỏi config có chủ đích)', function () {
    issue2938Connection(PaymentGatewayProviderCodeEnum::Sbps, 'sbps_merchant_x');

    expect(config('payments.gateway_drivers'))->not->toHaveKey('sbps');

    $resolved = app(WebhookConnectionResolver::class)->resolve(
        PaymentGatewayProviderCodeEnum::Sbps,
        json_encode(['sbps_merchant' => 'sbps_merchant_x'], JSON_THROW_ON_ERROR),
        null,
    );

    expect($resolved)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| 3. RÀO THẬT — nhà cung cấp thứ tư KHÔNG cần sửa file dùng chung
|--------------------------------------------------------------------------
*/

it('#2938 một nhà cung cấp THỨ TƯ phân giải được chỉ bằng adapter của nó', function () {
    // Đây là phép đo của cả issue. Với `match ($provider)` cũ, nhánh
    // `default => null` nuốt provider này và test đỏ — dù connection tồn tại,
    // đang bật, và mang đúng định danh trong payload.
    $connection = issue2938Connection(PaymentGatewayProviderCodeEnum::Sbps, 'sbps_merchant_fourth');

    app()->bind('issue2938.fourth-provider', fn (): PaymentGatewayContract => issue2938FourthProviderGateway());
    config(['payments.gateway_drivers.sbps' => 'issue2938.fourth-provider']);
    app()->forgetInstance(PaymentGatewayRegistry::class);

    $resolved = app(WebhookConnectionResolver::class)->resolve(
        PaymentGatewayProviderCodeEnum::Sbps,
        json_encode(['sbps_merchant' => 'sbps_merchant_fourth'], JSON_THROW_ON_ERROR),
        null,
    );

    expect($resolved)->not->toBeNull()
        ->and((string) $resolved->id)->toBe((string) $connection->id);
});

it('#2938 gợi ý `?connection=` vẫn không rehome được, kể cả với nhà cung cấp thứ tư', function () {
    // Rào chống rehome nay đọc `bindingMerchantAccountIds` do adapter khai,
    // không còn viết cứng `$decoded['account']` cho Stripe. Nếu vế đó chỉ chạy
    // cho Stripe thì test này xanh sai; nó phải chạy cho MỌI adapter nào khai
    // định danh ràng buộc.
    issue2938Connection(PaymentGatewayProviderCodeEnum::Sbps, 'sbps_merchant_fourth');
    $other = issue2938Connection(PaymentGatewayProviderCodeEnum::Sbps, 'sbps_merchant_other');

    app()->bind('issue2938.fourth-provider', fn (): PaymentGatewayContract => issue2938FourthProviderGateway());
    config(['payments.gateway_drivers.sbps' => 'issue2938.fourth-provider']);
    app()->forgetInstance(PaymentGatewayRegistry::class);

    $resolved = app(WebhookConnectionResolver::class)->resolve(
        PaymentGatewayProviderCodeEnum::Sbps,
        json_encode(['sbps_merchant' => 'sbps_merchant_fourth'], JSON_THROW_ON_ERROR),
        (string) $other->id,
    );

    expect($resolved)->toBeNull();
});
