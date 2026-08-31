<?php

namespace App\Services\Payment\Gateway\PayPay;

use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;

/** Decodes PayPay OAuth `responseToken` JWT via godx-jp/paypayopa-php-sdk. */
final class PayPayUserAuthorizationDecoder
{
    public function __construct(
        private readonly PayPaySdkClientFactory $clientFactory = new PayPaySdkClientFactory,
        private readonly PayPayCredentialsResolver $credentialsResolver = new PayPayCredentialsResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function decodeResponseToken(GatewayConnectionData $connection, string $responseToken): array
    {
        $credentials = $this->credentialsResolver->forConnection($connection);
        $client = $this->clientFactory->forConnection($connection, $credentials);

        return $client->user->decodeUserAuth($responseToken);
    }
}
