<?php

/**
 * #2893 — tài khoản của CHÍNH TA không phải tài khoản kết nối.
 *
 * Đóng dấu định danh Stripe thật (`acct_…`) lên hàng connection là việc #2893
 * phải làm để đối soát payout không mơ hồ trên tài khoản dùng chung (#2864).
 * Nhưng trước đó, `StripeConnectScope` coi MỌI chuỗi `acct_…` là tài khoản
 * kết nối và kèm header `Stripe-Account` — nên chính hành động "ghi định danh
 * cho đúng chuẩn" sẽ biến mọi lượt gọi Stripe của connection đó thành lượt gọi
 * "đóng vai chính mình".
 *
 * Cái hỏng đó KHÔNG lộ ra ở màn hình nào: đường chịu ảnh hưởng là
 * `StripeSettlementApiClient` — đọc phí và số dư thật — và tầng settlement
 * fail-open, lỗi chỉ đi vào log. Sổ đối soát sẽ trống dần trong im lặng.
 *
 * Phép phân biệt duy nhất là `STRIPE_ACCOUNT_ID`; hai chiều đều được ghim ở
 * đây, vì một rào chỉ biết kêu mà không biết im thì sẽ bị tắt.
 */

use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\Stripe\StripeConnectScope;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\ProviderEvent\StripePlatformAccount;
use App\Services\Payment\Settlement\Stripe\StripeSettlementApiClient;
use Stripe\Charge;
use Stripe\StripeClient;
use Tests\Support\Payment\SettlementTestFactory;

uses()->group('payment');

function accountConnection(string $account): GatewayConnectionData
{
    return new GatewayConnectionData(
        '22222222-2222-4222-8222-222222222222',
        PaymentGatewayProviderCodeEnum::Stripe,
        PaymentGatewayEnvironmentEnum::Test,
        $account,
        1,
    );
}

it('sends NO Stripe-Account header for the account our own API key belongs to', function () {
    config(['services.stripe.account_id' => 'acct_ourOwnPlatform']);

    expect(StripeConnectScope::requestOptions(accountConnection('acct_ourOwnPlatform')))->toBe([])
        ->and(StripeConnectScope::summaryFields(accountConnection('acct_ourOwnPlatform'))['connect_account_scope'])
        ->toBe('platform');
});

it('still scopes a genuine connected account — the platform exception is exactly one account wide', function () {
    config(['services.stripe.account_id' => 'acct_ourOwnPlatform']);

    expect(StripeConnectScope::requestOptions(accountConnection('acct_someMerchant')))
        ->toBe(['stripe_account' => 'acct_someMerchant']);
});

it('falls back to pre-#2893 behaviour when STRIPE_ACCOUNT_ID is unset — it never GUESSES that an account is ours', function () {
    config(['services.stripe.account_id' => null]);

    expect(StripeConnectScope::requestOptions(accountConnection('acct_ourOwnPlatform')))
        ->toBe(['stripe_account' => 'acct_ourOwnPlatform'])
        ->and(StripePlatformAccount::accountId())->toBeNull();
});

it('rejects a malformed STRIPE_ACCOUNT_ID instead of trusting it', function () {
    config(['services.stripe.account_id' => 'betoya-production']);

    expect(StripePlatformAccount::accountId())->toBeNull()
        ->and(StripePlatformAccount::isPlatformAccount('betoya-production'))->toBeFalse();
});

it('reads the settlement charge on the platform account — the path that would break in silence', function () {
    config(['services.stripe.account_id' => 'acct_ourOwnPlatform']);

    $connection = SettlementTestFactory::stripeConnection();
    $connection->update(['merchant_account_id' => 'acct_ourOwnPlatform']);

    $captured = null;
    $client = Mockery::mock(StripeClient::class);
    $client->charges = Mockery::mock();
    $client->charges->shouldReceive('retrieve')->once()
        ->andReturnUsing(function (...$args) use (&$captured) {
            $captured = $args;

            return Charge::constructFrom(['id' => 'ch_platform']);
        });

    (new StripeSettlementApiClient($client))->retrieveCharge($connection->fresh(), 'ch_platform');

    expect($captured)->toHaveCount(1);
});

it('keeps scoping settlement reads for a connected account', function () {
    config(['services.stripe.account_id' => 'acct_ourOwnPlatform']);

    $connection = SettlementTestFactory::stripeConnection();
    $connection->update(['merchant_account_id' => 'acct_connectedMerchant']);

    $captured = null;
    $client = Mockery::mock(StripeClient::class);
    $client->charges = Mockery::mock();
    $client->charges->shouldReceive('retrieve')->once()
        ->andReturnUsing(function (...$args) use (&$captured) {
            $captured = $args;

            return Charge::constructFrom(['id' => 'ch_merchant']);
        });

    (new StripeSettlementApiClient($client))->retrieveCharge($connection->fresh(), 'ch_merchant');

    expect($captured[2] ?? null)->toBe(['stripe_account' => 'acct_connectedMerchant']);
});
