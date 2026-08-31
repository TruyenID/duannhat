<?php

namespace App\Services\Payment\Gateway\PayPay;

use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\HandlerStack;
use PayPay\OpenPaymentAPI\Client;

final class PayPaySdkClientFactory
{
    public function forConnection(
        GatewayConnectionData $connection,
        PayPayCredentials $credentials,
    ): Client {
        $productionMode = $this->productionMode($connection->environment);

        return new Client(
            [
                'API_KEY' => $credentials->apiKey,
                'API_SECRET' => $credentials->apiSecret,
                'MERCHANT_ID' => $credentials->assumeMerchant,
            ],
            $productionMode,
            $this->httpClient($productionMode),
        );
    }

    private function productionMode(PaymentGatewayEnvironmentEnum $environment): bool
    {
        return $environment === PaymentGatewayEnvironmentEnum::Live;
    }

    private function httpClient(bool $productionMode): GuzzleHttpClient|false
    {
        $proxyUrl = trim((string) config('services.paypay.egress_proxy_url', ''));
        $proxyToken = (string) config('services.paypay.egress_proxy_token', '');

        if ($proxyUrl === '' || $proxyToken === '') {
            return false;
        }

        $baseUri = $productionMode
            ? 'https://apigw.paypay.ne.jp/v2'
            : 'https://apigw.sandbox.paypay.ne.jp/v2';

        $stack = HandlerStack::create(new PayPayEgressProxyHandler($proxyUrl, $proxyToken));

        return new GuzzleHttpClient([
            'base_uri' => $baseUri,
            'handler' => $stack,
            'timeout' => 30,
        ]);
    }
}
