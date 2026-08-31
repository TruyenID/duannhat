<?php

namespace App\Services\Payment\Gateway\Commands;

use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\Gateway\ValueObjects\GatewayRequestContext;
use App\Services\Payment\Gateway\ValueObjects\ProviderObjectReference;

final readonly class RetrieveRefundCommand
{
    public function __construct(
        public GatewayConnectionData $connection,
        public GatewayRequestContext $request,
        public ProviderObjectReference $refund,
    ) {}
}
