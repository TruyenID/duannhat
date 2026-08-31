<?php

namespace App\Services\Order\Contracts;

use App\Services\Order\Commands\ChangeOrderItemsCommand;
use App\Services\Order\Commands\CreateOrderCommand;
use App\Services\Order\ValueObjects\OrderLinePayload;
use App\Services\Order\ValueObjects\TrustedOrderSnapshot;

interface OrderPricingResolutionPort
{
    public function resolveOrder(CreateOrderCommand $command): TrustedOrderSnapshot;

    public function resolveLine(ChangeOrderItemsCommand $command): OrderLinePayload;
}
