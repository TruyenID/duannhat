<?php

namespace App\Services\Payment\Gateway\Commands;

use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\Gateway\ValueObjects\GatewayRequestContext;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Gateway\ValueObjects\ProviderObjectReference;
use App\Services\Payment\Gateway\ValueObjects\RedactedData;

final readonly class RefundPaymentCommand
{
    public function __construct(
        public GatewayConnectionData $connection,
        public GatewayRequestContext $request,
        public ProviderObjectReference $payment,
        public Money $money,
        public RedactedData $metadata = new RedactedData,
    ) {}
}
