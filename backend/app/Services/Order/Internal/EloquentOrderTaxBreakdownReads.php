<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Services\Customer\OrderTaxBreakdownAggregator;
use App\Services\Order\Contracts\OrderTaxBreakdownReads;

/**
 * #962 (7b) — hiện thực {@see OrderTaxBreakdownReads}.
 *
 * CHUYỂN TIẾP thẳng, không gộp lại — lý do ở docblock hợp đồng.
 */
final class EloquentOrderTaxBreakdownReads implements OrderTaxBreakdownReads
{
    public function __construct(private readonly OrderTaxBreakdownAggregator $aggregator) {}

    public function forOrders(iterable $orderIds): array
    {
        return $this->aggregator->forOrders($orderIds);
    }
}
