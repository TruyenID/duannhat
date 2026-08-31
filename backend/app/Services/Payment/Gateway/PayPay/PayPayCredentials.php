<?php

namespace App\Services\Payment\Gateway\PayPay;

final readonly class PayPayCredentials
{
    public function __construct(
        public string $apiKey,
        public string $apiSecret,
        public string $assumeMerchant,
        public ?string $webhookSecret = null,
    ) {}

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->apiSecret !== '';
    }
}
