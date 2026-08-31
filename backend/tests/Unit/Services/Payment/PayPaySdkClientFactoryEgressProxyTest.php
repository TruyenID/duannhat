<?php

use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\PayPay\PayPayCredentials;
use App\Services\Payment\Gateway\PayPay\PayPaySdkClientFactory;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use GuzzleHttp\Client as GuzzleHttpClient;
use Tests\TestCase;

uses(TestCase::class);

it('injects a proxied guzzle client when egress proxy env is set', function () {
    config([
        'services.paypay.egress_proxy_url' => 'https://api.tempofast.com/paypay-egress-proxy.php',
        'services.paypay.egress_proxy_token' => 'test-token',
    ]);

    $factory = new PayPaySdkClientFactory;
    $client = $factory->forConnection(
        new GatewayConnectionData(
            '019fe9b8-a4fa-7070-adf2-60eb82bd61a6',
            PaymentGatewayProviderCodeEnum::Paypay,
            PaymentGatewayEnvironmentEnum::Live,
            '653886312490745856',
            1,
        ),
        new PayPayCredentials('key', 'secret', '653886312490745856'),
    );

    $http = $client->http();
    expect($http)->toBeInstanceOf(GuzzleHttpClient::class);
    expect((string) $http->getConfig('base_uri'))->toBe('https://apigw.paypay.ne.jp/v2');
    expect($http->getConfig('handler'))->not->toBeNull();
});

it('uses the SDK default client when egress proxy env is empty', function () {
    config([
        'services.paypay.egress_proxy_url' => '',
        'services.paypay.egress_proxy_token' => '',
    ]);

    $factory = new PayPaySdkClientFactory;
    $client = $factory->forConnection(
        new GatewayConnectionData(
            '019fe9b8-a4fa-7070-adf2-60eb82bd61a6',
            PaymentGatewayProviderCodeEnum::Paypay,
            PaymentGatewayEnvironmentEnum::Live,
            '653886312490745856',
            1,
        ),
        new PayPayCredentials('key', 'secret', '653886312490745856'),
    );

    expect((string) $client->http()->getConfig('base_uri'))->toBe('https://apigw.paypay.ne.jp/v2');
});
